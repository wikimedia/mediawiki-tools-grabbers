<?php
/**
 * Fetches user groups
 *
 * @file
 * @ingroup Maintenance
 * @author Kunal Mehta <legoktm@gmail.com>
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', dirname( __FILE__ ) . '/../maintenance' );

require_once( 'Maintenance.php' );
require_once( 'mediawikibot.class.php' );

class GrabUserGroups extends Maintenance {

	/**
	 * Groups we don't want to import...
	 * @var array
	 */
	var $badGroups = array( '*', 'user', 'autoconfirmed' );

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs logs from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
	}

	public function execute() {
		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required!', true );
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

		$this->output( "Getting user group information.\n" );

		$params = array(
			'list' => 'allusers',
			'aulimit' => 'max',
			'auprop' => 'groups',
			'augroup' => implode( '|', $this->getGroups( $bot ) )
		);

		$userCount = 0;

		do {
			$data = $bot->query( $params );
			$stuff = array();
			foreach ( $data['query']['allusers'] as $user ) {
				foreach ( $user['groups'] as $group ) {
					if ( in_array( $group, $this->badGroups ) ) {
						continue;
					}
					$stuff[] = array( 'ug_user' => $user['userid'], 'ug_group' => $group );
				}
				$userCount++;
			}
			if ( $stuff ) {
				$this->insertRows( $stuff );
			}
			if ( isset( $data['query-continue'] ) ) {
				// @todo don't hardcode parameter names
				$params['aufrom'] = $data['query-continue']['allusers']['aufrom'];
				$more = true;
			} else {
				$more = false;
			}
		} while ( $more );

		$this->output( "Processed $userCount users.\n" );
	}

	/**
	 * @param $bot MediaWikiBot
	 * @return array
	 */
	public function getGroups( $bot ) {
		$params = array(
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'usergroups'
		);
		$data = $bot->query( $params );
		$groups = array();
		foreach ( $data['query']['usergroups'] as $group ) {
			if ( !in_array( $group['name'], $this->badGroups ) ) {
				$groups[] = $group['name'];
			}
		}
		return $groups;
	}

	/**
	 * Batch insert rows
	 * @param $rows array
	 */
	public function insertRows( $rows ) {
		global $wgDBname;
		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$dbw->insert( 'user_groups', $rows, __METHOD__, array( 'IGNORE' ) );
		$dbw->commit();
	}
}

$maintClass = 'GrabUserGroups';
require_once( RUN_MAINTENANCE_IF_MAIN );
