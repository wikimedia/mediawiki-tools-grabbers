<?php
/**
 * Grabs logs from a pre-existing wiki into a new wiki.
 * Useless without the correct revision table entries and whatnot (because
 * otherwise MediaWiki can't tell that page with the ID 231 is
 * "User:Jack Phoenix").
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @date 20 June 2012
 * @note Based on code by:
 * - Edward Chernenko <edwardspec@gmail.com> (MediaWikiDumper 1.1.5, logs.pl)
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', dirname( __FILE__ ) . '/../maintenance' );

require_once( 'Maintenance.php' );
require_once( 'mediawikibot.class.php' );

class GrabLogs extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs logs from a pre-existing wiki into a new wiki.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'start', 'Start point in crazy zulu format timestamp (2012-11-20T05:28:53Z)', false, true );
		$this->addOption( 'end', 'Log time at which to stop (in crazy zulu format timestamp)', false, true );
		$this->addOption( 'carlb', 'Tells the script to use lower api limits', false, false );
	}

	public function execute() {
		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required!', true );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		$carlb = $this->getOption( 'carlb' );

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

		$params = array(
			'list' => 'logevents',
			'lelimit' => 'max',
			'ledir' => 'newer',
			'leprop' => 'ids|title|type|user|userid|timestamp|comment|details',
		);
		if ( $carlb ) {
			# Tone this down a bit
			$params['lelimit'] = 100;
		}

		# A basic normal assortment - skip weird logs like avatars and namespaces
		$validLogTypes = array(
			'abusefilter',
			'block',
			'delete',
			'import',
			'interwiki',
			'merge',
			'move',
			'newusers',
			'patrol',
			'protect',
			'renameuser',
			'rights',
			'upload',
		);

		$lestart = $this->getOption( 'start' );
		$leend = $this->getOption( 'end' );
		$more = true;
		$i = 0;

		$this->output( "Fetching log events...\n" );
		do {
			if ( $lestart === null ) {
				unset( $params['lestart'] );
			} else {
				$params['lestart'] = $lestart;
			}
			$result = $bot->query( $params );

			if ( empty( $result['query']['logevents'] ) ) {
				$this->error( 'No log events found...', true );
			}

			foreach ( $result['query']['logevents'] as $logEntry ) {
				# Check if we've passed a specified endpoint
				if ( $leend && wfTimestamp( TS_MW, $logEntry['timestamp'] ) > wfTimestamp( TS_MW, $leend ) ) {
					$more = false;
					break;
				}
				if ( in_array( $logEntry['type'], $validLogTypes) ) {
					$this->processEntry( $logEntry );
				}

				if ( isset( $result['query-continue'] ) ) {
					$lestart = $result['query-continue']['logevents']['lestart'];
				} else {
					$lestart = null;
				}

				$more = !( $lestart === null );
				$i++;
				if ( $i % 500 == 0 ) {
					$this->output( "{$i} logs fetched...\n" );
				}
			}
		} while ( $more );

		$this->output( "\n" );
	}

	public function processEntry( $entry ) {
		global $wgContLang, $wgDBname;

		$action = $entry['action'];

		# Handler for reveleted stuff or some such
		$revdeleted = 0;
		if ( isset( $entry['actionhidden'] ) ) {
			$entry['title'] = 'Title hidden';
			$entry['ns'] = 0;
			$revdeleted = $revdeleted | LogPage::DELETED_ACTION;
		}
		if ( isset( $entry['commenthidden'] ) ) {
			$entry['comment'] = 'Comment hidden';
			$revdeleted = $revdeleted | LogPage::DELETED_COMMENT;
		}
		if ( isset( $entry['userhidden'] ) ) {
			$entry['user'] = 'User hidden';
			$entry['userid'] = 0;
			$revdeleted = $revdeleted | LogPage::DELETED_USER;
		}

		$title = $entry['title'];
		$ns = $entry['ns'];

		if( $ns != 0 ) {
			// HT hexmode & Skizzerz
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );

		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );
		if( $ts < 20080000000000 && preg_match( '/^Wikia\-/', $entry['user'], $matches ) ) {
			# A tiny bug on Wikia in 2006-2007, affects ~10 log entries only
			if ( isset( $matches[0] ) ) {
				$entry['user'] = substr( $entry['user'], 0, 6 );
			}
		}

		$e = array(
			'id' => $entry['logid'],
			'type' => $entry['type'],
			'action' => $entry['action'],
			'timestamp' => $ts,
			'user' => $entry['userid'],
			'user_text' => $entry['user'],
			'namespace' => $ns,
			'title' => $title,
			'page' => $entry['pageid'],
			'comment' => $wgContLang->truncate( $entry['comment'], 255 ),
			'params' => '',
			'deleted' => $revdeleted # Revdeleted
		);

		# Get params for logs that use them
		# Note - These use legacy 1.18 log_params format.
		# Supress warnings because if they're missing, they're missing from the source,
		# so not our problem
		wfSuppressWarnings();
		if( $action == 'patrol' ) {
			# Parameters: revision id, previous revision id, automatic?
			$e['params'] = $entry['patrol']['cur'] . "\n" .
				$entry['patrol']['prev'] . "\n" .
				$entry['patrol']['auto'];
		} elseif( $action == 'block' || $action == 'reblock' ) {
			# Parameters: Block expiration, options
			$e['params'] = $entry['block']['duration'] . "\n" .
				$entry['block']['flags'];
		} elseif( $action == 'move' || $action == 'move_redir' ) {
			# Parameters: Target page title, redirect suppressed?
			$e['params'] = $entry['move']['new_title'] . "\n";
			if( isset( $entry['move']['suppressedredirect'] ) ) {
				# Suppressed redirect.
				$e['params'] .= '1';
			}
		} elseif( $entry['type'] == 'abusefilter' ) {
			# [[Extension:AbuseFilter]]
			# Parameters: filter revision id, filter number
			foreach( array_keys( $entry ) as $eh ){
				print "$eh: {$entry[$eh]}\n";
			}
			$e['params'] =  $entry[0] . "\n" . $entry[1];

		} elseif( $entry['type'] == 'interwiki' ) {
			# [[Extension:Interwiki]]
			# Parameters: interwiki prefix, url, transcludable?, local?
			$e['params'] = $entry[0];
			if ( $action == 'iw_add' || $action == 'iw_edit' ) {
				$e['params'] .=  "\n" . $entry[1] . "\n" . $entry[2] . "\n" . $entry[3];
			}

		} elseif( $action == 'renameuser' ) {
			# [[Extension:Renameuser]]
			# Parameter: new user name
			$e['params'] = $entry[0];

		} elseif( $action == 'merge' ) {
			# Parameters: target (merged into), latest revision date
			$e['params'] = $entry[0] . "\n" . $entry[1];

		} elseif( $entry['type'] == 'protect' && ( $action == 'protect' || $action == 'modify' ) ) {
			# Parameters: protection type and expiration, cascading options
			$e['params'] = ( isset( $entry[0] ) ? $entry[0] : '') . ( isset( $entry[1] ) ?  "\n" . $entry[1] : '' );
		} elseif( $action == 'rights' ) {
			# Parameters: old groups, new groups
			$e['params'] = $entry['rights']['old'] . "\n" .
				$entry['rights']['new'];
		} elseif( $entry['type'] == 'newusers' && ( $action == 'create' || $action == 'create2' ) ) {
			# Parameter: new user ID (set to 0; mwauth can change this upon login)
			$e['params'] = ( isset( $entry[0]['param'] ) ? $entry[0]['param'] : '' );
		}
		wfRestoreWarnings();
		# Other log types with no specific params as of 1.20:
		# $entry['type'] = 'delete' (actions: delete, restore)
		# $action = 'unblock'
		# $action = 'unprotect'
		# $entry['type'] = 'import' (action: upload (type checking so as not to be confused with downloading files))
		# $entry['type'] = 'upload' (actions: upload, override)

		# $this->output( "Going to commit...\n" );

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$entry = array(
			'log_type' => $e['type'],
			'log_action' => $e['action'],
			'log_timestamp' => $e['timestamp'],
			'log_user' => $e['user'],
			'log_user_text' => $e['user_text'],
			'log_namespace' => $e['namespace'],
			'log_title' => $e['title'],
			'log_page' => $e['page'],
			'log_comment' => $e['comment'],
			'log_params' => $e['params']
		);

		# Backup workaround for revdeleted entries
		try {
			$dbw->insert( 'logging', $entry, __METHOD__ );
			$dbw->commit();

			# $this->output( "Changes committed to the database!\n" );
		} catch ( Exception $e ) {

			foreach ( array_values( $entry ) as $line ) {
				if ( $line == NULL ) {
					$this->output( "Exception caught; something exploded\n" );
					$line = 0;
				}
			}

			$dbw->insert( 'logging', $entry, __METHOD__ );
			$dbw->commit();
			# $this->output( "Changes committed to the database!\n" );
		}
	}
}

$maintClass = 'GrabLogs';
require_once( RUN_MAINTENANCE_IF_MAIN );
