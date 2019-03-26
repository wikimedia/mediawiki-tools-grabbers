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

require_once __DIR__ . '/../maintenance/Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabProtectedTitles extends Maintenance {

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
		$this->mDescription = 'Grabs user block data from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17T, etc).', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->fatalError( 'The URL to the target wiki\'s api.php is required!' );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
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

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, [], $this->getOption( 'db', $wgDBname ) );

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

		$params = [
			'list' => 'protectedtitles',
			'ptdir' => 'newer',
			'ptend' => $endDate,
			'ptlimit' => 'max',
			'ptprop' => 'userid|timestamp|expiry|comment|level',
		];

		$more = true;
		$ptstart = $startDate;
		$i = 0;

		$this->output( "Grabbing protected titles...\n" );
		do {
			if ( $ptstart === null ) {
				unset( $params['ptstart'] );
			} else {
				$params['ptstart'] = $ptstart;
			}

			$result = $this->bot->query( $params );

			if ( empty( $result['query']['protectedtitles'] ) ) {
				$this->fatalError( 'No protected titles, hence nothing to do. Aborting the mission.' );
			}

			foreach ( $result['query']['protectedtitles'] as $logEntry ) {
				$this->processEntry( $logEntry );
				$i++;

				if ( isset( $result['query-continue'] ) ) {
					$ptstart = $result['query-continue']['protectedtitles']['ptstart'];
					$this->output( "{$i} entries processed.\n" );
				} else {
					$ptstart = null;
				}

				$more = !( $ptstart === null );
			}

		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processEntry( $entry ) {
		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$data = [
			'pt_namespace' => $entry['ns'],
			'pt_title' => $entry['title'],
			'pt_user' => $entry['userid'] ?? 0,
			'pt_reason' => $entry['comment'],
			'pt_reason_id' => 0,
			'pt_timestamp' => $ts,
			'pt_expiry' => $entry['expiry'],
			'pt_create_perm' => $entry['level']
		];

		$this->dbw->insert( 'protected_titles', $data, __METHOD__ );
	}
}

$maintClass = 'GrabProtectedTitles';
require_once RUN_MAINTENANCE_IF_MAIN;
