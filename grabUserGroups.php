<?php
/**
 * Fetches user groups
 *
 * @file
 * @ingroup Maintenance
 * @author Kunal Mehta <legoktm@gmail.com>
 * @version 1.1
 */

require_once 'includes/ExternalWikiGrabber.php';

class GrabUserGroups extends ExternalWikiGrabber {

	/**
	 * Groups we don't want to import...
	 * @var array
	 */
	public $badGroups = [
		'*',
		'user',
		'autoconfirmed'
	];

	/**
	 * Groups we're going to import
	 * @var array
	 */
	public $groups = [];

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs user group assignments from a pre-existing wiki into a new wiki.';
		$this->addOption( 'groups', 'Get only a specific list of groups (pipe separated list of group names, by default everything except *, user and autoconfirmed)', false, true );
		$this->addOption( 'wikia', 'Set this param if the target wiki is on Wikia, which uses a different API', false, false );
	}

	public function execute() {
		parent::execute();

		$providedGroups = $this->getOption( 'groups' );
		if ( $providedGroups ) {
			$this->groups = explode( '|', $providedGroups );
		}

		$this->output( "Getting user group information.\n" );

		# TODO Less redundant switch ...
		# Wikia has a custom api module for this, because they accidentally removed the original
		if ( $this->getOption( 'wikia' ) ) {
			# They have a few extra usergroups we probably don't want...
			$this->badGroups = array_merge( $this->badGroups, [
				'authenticated',
				'bot-global',
				'chatmoderator',
				'content-reviewer',
				'content-volunteer',
				'council',
				'fandom-editor',
				'global-discussions-moderator',
				'helper',
				'request-to-be-forgotten-admin',
				'reviewer',
				'restricted-login',
				'restricted-login-exempt',
				'staff',
				'threadmoderator',
				'translator',
				'util',
				'vanguard',
				'voldev',
				'vstf'
			] );

			$params = [
				'list' => 'groupmembers',
				'gmgroups' => implode( '|', $this->getGroups() ),
				'gmlimit' => 'max',
			];

			$userCount = 0;

			do {
				$data = $this->bot->query( $params );
				$stuff = [];

				foreach( $data['users'] as $user ) {
					foreach ( $user['groups'] as $group ) {
						if ( in_array( $group, $this->groups ) ) {
							$stuff[] = [ 'ug_user' => $user['userid'], 'ug_group' => $group ];
						}
					}
					$userCount++;
				}

				if ( count( $stuff ) ) {
					$this->insertRows( $stuff );
				}
				if ( isset( $data['query-continue'] ) ) {
					$params['gmoffset'] = $data['query-continue']['groupmembers']['gmoffset'];
					$more = true;
				} else {
					$more = false;
				}
			} while ( $more );
		} else {
			$params = [
				'list' => 'allusers',
				'aulimit' => 'max',
				'auprop' => 'groups',
				'augroup' => implode( '|', $this->getGroups() )
			];

			$userCount = 0;

			do {
				$data = $this->bot->query( $params );
				$stuff = [];

				foreach ( $data['query']['allusers'] as $user ) {
					foreach ( $user['groups'] as $group ) {
						if ( in_array( $group, $this->groups ) ) {
							$stuff[] = [ 'ug_user' => $user['userid'], 'ug_group' => $group ];
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
		}

		$this->output( "Processed $userCount users.\n" );
	}

	/**
	 * @return array
	 */
	public function getGroups() {
		$params = [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'usergroups'
		];
		$data = $this->bot->query( $params );
		$groups = [];
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
				$this->fatalError( sprintf( 'Some of the provided groups don\'t exist on the wiki: %s',
					implode( '|', $invalidGroups ) ) );
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
		$this->dbw->insert( 'user_groups', $rows, __METHOD__, [ 'IGNORE' ] );
		$this->dbw->commit();
	}
}

$maintClass = 'GrabUserGroups';
require_once RUN_MAINTENANCE_IF_MAIN;
