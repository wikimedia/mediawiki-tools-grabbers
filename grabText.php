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

# Because we're not in maintenance
ini_set( 'include_path', dirname( __FILE__ ) . '/../maintenance' );

require_once( 'Maintenance.php' );
require_once( 'mediawikibot.class.php' );

class GrabText extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab text from an external wiki and import it into one of ours.\nDon't use this on a large wiki unless you absolutely must; it will be incredibly slow.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		# $this->addOption( 'start', 'Revision number at which to start', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'carlb', 'Tells the script to use lower api limits', false, false );
	}

	public function execute() {
		global $bot, $endDate, $wgDBname, $lastRevision, $skipped;
		$url = $this->getOption( 'url' );
		if( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", true );
		}
		$carlb = $this->getOption( 'carlb' );

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
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
				print "Logged in as $user...\n";
			} else {
				print "Warning - failed to log in as $user.\n";
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

		$skipped = array();
		$pageList = array();
		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

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

		# Print skipped list
		$this->output( "\nPage IDs skipped (not found):" );
		foreach ( $skipped as $pageID ) {
			$this->output( "$pageID\n" );
		}

		$this->output( "\n" );
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
		global $wgDBname, $bot, $endDate, $carlb, $skipped;

		$pageID = $page['pageid'];
		$title = $page['title'];
		$ns = $page['ns'];
		$localID = $pageID;
		$titleIsPresent = false;

		$this->output( "Processing page $pageID: $title\n" );

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
			'random' => wfRandom(),
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

		if ( $carlb ) {
			$rvmax = 10;
		} else {
			$rvmax = 'max';
		}

		$params = array(
			'prop' => 'revisions',
			'pageids' => $pageID,
			'rvlimit' => $rvmax,
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags',
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
				$last_rev_info = $this->processPageResult( $result, $localID, $last_rev_id );
			} else {
				if ( $params['rvlimit'] == 1 ) {
					$this->output( "Page id $pageID not found.\n" );
					return;
				} else {
					$params['rvlimit'] = 1;
					$result = $bot->query( $params );
					if ( isset( $result['query']['pages'] ) ) {
						$last_rev_info = $this->processPageResult( $result, $localID, $last_rev_id );
					} else {
						$this->output( "Page id $pageID not found.\n" );
						$skipped[] = $pageID;
						return;
					}
				}
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
	 * Take the result from revision request and call processRevision
	 *
	 */
	function processPageResult( $result, $localID, $last_rev_id ) {
		$revisions = array_values( $result['query']['pages'] );
		$revisions = $revisions[0]['revisions'];

		foreach ( $revisions as $revision ) {
			$last_rev_info = $this->processRevision( $revision, $localID, $last_rev_id );
		}
		return $last_rev_info;
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
		global $wgLang, $wgDBname, $lastRevision;

		if ( $revision['revid'] <= $lastRevision ) {
			# Oops? Too recent.
			return false;
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$revdeleted = 0;
		if ( isset( $revision['userhidden'] ) ) {
			$revdeleted = $revdeleted | 4;
			$revision['user'] = 'username removed';
			$revision['userid'] = 0;
		}
		if ( isset( $revision['commenthidden'] ) ) {
			$revdeleted = $revdeleted | 2;
			$revision['comment'] = 'edit summary removed';
		}
		if ( isset( $revision['texthidden'] ) ) {
			$revdeleted = $revdeleted | 1;
			$revision['*'] = 'This content has been removed.';
		}

		# Workaround check if it's already there; disabled for now
		if ( false ) {
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
		$tags = $revision['tags'];

		$e = array(
			'id' => $revision['revid'],
			'parent_id' => $revision['parentid'],
			'page' => $page_id,
			'text_id' => $this->storeText( $text ),
			'comment' => $comment,
			'user' => $revision['userid'], # May not be accurate to the new wiki, obvious, but whatever.
			'user_text' => $revision['user'],
			'timestamp' => wfTimestamp( TS_MW, $revision['timestamp'] ),
			'minor_edit' => ( isset( $reisionv['minor'] ) ? 1 : 0 ),
			'deleted' => $revdeleted,
			'len' => strlen( $text ),
			'parent_id' => ( $prev_rev_id || 0 )
		);

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		# insert revisions
		$dbw->insert(
			'revision',
			array(
				'rev_id' => $e['id'],
				'rev_parent_id' => $e['parentid'],
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
		# Insert tags, if any
		if ( count( $tags ) ) {
			$tagBlob = '';
			foreach ( $tags as $tag ) {
				$dbw->insert(
					'change_tags',
					array(
						'ct_rev_id' => $e['id'],
						'ct_tag' => $tag,
					),
					__METHOD__
				);
				if ( $tagBlob == '' ) {
					$tagBlob = $tag;
				} else {
					$tagBlob = "$tagBlob, $tag";
				}
			}
			$dbw->insert(
				'tag_summary',
				array(
					'ts_rev_id' => $e['id'],
					'ts_tags' => $tagBlob,
				),
				__METHOD__
			);
		}
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

	function sanitiseTitle( $ns, $title ) {
		if( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );
		return $title;
	}

}

$maintClass = 'GrabText';
require_once( RUN_MAINTENANCE_IF_MAIN );
