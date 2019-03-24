<?php
/**
 * Grabs deleted files from a pre-existing wiki into a new wiki. Only works with mw1.17+.
 * Also only works with target wikis using the default hashing structure. (Wikia's do.)
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @date 15 March 2019
 * @note Based on code by Jack Phoenix and Edward Chernenko.
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'includes/mediawikibot.class.php';
require_once 'includes/mediawikibotHacks.php';

class GrabDeletedFiles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs deleted files from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'imagesurl', 'URL to the target wiki\'s images directory', false, true, 'i' );
		$this->addOption( 'scrape', 'Use screenscraping instead of the API?'
			. ' (note: you don\'t want to do this unless you really have to.)', false, false, 's' );
		$this->addOption( 'username', 'Username to log into the target wiki', true, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', true, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'fafrom', 'Start point from which to continue with metadata.', false, true, 'start' );
		$this->addOption( 'skipmetadata', 'If you\'ve already populated oldarchive and just need to resume file downloads,'
			. ' use this to avoid duplicating the metadata db entries', false, false, 'm' );
		$this->addOption( 'from', 'Start point from which to continue file downloads. Must match an actual file record in the database.', false, true );
	}

	public function execute() {
		global $wgUploadDirectory;

		$url = $this->getOption( 'url' );
		$imagesurl = $this->getOption( 'imagesurl' );
		$scrape = $this->hasOption( 'scrape' );
		if ( !$url ) {
			$this->fatalError( 'The URL to the target wiki\'s api.php is required.' );
		}
		if ( !$imagesurl && !$scrape ) {
			$this->fatalError( 'Unless we\'re screenscraping it, the URL to the target wiki\'s images directory is required.' );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		if ( !$user || !$password ) {
			$this->fatalError( 'An admin username and password are required.' );
		}

		$this->output( "Working...\n" );

		# bot class and log in
		$bot = new MediaWikiBotHacked(
			$url,
			'json',
			$user,
			$password,
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);
		if ( !$bot->login() ) {
			$this->output( "Logged in as $user...\n" );

			# Commented out, this doesn't work on Wikia. Simply let the api
			# error out on first deletedrevs access
			# Does the user have deletion rights?
			#$params = [
			#	'list' => 'allusers',
			#	'aulimit' => '1',
			#	'auprop' => 'rights',
			#	'aufrom' => $user
			#];
			#$result = $bot->query( $params );
			#if ( !in_array( 'deletedtext', $result['query']['allusers'][0]['rights'] ) ) {
			#	$this->fatalError( "$user does not have required rights to fetch deleted content." );
			#}
		} else {
			$this->fatalError( "Failed to log in as $user." );
		}

		$skipMetaData = $this->hasOption( 'skipmetadata' );

		if ( !$skipMetaData ) {
			$params = [
				'list' => 'filearchive',
				'falimit' => 'max',
				'faprop' => 'sha1|timestamp|user|size|dimensions|description|mime|metadata|bitdepth'
			];

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
					$this->fatalError( 'No files found...' );
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
		}

		$this->output( "Downloading files... missing ones may have been deleted, or may be a sign of script failure. You may want to check via Special:Undelete.\n" );
		$count = 0;
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			'filearchive',
			[ 'fa_storage_key', 'fa_name' ],
			[],
			__METHOD__
		);

		$from = $this->getOption( 'from' );

		if ( $scrape ) {
			# Get the URL for this
			$queryGeneral = $bot->query( [ 'meta' => 'siteinfo' ] )['query']['general'];
			$articlePath = $queryGeneral['articlepath'];
			$serverURL = $queryGeneral['server'];
		}

		foreach ( $result as $row ) {
			$fileName = $row->fa_name;

			# This is really stupid, but, er, whatever.
			if ( $from !== null && $fileName !== $from ) {
				continue;
			} else {
				$from = null;
			}

			$file = $row->fa_storage_key;
			$fileLocalPath = $wgUploadDirectory . '/deleted/' . $file[0] . '/' . $file[1] . '/' . $file[2];

			$this->output( "Processing $fileName" );

			if ( !$scrape ) {
				# $imagesurl should be something like http://images.wikia.com/uncyclopedia/images
				# Example image: http://images.wikia.com/uncyclopedia/images/deleted/a/b/c/abcblahhash.png
				$fileurl = $imagesurl . '/deleted/' . $file[0] . '/' . $file[1] . '/' . $file[2] . '/' . $file;
				Wikimedia\suppressWarnings();
				$fileContent = file_get_contents( $fileurl );
				Wikimedia\restoreWarnings();

				if ( !$fileContent ) {
					$this->output( ": not found on remote server.\n" );
					continue;
				}
			} else {
				# Oh shit we gotta screenscrape Special:Undelete
				$undeletePage = $serverURL . substr( $articlePath, 0, -2 ) . 'Special:Undelete/File:' . urlencode( $fileName );

				# $this->output( "\nRequesting undelete page: $undeletePage" );
				$specialDeletePage = $bot->curl_get(
					# Yes, we'll just assume this works because we're dumbarses
					$undeletePage
				);

				# WE'RE DUMBARSES, OKAY?
				if ( $specialDeletePage[0] ) {
					$numMatches = preg_match_all(
						'/\<li\>\<input name="fileid\d+" type="checkbox" value="1" ?\/?> .* <a href="(.*target=.*file=.*token=[a-zA-Z0-9%]*)" title=".*<\/li>/',
						$specialDeletePage[1], $matches, PREG_SET_ORDER
					);

					if ( !$numMatches ) {
						$this->output( "\nScraping: No target revisions for $fileName found.\n" );
						continue;
					} else {
						$fileContent = [ false, "$file: revision not found" ];

						foreach ( $matches as $result ) {
							$url = $result[1];

							# The only thing we actually need to change in $url is to convert '&amp;'
							# into actual ampersands. So let's do that.
							$url = str_replace( '&amp;', '&', $url );

							# Because the overall logic of this script doesn't actually expect this
							# approach, we're actually just looking for the specific one...
							if ( strpos( $url, urlencode( $file ) ) === false ) {
								continue;
							}

							# Sometimes they randomly have the fullurl ?!
							if ( substr( $url, 0, 1 ) == '/' ) {
								$downloadTarget = $serverURL . $url;
							} else {
								$downloadTarget = $url;
							}

							# $this->output( "\nDownloading file content: $downloadTarget" );

							$fileContent = $bot->curl_get( $downloadTarget );

							# if ( !$fileContent[0] ) {
							# 	$this->output( "$fileContent[1] for $downloadTarget\n" );
							# }

							break;
						}
					}

					if ( !$fileContent[0] ) {
						$this->output( "\n$fileContent[1]\n" );
						$fileContent = false;
						continue;
					} else {
						# Errors handled; set to just actual content now
						$fileContent = $fileContent[1];
						# For debugging: quick visual check if it's even actually a file
						$this->output( " (first four characters: " . substr( $fileContent, 0, 4 ) . ")" );
					}

				} else {
					$this->output( "$specialDeletePage[1]\n" );
					continue;
				}
			}

			# Directory structure and save
			if ( !file_exists( $fileLocalPath ) ) {
				mkdir( $fileLocalPath, 0777, true );
				touch( $fileLocalPath . "/index.html" );
			}
			file_put_contents( $fileLocalPath . '/' . $file, $fileContent );
			$this->output( ": successfully saved as $file\n" );
			if ( ( $count % 500 ) == 0 && $count !== 0 ) {
				$this->output( "$count\n" );
			}
			$count++;
		}
	}

	function processFile( $entry ) {
		global $wgDBname;

		$e = [
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
		];

		$mime = $entry['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$e['fa_major_mime'] = substr( $entry['mime'], 0, $mimeBreak );
		$e['fa_minor_mime'] = substr( $entry['mime'], $mimeBreak + 1 );

		$e['fa_metadata'] = serialize( $entry['metadata'] );

		$ext = strtolower( pathinfo( $entry['name'], PATHINFO_EXTENSION ) );
		if ( $ext == 'jpeg' ) {
			# Because that doesn't actually resolve extension aliases, and we need these keys to match the actual files
			# TODO: are there others we should be checking?
			$ext = 'jpg';
		}
		$e['fa_storage_key'] = ltrim( Wikimedia\base_convert( $entry['sha1'], 16, 36, 40 ), '0' ) .
			'.' . $ext;

		# We could get these other fields from logging, but they appear to have no purpose so SCREW IT.
		$e['fa_deleted_user'] = 0;
		$e['fa_deleted_timestamp'] = null;
		$e['fa_deleted_reason'] = null;
		$e['fa_archive_name'] = null; # UN:N; MediaWiki figures it out anyway.

		$dbw = wfGetDB( DB_MASTER, [], $this->getOption( 'db', $wgDBname ) );

		$dbw->insert( 'filearchive', $e, __METHOD__ );

		# $this->output( "Changes committed to the database!\n" );
	}
}

$maintClass = 'GrabDeletedFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
