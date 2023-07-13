<?php
/**
 * Grabs files from a pre-existing wiki into a new wiki.
 * Merge back into grabImages or something later.
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 31 December 2012
 * @version 1.0
 * @note Based on code by Misza, Jack Phoenix and Edward Chernenko.
 */

require_once 'includes/FileGrabber.php';

class GrabFiles extends FileGrabber {

	/**
	 * End date
	 *
	 * @var MWTimestamp
	 */
	protected $endDate;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs files from a pre-existing wiki into a new wiki, using file upload configuration of the local wiki.';
		$this->addOption( 'from', 'Name of file to start from', false, true );
		$this->addOption( 'enddate', 'Date after which to ignore new files (20121222142317, 2012-12-22T14:23:17Z, etc)', false, true );
	}

	public function execute() {
		parent::execute();

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			$this->endDate = wfTimestamp( TS_MW, $this->endDate );
			if ( !$this->endDate ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$params = [
			'generator' => 'allimages',
			'gailimit' => 'max',
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|metadata|archivename|bitdepth|mediatype',
			'iilimit' => 'max'
		];

		$gaifrom = $this->getOption( 'from' );
		$more = true;
		$count = 0;

		if ( $gaifrom !== null ) {
			$params['gaifrom'] = $gaifrom;
		}

		$this->output( "Processing and downloading files...\n" );
		while ( $more ) {
			$result = $this->bot->query( $params );
			if ( empty( $result['query']['pages'] ) ) {
				$this->fatalError( 'No files found...' );
			}

			foreach ( $result['query']['pages'] as $file ) {
				$count = $count + $this->processFile( $file );
			}

			// rate limit
			LOW=22;
			HIGH=200;
			INTERVAL=$[ $[ $RANDOM % $[ $HIGH-$LOW+1] ] + $LOW ];
			sleep($INTERVAL);
			
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
	 * Process the information from a given file returned by the api
	 *
	 * @param array $entry Page data returned from the api with imageinfo
	 * @return int Number of image revisions processed.
	 */
	function processFile( $entry ) {
		$name = $this->sanitiseTitle( $entry['ns'], $entry['title'] );

		# Check if file already exists.
		# NOTE: wfFindFile() checks foreign repos too. Use local repo only
		# newFile skips supression checks
		$file = $this->localRepo->newFile( $name );
		if ( $file->exists() ) {
			return 0;
		}

		$this->output( "Processing {$name}: " );
		$count = 0;

		foreach ( $entry['imageinfo'] as $fileVersion ) {
			# Api returns file revisions from new to old.
			# WARNING: If a new version of a file is uploaded after the start of the script
			# (or endDate), the file and all its previous revisions would be skipped,
			# potentially leaving pages that were using the old image with redlinks.
			# To prevent this, we'll skip only more recent versions, and mark the first
			# one before the end date as the latest
			if ( !$count && wfTimestamp( TS_MW, $fileVersion['timestamp'] ) > $this->endDate ) {
				#return 0;
				continue;
			}

			# Check for Wikia's videos
			if ( $this->isWikiaVideo( $fileVersion ) ) {
				$this->output( "...this appears to be a video, skipping it.\n" );
				return 0;
			}

			if ( $count > 0 ) {
				$status = $this->oldUpload( $name, $fileVersion );
			} else {
				$status = $this->newUpload( $name, $fileVersion );
			}

			if ( $status->isOK() ) {
				$count++;
			}
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
