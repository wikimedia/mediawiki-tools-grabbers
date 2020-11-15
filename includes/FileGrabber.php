<?php
/**
 * Base class used for file grabbers
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 5 August 2019
 * @version 1.1
 * @note Based on code by Calimonious the Estrange, Misza, Jack Phoenix and Edward Chernenko.
 */

use MediaWiki\MediaWikiServices;

require_once 'ExternalWikiGrabber.php';

abstract class FileGrabber extends ExternalWikiGrabber {

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Local file repository
	 *
	 * @var LocalRepo
	 */
	protected $localRepo;

	/**
	 * Temporal file handle
	 *
	 * @var FileHandle
	 */
	protected $mTmpHandle;

	/**
	 * The target wiki is on Wikia
	 *
	 * @var boolean
	 */
	protected $isWikia;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'wikia', 'Set this param if the target wiki is on Wikia/Fandom, which needs to handle URLs in a special way', false, false );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->fatalError( 'The URL to the target wiki\'s api.php is required.' );
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, [], $this->getOption( 'db', $wgDBname ) );

		# Get a local repo instance
		$this->localRepo = RepoGroup::singleton()->getLocalRepo();

		$this->isWikia = $this->getOption( 'wikia' );

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		# bot class and log in if requested
		if ( $user && $password ) {
			$this->bot = new MediaWikiBot(
				$url,
				'json',
				$user,
				$password,
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
			if ( !$this->bot->login() ) {
				$this->output( "Logged in as $user...\n" );
			} else {
				$this->fatalError( "Failed to log in as $user." );
			}
		} else {
			$this->bot = new MediaWikiBot(
				$url,
				'json',
				'',
				'',
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
		}
	}

	/**
	 * Uploads the file from a given file returned by the api
	 * and registers it on the image table
	 * File must not exist in the image table
	 *
	 * @param string $name File name to upload
	 * @param array $fileVersion Image info data returned from the api
	 * @return Status Status of the file operation
	 */
	function newUpload( $name, $fileVersion ) {
		$this->output( "Uploading $name..." );
		if ( !isset( $fileVersion['url'] ) ) {
			# If the file is supressed and we don't have permissions,
			# we won't get URL nor MIME.
			# Skip the file revision instead of crashing
			$this->output( "File supressed, skipping it\n" );
			$status = Status::newFatal( 'SKIPPED' ); # Not an existing message but whatever
			return $status;
		}
		$fileurl = $this->sanitiseUrl( $fileVersion['url'] );

		$comment = $fileVersion['comment'];
		if ( !$comment ) {
			$comment = '';
		}

		$file_e = [
			'name' => $name,
			'size' => $fileVersion['size'],
			'width' => $fileVersion['width'],
			'height' => $fileVersion['height'],
			'bits' => $fileVersion['bitdepth'],
			'description' => $comment,
			'user' => $fileVersion['userid'],
			'user_text' => $fileVersion['user'],
			'timestamp' =>  wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
			'media_type' => $fileVersion['mediatype'],
			'deleted' => 0,
			'sha1' => Wikimedia\base_convert( $fileVersion['sha1'], 16, 36, 31 ),
			'metadata' => serialize( $this->processMetaData( $fileVersion['metadata'] ) ),
		];

		$mime = $fileVersion['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$file_e['major_mime'] = substr( $mime, 0, $mimeBreak );
		$file_e['minor_mime'] = substr( $mime, $mimeBreak + 1 );

		$performer = User::newFromIdentity( $this->getUserIdentity( (int)$file_e['user'], $file_e['user_text'] ) );

		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $this->dbw, 'img_description', $comment );
		$actorMigration = ActorMigration::newMigration();
		$actorFields = $actorMigration->getInsertValues( $this->dbw, 'img_user', $performer );

		# Current version
		$e = [
			'img_name' => $name,
			'img_size' => $file_e['size'],
			'img_width' => $file_e['width'],
			'img_height' => $file_e['height'],
			'img_bits' => $file_e['bits'],
			#'img_description' => $file_e['description'],
			#'img_user' => $file_e['user'],
			#'img_user_text' => $file_e['user_text'],
			'img_timestamp' => $file_e['timestamp'],
			'img_media_type' => $file_e['media_type'],
			'img_sha1' => $file_e['sha1'],
			'img_metadata' => $file_e['metadata'],
			'img_major_mime' => $file_e['major_mime'],
			'img_minor_mime' => $file_e['minor_mime']
		] + $commentFields + $actorFields;
		$this->dbw->insert( 'image', $e, __METHOD__ );
		$status = $this->storeFileFromURL( $name, $fileurl, false );
		$this->output( "Done\n" );
		return $status;
	}

	/**
	 * Uploads the file from a given file returned by the api as an old
	 * version of the file and registers it on the oldimage table
	 *
	 * @param string $name File name to upload
	 * @param array $fileVersion Image info data returned from the api
	 * @return Status Status of the file operation
	 */
	function oldUpload( $name, $fileVersion ) {
		$this->output( "Uploading $name version {$fileVersion['timestamp']}..." );
		if ( !isset( $fileVersion['url'] ) ) {
			# If the file is supressed and we don't have permissions,
			# we won't get URL nor MIME.
			# Skip the file revision instead of crashing
			$this->output( "File supressed, skipping it\n" );
			$status = Status::newFatal( 'SKIPPED' ); # Not an existing message but whatever
			return $status;
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$filedeleted = 0;
		if ( isset( $fileVersion['userhidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_USER;
			if ( !isset( $fileVersion['user'] ) ) {
				$fileVersion['user'] = ''; # username removed
			}
			if ( !isset( $fileVersion['userid'] ) ) {
				$fileVersion['userid'] = 0;
			}
		}
		if ( isset( $fileVersion['commenthidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_COMMENT;
			$comment = ''; # edit summary removed
		} else {
			$comment = $fileVersion['comment'];
			if ( !$comment ) {
				$comment = '';
			}
		}
		if ( isset( $fileVersion['filehidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_FILE;
		}
		if ( isset ( $fileVersion['suppressed'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_RESTRICTED;
		}

		$fileurl = $this->sanitiseUrl( $fileVersion['url'] );

		$file_e = [
			'name' => $name,
			'size' => $fileVersion['size'],
			'width' => $fileVersion['width'],
			'height' => $fileVersion['height'],
			'bits' => $fileVersion['bitdepth'],
			'description' => $comment,
			'user' => $fileVersion['userid'],
			'user_text' => $fileVersion['user'],
			'timestamp' =>  wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
			'media_type' => $fileVersion['mediatype'],
			'deleted' => $filedeleted,
			'sha1' => Wikimedia\base_convert( $fileVersion['sha1'], 16, 36, 31 ),
			'metadata' => serialize( $this->processMetaData( $fileVersion['metadata'] ) ),
		];

		$mime = $fileVersion['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$file_e['major_mime'] = substr( $mime, 0, $mimeBreak );
		$file_e['minor_mime'] = substr( $mime, $mimeBreak + 1 );

		$performer = User::newFromIdentity( $this->getUserIdentity( (int)$file_e['user'], $file_e['user_text'] ) );

		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $this->dbw, 'oi_description', $comment );
		$actorMigration = ActorMigration::newMigration();
		$actorFields = $actorMigration->getInsertValues( $this->dbw, 'oi_user', $performer );

		# Old version
		$e = [
			'oi_name' => $name,
			'oi_archive_name' => $fileVersion['archivename'],
			'oi_size' => $file_e['size'],
			'oi_width' => $file_e['width'],
			'oi_height' => $file_e['height'],
			'oi_bits' => $file_e['bits'],
			#'oi_description' => $file_e['description'],
			#'oi_user' => $file_e['user'],
			#'oi_user_text' => $file_e['user_text'],
			'oi_timestamp' => $file_e['timestamp'],
			'oi_media_type' => $file_e['media_type'],
			'oi_deleted' => $file_e['deleted'],
			'oi_sha1' => $file_e['sha1'],
			'oi_metadata' => $file_e['metadata'],
			'oi_major_mime' => $file_e['major_mime'],
			'oi_minor_mime' => $file_e['minor_mime']
		] + $commentFields + $actorFields;
		$this->dbw->insert( 'oldimage', $e, __METHOD__ );
		$status = $this->storeFileFromURL( $name, $fileurl, $file_e['timestamp'] );
		$this->output( "Done\n" );
		return $status;
	}

	/**
	 * Stores the file from the URL to the local repository
	 *
	 * @param string $name Name of the file
	 * @param string $fileurl URL of the file to be downloaded
	 * @param int|boolean timestamp in case of old file or false otherwise
	 * @param string $sha1 sha of the file to ensure that it's not corrupt (optional)
	 * @return Status status of the operation
	 */
	function storeFileFromURL( $name, $fileurl, $timestamp, $sha1 = null ) {
		$maxRetries = 3; # Just an arbitrary value
		$status = Status::newFatal( 'UNKNOWN' );
		$tmpPath = tempnam( wfTempDir(), 'grabfile' );
		$targeturl = $fileurl;
		# Retry in case of download failure
		for ( $retries = 0; !$status->isOK() && $retries < $maxRetries; $retries++ ) {
			if ( $retries > 0 ) {
				# Maybe sha1 didn't match because an old version of the file
				# is cached on the server. Try to append a random parameter
				# to the URL to trick the server to get a fresh version
				if ( strpos( $fileurl, '?' ) !== false ) {
					$targeturl = "{$fileurl}&purge={$retries}";
				} else {
					$targeturl = "{$fileurl}?purge={$retries}";
				}
				# Also wait some time in case the server is temporarily unavailable
				sleep( 20 * $retries );
			}
			$status = $this->downloadFile( $targeturl, $tmpPath, $sha1 );
		}
		if ( $status->isOK() ) {
			$file = $this->localRepo->newFile( $name, $timestamp );
			$status = $file->publish( $tmpPath );
			if ( !$status->isOK() ) {
				$this->output( sprintf( " Error when publishing file %s to the local file repo: %s\n",
					$name, $status->getWikiText() ) );
			}
		} else {
			$this->output( sprintf( " Failed to save file %s from URL %s\n", $name, $fileurl ) );
		}
		unlink( $tmpPath );
		return $status;
	}

	/**
	 * Downloads a URL to a specified temporal file
	 *
	 * @param string $fileurl URL of the file to be downloaded
	 * @param string $targetTempFile path for the downloaded file
	 * @param string $sha1 sha of the file to ensure that it's not corrupt (optional)
	 * @return Status status of the operation
	 */
	function downloadFile( $fileurl, $targetTempFile, $sha1 = null ) {
		$this->mTmpHandle = fopen( $targetTempFile, 'wb' );
		$req = MWHttpRequest::factory( $fileurl, [ 'timeout' => 90 ], __METHOD__ );
		$req->setCallback( [ $this, 'saveTempFileChunk' ] );
		$status = $req->execute();
		fclose( $this->mTmpHandle );
		if ( $status->isOK() ) {
			if ( is_null( $sha1 ) ) {
				return $status;
			}
			# Check sha1
			$storedSha1 = Wikimedia\base_convert( sha1_file( $targetTempFile ), 16, 36, 31 );
			if ( $storedSha1 == $sha1 ) {
				return $status;
			}
			$status = Status::newFatal( 'FILECORRUPT' ); # Not an existing message but whatever
			$this->output( sprintf( " File from URL %s doesn\'t match the expected sha1. Expected: %s. Actual: %s\n",
				$fileurl, $sha1, $storedSha1 ) );
		} else {
			$this->output( sprintf( " Error when saving contents of URL %s: %s\n",
				$fileurl, $status->getWikiText() ) );
		}
		return $status;
	}

	/**
	 * Callback: save a chunk of the result of a HTTP request to the temporary file
	 * Copied from UploadFromUrl
	 *
	 * @param mixed $req
	 * @param string $buffer
	 * @return int Number of bytes handled
	 */
	function saveTempFileChunk( $req, $buffer ) {
		$nbytes = fwrite( $this->mTmpHandle, $buffer );

		if ( $nbytes != strlen( $buffer ) ) {
			// Well... that's not good!
			$this->output( sprintf( " Short write %s/%s bytes, aborting.\n",
				$nbytes, strlen( $buffer ) ), 1 );
			fclose( $this->mTmpHandle );
			$this->mTmpHandle = false;
		}

		return $nbytes;
	}

	/**
	 * Formats metadata to the original format stored by MediaWiki
	 * The api returns an array of objects {name: paramName, value: paramValue}
	 * but we want to store {paramName: paramValue}
	 *
	 * @param $metadata Array as retrieved from the api
	 * @returns array
	 */
	function processMetaData( $metadata ) {
		$result = [];
		if ( !is_array( $metadata ) ) {
			return $result;
		}
		foreach ( $metadata as $namevalue ) {
			$name = $namevalue['name'];
			$value = $namevalue['value'];
			if ( is_array( $value ) ) {
				$result[$name] = $this->processMetaData( $value );
			} else {
				$result[$name] = $value;
			}
		}
		return $result;
	}

	/**
	 * Check for Wikia's videos
	 *
	 * @param $fileVersion Array as retrieved from the imageinfo api
	 * @returns bool true if it's an external video
	 */
	function isWikiaVideo( $fileVersion ) {
		# This is somewhat dumb as it only checks for YouTube videos, but
		# without the MIME check it catches .ogv etc.
		# A better check would be to check the mediatype and for the lack
		# of a known file extension in the title, but I don't wanna mess
		# with regex right now. --ashley, 17 April 2016
		if ( $this->isWikia &&
			$fileVersion['mime'] == 'video/youtube' &&
			strtoupper( $fileVersion['mediatype'] ) == 'VIDEO'
		) {
			return true;
		}
		return false;
	}

	/**
	 * Sanitise file URL. Just checking for wikia's madness for now
	 *
	 * @param $fileurl string URL of the file
	 * @returns string sanitised URL
	 */
	function sanitiseUrl( $fileurl ) {
		if ( $this->isWikia ) {
			# Wikia is now serving "optimised" lossy images instead of the originals
			# See http://community.wikia.com/wiki/Thread:1200407
			# Add format=original to the URL to hopefully force it to download the original
			if ( strpos( $fileurl, '?' ) !== false ) {
				$fileurl .= '&format=original';
			} else {
				$fileurl .= '?format=original';
			}
		}
		return $fileurl;
	}
}
