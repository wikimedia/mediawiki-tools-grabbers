<?php
/**
 * Grabs files from a pre-existing wiki into a new wiki.
 * Merge back into grabImages or something later.
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @date 31 December 2012
 * @note Based on code by Misza, Jack Phoenix and Edward Chernenko.
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'mediawikibot.class.php';

class GrabFiles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs files from a pre-existing wiki into a new wiki. Assumes a normal file hashing structure on each end.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'from', 'Name of file to start from', false, true );
		$this->addOption( 'enddate', 'Date after which to ignore new files (20121222142317, 2012-12-22T14:23:17T, etc)', false, true );
	}

	public function execute() {
		global $wgUploadDirectory, $endDate;

		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			$endDate = wfTimestamp( TS_MW, $endDate );
			if ( !$endDate ) {
				$this->error( "Invalid enddate format.\n", true );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required.', true );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$this->output( "Working...\n" );

		# bot class and log in if requested
		if ( $user && $password ) {
			$bot = new MediaWikiBot(
				$url,
				'json',
				$user,
				$password,
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
			if ( !$bot->login() ) {
				$this->output( "Logged in as $user...\n" );
			} else {
				$this->output( "WARNING: Failed to log in as $user.\n" );
			}
		} else {
			$bot = new MediaWikiBot(
				$url,
				'json',
				'',
				'',
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
		}

		$params = array(
			'generator' => 'allimages',
			'gailimit' => 500,
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|metadata|archivename|bitdepth|mediatype',
			'iilimit' => 500
		);

		$gaifrom = $this->getOption( 'from' );
		$more = true;
		$count = 0;

		$this->output( "Processing and downloading files...\n" );
		while ( $more ) {
			if ( $gaifrom === null ) {
				unset( $params['gaifrom'] );
			} else {
				$params['gaifrom'] = $gaifrom;
			}
			$result = $bot->query( $params );
			if ( empty( $result['query']['pages'] ) ) {
				$this->error( 'No files found...', true );
			}

			foreach ( $result['query']['pages'] as $file ) {
				$count = $count + $this->processFile( $file );
			}

			if ( isset( $result['query-continue'] ) ) {
				$gaifrom = $result['query-continue']['allimages']['gaifrom'];
			} else {
				$gaifrom = null;
			}
			$more = !( $gaifrom === null );
		}
		$this->output( "$count files downloaded.\n" );
	}

	function processFile( $entry ) {
		global $wgDBname, $wgUploadDirectory, $endDate;

		$name = $entry['title'];
		$name = preg_replace( '/^[^:]*?:/', '', $name );
		$name = str_replace( ' ', '_', $name );

		# Check if file already exists.
		$file = wfFindFile( $name );
		if ( is_object( $file ) ) {
			return 0;
		}

		$this->output( "Processing {$entry['title']}: " );
		$count = 0;

		foreach ( $entry['imageinfo'] as $fileVersion ) {
			if ( !$count && $endDate < wfTimestamp( TS_MW, $fileVersion['timestamp'] ) ) {
				return 0;
			}
			$fileurl = $fileVersion['url'];

			if ( isset( $fileVersion['archivename'] ) ) {
				# Old version
				$e = array(
					'oi_name' => $name,
					'oi_archive_name' => $fileVersion['archivename'],
					'oi_size' => $fileVersion['size'],
					'oi_width' => $fileVersion['width'],
					'oi_height' => $fileVersion['height'],
					'oi_bits' => $fileVersion['bitdepth'],
					'oi_description' => $fileVersion['comment'],
					'oi_user' => $fileVersion['userid'],
					'oi_user_text' => $fileVersion['user'],
					'oi_timestamp' => wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
					'oi_media_type' => $fileVersion['mediatype'],
					'oi_deleted' => 0,
					'oi_sha1' => $fileVersion['sha1'],
					'oi_metadata' => serialize( $fileVersion['metadata'] )
				);

				$mime = $fileVersion['mime'];
				$mimeBreak = strpos( $mime, '/' );
				$e['oi_major_mime'] = substr( $mime, 0, $mimeBreak );
				$e['oi_minor_mime'] = substr( $mime, $mimeBreak + 1 );

				$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
				$dbw->begin();
				$dbw->insert( 'oldimage', $e, __METHOD__ );
				$dbw->commit();

				$fileLocalPath = $wgUploadDirectory . '/archive/' . $urlparts[$urli - 3] . '/' . $urlparts[$urli - 2] . '/' . $name;
				$fileLocalDir = $wgUploadDirectory . '/archive/' . $urlparts[$urli - 3] . '/' . $urlparts[$urli - 2] . '/';
			} else {
				# Current version
				# Check if title is present in database because someone screwed up
				$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
				$dbr->begin();
				$result = $dbr->select(
					'image',
					'img_name',
					array( 'img_name' => $name ),
					__METHOD__
				);
				$dbr->commit();
				if ( !$dbr->fetchObject( $result ) ) {
					$e = array(
						'img_name' => $name,
						'img_size' => $fileVersion['size'],
						'img_width' => $fileVersion['width'],
						'img_height' => $fileVersion['height'],
						'img_metadata' => serialize( $fileVersion['metadata'] ),
						'img_bits' => $fileVersion['bitdepth'],
						'img_media_type' => $fileVersion['mediatype'],
						'img_description' => $fileVersion['comment'],
						'img_user' => $fileVersion['userid'],
						'img_user_text' => $fileVersion['user'],
						'img_timestamp' => wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
						'img_sha1' => $fileVersion['sha1']
					);

					$mime = $fileVersion['mime'];
					$mimeBreak = strpos( $mime, '/' );
					$e['img_major_mime'] = substr( $mime, 0, $mimeBreak );
					$e['img_minor_mime'] = substr( $mime, $mimeBreak + 1 );

					$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

					$dbw->insert( 'image', $e, __METHOD__ );
					$dbw->commit();
				}

				$urlparts = explode( '/', $fileurl );
				$urli = count( $urlparts );

				$fileLocalPath = $wgUploadDirectory . '/' . $urlparts[$urli - 3] . '/' . $urlparts[$urli - 2] . '/' . $name;
				$fileLocalDir = $wgUploadDirectory . '/' . $urlparts[$urli - 3] . '/' . $urlparts[$urli - 2] . '/';
			}

			wfSuppressWarnings();
			$fileContent = file_get_contents( $fileurl );
			wfRestoreWarnings();
			if ( !$fileContent ) {
				$this->output( "$name not found on remote server.\n" );
				continue;
			}

			# Directory structure and save
			if ( !file_exists( $fileLocalDir ) ) {
				mkdir( $fileLocalDir, 0777, true );
			}
			file_put_contents( $fileLocalPath, $fileContent );

			$count++;
		}
		if ( $count == 1 ) {
			$this->output( "1 revision\n" );
		} else {
			$this->output( "$count revisions\n" );
		}

		return $count;
	}
}

$maintClass = 'GrabFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
