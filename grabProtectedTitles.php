<?php
/**
 * Maintenance script to grab the protected titles from a wiki (to which we have
 * only read-only access instead of full database access).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @note Based on code by:
 * - Legoktm & Uncyclopedia development team, 2013 (protected_titles_table.py)
 */

require_once 'includes/ExternalWikiGrabber.php';

class GrabProtectedTitles extends ExternalWikiGrabber {

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs protected titles from a pre-existing wiki into a new wiki.';
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc).', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17Z, etc); defaults to current timestamp.', false, true );
	}

	public function execute() {
		parent::execute();

		$startDate = $this->getOption( 'startdate' );
		if ( $startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $startDate ) ) {
				$this->fatalError( 'Invalid startdate format.' );
			}
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		$params = [
			'list' => 'protectedtitles',
			'ptdir' => 'newer',
			'ptend' => $endDate,
			'ptlimit' => 'max',
			'ptprop' => 'userid|timestamp|expiry|comment|level',
		];

		if ( $startDate !== null ) {
			$params['ptstart'] = $startDate;
		}

		$more = true;
		$i = 0;

		$this->output( "Grabbing protected titles...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['protectedtitles'] ) ) {
				$this->fatalError( 'No protected titles, hence nothing to do. Aborting the mission.' );
			}

			foreach ( $result['query']['protectedtitles'] as $logEntry ) {
				$this->processEntry( $logEntry );
				$i++;
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['protectedtitles'] ) ) {
				$params = array_merge( $params, $result['query-continue']['protectedtitles'] );
				$this->output( "{$i} entries processed.\n" );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
				$this->output( "{$i} entries processed.\n" );
			} else {
				$more = false;
			}

		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processEntry( $entry ) {
		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$commentFields = $this->commentStore->insert( $this->dbw, 'pt_reason', $entry['comment'] );

		$title = $this->sanitiseTitle( $entry['ns'], $entry['title'] );

		$data = [
			'pt_namespace' => $entry['ns'],
			'pt_title' => $title,
			'pt_user' => $entry['userid'] ?? 0,
			#'pt_reason' => $entry['comment'],
			#'pt_reason_id' => 0,
			'pt_timestamp' => $ts,
			'pt_expiry' => ( $entry['expiry'] == 'infinity' ? $this->dbw->getInfinity() : wfTimestamp( TS_MW, $entry['expiry'] ) ),
			'pt_create_perm' => $entry['level']
		] + $commentFields;

		$this->dbw->insert( 'protected_titles', $data, __METHOD__, [ 'IGNORE' ] );
	}
}

$maintClass = 'GrabProtectedTitles';
require_once RUN_MAINTENANCE_IF_MAIN;
