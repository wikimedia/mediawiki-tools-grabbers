<?php
/**
 * Maintenance script to grab the user block data from a wiki (to which we have
 * only read-only access instead of full database access).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@countervandalism.net>
 * @version 0.2
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

class GrabUserBlocks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs user block data from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required!', true );
		}
		$this->output( "Working...\n" );

		$params = array(
			'action' => 'query',
			'format' => 'json',
			'list' => 'blocks',
			'bklimit' => 'max',
			'bkprop' => 'id|user|userid|by|byid|timestamp|expiry|reason|range|flags',
		);

		$more = true;
		$bkstart = null;
		$i = 0;
		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

		do {
			$this->output( "Do loop, instance {$i}...\n" );
			if ( $bkstart === null ) {
				unset( $params['bkstart'] );
			} else {
				$params['bkstart'] = $bkstart;
			}

			$q = $this->getOption( 'url' ) . '?' . wfArrayToCGI( $params );
			$result = Http::get( $q );
			$result = json_decode( $result, true );

			if ( empty( $result['query']['blocks'] ) ) {
				$this->error( 'No blocks, hence nothing to do. Aborting the mission.', true );
			}

			// @todo FIXME: ALTER TABLE to remove the AUTO_INCREMENT and readd it when we're done
			$dbw->query(
				"ALTER TABLE {$dbw->tableName( 'ipblocks' )} CHANGE ipb_id ipb_id int(11) NOT NULL;",
				__METHOD__
			);

			foreach ( $result['query']['blocks'] as $logEntry ) {
				$this->processEntry( $logEntry );

				if ( isset( $result['query-continue'] ) ) {
					$bkstart = $result['query-continue']['blocks']['bkstart'];
				} else {
					$bkstart = null;
				}

				$more = !( $bkstart === null );
				$i++;
			}

			# Readd the AUTO_INCREMENT here
		} while ( $more );

		$this->output( "\n" );
	}

	public function processEntry( $entry ) {
		global $wgDBname;

		// Skip autoblocks, nothing we can do about 'em
		if ( preg_match( '/(A|a)utoblocked/', $entry['reason'] ) ) {
			continue;
		}

		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$data = array(
			#'ipb_id' => $entry['id'],
			'ipb_address' => $entry['user'],
			'ipb_user' => $entry['userid'],
			'ipb_by' => $entry['byid'],
			'ipb_by_text' => $entry['by'],
			'ipb_reason' => $entry['reason'],
			'ipb_timestamp' => $ts,
			'ipb_auto' => 0,
			'ipb_anon_only' => ( isset( $entry['anononly'] ) ? true : false ),
			'ipb_create_account' => ( isset( $entry['nocreate'] ) ? true : false ),
			'ipb_enable_autoblock' => ( isset( $entry['autoblock'] ) ? true : false ),
			'ipb_expiry' => ( $entry['expiry'] == 'infinity' ? : $dbw->getInfinity() : wfTimestamp( TS_MW, $entry['expiry'] ) ),
			'ipb_range_start' => ( isset( $entry['rangestart'] ) ? $entry['rangestart'] : false ),
			'ipb_range_end' => ( isset( $entry['rangeend'] ) ? $entry['rangeend'] : false ),
			'ipb_deleted' => 0,
			'ipb_block_email' => ( isset( $entry['noemail'] ) ? true : false ),
			'ipb_allow_usertalk' => ( isset( $entry['allowusertalk'] ) ? true : false ),
		);

		$this->output( "Going to commit...\n" );

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$dbw->insert( 'ipblocks', $data, __METHOD__ );
		$dbw->commit();

		$this->output( "Changes committed to the database!\n" );
	}
}

$maintClass = 'GrabUserBlocks';
require_once RUN_MAINTENANCE_IF_MAIN;