<?php
/**
 * Maintenance script to grab text from a wiki and import it to another wiki.
 * Translated from Edward Chernenko's Perl version (text.pl).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @version 0.6
 * @date 1 January 2013
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', __DIR__ . '/../maintenance' );

require_once 'Maintenance.php';
require_once 'mediawikibot.class.php';

class GrabNewText extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab new changes from an external wiki and add it over an imported dump.\nFor use when the available dump is slightly out of date.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17T, etc); note that this cannot go back further than 1-3 months on most projects.', true, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
	}

	public function execute() {
		global $bot, $endDate, $startDate, $wgDBname, $lastRevision;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", true );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$startDate = $this->getOption( 'startdate' );
		if ( $startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $startDate ) ) {
				$this->error( "Invalid startdate format.\n", true );
			}
		} else {
			$this->error( "A timestamp to start from is required.\n", true );
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->error( "Invalid enddate format.\n", true );
			}
		} else {
			$endDate = wfTimestampNow();
		}

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
				$this->output( "Warning - failed to log in as $user.\n" );
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

		$this->output( "\n" );

		# Get page changes from recentchanges and crap
		$this->processRecentLogs();
		$this->processRecentChanges();

		# Done.
	}

	/**
	 * Get page edits and creations
	 */
	function processRecentChanges() {
		global $wgDBname, $endDate, $startDate, $bot;

		$blackList = array(); # Don't get new edits for these
		$more = true;
		$count = 0;

		# Get last revision id to avoid duplicates
		$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
		$result = (int)$dbr->selectField(
			'revision',
			'rev_id',
			array(),
			__METHOD__,
			array( 'ORDER BY' => 'rev_id DESC' )
		);
		$lastRevision = $result;

		# Get edits
		$params = array(
			'list' => 'recentchanges',
			'rcdir' => 'newer',
			'rctype' => 'edit|new',
			'rclimit' => 'max',
			'rcprop' => 'title|sizes|redirect|ids',
			'rcend' => $endDate
		);
		$rcstart = $startDate;
		$count = 0;
		$more = true;

		$this->output( "Retreiving list of changed pages...\n" );
		while ( $more ) {
			$params['rcstart'] = $rcstart;

			$result = $bot->query( $params );
			if ( empty( $result['query']['recentchanges'] ) ) {
				$this->output( 'No changes found...', true );
			}
			foreach ( $result['query']['recentchanges'] as $entry ) {
				# new pages, new uploads, edited pages
				# while more, parse into $pagesList
				if ( ( $count % 500 ) == 0 ) {
					$this->output( "$count\n" );
				}

				$title = $entry['title'];
				$ns = $entry['ns'];
				if ( $ns != 0 ) {
					$title = preg_replace( '/^[^:]*?:/', '', $title );
				}
				$title = str_replace( ' ', '_', $title );
				$listKey = $ns . 'cowz' . $title;

				if ( in_array( $listKey, $blackList ) ) {
					# Already done; continue
					continue;
				}
				$blackList[] = $listKey;

				$pageInfo = array(
					'pageid' => $entry['pageid'],
					'title' => $entry['title'],
					'ns' => $ns,
					'protection' => '',
					'redirect' => 0,
					'length' => $entry['newlen']
				);
				if ( isset( $entry['redirect'] ) ) {
					$pageInfo['redirect'] = 1;
				}
				$this->processPage( $pageInfo, $startDate );

				$count++;
			}
			if ( isset( $result['query-continue'] ) ) {
				$rcstart = $result['query-continue']['recentchanges']['rcstart'];
			} else {
				$rcstart = null;
			}
			$more = !( $rcstart === null );
		}
		$this->output( "\n" );
	}

	/**
	 * Get delete/move/import changes
	 */
	function processRecentLogs() {
		global $bot, $endDate, $wgDBname, $startDate;

		$params = array(
			'list' => 'logevents',
			'ledir' => 'newer',
			'letype' => 'delete|move|import',
			'lelimit' => 'max',
			'leend' => $endDate
		);
		$lestart = null;
		$more = true;

		$this->output( "Updating deleted and moved items...\n" );
		while ( $more ) {
			if ( $lestart === null ) {
				$params['lestart'] = $startDate;
			} else {
				$params['lestart'] = $lestart;
			}
			$result = $bot->query( $params );
			if ( empty( $result['query']['logevents'] ) ) {
				$this->output( "No changes found...\n", true );
			} else {
				$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
				foreach ( $result['query']['logevents'] as $logEntry ) {
					if ( ( $count % 500 ) == 0 ) {
						$this->output( "$count\n" );
					}
					$pageid = $logEntry['pageid'];
					$title = $logEntry['title'];
					$ns = $logEntry['ns'];
					$title = $this->sanitiseTitle( $ns, $title );

					if ( $logEntry['action'] == 'move' ) {
						$this->output( "$ns:$title was moved; updating....\n" );
						# Move our copy
						# New title
						$redirect = true;
						$pageID = $logEntry['pageid'];
						$newns = $logEntry['move']['new_ns'];
						$newTitle = $this->sanitiseTitle( $newns, $logEntry['move']['new_title'] );
						if ( !$pageID ) {
							$pageID = $this->getPageID( $ns, $title );
							if ( !$pageID ) {
								$this->output( "$ns:$title not found in database.\n" );
								# Failed. Meh.
								continue;
							}
							# Redirect surpressed
							$redirect = false;
						}
						$source = Title::newFromText( $logEntry['title'] );
						$dest = Title::newFromText( $logEntry['move']['new_title'] );

						$dbw->begin( __METHOD__ );
						$err = $source->moveTo( $dest, false, '', $redirect );
						if ( $err !== true ) {
							$msg = array_shift( $err[0] );
							$this->output( "\nFAILED: " . wfMessage( $msg, $err[0] )->text() );
						}
						$dbw->commit( __METHOD__ );

					} elseif ( $logEntry['action'] ==  'delete' ) {
						$this->output( "$ns:$title was deleted; updating....\n" );
						# Delete our copy, move revisions -> archive
						$this->updateDeleted( $ns, $title, $dbw );
					} elseif ( $logEntry['action'] == 'restore' ) {
						$this->output( "$ns:$title was undeleted; updating....\n" );
						# Remove any revisions from archive and process as new
						$page = $this->updateRestored( $ns, $title, $dbw );

						if ( $page ) {
							$this->processPage( $page );
							$this->output( "$ns:$title processed.\n" );
							$blackList[] = $ns."cowz".$title;
						}
					}
					elseif ( $logEntry['action'] == 'upload' ) {
						$this->output( "$ns:$title was imported; updating....\n" );
						# Process as new
						if ( !$pageID ) {
							$pageID = '';
						}
						$pageInfo = array(
							'pageid' => $pageID,
							'title' => $title,
							'ns' => $ns,
							'protection' => '',
							'redirect' => 0,
							'length' => null
						);
						$this->processPage( $pageInfo );
						#... not tested
					}
					$count++;
				}
			}
			if ( isset( $result['query-continue'] ) ) {
				$lestart = $result['query-continue']['logevents']['lestart'];
			} else {
				$lestart = null;
			}
			$more = !( $lestart === null );
		}
		$this->output( "\n" );
	}

	/**
	 * Handle an individual page.
	 *
	 * @param array $page Array retrieved from the API, containing pageid,
	 *                     page title, namespace, protection status and more...
	 * @param int|null $start Timestamp from which to get revisions; if this is
	 *                     defined, protection stuff is skipped.
	 */
	function processPage( $page, $start = null ) {
		global $wgDBname, $bot, $endDate;

		$pageID = $page['pageid'];
		$title = $page['title'];
		$ns = $page['ns'];
		$localID = $pageID;
		$titleIsPresent = false;

		$this->output( "Processing page $pageID...\n" );

		# Trim and convert displayed title to database page title
		if ( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		if ( $start ) {
			# Check if title is present
			$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
			$result = $dbr->select(
				'page',
				'page_id',
				array(
					'page_namespace' => $ns,
					'page_title' => $title
				),
				__METHOD__
			);
			$row = $dbr->fetchObject( $result );
			if ( $row ) {
				$localID = $row->page_id;
				$titleIsPresent = true;

			} else {
				# Check if id is present
				$result = $dbr->select(
					'page',
					'page_title',
					array( 'page_id' => $pageID ),
					__METHOD__
				);
				if ( $dbr->fetchObject( $result ) ) {
					$resid = (int)$dbr->selectField(
						'page',
						'page_id',
						array(),
						__METHOD__,
						array( 'ORDER BY' => 'page_id DESC' )
					);
					$localID = $resid + 1;
				}
			}
		}

		# Update page_restrictions
		# NOTE - this doesn't support if the protections are already there, just adds blindly
		if ( !$start && $page['protection'] ) {
			foreach ( $page['protection'] as $prot ) {
				$e = array(
					'page' => $localID,
					'type' => $prot['type'],
					'level' => $prot['level'],
					'cascade' => 0,
					'user' => null,
					'expiry' => ( $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] ) ),
					'id' => null
				);
				$dbw->insert(
					'page_restrictions',
					array(
						'pr_page' => $e['page'],
						'pr_type' => $e['type'],
						'pr_level' => $e['level'],
						'pr_cascade' => $e['cascade'],
						'pr_user' => $e['user'],
						'pr_expiry' => $e['expiry'],
						'pr_id' => $e['id'],
					),
					__METHOD__
				);
				$dbw->commit();
				# $this->output( "Committed page_restrictions changes.\n" );
			}
		}

		$page_e = array(
			'id' => $localID,
			'namespace' => $ns,
			'title' => $title,
			'restrictions' => '',
			'counter' => 0,
			'is_redirect' => ( isset( $page['redirect'] ) ? 1 : 0 ),
			'is_new' => 0,
			'random' => rand(),
			'touched' => wfTimestampNow(),
			'len' => $page['length'],
		);

		# Retrieving the list of revisions, including text.
		$revision_latest;
		$last_rev_id = 0;
		$more = true;
		$rvcontinue = null;
		# 'rvcontinue' in 1.20+, 'rvstartid' in 1.19-
		$rvcontinuename = 'rvcontinue';

		$params = array(
			'prop' => 'revisions',
			'pageids' => $pageID,
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $endDate )
		);
		if ( $start ) {
			$params['rvstart'] = wfTimestamp( TS_ISO_8601, $start );
		}
		do {
			if ( $rvcontinue === null ) {
				unset( $params[$rvcontinuename] );
			} else {
				$params[$rvcontinuename] = $rvcontinue;
			}

			$result = $bot->query( $params );
			if ( isset( $result['query']['pages'] ) ) {
				$revisions = array_values( $result['query']['pages'] );
				$revisions = $revisions[0]['revisions'];

				foreach ( $revisions as $revision ) {
					$last_rev_info = $this->processRevision( $revision, $localID, $last_rev_id );
				}
			} else {
				$this->output( "Page id $pageID not found.\n" );
				return;
			}
			if ( isset( $result['query-continue'] ) ) {
				# Check name being used - if it's not the set one, reset it
				if ( !isset( $result['query-continue']['revisions'][$rvcontinuename] ) ) {
					$rvcontinuename = 'rvstartid';
				}
				$rvcontinue = $result['query-continue']['revisions'][$rvcontinuename];
			} else {
				$rvcontinue = null;
			}
			$more = !( $rvcontinue === null );
		} while ( $more );

		if ( !$last_rev_info ) {
			# Dupe.
			return;
		}

		$page_e['latest'] = $last_rev_info[0];
		$page_e['len'] = $last_rev_info[1];
		if ( !$start ) {
			$dbw->insert(
				'page',
				array(
					'page_id' => $page_e['id'],
					'page_namespace' => $page_e['namespace'],
					'page_title' => $page_e['title'],
					'page_restrictions' => $page_e['restrictions'],
					'page_counter' => $page_e['counter'],
					'page_is_redirect' => $page_e['is_redirect'],
					'page_is_new' => $page_e['is_new'],
					'page_random' => $page_e['random'],
					'page_touched' => $page_e['touched'],
					'page_latest' => $page_e['latest'],
					'page_len' => $page_e['len']
				),
				__METHOD__
			);
		} else {
			# update or insert if not present
			if ( $titleIsPresent ) {
				$this->output( "Updating page entry $localID\n" );
				$dbw->update(
					'page',
					array(
						'page_namespace' => $page_e['namespace'],
						'page_title' => $page_e['title'],
						'page_restrictions' => $page_e['restrictions'],
						'page_counter' => $page_e['counter'],
						'page_is_redirect' => $page_e['is_redirect'],
						'page_is_new' => $page_e['is_new'],
						'page_random' => $page_e['random'],
						'page_touched' => $page_e['touched'],
						'page_latest' => $page_e['latest'],
						'page_len' => $page_e['len']
					),
					array( 'page_id' => $localID ),
					__METHOD__
				);
			} else {
				$this->output( "Inserting page entry $localID\n" );
				$dbw->insert(
					'page',
					array(
						'page_id' => $localID,
						'page_namespace' => $page_e['namespace'],
						'page_title' => $page_e['title'],
						'page_restrictions' => $page_e['restrictions'],
						'page_counter' => $page_e['counter'],
						'page_is_redirect' => $page_e['is_redirect'],
						'page_is_new' => $page_e['is_new'],
						'page_random' => $page_e['random'],
						'page_touched' => $page_e['touched'],
						'page_latest' => $page_e['latest'],
						'page_len' => $page_e['len']
					),
					__METHOD__
				);
			}
		}

		$dbw->commit();
	}

	/**
	 * Process an individual page revision.
	 *
	 * @param array $revision Array retrieved from the API, containing the revision
	 *                    text, ID, timestamp, whether it was a minor edit or
	 *                    not and much more
	 * @param int $page_id Page ID number
	 * @param int $prev_rev_id Previous revision ID (revision.rev_parent_id)
	 */
	function processRevision( $revision, $page_id, $prev_rev_id ) {
		global $wgLang, $wgDBname, $lastRevision;

		if ( $revision['revid'] <= $lastRevision ) {
			# Oops?
			return false;
		}
		# Workaround check if it's already there.
		$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
		$result = $dbr->select(
			'revision',
			'rev_page',
			array( 'rev_id' => $revision['revid'] ),
			__METHOD__
		);
		if ( $dbr->fetchObject( $result ) ) {
			# Already in database
			return false;
		}

		$text = $revision['*'];
		$comment = $revision['comment'];
		if( $comment ) {
			$comment = $wgLang->truncate( $comment, 255 );
		} else {
			$comment = '';
		}

		$e = array(
			'id' => $revision['revid'],
			'page' => $page_id,
			'text_id' => $this->storeText( $text ),
			'comment' => $comment,
			'user' => $revision['userid'], # May not be accurate to the new wiki, obvious, but whatever.
			'user_text' => $revision['user'],
			'timestamp' => wfTimestamp( TS_MW, $revision['timestamp'] ),
			'minor_edit' => ( isset( $reisionv['minor'] ) ? 1 : 0 ),
			'deleted' => 0,	#revdeleted; would need a handler elsewhere for these
			'len' => strlen( $text ),
			'parent_id' => ( $prev_rev_id || 0 )
		);

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$this->output( "Inserting revision {$e['id']}\n" );
		$dbw->insert(
			'revision',
			array(
				'rev_id' => $e['id'],
				'rev_page' => $e['page'],
				'rev_text_id' => $e['text_id'],
				'rev_comment' => $e['comment'],
				'rev_user' => $e['user'],
				'rev_user_text' => $e['user_text'],
				'rev_timestamp' => $e['timestamp'],
				'rev_minor_edit' => $e['minor_edit'],
				'rev_deleted' => $e['deleted'],
				'rev_len' => $e['len'],
				'rev_parent_id' => $e['parent_id'],
			),
			__METHOD__
		);
		$dbw->commit();

		return array( $revision['revid'], $e['len'] );
	}

	# Stores revision texts in the text table.
	function storeText( $text ) {
		global $current_text_id, $wgDBname;

		if ( !isset( $current_text_id ) ) {
			$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
			$result = $dbr->select(
				'text',
				'old_id',
				'',
				__METHOD__,
				array(
					'LIMIT' => 1,
					'ORDER BY' => '`text`.`old_id` DESC'
				)
			);
			$row = $dbr->fetchObject( $result );
			if ( $row ) {
				$current_text_id = $row->old_id;
			} else {
				$current_text_id = 0;
			}
			$dbr->freeResult( $result );
		}
		$current_text_id++;

		$e = array(
			'id' => $current_text_id,
			'text' => $text,
			'flags' => ''
		);

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$dbw->insert(
			'text',
			array(
				'old_id' => $e['id'],
				'old_text' => $e['text'],
				'old_flags' => $e['flags']
			),
			__METHOD__
		);

		return $current_text_id;
	}

	function updateDeleted( $ns, $title, $dbw ) {
		global $wgDBname;
		$e = array(
			'ar_text' => '',
			'ar_flags' => '',
			'ar_namespace' => $ns,
			'ar_title' => $title
		);
		$e['ar_page_id'] = $this->getPageID( $ns, $title );
		if ( !$e['ar_page_id'] ) {
			# Can't continue without an id.
			return;
		}

		# Get and insert revision data
		$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
		$result = $dbr->select(
			'revision',
			array(
				'rev_comment',
				'rev_user',
				'rev_user_text',
				'rev_timestamp',
				'rev_minor_edit',
				'rev_id',
				'rev_text_id',
				'rev_deleted',
				'rev_len',
				'rev_parent_id',
				'rev_sha1'
			),
			array( 'rev_page' => $e['ar_page_id'] ),
			__METHOD__
		);
		foreach ( $result as $row ) {
			$e['ar_comment'] = $row->rev_comment;
			$e['ar_user'] = $row->rev_user;
			$e['ar_user_text'] = $row->rev_user_text;
			$e['ar_timestamp'] = $row->rev_timestamp;
			$e['ar_minor_edit'] = $row->rev_minor_edit;
			$e['ar_rev_id'] = $row->rev_id;
			$e['ar_text_id'] = $row->rev_text_id;
			$e['ar_deleted'] = $row->rev_deleted;
			$e['ar_len'] = $row->rev_len;
			$e['ar_parent_id'] = $row->rev_parent_id;
			$e['ar_sha1'] = $row->rev_sha1;

			$dbw->insert( 'archive', $e, __METHOD__ );
		}

		# Delete page and revision entries
		$dbw->delete(
			'page',
			array( 'page_id' => $e['ar_page_id'] ),
			__METHOD__
		);
		$dbw->delete(
			'revision',
			array( 'rev_page' => $e['ar_page_id'] ),
			__METHOD__
		);
		# Full clean up in general database rebuild.
	}

	function updateRestored( $ns, $title, $dbw ) {
		$pageID = $this->getPageID( $ns, $title );
		if ( $pageID ) {
			$dbw->delete(
				'archive',
				array(
					'ar_title' => $title,
					'ar_namespace' => $ns
				),
				__METHOD__
			);
		} else {
			$pageID = '';
		}
		$pageInfo = array(
			'pageid' => $pageID,
			'title' => $title,
			'ns' => $ns,
			'protection' => '',
			'redirect' => 0,
			'length' => null
		);
		return $pageInfo;
	}

	# For use with deleted crap that chucks the id; spotty at best.
	function getPageID( $ns, $title ) {
		$dbr = wfGetDB( DB_SLAVE );
		$result = $dbr->select(
			'page',
			array( 'page_id' ),
			array(
				'page_namespace' => $ns,
				'page_title' => $title,
			),
			__METHOD__
		);
		$row = $dbr->fetchObject( $result );
		if ( $row ) {
			return $row->page_id;
		} else {
			# Page not present; either moved or otherwise lost.
			return false;
		}
	}

	function sanitiseTitle( $ns, $title ) {
		if ( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );
		return $title;
	}
}

$maintClass = 'GrabNewText';
require_once RUN_MAINTENANCE_IF_MAIN;
