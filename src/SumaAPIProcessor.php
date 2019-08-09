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
	private $google_drive_folder_archive_id;

	public function __construct( $daily = false ) {
		global $suma_api_url;
		global $initiative_id;

		if ( $daily ) {
			$this->daily        = $daily;
			$yesterday          = date( 'Ymd', time() - 60 * 60 * 24 );
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?sdate=' . $yesterday . '&edate=' . $yesterday . '&id=' . $initiative_id ) );
		} else {
			$this->initial_json = json_decode( $this->getJson( $suma_api_url . '/sessions?id=' . $initiative_id ) );
		}
		global $google_drive_folder_id;
		global $google_drive_folder_archive_id;

		$this->google_drive_folder_id         = $google_drive_folder_id;
		$this->google_drive_folder_archive_id = $google_drive_folder_archive_id;
		$this->locations                      = $this->getLocations();
		$this->initial_offset                 = $this->getInitialOffset();
		$this->has_more                       = $this->hasMore();

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

	private function updateGoogleSheet( $data, $archive = false ) {
		$new_data_count = count( $data );

		if ( ! $archive ) {
			$file_id = $this->getGoogleDriveFileId( $this->google_drive_folder_id );
		} else {
			$file_id = $this->getGoogleDriveFileId( $this->google_drive_folder_archive_id );
		}

		if ( ! empty( $file_id ) ) {
			$file_id              = $file_id [ count( $file_id ) - 1 ][0];
			$row_count            = $this->getRowCount( $file_id );
			$newSpreadSheetNeeded = ( $row_count > 145000 || $row_count + $new_data_count > 145000 ) ? true : false;

			if ( ! $archive ) {
				if ($newSpreadSheetNeeded){
					$this->messageMe( "Making room in file with id: " . $file_id );
					$this->makeRoomForNewRows( $new_data_count, $file_id );
					$this->messageMe( "Finished making room in file with id: " . $file_id );
				}
			} elseif ( $newSpreadSheetNeeded ) {
				$this->messageMe( 'Creating new spreadsheet in archive' );
				$this->createSpreadSheet( $archive );
				$this->messageMe( 'New spreadsheet created in archive' );
			}

			if ( $this->google_spreadsheet_id != $file_id ) {
				if ($archive){
					$this->messageMe( "Google Drive File for archive selected with id: " . $file_id );
				}else{
					$this->messageMe( "Google Drive File selected with id: " . $file_id );
				}
			}
			$this->google_spreadsheet_id = $file_id;
		} else {
			$this->messageMe( 'Creating new spreadsheet' );
			$this->createSpreadSheet( $archive );
			$this->messageMe( 'New spreadsheet created' );
		}

		$values = ! $archive ? $this->prepareValuesForSpreadsheet( $data ) : $this->prepareValuesForArchiving( $data );

		$this->appendValuesToSpreadsheet( $values, $this->google_spreadsheet_id );
	}

	private function prepareValuesForArchiving( $rows ) {
		$values = [];

		foreach ( $rows as $row ) {
			$data = [];
			foreach ($row as $key=>$value){
				if ($key == "location_id" || $key == 2){
					$data[] = $this->locations[ $value ] . ' (' . $value . ')';
				}else{
					$data[] = $value;
				}
			}
			$values[] = $data;
		}

		return $values;
	}

	private function prepareValuesForSpreadsheet( $sessionsData ) {
		$values = [];

		foreach ( $sessionsData as $sessionData ) {

			$data     = [];
			$data[]   = $sessionData['session_id'];
			$data[]   = $sessionData['activity'];
			$data[]   = $this->locations[ $sessionData['location_id'] ] . ' (' . $sessionData['location_id'] . ')';
			$data[]   = $sessionData['date'];
			$data[]   = $sessionData['date'];
			$values[] = $data;
		}

		return $values;
	}

	private function makeRoomForNewRows( $numberOfRowsToMove, $spreadsheetId ) {

		$this->messageMe( "Getting: " . $numberOfRowsToMove . " rows from file: " . $spreadsheetId );

		$readRange = $numberOfRowsToMove + 2;
		$range     = "Sheet1!2:" . $readRange;

		$rowsToArchive = $this->google_spreadsheet_service->spreadsheets_values->get( $spreadsheetId, $range );
		$rowsToArchive = $rowsToArchive->getValues();

		$this->messageMe( "Moving: " . $numberOfRowsToMove . " rows from file: " . $spreadsheetId );
		$this->updateGoogleSheet( $rowsToArchive, true );
		$this->messageMe( "Done moving: " . $numberOfRowsToMove . " rows from file: " . $spreadsheetId );

		$range = [
			'range' => [
				'sheetId'    => 0,
				'dimension'  => "ROWS",
				'startIndex' => 1,
				'endIndex'   => $numberOfRowsToMove + 1
			]
		];

		$removeRequests = [
			new Google_Service_Sheets_Request( [
				'deleteDimension' => $range
			] )
		];

		$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest( [
			'requests' => $removeRequests
		] );
		$this->google_spreadsheet_service->spreadsheets->batchUpdate( $spreadsheetId, $batchUpdateRequest );
	}

	private function getRowCount( $spreadsheet_id ) {
		$row_count = count( $this->google_spreadsheet_service->spreadsheets_values->get( $spreadsheet_id, 'Sheet1' ) );

		return $row_count;
	}

	private function createSpreadSheet( $archive = false ) {

		$folderId    = ! $archive ? $this->google_drive_folder_id : $this->google_drive_folder_archive_id;
		$requestBody = new Google_Service_Sheets_Spreadsheet();
		$properties  = new Google_Service_Sheets_SpreadsheetProperties();
		$title       = ! $archive ? "sumaData" : 'sumaDataArchive_' . time();
		$properties->setTitle( $title );
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

		$this->appendValuesToSpreadsheet( $values, $this->google_spreadsheet_id );
	}

	private function appendValuesToSpreadsheet( $values, $file_id ) {

		$this->messageMe( "Sending " . count( $values ) . " elements to Google Drive file" );
		$body   = new Google_Service_Sheets_ValueRange( [
			'values' => $values
		] );
		$params = [ "valueInputOption" => "USER_ENTERED" ];

		try {
			$this->google_spreadsheet_service->spreadsheets_values->append( $file_id, $this->google_spreadsheet_range,
				$body, $params );
		} catch ( Exception $e ) {
			//Todo handle error
			var_dump( $e->getMessage() );
			die();
		}
	}

	private function getGoogleDriveFileId( $folder_id ) {
		$file_ids  = [];
		$pageToken = null;
		do {
			$response = $this->google_drive_service->files->listFiles( array(
				'q'         => "'" . $folder_id . "' in parents and mimeType='application/vnd.google-apps.spreadsheet'",
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
		do {
			$sessionsData = $this->getSessionsData();
			$this->updateGoogleSheet( $sessionsData, true );
			$this->movePagination();
		} while ( $this->has_more );
	}

	public function getPreviousDayCount() {
		do {
			$sessionsData = $this->getSessionsData();
			$this->updateGoogleSheet( $sessionsData );
			$this->movePagination();
		} while ( $this->has_more );
	}

	public function remove_all_from_google_drive( $archive = false ) {
		$folder_id = ! $archive ? $this->google_drive_folder_id : $this->google_drive_folder_archive_id;
		$file_ids  = $this->getGoogleDriveFileId( $folder_id );
		$errors    = [];
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