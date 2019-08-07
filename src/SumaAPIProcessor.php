<?php
/**
 * Created by PhpStorm.
 * User: acarrasco
 * Date: 1/29/19
 * Time: 12:26 PM
 */
require __DIR__ . '/../vendor/autoload.php';

class SumaAPIProcessor {

	private $google_client;
	private $google_spreadsheet_service;
	private $google_drive_service;
	private $google_spreadsheet_id;
	private $google_spreadsheet_range;
	private $google_drive_file_count;
	private $initial_json;
	private $initial_offset;
	private $has_more;
	private $locations;
	private $daily = false;
	private $google_drive_folder_id;

	public function __construct( $daily = false ) {
		global $suma_api_url;
		global $initiative_id;

		if ( $daily ) {
			$this->daily        = $daily;
			$yesterday          = date( 'Ymd', time() - 60 * 60 * 24 );
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?sdate=' . $yesterday . '&edate=' . $yesterday . '&id=' . $initiative_id ) );
			global $google_drive_folder_id;
			$this->google_drive_folder_id = $google_drive_folder_id;
		} else {
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?id=' . $initiative_id ) );
			global $google_drive_folder_archive_id;
			$this->google_drive_folder_id = $google_drive_folder_archive_id;
		}
		$this->locations      = $this->getLocations();
		$this->initial_offset = $this->getInitialOffset();
		$this->has_more       = $this->hasMore();

		global $google_json_auth_file_path;
		$client = new \Google_Client();
		$client->setApplicationName( 'SUMACount' );
		$client->setScopes( [ \Google_Service_Sheets::SPREADSHEETS, \Google_Service_Drive::DRIVE ] );
		$client->setAccessType( 'offline' );

		$client->setAuthConfig( $google_json_auth_file_path );
		$this->google_spreadsheet_service = new Google_Service_Sheets( $client );
		$this->google_drive_service       = new Google_Service_Drive( $client );
		$this->google_client              = $client;
		$this->google_spreadsheet_range   = 'Sheet1';

	}

	private function updateGoogleSheet( $sessionsData ) {
		$values         = [];
		$new_data_count = count( $sessionsData );

		$file_id = $this->getGoogleDriveFileId();

		if ( ! empty( $file_id ) ) {
			$file_id   = $file_id [ count( $file_id ) - 1 ][0];
			$row_count = $this->getRowCount( $file_id );

			if ( $row_count > 50 || $row_count + $new_data_count > 50 ) {
				$this->makeRoomForNewRows($new_data_count, $file_id);
//				$this->messageMe( 'Creating new spreadsheet' );
//				$this->createSpreadSheet();
//				$this->messageMe( 'New spreadsheet created' );
			} else {
				if ( $this->google_spreadsheet_id != $file_id ) {
					$this->messageMe( "Google Drive File selected with id: " . $file_id );
				}
				$this->google_spreadsheet_id = $file_id;
			}
		} else {
			$this->messageMe( 'Creating new spreadsheet' );
			$this->createSpreadSheet();
			$this->messageMe( 'New spreadsheet created' );
		}

		foreach ( $sessionsData as $sessionData ) {

			$data     = [];
			$data[]   = $sessionData['session_id'];
			$data[]   = $sessionData['activity'];
			$data[]   = $this->locations[ $sessionData['location_id'] ] . ' (' . $sessionData['location_id'] . ')';
			$data[]   = $sessionData['date'];
			$data[]   = $sessionData['date'];
			$values[] = $data;
		}

		$this->appendValuesToSpreadsheet( $values );
	}

	private function makeRoomForNewRows($numberOfRowsToMove, $spreadsheetId){

		$range = "Sheet1!2:".$numberOfRowsToMove+2;
		$rowsToArchive = $this->google_spreadsheet_service->spreadsheets_values->get($spreadsheetId, $range);

		$this->archiveRows($rowsToArchive);
		//testing vs code
		$range = [
			'range' => [
				'sheetId' => 0,
				'dimension' => "ROWS",
				'startIndex' => 1,
				'endIndex' => 50+1
			]
		];

		$removeRequests = [
			// Change the spreadsheet's title.
			new Google_Service_Sheets_Request([
				'deleteDimension' => $range
			])
		];

// Add additional requests (operations) ...
		$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
			'requests' => $removeRequests
		]);
		$this->google_spreadsheet_service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
		var_dump('aaaaaaaaaaa');
		die();
	}

	private function archiveRows($rows){
		var_dump($rows);
		die();
	}

	private function getRowCount( $spreadsheet_id ) {
		$row_count = count( $this->google_spreadsheet_service->spreadsheets_values->get( $spreadsheet_id, 'Sheet1' ) );

		return $row_count;
	}

	private function createSpreadSheet() {

		$this->google_drive_file_count = $this->google_drive_file_count + 1;
		$file_number                   = $this->google_drive_file_count;
		$folderId                      = $this->google_drive_folder_id;
		$requestBody                   = new Google_Service_Sheets_Spreadsheet();
		$properties                    = new Google_Service_Sheets_SpreadsheetProperties();
		$properties->setTitle( 'sumaData_' . $file_number );
		$requestBody->setProperties( $properties );
		$response = $this->google_spreadsheet_service->spreadsheets->create( $requestBody );
		$file_id  = $response->getSpreadsheetId();

		$emptyFileMetadata = new Google_Service_Drive_DriveFile();
		$file              = $this->google_drive_service->files->get( $file_id, array( 'fields' => 'parents' ) );
		$previousParents   = join( ',', $file->parents );
		$this->google_drive_service->files->update( $file_id, $emptyFileMetadata, array(
			'addParents'    => $folderId,
			'removeParents' => $previousParents,
			'fields'        => 'id, parents'
		) );

		$this->messageMe( "New Google Drive File created with id: " . $file_id );
		$this->google_spreadsheet_id = $file_id;

		$values   = [];
		$data     = [];
		$data[]   = "Session ID";
		$data[]   = "# Activity";
		$data[]   = "Location";
		$data[]   = "Date";
		$data[]   = "Combine";
		$values[] = $data;

		$this->appendValuesToSpreadsheet( $values );
	}

	private function appendValuesToSpreadsheet( $values ) {

		$this->messageMe( "Sending " . count( $values ) . " elements to Google Drive file" );
		$body   = new Google_Service_Sheets_ValueRange( [
			'values' => $values
		] );
		$params = [ "valueInputOption" => "USER_ENTERED" ];

		try {
			$this->google_spreadsheet_service->spreadsheets_values->append( $this->google_spreadsheet_id, $this->google_spreadsheet_range,
				$body, $params );
		} catch ( Exception $e ) {
			//Todo handle error
			var_dump( $e->getMessage() );
			die();
		}
	}

	private function getGoogleDriveFileId() {
		$file_ids  = [];
		$pageToken = null;
		do {
			$response = $this->google_drive_service->files->listFiles( array(
				'q'         => "'" . $this->google_drive_folder_id . "' in parents and mimeType='application/vnd.google-apps.spreadsheet'",
				'spaces'    => 'drive',
				'pageToken' => $pageToken,
				'fields'    => 'nextPageToken, files(id, name)',
			) );

			foreach ( $response->files as $file ) {
				$this->google_drive_file_count = $this->google_drive_file_count + 1;

				$file_ids[] = [ $file->id, $file->name ];
			}
			$pageToken = $response->pageToken;
		} while ( $pageToken != null );

		if ( ! empty( $file_ids ) ) {
			usort( $file_ids, function ( $a, $b ) {
				return strnatcmp( $a[1], $b[1] );
			} );

			return $file_ids;
		}

		return [];
	}

	public function getCountsFromBeginningOfTime() {
		$sessionsData = array();
		do {
			$sessionsData = $this->getSessionsData();
			$this->updateGoogleSheet( $sessionsData );
			$sessionsData = array();
			$this->movePagination();

		} while ( $this->has_more );
	}

	public function getPreviousDayCount() {
		$sessionsData = array();
		do {
			$sessionsData = $this->getSessionsData();
			$this->updateGoogleSheet( $sessionsData );
			$sessionsData = array();
			$this->movePagination();

		} while ( $this->has_more );
	}

	public function remove_all_from_google_drive() {
		$file_ids = $this->getGoogleDriveFileId();

		$errors = [];
		foreach ( $file_ids as $file_id ) {
			try {
				$this->messageMe( "Deleting Google Drive File with id: " . $file_id[0] );
				$this->google_drive_service->files->delete( $file_id[0] );
			} catch ( Exception $e ) {
				$errors[] = [ $e, $file_id[1] ];
			}
		}

		foreach ( $errors as $error ) {
			if ( $error[0] instanceof Google_Service_Exception ) {
				echo "Can't delete file " . $error[1];
				echo $error[0]->getMessage();
			}
		}

	}

	private function movePagination() {
		global $suma_api_url;
		global $initiative_id;
		$this->initial_offset = $this->initial_offset + 10000;

		if ( $this->daily ) {
			$yesterday          = date( 'Ymd', time() - 60 * 60 * 24 );
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?sdate=' . $yesterday . '&edate=' . $yesterday . '&id=' . $initiative_id . '&offset=' . $this->initial_offset ) );
		} else {
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?id=' . $initiative_id . '&offset=' . $this->initial_offset ) );
		}
		$this->has_more = $this->hasMore();
	}

	private function getSessionsData() {
		$result = array();

		if ( isset( $this->initial_json->initiative->sessions ) ) {
			$sessions = $this->initial_json->initiative->sessions;

			foreach ( $sessions as $session ) {
				$temp          = array();
				$session_id    = $session->id;
				$temp_date     = date_create_from_format( 'Y-m-d H:i:s', trim( $session->start ) );
				$session_start = date_format( $temp_date, 'm/d/Y H:i' );

				$location_count      = 0;
				$current_location_id = "";

				foreach ( $session->counts as $count ) {
					if ( empty( $current_location_id ) ) {
						$current_location_id = $count->location;
						$location_count ++;
					} elseif ( $current_location_id != $count->location ) {
						$temp['session_id']  = $session_id;
						$temp['activity']    = $location_count;
						$temp['location_id'] = $current_location_id;
						$temp['date']        = $session_start;

						$result[] = $temp;

						$current_location_id = $count->location;
						$temp                = array();
						$location_count      = 1;
					} else {
						$location_count ++;
					}
				}

				if ( $location_count != 0 ) {
					$temp['session_id']  = $session_id;
					$temp['activity']    = $location_count;
					$temp['location_id'] = $current_location_id;
					$temp['date']        = $session_start;
					$result[]            = $temp;
				}

			}
		}

		return $result;
	}

	private function messageMe( $message ) {
		$date = new DateTime();
		$date = $date->format( "y:m:d h:i:s" );
		echo $date . " " . $message . PHP_EOL;
	}

	private function hasMore() {
		if ( isset( $this->initial_json->status->{'has more'} ) ) {
			return $this->initial_json->status->{'has more'} === 'true';
		}
	}

	private function getLocations() {

		$result = array();

		foreach ( $this->initial_json as $location_dictionary ) {
			if ( isset( $location_dictionary->dictionary->locations ) ) {
				foreach ( $location_dictionary->dictionary->locations as $location_element ) {
					$result[ $location_element->id ] = $location_element->title;
				}
			}
		}

		return $result;
	}

	private function getInitialOffset() {
		if ( isset( $this->initial_json->status->offset ) ) {
			return $this->initial_json->status->offset;
		}
	}

	private function getJson( $url ) {

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_URL, $url );
		$result = curl_exec( $ch );
		curl_close( $ch );

		return $result;
	}

}