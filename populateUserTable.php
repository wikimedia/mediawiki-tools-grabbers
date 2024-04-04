<?php
/**
 * Populates the user table with information from other tables.
 * Useful to fill the user table with stub data after importing
 * content from other grabbers.
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.3.0
 * @date 17 August 2023
 */

require_once __DIR__ . '/../maintenance/Maintenance.php';

class PopulateUserTable extends Maintenance {

	/**
	 * Handle to the database connection
	 *
	 * @var DatabaseBase
	 */
	protected $dbw;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Populates the user table creating stub users (user ID and name) from other tables.' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false /* required? */, true /* withArg */ );
	}

	public function execute() {
		global $wgDBname;

		# Get a single DB_PRIMARY connection
		$this->dbw = wfGetDB( DB_PRIMARY, [], $this->getOption( 'db', $wgDBname ) );

		$this->populateUsersFromTable( 'actor', [
			'id' => 'actor_user',
			'name' => 'actor_name'
		] );
	}

	function populateUsersFromTable( $table, $columnInfo ) {
		$userIDField = $columnInfo['id'];
		$userNameField = $columnInfo['name'];

		# Dummy values for all required fields
		$e = [
			'user_id' => '',
			'user_name' => '',
			'user_real_name' => '',
			'user_password' => '',
			'user_newpassword' => '',
			'user_email' => '',
			'user_touched' => wfTimestampNow(),
			'user_token' => ''
		];

		$this->output( "Populating users from table $table..." );

		$count = 0;
		$lastUserId = 0;

		while ( true ) {
			$result = $this->dbw->select(
				[ $table, 'user' ],
				[ $userIDField, $userNameField ],
				[
					'user_id' => null,
					"$userIDField > $lastUserId"
				],
				__FUNCTION__,
				[
					'DISTINCT',
					'ORDER BY' => $userIDField,
					'LIMIT' => $this->getBatchSize(),
				],
				[
					'user' => [ 'LEFT JOIN', "user_id=$userIDField" ]
				]
			);

			if ( !$result->numRows() ) {
				break;
			}

			$row = $result->fetchRow();
			while ( $row ) {
				$lastUserId = $row[$userIDField];
				$e['user_id'] = $lastUserId;
				$e['user_name'] = $row[$userNameField];
				$inserted = $this->dbw->insert(
					'user',
					$e,
					__METHOD__,
					# If there have been a rename in the middle, can be
					# duplicate ID for different user names
					[ 'IGNORE' ]
				);
				if ( $inserted ) {
					$count++;
					if ( $count % 500 == 0 ) {
						$this->output( "$count insertions...\n" );
					}
				}
				$row = $result->fetchRow();
			}
		}
		$this->output( "Done: $count insertions.\n" );
	}
}

$maintClass = PopulateUserTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
