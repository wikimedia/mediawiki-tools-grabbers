<?php
/**
 * Grabs deleted files from a pre-existing wiki into a new wiki. Only works with mw1.17+.
 * Also only works with target wikis using the default hashing structure. (Wikia's do.)
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @date 31 December 2012
 * @note Based on code by Jack Phoenix and Edward Chernenko.
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabDeletedFiles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs deleted files from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'imagesurl', 'URL to the target wiki\'s images directory', true, true, 'i' );
		$this->addOption( 'username', 'Username to log into the target wiki', true, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', true, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'fafrom', 'Start point from which to continue with metadata.', false, true, 'start' );
	}

	public function execute() {
		global $wgUploadDirectory;

		$url = $this->getOption( 'url' );
		$imagesurl = $this->getOption( 'imagesurl' );
		if ( !$url || !$imagesurl ) {
			$this->error( 'The URLs to the target wiki\'s api.php and images directory are required.', true );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		if ( !$user || !$password ) {
			$this->error( 'An admin username and password are required.', true );
		}

		$this->output( "Working...\n" );

		# bot class and log in
		$bot = new MediaWikiBot(
			$url,
			'json',
			$user,
			$password,
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);
		if ( !$bot->login() ) {
			$this->output( "Logged in as $user...\n" );

			# Does the user have deletion rights?
			$params = array(
				'list' => 'allusers',
				'aulimit' => '1',
				'auprop' => 'rights',
				'aufrom' => $user
			);
			$result = $bot->query( $params );
			if ( !in_array( 'deletedtext', $result['query']['allusers'][0]['rights'] ) ) {
				$this->error( "$user does not have required rights to fetch deleted content.", true );
			}
		} else {
			$this->error( "Failed to log in as $user.", true );
		}

		$params = array(
			'list' => 'filearchive',
			'falimit' => 'max',
			'faprop' => 'sha1|timestamp|user|size|dimensions|description|mime|metadata|bitdepth'
		);

		$fafrom = $this->getOption( 'fafrom' );
		$more = true;
		$count = 0;

		$this->output( "Processing file metadata...\n" );
		while ( $more ) {
			if ( $fafrom === null ) {
				unset( $params['fafrom'] );
			} else {
				$params['fafrom'] = $fafrom;
			}
			$result = $bot->query( $params );
			if ( empty( $result['query']['filearchive'] ) ) {
				$this->error( 'No files found...', true );
			}

			foreach ( $result['query']['filearchive'] as $fileVersion ) {
				if ( ( $count % 500 ) == 0 ) {
					$this->output( "$count\n" );
				}
				$this->processFile( $fileVersion );
				$count++;
			}

			if ( isset( $result['query-continue'] ) ) {
				$fafrom = $result['query-continue']['filearchive']['fafrom'];
			} else {
				$fafrom = null;
			}
			$more = !( $fafrom === null );
		}
		$this->output( "$count files found.\n" );

		$this->output( "\n" );

		$this->output( "Downloading files... missing ones may have been deleted, or may be a sign of script failure. You may want to check via Special:Undelete.\n" );
		$count = 0;
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			'filearchive',
			array( 'fa_storage_key', 'fa_name' ),
			array(),
			__METHOD__
		);

		foreach ( $result as $row ) {
			$fileName = $row->fa_name;
			$file = $row->fa_storage_key;
			$fileLocalPath = $wgUploadDirectory . '/deleted/' . $file[0] . '/' . $file[1] . '/' . $file[2];

			# $imagesurl should be something like http://images.wikia.com/uncyclopedia/images
			# Example image: http://images.wikia.com/uncyclopedia/images/deleted/a/b/c/abcblahhash.png
			$fileurl = $imagesurl . '/deleted/' . $file[0] . '/' . $file[1] . '/' . $file[2] . '/' . $file;
			wfSuppressWarnings();
			$fileContent = file_get_contents( $fileurl );
			wfRestoreWarnings();
			if ( !$fileContent ) {
				$this->output( "$fileName not found on remote server.\n" );
				continue;
			}

			# Directory structure and save
			if ( !file_exists( $fileLocalPath ) ) {
				mkdir( $fileLocalPath, 0777, true );
			}
			file_put_contents( $fileLocalPath . '/' . $file, $fileContent );
			if ( ( $count % 500 ) == 0 ) {
				$this->output( "$count\n" );
			}
			$count++;
		}
	}

	function processFile( $entry ) {
		global $wgDBname;

		$e = array(
			'fa_name' => $entry['name'],
			'fa_size' => $entry['size'],
			'fa_width' => $entry['width'],
			'fa_height' => $entry['height'],
			'fa_bits' => $entry['bitdepth'],
			'fa_description' => $entry['description'],
			'fa_user' => $entry['userid'],
			'fa_user_text' => $entry['user'],
			'fa_timestamp' => wfTimestamp( TS_MW, $entry['timestamp'] ),
			'fa_storage_group' => 'deleted',
			'fa_media_type' => null,
			'fa_deleted' => 0
		);

		$mime = $entry['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$e['fa_major_mime'] = substr( $entry['mime'], 0, $mimeBreak );
		$e['fa_minor_mime'] = substr( $entry['mime'], $mimeBreak + 1 );

		$e['fa_metadata'] = serialize( $entry['metadata'] );
		$e['fa_storage_key'] = $this->str_sha1_36( $entry['sha1'] ) . '.' . pathinfo( $entry['name'], PATHINFO_EXTENSION );

		# We could get these other fields from logging, but they appear to have no purpose so SCREW IT.
		$e['fa_deleted_user'] = 0;
		$e['fa_deleted_timestamp'] = null;
		$e['fa_deleted_reason'] = null;
		$e['fa_archive_name'] = null; # UN:N; MediaWiki figures it out anyway.

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

		$dbw->insert( 'filearchive', $e, __METHOD__ );
		$dbw->commit();

		# $this->output( "Changes committed to the database!\n" );
	}

	# Base conversion function to make up for PHP's overwhelming crappiness
	# (base_convert doesn't work with large numbers)
	# Borrowed from some guy's comments on php.net...
	function str_sha1_36( $str ) {
		$str = trim( $str );

		$len = strlen( $str );
		$q = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$r = base_convert( $str[$i], 16, 10 );
			$q = bcadd( bcmul( $q, 16 ), $r );
		}
		$s = '';
			while ( bccomp( $q, '0', 0 ) > 0 ) {
			$r = intval( bcmod( $q, 36 ) );
			$s = base_convert( $r, 10, 36 ) . $s;
			$q = bcdiv( $q, 36, 0 );
		}
		return $s;
	}
}

$maintClass = 'GrabDeletedFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
