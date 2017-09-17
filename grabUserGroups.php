<?php
/**
 * Fetches user groups
 *
 * @file
 * @ingroup Maintenance
 * @author Kunal Mehta <legoktm@gmail.com>
 * @version 1.0
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'mediawikibot.class.php';

class GrabUserGroups extends Maintenance {

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

	/**
	 * Groups we don't want to import...
	 * @var array
	 */
	public $badGroups = array( '*', 'user', 'autoconfirmed' );

	/**
	 * Groups we're going to import
	 * @var array
	 */
	public $groups = array();

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs user group assignments from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'groups', 'Get only a specific list of groups (pipe separated list of group names, by default everything except *, user and autoconfirmed)', false, true );
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
		$providedGroups = $this->getOption( 'groups' );
		if ( $providedGroups ) {
			$this->groups = explode( '|', $providedGroups );
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

		$this->output( "Getting user group information.\n" );

		$params = array(
			'list' => 'allusers',
			'aulimit' => 'max',
			'auprop' => 'groups',
			'augroup' => implode( '|', $this->getGroups() )
		);

		$userCount = 0;

		do {
			$data = $this->bot->query( $params );
			$stuff = array();
			foreach ( $data['query']['allusers'] as $user ) {
				if ( isset( $user['userid'] ) ) {
					$userId = $user['userid'];
				} elseif ( isset( $user['id'] ) ) {
					# Because Wikia is different
					$userId = $user['id'];
				}
				foreach ( $user['groups'] as $group ) {
					if ( in_array( $group, $this->groups ) ) {
						$stuff[] = array( 'ug_user' => $userId, 'ug_group' => $group );
					}
				}
				$userCount++;
			}
			if ( count( $stuff ) ) {
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
	 * @return array
	 */
	public function getGroups() {
		$params = array(
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'usergroups'
		);
		$data = $this->bot->query( $params );
		$groups = array();
		foreach ( $data['query']['usergroups'] as $group ) {
			if ( !in_array( $group['name'], $this->badGroups ) ) {
				$groups[] = $group['name'];
			}
		}
		if ( count( $this->groups ) ) {
			# Check in case the user made a typo
			$finalGroups = array_intersect( $this->groups, $groups );
			$invalidGroups = array_values( array_diff( $this->groups, $groups ) );
			if ( count( $invalidGroups ) ) {
				$this->error( sprintf( 'Some of the provided groups don\'t exist on the wiki: %s',
					implode( '|', $invalidGroups ) ), 1 );
			}
			$groups = $finalGroups;
		}
		# Update groups to use outside here
		$this->groups = $groups;
		return $groups;
	}

	/**
	 * Batch insert rows
	 * @param array $rows
	 */
	public function insertRows( $rows ) {
		$this->dbw->insert( 'user_groups', $rows, __METHOD__, array( 'IGNORE' ) );
		$this->dbw->commit();
	}
}

$maintClass = 'GrabUserGroups';
require_once RUN_MAINTENANCE_IF_MAIN;
