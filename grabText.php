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

require_once( 'Maintenance.php' );
require_once( 'mediawikibot.class.php' );

class GrabText extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab text from an external wiki and import it into one of ours.\nDon't use this in full on a large wiki; it will be incredibly slow.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		# $this->addOption( 'start', 'Revision at which to start', false, true );
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17T, etc); note that this cannot go back further than 1-3 months on most projects.', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'revisions', 'Which revisions to try getting: \'all\', \'live\' only, or \'deleted\' only (defaults to all)', false, true );
		$this->addOption( 'nsinfo', 'Print namespace info to add to LocalSettings.php', false, false );
		$this->addOption( 'protectioninfo', 'Import protection data.', false, false );
		$this->addOption( 'drcontinue', 'For the idiot brigade, api continue to restart deleted revision process', false, true );
		$this->addOption( 'carlb', 'Tells the script to use lower api limits', false, false );
	}

	public function execute() {
		global $bot, $timestamp, $wgDBname, $lastRevision, $gnashingOfTeeth;
		$url = $this->getOption( 'url' );
		if( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", true );
		}
		$onlyNamespaces = $this->getOption( 'nsinfo' );

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		$startDate = $this->getOption( 'startdate' );
		if ( $startDate && !wfTimestamp( TS_ISO_8601, $startDate ) ) {
			$this->error( "Invalid startdate format.\n", true );
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->error( "Invalid enddate format.\n", true );
			}
		} else {
			$timestamp = wfTimestampNow();
		}
		if( $this->getOption( 'revisions' ) == 'live' ) {
			$getDeleted = false;
			$getLive = true;
		} elseif( $this->getOption( 'revisions' ) == 'deleted' ) {
			$getDeleted = true;
			$getLive = false;
		} else {
			$getDeleted = true;
			$getLive = true;
		}
		if ( $onlyNamespaces ) {
			$getDeleted = false;
			$getLive = false;
			$startDate = false;
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
				print "Logged in as $user...\n";
				if ( $getDeleted ) {
					# Does the user have deletion rights?
					$params = array(
						'list' => 'allusers',
						'aulimit' => '1',
						'auprop' => 'rights',
						'aufrom' => $user
					);
					$result = $bot->query( $params );
					if ( !in_array( 'deletedtext', $result['query']['allusers'][0]['rights'] ) ) {
						if ( !$getLive ) {
							$this->error( "$user does not have required rights to fetch deleted revisions.", true );
						} else {
							print "Warning - $user does not have required rights to fetch deleted revisions. Fetching only live revisions.\n";
						}
					}
				}
			} elseif( $getDeleted ) {
				$this->error( "Failed to log in as $user.", true );
			} else {
				print "Warning - failed to log in as $user.\n";
			}
		} else {
			if( !$getLive && $getDeleted ) {
				$this->error( "Sysop login required for fetching deleted revisions.", true );
			}
			$bot = new MediaWikiBot(
				$url,
				'json',
				'',
				'',
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
		}

		$pageList = array();
		$this->output( "\n" );

		# Specified start - need to get pages from recentchanges and crap
		if ( $startDate ) {
			# Get pages list from logevents and recentchanges
			$blackList = array(); # Don't get new edits for these
			$more = true;
			$count = 0;
			# Workaround for the fact that this script doesn't work
			$gnashingOfTeeth = true;

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
/*			
			# Get changes
			$params = array(
				'list' => 'logevents',
				'ledir' => 'newer',
				'letype' => 'delete|move|import',
				'lelimit' => 'max',
				'leend' => wfTimestamp( TS_ISO_8601, $timestamp )
			);
			$lestart = null;
			$this->output( "Updating deleted and moved items...\n" );
			while ( $more ) {
				if ( $lestart === null ) {
					$params['lestart'] = wfTimestamp( TS_ISO_8601, $startDate );
				} else {
					$params['lestart'] = $lestart;
				}
				$result = $bot->query( $params );
				if ( empty( $result['query']['logevents'] ) ) {
					$this->output( "No changes found...\n", true );
				}
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

					}
					elseif ( $logEntry['action'] ==  'delete' ) {
						$this->output( "$ns:$title was deleted; updating....\n" );
						# Delete our copy, move revisions -> archive
						$this->updateDeleted( $ns, $title, $dbw );
					}
					elseif ( $logEntry['action'] == 'restore' ) {
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
				if ( isset( $result['query-continue'] ) ) {
					$lestart = $result['query-continue']['logevents']['lestart'];
				} else {
					$lestart = null;
				}
				$more = !( $lestart === null );
			}

			$this->output( "\n" );
*/
			# Get edits
			$params = array(
				'list' => 'recentchanges',
				'rcdir' => 'newer',
				'rctype' => 'edit|new',
				'rclimit' => 'max',
				'rcprop' => 'title|sizes|redirect|ids',
				'rcend' => $timestamp
			);
			$rcstart = wfTimestamp( TS_MW, $startDate );
			$count = 0;
			$more = true;
			
			$this->output( "Retreiving list of changed pages...\n" );
			while ( $more ) {
				$params['rcstart'] = $rcstart;

				$result = $bot->query( $params );
				if ( empty( $result['query']['recentchanges'] ) ) {
					$this->error( 'No changes found...', true );
				}
				foreach ( $result['query']['recentchanges'] as $entry ) {
					# new pages, new uploads, edited pages
					# while more, parse into $pagesList
					if ( ( $count % 500 ) == 0 ) {
						$this->output( "$count\n" );
					}
					
					$title = $entry['title'];
					$ns = $entry['ns'];
					if( $ns != 0 ) {
						$title = preg_replace( '/^[^:]*?:/', '', $title );
					}
					$title = str_replace( ' ', '_', $title );
					$listKey = $ns."cowz".$title;

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
			
			# Go to next thing
		}
		# No specified start - can get all pages as a list, start by getting namespace numbers...
		elseif( !$onlyNamespaces ) {

			$this->output( "Retreiving namespaces list...\n" );

			$params = array(
				'meta' => 'siteinfo',
				'siprop' => 'namespaces|statistics|namespacealiases'
			);
			$result = $bot->query( $params );
			$siteinfo = $result['query'];

			# No data - bail out early
			if ( empty( $siteinfo ) ) {
				$this->error( 'No siteinfo data found...', true );
			}

			$textNamespaces = array();
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special and weird Wikia namespaces
				if ( $ns  < 0 || $ns >= 400 ) {
					continue;
				}
				$textNamespaces[] = $ns;
			}
			if ( !$textNamespaces ) {
				$this->error( 'Got no namespaces...', true );
			}

			# Get list of live pages from namespaces and continue from there
			if ( $getLive ) {
				$pageCount = $siteinfo['statistics']['pages'];

				$this->output( "Generating page list - $pageCount expected...\n" );
				$pageCount = 0;
				$pageList = array();

				foreach ( $textNamespaces as $ns ) {
					$nsPageCount = 0;
					$more = true;
					$gapfrom = null;
					$params = array(
						'generator' => 'allpages',
						'gaplimit' => 'max',
						'prop' => 'info',
						'inprop' => 'protection',
						'gapnamespace' => $ns
					);
					do {
						# Note - 'gapfrom' became 'gapcontinue' in mw1.20, though the former is still supported.
						if ( $gapfrom === null ) {
							unset( $params['gapfrom'] );
						} else {
							$params['gapfrom'] = $gapfrom;
						}
						$result = $bot->query( $params );

						# Skip empty namespaces
						if ( isset( $result['query'] ) ) {
							$pages = $result['query']['pages'];

							$resultsCount = 0;
							foreach ( $pages as $page ) {
								$pageList[] = $page;
								$resultsCount++;
							}
							$nsPageCount += $resultsCount;

							# Try mw1.20+ version and fall back to old gapfrom if it fails.
							if ( isset( $result['query-continue'] ) ) {
								if ( isset( $result['query-continue']['allpages']['gapcontinue'] ) ) {
									$gapfrom = $result['query-continue']['allpages']['gapcontinue'];
								} else $gapfrom = $result['query-continue']['allpages']['gapfrom'];
							} else {
								$gapfrom = null;
							}
							$more = !( $gapfrom === null );
						} else {
							$more = false;
						}
					} while ( $more );

					$this->output( "$nsPageCount pages found in namespace $ns.\n" );
					$pageCount += $nsPageCount;
				}
				$this->output( "\nPage list saved - found $pageCount total pages.\n" );
				$this->output( "\n" );

				$this->output( "Saving all pages, including text, edit history and protection settings...\n" );

				$currentPage = 0;
				$this->output( "0 pages committed...\n" );
				foreach ( $pageList as $page ) {
					$this->processPage( $page );
					$currentPage++;
					if ( $currentPage % 500 == 0 ) {
						$this->output( "$currentPage\n" );
					}
				}

				$this->output( "\n" );
			}
			# Get deleted revisions
			if( $getDeleted ) {
				$this->output( "\nSaving deleted revisions...\n" );
				$revisions_processed = 0;

				foreach ( $textNamespaces as $ns ) {
					$more = true;
					$drcontinue = $this->getOption( 'drcontinue' );
					if ( !$drcontinue  ) {
						$drcontinue = null;
					} else {
						# Parse start namespace from input string and use
						# Length of namespace number
						$nsStart = strpos( $drcontinue, '|' );
						# Namespsace number
						if ( $nsStart == 0 ) {
							$nsStart = 0;
						} else {
							$nsStart = substr( $drcontinue, 0, $nsStart );
						}
						if ( $ns < $nsStart ) {
							print "Skipping $ns\n";
							continue;
						} else if ( $nsStart != $ns ) {
							$drcontinue = null;
						}
					}
					# Count revisions, except it actually gets chunks of revisions, so these are just chunks - either the entire page or up to 500 revisions of it at a time.
					$nsRevisions = 0;

					$params = array(
						'list' => 'deletedrevs',
						'drnamespace' => $ns,
						'drlimit' => 'max',
						'drdir' => 'newer',
						'drprop' => 'revid|user|userid|comment|minor|content|parentid',
					);
					if ( $this->getOption( 'carlb' ) ) {
						# 50 was apparently too much.
						$params['drlimit'] = 10;
					}

					do {
						if ( $drcontinue === null ) {
							unset( $params['drcontinue'] );
						} else {
							$params['drcontinue'] = $drcontinue;
						}
						$result = $bot->query( $params );
						if ( empty( $result ) ) {
							sleep( .5 );
							$this->output( "Bad result.\n" );
							continue;
						}

						$pageChunks = $result['query']['deletedrevs'];
						if ( empty( $pageChunks ) ) {
							$this->output( "No revisions found.\n" );
							$more = false;
						}

						foreach ( $pageChunks as $pageChunk ) {
							if ( $nsRevisions % 500 == 0 ) {
								$this->output( "$ns: $nsRevisions\n" );
							}
							$this->processDeletedRevisions( $pageChunk );

							if ( isset( $result['query-continue'] ) ) {
								$drcontinue = str_replace( '&', '%26', $result['query-continue']['deletedrevs']['drcontinue'] );
							} else {
								$drcontinue = null;
							}
							$more = !( $drcontinue === null );
							$nsRevisions ++;
						}
					} while ( $more );
					$this->output( "$nsRevisions chunks of revisions processed in namespace $ns.\n" );
					$revisions_processed += $nsRevisions;
				}

				$this->output( "\n" );
				$this->output( "Saved $revisions_processed deleted revisions.\n" );
			}
		}

		$this->parseNamespaces();

		# Done.
	}

	/**
	 * Handle an individual page.
	 *
	 * @param $page Array: array retrieved from the API, containing pageid,
	 *                     page title, namespace, protection status and more...
	 * @param $start Int: timestamp from which to get revisions; if this is
	 *                     defined, protection stuff is skipped.
	 */
	function processPage( $page, $start = null ) {
		global $wgDBname, $bot, $timestamp;

		$pageID = $page['pageid'];
		$title = $page['title'];
		$ns = $page['ns'];
		$localID = $pageID;
		$titleIsPresent = false;
		
		$this->output( "Processing page $pageID...\n" );

		# Trim and convert displayed title to database page title
		if( $ns != 0 ) {
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
						array( 'ORDER BY' => 'page_id desc' )
					);
					$localID = $resid + 1;
				}
			}
		}

		# Update page_restrictions
		# NOTE - this doesn't support if the protections are already there, just adds blindly
		# NOTE - move this to other function
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
			'rvend' => wfTimestamp( TS_ISO_8601, $timestamp )
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
	 * @param $revision Array: array retrieved from the API, containing the revision
	 *                    text, ID, timestamp, whether it was a minor edit or
	 *                    not and much more
	 * @param $page_e UNUSED
	 * @param $prev_rev_id Integer: previous revision ID (revision.rev_parent_id)
	 */
	function processRevision( $revision, $page_id, $prev_rev_id ) {
		global $wgLang, $wgDBname, $lastRevision, $gnashingOfTeeth;

		if ( $revision['revid'] <= $lastRevision) {
			# Oops?
			return false;
		}
		if ( $gnashingOfTeeth ) {
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

	# Add deleted revisions to the archive and text tables
	# Takes results in chunks because that's how the API returns pages - with chunks of revisions.
	function processDeletedRevisions( $pageChunk ) {
		global $wgContLang, $wgDBname, $prev_text_id;

		if ( !isset( $chunk ) ) {
			$chunk = 1;
		}

		$ns = $pageChunk['ns'];
		$title = $pageChunk['title'];
		if( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );

		$revisions = $pageChunk['revisions'];
		foreach ( $revisions as $revision ) {
			$text = $revision['*'];
			if ( isset( $revision['parentid'] ) ) {
				$parentID = $revision['parentid'];
			} else {
				$parentID = null;
			}

			$e = array(
				'namespace' => $ns,
				'title' => $title,
				'text' => '',
				'comment' => $wgContLang->truncate( $revision['comment'], 255 ),
				'user' => $revision['userid'],
				'user_text' => $revision['user'],
				'timestamp' => wfTimestamp( TS_MW, $revision['timestamp'] ),
				'minor_edit' => ( isset( $revision['minor'] ) ? 1 : 0 ),
				'flags' => '',
				'rev_id' => $revision['revid'],
				'text_id' => $this->storeText( $text ),
				'deleted' => 0,
				'len' => strlen( $text ),
				'page_id' => null,
				'parent_id' => $parentID
			);

			# $this->output( "Going to commit changes into the 'archive' table...\n" );

			$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
			$dbw->insert(
				'archive',
				array(
					'ar_namespace' => $e['namespace'],
					'ar_title' => $e['title'],
					'ar_text' => $e['text'],
					'ar_comment' => $e['comment'],
					'ar_user' => $e['user'],
					'ar_user_text' => $e['user_text'],
					'ar_timestamp' => $e['timestamp'],
					'ar_minor_edit' => $e['minor_edit'],
					'ar_flags' => $e['flags'],
					'ar_rev_id' => $e['rev_id'],
					'ar_text_id' => $e['text_id'],
					'ar_deleted' => $e['deleted'],
					'ar_len' => $e['len'],
					'ar_page_id' => $e['page_id'],
					'ar_parent_id' => $e['parent_id']
				),
				__METHOD__
			);
			$dbw->commit();

			# $this->output( "Changes committed to the database!\n" );
		}
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
		if( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );
		return $title;
	}

	# Custom namespaces - make a list as these will need to be added to the localsettings
	# May just be printing useless information, but more useful to have it available than not.
	function parseNamespaces() {
		global $bot;
		$params = array(
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|namespacealiases'
		);
		$result = $bot->query( $params );
		if ( !$result['query'] ) {
			$this->error( 'Got no namespaces...', true );
		}
		$namespaces = $result['query']['namespaces'];
		$customNamespaces = array();

		$contentNamespaces = array();	# $wgContentNamespaces[] = 500;
		$subpageNamespaces = array();	# $wgNamespacesWithSubpages[] = 500;
		foreach( array_keys( $namespaces ) as $ns ) {
			# Content?
			if ( isset( $namespaces[$ns]['content'] ) ) {
				$contentNamespaces[] = $ns;
			}
			# Subpages?
			if ( isset( $namespaces[$ns]['subpages'] ) ) {
				$subpageNamespaces[] = $ns;
			}
			if ( $ns >= 100 ) {
				$customNamespaces[$ns] = $namespaces[$ns]['canonical']; #name
				# print "$ns: {$siteinfo['namespaces'][$ns]['canonical']} \n";
			}
		}
		$namespaceAliases = array();	# $wgNamespaceAliases['WP'] = NS_PROJECT;
		foreach( $result['query']['namespacealiases'] as $nsa ) {
			$namespaceAliases[$nsa['*']] = $nsa['id'];
		}
		# Show stuff
			# $customNamespaces
			# $contentNamespaces
			# $subpageNamespaces
			# $namespaceAliases
	}
}

$maintClass = 'GrabText';
require_once( RUN_MAINTENANCE_IF_MAIN );
