<?php
/**
 * Maintenance script to grab images from a wiki and import them to another
 * wiki.
 * Translated from Misza's python version.
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.0
 * @date 6 December 2023
 */

require_once 'includes/FileGrabber.php';

class GrabImages extends FileGrabber {

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Get images from an external wiki and save them to our disk. ' .
			'This script does not import them to the wiki. If you want files to be imported, use grabFiles instead.';
		$this->addOption( 'folder', 'Folder to save images to', true /* required? */, true /* withArg */ );
		$this->addOption( 'from', 'Name of file to start from', false /* required? */, true /* withArg */ );
	}

	/**
	 * The function to grab images from a specified URL
	 */
	public function execute() {
		parent::execute();

		$folder = $this->getOption( 'folder' );
		if ( !file_exists( $folder ) ) {
			$this->fatalError( "Output folder doesn't exist: {$folder}" );
			return false;
		}

		$this->output( "The directory where images will be stored in is: {$folder}\n" );

		$params = [
			'generator' => 'allimages',
			'gailimit' => 'max',
			'prop' => 'imageinfo',
			'iiprop' => 'url|sha1|mime',
			'iilimit' => '1'
		];

		$gaifrom = $this->getOption( 'from' );
		if ( $gaifrom !== null ) {
			$params['gaifrom'] = $gaifrom;
		}

		$more = true;
		$count = 0;

		while ( $more ) {
			$result = $this->bot->query( $params );
			if ( empty( $result['query']['pages'] ) ) {
				$this->fatalError( 'No files found...' );
			}

			foreach ( $result['query']['pages'] as $file ) {
				$count = $count + $this->processFile( $file, $folder );
			}

			if ( isset( $result['query-continue'] ) ) {
				$gaifrom = $result['query-continue']['allimages']['gaifrom'];
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
		}
		$this->output( "$count files downloaded.\n" );
	}

	/**
	 * Downloads the image returned by the api
	 *
	 * @param array $entry Page data returned from the api with imageinfo
	 * @param string $folder Folder to save the file to
	 * @return int 1 if image has been downloaded, 0 otherwise
	 */
	function processFile( $entry, $folder ) {
		$name = $this->sanitiseTitle( $entry['ns'], $entry['title'] );
		$count = 0;

		// We're getting only one file revision (the latest one)
		foreach ( $entry['imageinfo'] as $fileVersion ) {
			# Check for Wikia's videos
			if ( $this->isWikiaVideo( $fileVersion ) ) {
				$this->output( "...File {$name} appears to be a video, skipping it.\n" );
				return 0;
			}

			if ( !isset( $fileVersion['url'] ) ) {
				# If the file is supressed and we don't have permissions,
				# we won't get URL nor MIME.
				# Skip the file revision instead of crashing
				$this->output( "...File {$name} supressed, skipping it\n" );
				return 0;
			}

			$url = $this->sanitiseUrl( $fileVersion['url'] );
			$sha1 = Wikimedia\base_convert( $fileVersion['sha1'], 16, 36, 31 );
			$path = "$folder/$name";

			if ( file_exists( $path ) ) {
				$storedSha1 = Wikimedia\base_convert( sha1_file( $path ), 16, 36, 31 );
				if ( $storedSha1 == $sha1 ) {
					$this->output( "File {$name} already exists. SKIPPED\n" );
					return $count;
				}
			}

			$status = $this->downloadFile( $url, $path, $name, $sha1 );

			if ( $status->isOK() ) {
				$count++;
			}
		}
		if ( $count == 1 ) {
			$this->output( "Store {$url} as {$path} OK\n" );
		} else {
			$this->output( "Store {$url} as {$path} FAILED\n" );
		}

		return $count;
	}
}

$maintClass = 'GrabImages';
require_once RUN_MAINTENANCE_IF_MAIN;
