<?php
/**
 * Maintenance script to grab the user block data from a wiki (to which we have
 * only read-only access instead of full database access).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.0
 * @date 28 July 2013
 * @note Based on code by:
 * - Legoktm & Uncyclopedia development team, 2013 (blocks_table.py)
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabUserBlocks extends Maintenance {

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
			$this->error( 'The URL to the target wiki\'s api.php is required!', 1 );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		$startDate = $this->getOption( 'startdate' );
		if ( $startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $startDate ) ) {
				$this->error( "Invalid startdate format.\n", 1 );
			}
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->error( "Invalid enddate format.\n", 1 );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

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
				$this->error( "Failed to log in as $user.", 1 );
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
			'list' => 'blocks',
			'bkdir' => 'newer',
			'bkend' => $endDate,
			'bklimit' => 'max',
			'bkprop' => 'id|user|userid|by|byid|timestamp|expiry|reason|range|flags',
		);

		$more = true;
		$bkstart = $startDate;
		$i = 0;

		$this->output( "Grabbing blocks...\n" );
		do {
			if ( $bkstart === null ) {
				unset( $params['bkstart'] );
			} else {
				$params['bkstart'] = $bkstart;
			}

			$result = $this->bot->query( $params );

			if ( empty( $result['query']['blocks'] ) ) {
				$this->error( 'No blocks, hence nothing to do. Aborting the mission.', 1 );
			}

			foreach ( $result['query']['blocks'] as $logEntry ) {
				// Skip autoblocks, nothing we can do about 'em
				if ( !isset( $logEntry['automatic'] ) ) {
					$this->processEntry( $logEntry );
					$i++;
				}

				if ( isset( $result['query-continue'] ) ) {
					$bkstart = $result['query-continue']['blocks']['bkstart'];
					$this->output( "{$i} entries processed.\n" );
				} else {
					$bkstart = null;
				}

				$more = !( $bkstart === null );
			}

			# Readd the AUTO_INCREMENT here
		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processEntry( $entry ) {
		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$data = array(
			'ipb_id' => $entry['id'],
			'ipb_address' => $entry['user'],
			'ipb_user' => $entry['userid'],
			'ipb_by' => $entry['byid'],
			'ipb_by_text' => $entry['by'],
			'ipb_reason' => $entry['reason'],
			'ipb_timestamp' => $ts,
			'ipb_auto' => 0,
			'ipb_anon_only' => isset( $entry['anononly'] ),
			'ipb_create_account' => isset( $entry['nocreate'] ),
			'ipb_enable_autoblock' => isset( $entry['autoblock'] ),
			'ipb_expiry' => ( $entry['expiry'] == 'infinity' ? $this->dbw->getInfinity() : wfTimestamp( TS_MW, $entry['expiry'] ) ),
			'ipb_range_start' => ( isset( $entry['rangestart'] ) ? $entry['rangestart'] : false ),
			'ipb_range_end' => ( isset( $entry['rangeend'] ) ? $entry['rangeend'] : false ),
			'ipb_deleted' => isset( $entry['hidden'] ),
			'ipb_block_email' => isset( $entry['noemail'] ),
			'ipb_allow_usertalk' => isset( $entry['allowusertalk'] ),
		);
		$this->dbw->insert( 'ipblocks', $data, __METHOD__ );
		$this->dbw->commit();
	}
}

$maintClass = 'GrabUserBlocks';
require_once RUN_MAINTENANCE_IF_MAIN;
