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
 * @version 1.0
 * @date 10 August 2017
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabAbuseFilter extends Maintenance {

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

	/**
	 * Handle to the database connection
	 *
	 * @var DatabaseBase
	 */
	protected $dbw;

	/**
	 * MediaWikiBot instance
	 *
	 * @var MediaWikiBot
	 */
	protected $bot;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs logs from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required!', 1 );
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$this->output( "Working...\n" );

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
				$this->error( "Failed to log in as $user.\n", 1 );
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

		$params = array(
			'list' => 'abusefilters',
			'abflimit' => 'max',
			'ledir' => 'newer',
			'leprop' => 'id|description|pattern|actions|hits|comments|lasteditor|lastedittime|status|private',
		);

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
		$e = array(
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
		);

		$this->dbw->insert( 'abuse_filter', $e, __METHOD__ );
		$this->dbw->commit();
	}

}

$maintClass = 'GrabAbuseFilter';
require_once RUN_MAINTENANCE_IF_MAIN;
