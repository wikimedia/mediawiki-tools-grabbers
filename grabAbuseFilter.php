<?php
/**
 * Grabs obuse filters from a pre-existing wiki into a new wiki.
 * Extension:AbuseFilter must be installed on the wiki (database tables at least)
 * Only the current version of filters will be imported as other data is not
 * available from the api (like old versions or abuse logs)
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.1
 * @date 5 August 2019
 */

require_once 'includes/ExternalWikiGrabber.php';

class GrabAbuseFilter extends ExternalWikiGrabber {

	/**
	 * API limits to use instead of max
	 *
	 * @var int
	 */
	protected $apiLimits;

	/**
	 * API limits to use instead of max
	 *
	 * @var array
	 */
	protected $validLogTypes;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grabs logs from a pre-existing wiki into a new wiki.' );
	}

	public function execute() {
		parent::execute();

		$this->output( "Working...\n" );

		$params = [
			'list' => 'abusefilters',
			'abflimit' => 'max',
			'ledir' => 'newer',
			'leprop' => 'id|description|pattern|actions|hits|comments|lasteditor|lastedittime|status|private',
		];

		$more = true;
		$i = 0;
		$abfstartid = null;

		$this->output( "Fetching abuse filters...\n" );
		do {
			if ( $abfstartid === null ) {
				unset( $params['abfstartid'] );
			} else {
				$params['abfstartid'] = $abfstartid;
			}
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['abusefilters'] ) ) {
				$this->output( "No abuse filters found...\n" );
				break;
			}

			foreach ( $result['query']['abusefilters'] as $filter ) {
				$this->processEntry( $filter );

				if ( isset( $result['query-continue'] ) ) {
					$abfstartid = $result['query-continue']['abusefilters']['abfstartid'];
				} else {
					$abfstartid = null;
				}

				$more = !( $abfstartid === null );
				$i++;
				if ( $i % 500 == 0 ) {
					$this->output( "{$i} abuse filters fetched...\n" );
				}
			}

		} while ( $more );

		$this->output( "Done. {$i} abuse filters fetched.\n" );
	}

	public function processEntry( $entry ) {
		$e = [
			'af_id' => $entry['id'],
			'af_pattern' => $entry['pattern'],
			'af_user' => 0, # Not available
			'af_user_text' => $entry['lasteditor'],
			'af_timestamp' => wfTimestamp( TS_MW, $entry['lastedittime'] ),
			'af_enabled' => isset( $entry['enabled'] ),
			'af_comments' => $entry['comments'],
			'af_public_comments' => $entry['description'],
			'af_hidden' => isset( $entry['private'] ),
			'af_hit_count' => $entry['hits'],
			'af_throttled' => false,
			'af_deleted' => isset( $entry['deleted'] ),
			'af_actions' => $entry['actions'],
			'af_global' => false,
			'af_group' => 'default'
		];

		$this->dbw->insert( 'abuse_filter', $e, __METHOD__ );
		$this->dbw->commit();
	}

}

$maintClass = 'GrabAbuseFilter';
require_once RUN_MAINTENANCE_IF_MAIN;
