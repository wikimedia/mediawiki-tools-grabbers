<?php
/**
 * Maintenance script to grab text from a wiki and import it to another wiki.
 * Translated from Edward Chernenko's Perl version (text.pl).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.0
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

	/**
	 * Whether our wiki supports page counters, to use counters if remote wiki also has them
	 *
	 * @var bool
	 */
	protected $supportsCounters;

	/**
	 * Start date
	 *
	 * @var string
	 */
	protected $startDate;

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Last revision in the current db
	 *
	 * @var int
	 */
	protected $lastRevision = 0;

	/**
	 * Last text id in the current db
	 *
	 * @var int
	 */
	protected $lastTextId = 0;

	/**
	 * Array of namespaces to grab changes
	 *
	 * @var Array
	 */
	protected $namespaces = null;

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
	 * A list of page ids already processed. Don't get new edits for those
	 *
	 * @var array
	 */
	protected $pagesProcessed = array();

	/**
	 * A list of page ids already processed for protection
	 *
	 * @var array
	 */
	protected $pagesProtected = array();

	/**
	 * Used to know if the current user can see deleted revisions on remote wiki,
	 * to also gather them to be available on local wiki
	 *
	 * @var bool
	 */
	protected $canSeeDeletedRevs = true;

	/**
	 * A list of page titles involved in moves, that need special treatment in deletes/restores
	 *
	 * @var array
	 */
	protected $movedTitles = array();

	/**
	 * The target wiki is on Wikia
	 *
	 * @var boolean
	 */
	protected $isWikia;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab new changes from an external wiki and add it over an imported dump.\nFor use when the available dump is slightly out of date.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17T, etc); note that this cannot go back further than 1-3 months on most projects', true, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp. May leave pages in inconsistent state if page moves are involved', false, true );
		$this->addOption( 'namespaces', 'A pipe-separated list of namespaces (ID) to grab changes from. Defaults to all namespaces', false, true );
		$this->addOption( 'wikia', 'Set this param if the target wiki is on Wikia, to perform some optimizations', false, false );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", 1 );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$this->startDate = $this->getOption( 'startdate' );
		if ( $this->startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $this->startDate ) ) {
				$this->error( "Invalid startdate format.\n", 1 );
			}
		} else {
			$this->error( "A timestamp to start from is required.\n", 1 );
		}
		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $this->endDate ) ) {
				$this->error( "Invalid enddate format.\n", 1 );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		if ( $this->hasOption( 'namespaces' ) ) {
			$this->namespaces = explode( '|', $this->getOption( 'namespaces' ) );
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

		# Check if wiki supports page counters (removed from core in 1.25)
		$this->supportsCounters = $this->dbw->fieldExists( 'page', 'page_counter', __METHOD__ );
		$this->isWikia = $this->getOption( 'wikia' );

		# Get last revision id to avoid duplicates
		$this->lastRevision = (int)$this->dbw->selectField(
			'revision',
			'rev_id',
			array(),
			__METHOD__,
			array( 'ORDER BY' => 'rev_id DESC' )
		);

		# Get last text id
		$this->lastTextId = (int)$this->dbw->selectField(
			'text',
			'old_id',
			array(),
			__METHOD__,
			array( 'ORDER BY' => 'old_id DESC' )
		);

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
				$this->error("Failed to log in as $user.", 1);
			}
		} else {
			$this->canSeeDeletedRevs = false;
			$this->bot = new MediaWikiBot(
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

		$this->output( "\nDone.\n" );
		# Done.
	}

	/**
	 * Get page edits and creations
	 */
	function processRecentChanges() {
		$more = true;
		$count = 0;

		# Get edits
		$params = array(
			'list' => 'recentchanges',
			'rcdir' => 'newer',
			'rctype' => 'edit|new',
			'rclimit' => 'max',
			'rcprop' => 'title|sizes|redirect|ids',
			'rcend' => $this->endDate
		);
		$rcstart = $this->startDate;
		$count = 0;
		$more = true;
		if ( !is_null( $this->namespaces ) ) {
			$params['rcnamespace'] = implode( '|', $this->namespaces );
		}

		$this->output( "Retreiving list of changed pages...\n" );
		while ( $more ) {
			$params['rcstart'] = $rcstart;

			$result = $this->bot->query( $params );
			if ( empty( $result['query']['recentchanges'] ) ) {
				$this->output( 'No changes found...' );
			}
			foreach ( $result['query']['recentchanges'] as $entry ) {
				# new pages, new uploads, edited pages
				# while more, parse into $pagesList
				if ( ( $count % 500 ) == 0 ) {
					$this->output( "$count\n" );
				}

				$title = $entry['title'];
				$ns = $entry['ns'];
				$title = $this->sanitiseTitle( $ns, $title );

				if ( in_array( $entry['pageid'], $this->pagesProcessed ) ) {
					# Already done; continue
					continue;
				}
				$this->pagesProcessed[] = $entry['pageid'];

				$pageInfo = array(
					'pageid' => $entry['pageid'],
					'title' => $entry['title'],
					'ns' => $ns,
					'protection' => null,
				);
				if ( in_array( $entry['pageid'], $this->pagesProtected ) ) {
					$pageInfo['protection'] = true;
					# Remove from the array so we don't attempt to insert restrictions again
					array_slice( $this->pagesProtected, array_search( $entry['pageid'], $this->pagesProtected ), 1 );
				}
				$this->processPage( $pageInfo, $this->startDate );

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
		$params = array(
			'list' => 'logevents',
			'ledir' => 'newer',
			'lelimit' => 'max',
			'leend' => $this->endDate
		);

		if ( $this->isWikia ) {
			# letype doesn't accept multiple values. Multiple values work only
			# on wikia but breaks on other standard wikis
			$params['letype'] = 'delete|upload|move|protect';
		}

		$lestart = null;
		$count = 0;
		$more = true;

		$this->output( "Updating deleted and moved items...\n" );
		while ( $more ) {
			if ( $lestart === null ) {
				$params['lestart'] = $this->startDate;
			} else {
				$params['lestart'] = $lestart;
			}
			$result = $this->bot->query( $params );
			if ( empty( $result['query']['logevents'] ) ) {
				$this->output( "No changes found...\n" );
			} else {
				foreach ( $result['query']['logevents'] as $logEntry ) {
					if ( ( $count % 500 ) == 0 ) {
						$this->output( "$count\n" );
					}
					$pageID = $logEntry['pageid'];
					$title = $logEntry['title'];
					$ns = $logEntry['ns'];
					$title = $this->sanitiseTitle( $ns, $title );
					$sourceTitle = Title::makeTitle( $ns, $title );
					$newns = -1;
					if ( $logEntry['type'] == 'move' ) {
						if ( isset( $logEntry['move'] ) ) {
							$newns = $logEntry['move']['new_ns'];
						} else {
							$newns = $logEntry['params']['target_ns'];
						}
					}
					if ( !is_null( $this->namespaces ) && !in_array( $ns, $this->namespaces ) && !in_array( $newns, $this->namespaces ) ) {
						continue;
					}

					if ( $logEntry['type'] == 'move' ) {
						# Move our copy
						# New title
						if ( isset( $logEntry['move'] ) ) {
							$newTitle = $this->sanitiseTitle( $newns, $logEntry['move']['new_title'] );
						} else {
							$newTitle = $this->sanitiseTitle( $newns, $logEntry['params']['target_title'] );
						}
						$destTitle = Title::makeTitle( $newns, $newTitle );

						$this->output( "$sourceTitle was moved to $destTitle; updating...\n" );
						$this->processMove( $ns, $title );
						$this->processMove( $newns, $newTitle );

					} elseif ( $logEntry['type'] == 'delete' && $logEntry['action'] == 'delete' ) {
						if ( ! in_array( (string)$sourceTitle, $this->movedTitles ) ) {
							$this->output( "$sourceTitle was deleted; updating...\n" );
							# Delete our copy, move revisions -> archive
							$pageID = $this->getPageID( $ns, $title );
							if ( ! $pageID ) {
								# Page may be created and then deleted before we processed recentchanges
								$this->output( "Page $sourceTitle not found in database, nothing to delete.\n" );
								# Update deleted revisions from remote wiki anyway
								$this->updateDeletedRevs( $ns, $title );
							} else {
								$this->archiveAndDeletePage( $pageID, $ns, $title );
							}
						} else {
							$this->output( "$sourceTitle was deleted; updating only archived revisions...\n" );
							# we've already processed this title as part of a page move.
							# It may not be the current page anymore, so just update the archived revisions
							$this->updateDeletedRevs( $ns, $title );
						}
					} elseif ( $logEntry['type'] == 'delete' && $logEntry['action'] == 'restore' ) {
						$this->output( "$sourceTitle was undeleted; updating....\n" );
						# Remove any revisions from archive, and process as new
						$this->updateRestored( $ns, $title );
						$pageInfo = array(
							'pageid' => $pageID,
							'title' => $title,
							'ns' => $ns,
							'protection' => true,
						);
						$this->processPage( $pageInfo, null, false );
						if ( ! in_array( $pageID, $this->pagesProcessed ) ) {
							$this->pagesProcessed[] = $pageID;
						}
						$this->output( "$sourceTitle processed.\n" );
					} elseif ( $logEntry['type'] == 'upload' ) { # action can be upload or reupload
						$this->output( "$sourceTitle was imported; updating....\n" );
						# Process as new
						if ( !$pageID ) {
							$pageID = null;
						}
						$pageInfo = array(
							'pageid' => $pageID,
							'title' => $title,
							'ns' => $ns,
							'protection' => true,
						);
						$this->processPage( $pageInfo );
						if ( ! in_array( $pageID, $this->pagesProcessed ) ) {
							$this->pagesProcessed[] = $pageID;
						}
					} elseif ( $logEntry['type'] == 'protect' ) {
						# Don't bother if there's no pageID
						if ( $pageID ) {
							$pageInfo = array(
								'pageid' => $pageID,
								'title' => $title,
								'ns' => $ns,
								'protection' => null,
							);
							if ( $logEntry['action'] == 'unprotect' ) {
								# Remove protection info
								$this->dbw->delete(
									'page_restrictions',
									array( 'pr_page' => $pageID ),
									__METHOD__
								);
							} elseif ( ! in_array( $pageID, $this->pagesProtected ) ) {
								$pageInfo['protection'] = true;
							}
							$this->processPage( $pageInfo, $this->startDate );
							if ( ! in_array( $pageID, $this->pagesProcessed ) ) {
								$this->pagesProcessed[] = $pageID;
							}
						}
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
	 * @param array $page: Array retrieved from the API, containing pageid,
	 *     page title, namespace, protection status and more...
	 * @param int|null $start: Timestamp from which to get revisions; if this is
	 *     defined, protection stuff is skipped.
	 * @param bool|null $skipPrevious: Skip revision ids lower than the largest revision
	 *     existing when the script started, a shortcut to not process old
	 *     revisions that should be already in the database
	 */
	function processPage( $page, $start = null, $skipPrevious = true ) {
		global $wgContentHandlerUseDB;

		$pageID = $page['pageid'];
		$pageTitle = null;
		$pageDesignation = "id $pageID";
		if ( ! $pageID ) {
			# We don't have page id... we need to use page title
			$pageTitle = (string)Title::makeTitle( $page['ns'], $page['title'] );
			$pageDesignation = $pageTitle;
		}

		$this->output( "Processing page $pageDesignation...\n" );

		$params = array(
			'prop' => 'info|revisions',
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $this->endDate )
		);
		if ( $pageID ) {
			$params['pageids'] = $pageID;
		} else {
			# We don't have page id... we need to use page title
			$params['titles'] = $pageTitle;
		}
		if ( $start ) {
			$params['rvstart'] = wfTimestamp( TS_ISO_8601, $start );
		}
		if ( $page['protection'] ) {
			$params['inprop'] = 'protection';
		}
		if ( $wgContentHandlerUseDB ) {
			$params['rvprop'] = $params['rvprop'] . '|contentmodel';
		}

		$result = $this->bot->query( $params );

		if ( ! $result || isset( $result['error'] ) ) {
			$this->error( "Error getting revision information from API for page $pageDesignation.", 1 );
			return;
		}

		if ( isset( $params['inprop'] ) ) {
			unset( $params['inprop'] );
		}

		if ( $start ) {
			# start and the continuation parameter cannot be used together, so we remove it for next requests
			unset( $params['rvstart'] );
		}

		$info_pages = array_values( $result['query']['pages'] );
		if ( isset( $info_pages[0]['missing'] ) ) {
			$this->output( "Page $pageDesignation not found.\n" );
			return;
		}

		if ( !$pageID ) {
			$pageID = $info_pages[0]['pageid'];
		}

		$page_e = array(
			'namespace' => null,
			'title' => null,
			'restrictions' => '',
			'counter' => 0,
			'is_redirect' => 0,
			'is_new' => 0,
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => 0,
			'content_model' => null
		);
		# Trim and convert displayed title to database page title
		# Get it from the returned value from api
		$page_e['namespace'] = $info_pages[0]['ns'];
		$page_e['title'] = $this->sanitiseTitle( $info_pages[0]['ns'], $info_pages[0]['title'] );

		# Get other information from api info
		$page_e['is_redirect'] = ( isset( $info_pages[0]['redirect'] ) ? 1 : 0 );
		$page_e['is_new'] = ( isset( $info_pages[0]['new'] ) ? 1 : 0 );
		$page_e['len'] = $info_pages[0]['length'];
		$page_e['counter'] = ( isset( $info_pages[0]['counter'] ) ? $info_pages[0]['counter'] : 0 );
		$page_e['latest'] = $info_pages[0]['lastrevid'];
		$defaultModel = null;
		if ( $wgContentHandlerUseDB && isset( $info_pages[0]['contentmodel'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			#$defaultModel = ContentHandler::getDefaultModelFor( Title:makeTitle( $page_e['namespace'], $page_e['title'] ) );
			$defaultModel = MWNamespace::getNamespaceContentModel( $info_pages[0]['ns'] ) || CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $info_pages[0]['contentmodel'] ) {
				$page_e['content_model'] = $info_pages[0]['contentmodel'];
			}
		}

		# Check if page is present
		$pageIsPresent = false;
		$rowCount = $this->dbw->selectRowCount(
			'page',
			'page_id',
			array( 'page_id' => $pageID ),
			__METHOD__
		);
		if ( $rowCount ) {
			$pageIsPresent = true;
		}

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		if ( ! $pageIsPresent ) {
			$conflictingPageID = $this->getPageID( $page_e['namespace'], $page_e['title'] );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $conflictingPageID, $page_e['namespace'], $page_e['title'] );
			}
		}

		# Update page_restrictions (only if requested)
		if ( isset( $info_pages[0]['protection'] ) ) {
			$this->output( "Setting page_restrictions changes on page_id $pageID.\n" );
			# Delete first any existing protection
			$this->dbw->delete(
				'page_restrictions',
				array( 'pr_page' => $pageID ),
				__METHOD__
			);
			# insert current restrictions
			foreach ( $info_pages[0]['protection'] as $prot ) {
				# Skip protections inherited from cascade protections
				if ( !isset( $prot['source'] ) ) {
					$e = array(
						'page' => $pageID,
						'type' => $prot['type'],
						'level' => $prot['level'],
						'cascade' => (int)isset( $prot['cascade'] ),
						'user' => null,
						'expiry' => ( $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] ) )
					);
					$this->dbw->insert(
						'page_restrictions',
						array(
							'pr_page' => $e['page'],
							'pr_type' => $e['type'],
							'pr_level' => $e['level'],
							'pr_cascade' => $e['cascade'],
							'pr_user' => $e['user'],
							'pr_expiry' => $e['expiry']
						),
						__METHOD__
					);
				}
			}
		}

		$revisionsProcessed = false;
		while ( true ) {
			foreach ( $info_pages[0]['revisions'] as $revision ) {
				if ( ! $skipPrevious || $revision['revid'] > $this->lastRevision) {
					$revisionsProcessed = $this->processRevision( $revision, $pageID, $defaultModel ) || $revisionsProcessed;
				} else {
					$this->output( sprintf( "Skipping the processRevision of revision %d minor or equal to the last revision of the database (%d).\n", $revision['revid'], $this->lastRevision ) );
				}
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['revisions'] ) ) {
				# Add continuation parameters
				$params = array_merge( $params, $result['query-continue']['revisions'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( ! $result || isset( $result['error'] ) ) {
				$this->error( "Error getting revision information from API for page $pageDesignation.", 1 );
				return;
			}

			$info_pages = array_values( $result['query']['pages'] );
		}

		if ( !$revisionsProcessed ) {
			# We already processed the page before? page doesn't need updating, then
			return;
		}

		$insert_fields = array(
			'page_namespace' => $page_e['namespace'],
			'page_title' => $page_e['title'],
			'page_restrictions' => $page_e['restrictions'],
			'page_is_redirect' => $page_e['is_redirect'],
			'page_is_new' => $page_e['is_new'],
			'page_random' => $page_e['random'],
			'page_touched' => $page_e['touched'],
			'page_latest' => $page_e['latest'],
			'page_len' => $page_e['len'],
			'page_content_model' => $page_e['content_model']
		);
		if ( $this->supportsCounters && $page_e['counter'] ) {
			$insert_fields['page_counter'] = $page_e['counter'];
		}
		if ( ! $pageIsPresent ) {
			# insert if not present
			$this->output( "Inserting page entry $pageID\n" );
			$insert_fields['page_id'] = $pageID;
			$this->dbw->insert(
				'page',
				$insert_fields,
				__METHOD__
			);
		} else {
			# update existing
			$this->output( "Updating page entry $pageID\n" );
			$this->dbw->update(
				'page',
				$insert_fields,
				array( 'page_id' => $pageID ),
				__METHOD__
			);
		}
		$this->dbw->commit();
	}

	/**
	 * Process an individual page revision.
	 *
	 * @param array $revision Array retrieved from the API, containing the revision
	 *     text, ID, timestamp, whether it was a minor edit or not and much more
	 * @param int $page_id Page ID number of the revision we are going to insert
	 * @param string $defaultModel Default content model for this page
	 * @return bool Whether revision has been inserted or not
	 */
	function processRevision( $revision, $page_id, $defaultModel ) {
		global $wgContLang, $wgContentHandlerUseDB;
		$revid = $revision['revid'];

		# Workaround check if it's already there.
		$rowCount = $this->dbw->selectRowCount(
			'revision',
			'rev_id',
			array( 'rev_id' => $revid ),
			__METHOD__
		);
		if ( $rowCount ) {
			# Already in database
			$this->output( "Revision $revid is already in the database. Skipped.\n" );
			return false;
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$revdeleted = 0;
		if ( isset( $revision['userhidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_USER;
			if ( !isset( $revision['user'] ) ) {
				$revision['user'] = ''; # username removed
			}
			if ( !isset( $revision['userid'] ) ) {
				$revision['userid'] = 0;
			}
		}
		if ( isset( $revision['commenthidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_COMMENT;
			$comment = ''; # edit summary removed
		} else {
			$comment = $revision['comment'];
			if ( $comment ) {
				$comment = $wgContLang->truncate( $comment, 255 );
			} else {
				$comment = '';
			}
		}
		if ( isset( $revision['texthidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_TEXT;
			$text = ''; # This content has been removed.
		} else {
			$text = $revision['*'];
		}
		if ( isset ( $revision['suppressed'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_RESTRICTED;
		}

		$e = array(
			'id' => $revid,
			'page' => $page_id,
			'comment' => $comment,
			'user' => $revision['userid'], # May not be accurate to the new wiki, obvious, but whatever.
			'user_text' => $revision['user'],
			'timestamp' => wfTimestamp( TS_MW, $revision['timestamp'] ),
			'minor_edit' => ( isset( $revision['minor'] ) ? 1 : 0 ),
			'deleted' => $revdeleted,
			'len' => strlen( $text ),
			'parent_id' => $revision['parentid'],
			# Do not attempt to get the field from api, because it's not what
			# you'd expect. See T75411
			'sha1' => Revision::base36Sha1( $text ),
			'content_model' => null,
			'content_format' => null
		);

		$e['text_id'] = $this->storeText( $text, $e['sha1'], $page_id, $revid );

		# Set content model
		if ( $wgContentHandlerUseDB && isset( $revision['contentmodel'] ) ) {
			# Set only if not the default content model
			if ( $defaultModel != $revision['contentmodel'] ) {
				$e['content_model'] = $revision['contentmodel'];
				$defaultFormat = ContentHandler::getForModelID( $defaultModel )->getDefaultFormat();
				if ( $defaultFormat != $revision['contentformat'] ) {
					$e['content_format'] = $revision['contentformat'];
				}
			}
		}

		$insert_fields = array(
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
			'rev_sha1' => $e['sha1'],
			'rev_content_model' => $e['content_model'],
			'rev_content_format' => $e['content_format'],
		);

		$this->output( sprintf( "Inserting revision %s\n", $e['id'] ) );
		$this->dbw->insert(
			'revision',
			$insert_fields,
			__METHOD__
		);

		# Insert tags, if any
		if ( isset( $revision['tags'] ) && count( $revision['tags'] ) > 0 ) {
			foreach ( $revision['tags'] as $tag ) {
				$this->dbw->insert(
					'change_tag',
					array(
						'ct_rev_id' => $e['id'],
						'ct_tag' => $tag,
					),
					__METHOD__
				);
			}
			$this->dbw->insert(
				'tag_summary',
				array(
					'ts_rev_id' => $e['id'],
					'ts_tags' => implode( ',', $revision['tags'] ),
				),
				__METHOD__
			);
		}

		$this->dbw->commit();

		return true;
	}

	/**
	 * Stores revision text in the text table. If the page ID is provided and
	 * a revision exists with the same text, it will reuse it instead of
	 * creating a duplicate entry in text table.
	 * If configured, stores text in external storage
	 *
	 * @param string $text Text of the revision to store
	 * @param string $sha1 computed sha1 of the text
	 * @param int $pageID page id of the revision, used to return the
	 *            previous revision text if it's the same (optional)
	 * @param int $revisionID revision id (optional)
	 * @return int text id of the inserted text
	 */
	function storeText( $text, $sha1, $pageID = 0, $revisionID = 0 ) {
		global $wgDefaultExternalStore;

		if ( $pageID ) {
			# Check first if the text already exists on any revision of the current page,
			# to reuse text rows on page moves, protections, etc
			# Return the previous revision from that page
			$row = $this->dbw->selectRow(
				array( 'revision' ),
				array( 'rev_id', 'rev_sha1', 'rev_text_id' ),
				"rev_page = $pageID AND rev_id <= $revisionID",
				__METHOD__,
				array(
					'LIMIT' => 1,
					'ORDER BY' => 'rev_id DESC'
				)
			);

			if ( $row && $row->rev_sha1 == $sha1 ) {
				# Return the existing text id instead of creating a new one
				return $row->rev_text_id;
			}
		}

		$this->lastTextId++;

		$flags = Revision::compressRevisionText( $text );

		# Write to external storage if required
		if ( $wgDefaultExternalStore ) {
			# Store and get the URL
			$text = ExternalStore::insertToDefault( $text );
			if ( !$text ) {
				throw new MWException( "Unable to store text to external storage" );
			}
			if ( $flags ) {
				$flags .= ',';
			}
			$flags .= 'external';
		}

		$e = array(
			'id' => $this->lastTextId,
			'text' => $text,
			'flags' => $flags
		);

		$this->dbw->insert(
			'text',
			array(
				'old_id' => $e['id'],
				'old_text' => $e['text'],
				'old_flags' => $e['flags']
			),
			__METHOD__
		);

		return $e['id'];
	}

	/**
	 * Copies revisions to archive and then deletes the page and revisions
	 */
	function archiveAndDeletePage( $pageID, $ns, $title ) {
		$e = array(
			'ar_page_id' => $pageID,
			'ar_namespace' => $ns,
			'ar_title' => $title
		);

		# Get and insert revision data
		$result = $this->dbw->select(
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
				'rev_sha1',
				'rev_content_model',
				'rev_content_format'
			),
			array( 'rev_page' => $pageID ),
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
			$e['ar_content_model'] = $row->rev_content_model;
			$e['ar_content_format'] = $row->rev_content_format;

			$this->dbw->insert( 'archive', $e, __METHOD__ );
		}

		# Delete page and revision entries
		$this->dbw->delete(
			'page',
			array( 'page_id' => $pageID ),
			__METHOD__
		);
		$this->dbw->delete(
			'revision',
			array( 'rev_page' => $pageID ),
			__METHOD__
		);
		# Also delete any restrictions
		$this->dbw->delete(
			'page_restrictions',
			array( 'pr_page' => $pageID ),
			__METHOD__
		);
		# Full clean up in general database rebuild.
	}

	function updateRestored( $ns, $title ) {
		# Get a reference of the archived revisions text id's we're going to delete
		# Delete existing deleted revisions for page
		$this->dbw->delete(
			'archive',
			array(
				'ar_title' => $title,
				'ar_namespace' => $ns
			),
			__METHOD__
		);
		$this->updateDeletedRevs( $ns, $title );
	}

	/**
	 * Get deleted revisions of a particular title on remote wiki, and inserts
	 * them on the archive table if they don't exist already
	 *
	 * @param int $ns Namespace of the restored page
	 * @param string $title Title of the restored page
	 **/
	function updateDeletedRevs( $ns, $title ) {
		$pageTitle = Title::makeTitle( $ns, $title );
		if ( ! $this->canSeeDeletedRevs ) {
			$this->output( "Unable to see deleted revisions for title $pageTitle\n" );
			return;
		}

		$params = array(
			'list' => 'deletedrevs',
			'titles' => (string)$pageTitle,
			'drprop' => 'revid|parentid|user|userid|comment|minor|len|content|tags',
			'drlimit' => 'max',
			'drdir' => 'newer'
		);

		$result = $this->bot->query( $params );

		if ( ! $result || isset( $result['error'] ) ) {
			if ( isset( $result['error'] ) && $result['error']['code'] == 'drpermissiondenied' ) {
				$this->output( "Warning: Current user can't see deleted revisions.\n" .
					"Unable to see deleted revisions for title $pageTitle\n" );
				$this->canSeeDeletedRevs = false;
				return;
			}
			$this->error( "Error getting deleted revision information from API for page $pageTitle.", 1 );
			return;
		}

		if ( count( $result['query']['deletedrevs'] ) === 0 ) {
			# No deleted revisions for that title, nothing to do
			return;
		}

		$info_deleted = $result['query']['deletedrevs'][0];

		while ( true ) {
			foreach ( $info_deleted['revisions'] as $revision ) {
				$this->processDeletedRevision( $revision, $ns, $title );
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['deletedrevs'] ) ) {
				# Add continuation parameters
				$params = array_merge( $params, $result['query-continue']['deletedrevs'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( ! $result || isset( $result['error'] ) ) {
				$this->error( "Error getting deleted revision information from API for page $pageTitle.", 1 );
				return;
			}

			$info_deleted = $result['query']['deletedrevs'][0];
		}
	}

	/**
	 * Insert a given deleted revision obtained from an api requested
	 * to the database.
	 * Checks if revision already exists on archive
	 *
	 * @param array $revision array retrieved from the API, containing the revision
	 *     text, ID, timestamp, whether it was a minor edit or not and much more
	 * @param int $ns Namespace number of the deleted revision
	 * @param string $title Title of the deleted revision
	 */
	function processDeletedRevision( $revision, $ns, $title ) {
		global $wgContLang;

		# Check if archived revision is already there to prevent duplicate entries
		if ( $revision['revid'] ) {
			$count = $this->dbw->selectRowCount(
				'archive',
				'1',
				array( 'ar_rev_id' => $revision['revid'] ),
				__METHOD__
			);
			if ( $count > 0 ) {
				return;
			}
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$revdeleted = 0;
		if ( isset( $revision['userhidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_USER;
			if ( !isset( $revision['user'] ) ) {
				$revision['user'] = ''; # username removed
			}
			if ( !isset( $revision['userid'] ) ) {
				$revision['userid'] = 0;
			}
		}
		if ( isset( $revision['commenthidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_COMMENT;
			$comment = ''; # edit summary removed
		} else {
			$comment = $revision['comment'];
			if ( $comment ) {
				$comment = $wgContLang->truncate( $comment, 255 );
			} else {
				$comment = '';
			}
		}
		if ( isset( $revision['texthidden'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_TEXT;
			$text = ''; # This content has been removed.
		} else {
			$text = $revision['*'];
		}
		if ( isset ( $revision['suppressed'] ) ) {
			$revdeleted = $revdeleted | Revision::DELETED_RESTRICTED;
		}

		$e = array(
			'ns' => $ns,
			'title' => $title,
			'id' => $revision['revid'],
			'comment' => $comment,
			'user' => $revision['userid'], # May not be accurate to the new wiki, obvious, but whatever.
			'user_text' => $revision['user'],
			'timestamp' => wfTimestamp( TS_MW, $revision['timestamp'] ),
			'minor_edit' => ( isset( $revision['minor'] ) ? 1 : 0 ),
			'deleted' => $revdeleted,
			'len' => strlen( $text ),
			'parent_id' => $revision['parentid'],
			'sha1' => Revision::base36Sha1( $text ),
			'content_model' => null, # Content handler not available for deleted revisions
			'content_format' => null
		);

		$e['text_id'] = $this->storeText( $text, $e['sha1'] );

		$insert_fields = array(
			'ar_namespace' => $ns,
			'ar_title' => $title,
			'ar_rev_id' => $e['id'],
			'ar_comment' => $e['comment'],
			'ar_user' => $e['user'],
			'ar_user_text' => $e['user_text'],
			'ar_timestamp' => $e['timestamp'],
			'ar_minor_edit' => $e['minor_edit'],
			'ar_text_id' => $e['text_id'],
			'ar_deleted' => $e['deleted'],
			'ar_len' => $e['len'],
			#'ar_page_id' => NULL, # Not requred and unreliable from api
			'ar_parent_id' => $e['parent_id'],
			'ar_sha1' => $e['sha1'],
			'ar_content_model' => $e['content_model'],
			'ar_content_format' => $e['content_format']
		);

		$this->output( sprintf( "Inserting deleted revision %s\n", $e['id'] ) );
		$this->dbw->insert(
			'archive',
			$insert_fields,
			__METHOD__
		);

		# Insert tags, if any
		if ( isset( $revision['tags'] ) && count( $revision['tags'] ) > 0 ) {
			foreach ( $revision['tags'] as $tag ) {
				$this->dbw->insert(
					'change_tag',
					array(
						'ct_rev_id' => $e['id'],
						'ct_tag' => $tag,
					),
					__METHOD__
				);
			}
			$this->dbw->insert(
				'tag_summary',
				array(
					'ts_rev_id' => $e['id'],
					'ts_tags' => implode( ',', $revision['tags'] ),
				),
				__METHOD__
			);
		}

		$this->dbw->commit();
	}

	function processMove( $ns, $title ) {
		$sourceTitle = Title::makeTitle( $ns, $title );
		if ( ! in_array( (string)$sourceTitle, $this->movedTitles ) ) {
			$this->movedTitles[] = (string)$sourceTitle;
		}
		$this->output( "Check whether $sourceTitle refers to the same page on both wikis...\n" );
		$pageID = $this->getPageID( $ns, $title );
		if ( $pageID ) {
			# There's a local page at the given title
			# Check if page exists on remote wiki
			$params = array(
				'prop' => 'info',
				'pageids' => $pageID
			);
			$result = $this->bot->query( $params );

			if ( ! $result || isset( $result['error'] ) ) {
				$this->error( "Error getting information from API for page ID $pageID", 1 );
				return;
			}
			$info_pages = array_values( $result['query']['pages'] );

			if ( isset( $info_pages[0]['missing'] ) ) {
				# Local page doesn't exist on remote wiki. It must have been deleted
				# NOTE: When overwritting empty redirects on move, they're deleted
				# without being archived, but here we're archiving everything
				$this->output( "Page ID $pageID for title $sourceTitle on local wiki doesn't exist on remote. Archiving...\n" );
				$this->archiveAndDeletePage( $pageID, $ns, $title );
			} else {
				$remotePageNs = $info_pages[0]['ns'];
				$remotePageTitle = $this->sanitiseTitle( $info_pages[0]['ns'], $info_pages[0]['title'] );
				if ( $remotePageNs == $ns && $remotePageTitle == $title ) {
					$this->output( "$sourceTitle refer to the same page on both wikis. Nothing to do.\n" );
					# If the existing page has the same title, nothing more to do
					# If it was moved, processPage should have been called already
					return;
				}
				# Existing page is on a different title on remote wiki
				# Move it, but first check that there's not a conflicting title!
				$conflictingPageID = $this->getPageID( $remotePageNs, $remotePageTitle );
				if ( $conflictingPageID ) {
					# Whoops...
					$this->resolveConflictingTitle( $conflictingPageID, $remotePageNs, $remotePageTitle );
				}
				$this->output( sprintf( "Page ID $pageID has been moved on remote wiki. Moving $sourceTitle to %s...\n",
					Title::makeTitle( $remotePageNs, $remotePageTitle ) ) );
				$this->dbw->update(
					'page',
					array(
						'page_namespace' => $remotePageNs,
						'page_title' => $remotePageTitle,
					),
					array( 'page_id' => $pageID ),
					__METHOD__
				);
				# Update revisions on the moved page
				$pageInfo = array(
					'pageid' => $pageID,
					'title' => $remotePageTitle,
					'ns' => $remotePageNs,
					'protection' => true,
				);
				# Need to process also old revisions in case there were page restores
				$this->processPage( $pageInfo, null, false );
				if ( ! in_array( $pageID, $this->pagesProcessed ) ) {
					$this->pagesProcessed[] = $pageID;
				}
			}
			# Now process the original title. If it exists on remote wiki, the
			# corresponding page will be created, otherwise nothing will be done
			$pageInfo = array(
				'pageid' => null,
				'title' => $title,
				'ns' => $ns,
				'protection' => true,
			);
			# Need to process also old revisions in case there were page restores
			$this->processPage( $pageInfo, null, false );
		} else {
			# Local title doesn't exist. Should have been created after,
			# the move but we haven't processed it yet in recentchanges.
			# Or it's under another title. See if title exists on remote wiki
			$params = array(
				'prop' => 'info',
				'titles' => (string)$sourceTitle
			);
			$result = $this->bot->query( $params );

			if ( ! $result || isset( $result['error'] ) ) {
				$this->error( "Error getting information from API for page $sourceTitle", 1 );
				return;
			}
			$info_pages = array_values( $result['query']['pages'] );

			# Check if title exists on remote wiki
			if ( isset( $info_pages[0]['missing'] ) ) {
				# Title doesn't exist on local nor remote wiki
				# Nothing to do
				$this->output( "Title $sourceTitle doesn't exist on both wikis. Nothing to do.\n" );
				return;
			}
			$remoteID = $info_pages[0]['pageid'];
			# Check if the page on the remote wiki exists on local database.
			# If it exists, it'll be under a different title, because we
			# already know that the original local title doesn't exist
			$row = $this->dbw->selectRow(
				'page',
				array(
					'page_namespace',
					'page_title'
				),
				array( 'page_id' => $remoteID ),
				__METHOD__
			);
			if ( $row ) {
				# Page exists under a different title, move it
				$this->output( sprintf( "Page ID $remoteID has been moved on remote wiki. Moving %s to $sourceTitle...\n",
					Title::makeTitle( $row->page_namespace, $row->page_title ) ) );
				$this->dbw->update(
					'page',
					array(
						'page_namespace' => $ns,
						'page_title' => $title,
					),
					array( 'page_id' => $remoteID ),
					__METHOD__
				);
			}
			# Do processPage. If we had the page and we've moved it, it'll add the
			# revisions of the move, otherwise it will create the page if needed
			$pageInfo = array(
				'pageid' => $remoteID,
				'title' => $title,
				'ns' => $ns,
				'protection' => true,
			);
			# Need to process also old revisions in case there were page restores
			$this->processPage( $pageInfo, null, false );
			if ( ! in_array( $remoteID, $this->pagesProcessed ) ) {
				$this->pagesProcessed[] = $remoteID;
			}
		}
	}

	/**
	 * Fixes a situation where we have the same title on local and remote wiki
	 * but with different page ID. The fix is to get the title for the local
	 * page ID on the remote wiki.
	 * If local page id doesn't exist on remote, delete (and archive) local page
	 * since it must have been deleted. If it exists (in this case with different
	 * title) then move it to where it belongs
	 *
	 * @param int $conflictingPageID page ID with different title on local
	 *     and remote wiki
	 * @param int $remoteNs Namespace number of remote title for page id
	 * @param string $remoteTitle remote title for page id
	 * @param int $initialConflict optional - original conflicting ID to avoid
	 *     endless loops if pages were moved in round
	 * @return object A page object retrieved from database if an endless loop is
	 *     detected, used internally on recursive calls
	 */
	function resolveConflictingTitle( $conflictingPageID, $remoteNs, $remoteTitle, $initialConflict = 0 ) {
		$pageObj = null;
		$pageTitle = Title::makeTitle( $remoteNs, $remoteTitle );
		$this->output( "Warning: remote page ID $conflictingPageID has conflicting title $pageTitle with existing local page ID $conflictingPageID. Attempting to fix it...\n" );
		if ( ! in_array( (string)$pageTitle, $this->movedTitles ) ) {
			$this->movedTitles[] = (string)$pageTitle;
		}

		# Get current title of the existing local page ID and move it to where it belongs
		$params = array(
			'prop' => 'info',
			'pageids' => $conflictingPageID
		);
		$result = $this->bot->query( $params );
		$info_pages = array_values( $result['query']['pages'] );

		# First call to resolveConflictingTitle won't enter here, but on further recursive calls
		if ( isset( $info_pages[0]['missing'] ) ) {
			$this->output( "Page ID $conflictingPageID not found on remote wiki. Deleting...\n" );
			# Delete our copy, move revisions to archive
			# NOTE: If page was moved on remote wiki before deleting, we may potentially
			# leave revisions in archive with wrong title.
			$this->archiveAndDeletePage( $conflictingPageID, $remoteNs, $remoteTitle );
		} else {
			# Move page, but check first that the target title doesn't exist on local to avoid a conflict
			$resultingNs = $info_pages[0]['ns'];
			$resultingTitle = $this->sanitiseTitle( $info_pages[0]['ns'], $info_pages[0]['title'] );
			$resultingPageID = $this->getPageID( $resultingNs, $resultingTitle );
			$resultingPageTitle = Title::makeTitle( $resultingNs, $resultingTitle );
			if ( ! in_array( (string)$resultingPageTitle, $this->movedTitles ) ) {
				$this->movedTitles[] = (string)$resultingPageTitle;
			}

			if ( $resultingPageID ) {

				if ( $initialConflict == $resultingPageID ) {
					# This should never happen, unless we move A->B, C->A, B->C
					# In this case, we can't just rename, because it will blatantly violate the unique key for title
					# Get the page information, delete it from DB and restore it after the move
					$this->output( "Endless loop detected! Storing page ID $resultingPageID for later restore.\n" );
					$pageObj = (array)$this->dbw->selectRow(
						'page',
						'*',
						array( 'page_id' => $resultingPageID ),
						__METHOD__
					);
					$this->dbw->delete(
						'page',
						array( 'page_id' => $resultingPageID ),
						__METHOD__
					);
				} else {
					# Whoops! resulting title already exists locally, here we go again...
					$pageObj = $this->resolveConflictingTitle( $resultingPageID, $resultingNs, $resultingTitle, $conflictingPageID );
				}

				if ( $pageObj && $initialConflict === 0 ) {
					# Once we're resolved all conflicts, if we returend a $pageObj and we're on the originall call,
					# restore the deleted page entry, with the correct page ID.
					$this->output( sprintf( "Restoring page ID %s at title %s.\n",
						$pageObj['page_id'], $resultingPageTitle ) );
					$pageObj['page_namespace'] = $resultingNs;
					$pageObj['page_title'] = $resultingTitle;
					$this->dbw->insert(
						'page',
						$pageObj,
						__METHOD__
					);
					# We've restored the page fixing the title, nothing more to do!
					return null;
				}

			}
			$this->output( "Moving page ID $conflictingPageID to $resultingPageTitle...\n" );
			$this->dbw->update(
				'page',
				array(
					'page_namespace' => $resultingNs,
					'page_title' => $resultingTitle,
				),
				array( 'page_id' => $conflictingPageID ),
				__METHOD__
			);
		}
		return $pageObj;
	}

	/**
	 * For use with deleted crap that chucks the id; spotty at best.
	 *
	 * @param int $ns Namespace number
	 * @param string $title Title of the page without the namespace
	 */
	function getPageID( $ns, $title ) {
		$pageID = (int)$this->dbw->selectField(
			'page',
			'page_id',
			array(
				'page_namespace' => $ns,
				'page_title' => $title,
			),
			__METHOD__
		);
		return $pageID;
	}

	/**
	 * Strips the namespace from the title, if namespace number is different than 0,
	 *  and converts spaces to underscores. For use in database
	 *
	 * @param int $ns Namespace number
	 * @param string $title Title of the page with the namespace
	 */
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
