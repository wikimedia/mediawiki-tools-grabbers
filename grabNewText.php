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
 * @version 1.1
 * @date 5 August 2019
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/TextGrabber.php';

class GrabNewText extends TextGrabber {

	/**
	 * Start date
	 *
	 * @var string
	 */
	protected $startDate;

	/**
	 * Last revision in the current db
	 *
	 * @var int
	 */
	protected $lastRevision = 0;

	/**
	 * Array of namespaces to grab changes
	 *
	 * @var Array
	 */
	protected $namespaces = null;

	/**
	 * A list of page ids already processed. Don't get new edits for those
	 *
	 * @var array
	 */
	protected $pagesProcessed = [];

	/**
	 * A list of page ids already processed for protection
	 *
	 * @var array
	 */
	protected $pagesProtected = [];

	/**
	 * Used to know if the current user can see deleted revisions on remote wiki,
	 * to also gather them to be available on local wiki
	 *
	 * @var bool
	 */
	protected $canSeeDeletedRevs = true;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab new changes from an external wiki and add it over an imported dump.\nFor use when the available dump is slightly out of date.";
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc); note that this cannot go back further than 1-3 months on most projects. If a start date is not provided, the last revision timestamp in the database is used.', false, true );
		$this->addOption( 'namespaces', 'A pipe-separated list of namespaces (ID) to grab changes from. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->startDate = $this->getOption( 'startdate' );
		if ( $this->startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $this->startDate ) ) {
				$this->fatalError( 'Invalid startdate format.' );
			}
		} else {
			// If a start date was not provided, use the latest timestamp from the database.
			$this->startDate = $this->dbw->selectField(
				'revision',
				'rev_timestamp',
				[],
				__METHOD__,
				[ 'ORDER BY' => 'rev_timestamp DESC' ]
			);
			if ( !$this->startDate ) {
				$this->fatalError( 'No startdate provided, and no revisions found in our DB.' );
			}

			$this->output( "Using the latest revision timestamp in our database: $this->startDate\n" );
		}

		if ( $this->hasOption( 'namespaces' ) ) {
			$this->namespaces = explode( '|', $this->getOption( 'namespaces' ) );
		}

		# Get last revision id to avoid duplicates
		$this->lastRevision = (int)$this->dbw->selectField(
			'revision',
			'rev_id',
			[],
			__METHOD__,
			[ 'ORDER BY' => 'rev_id DESC' ]
		);

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
		$params = [
			'list' => 'recentchanges',
			'rcdir' => 'newer',
			'rctype' => 'edit|new',
			'rclimit' => 'max',
			'rcprop' => 'title|sizes|redirect|ids',
			'rcstart' => $this->startDate,
			'rcend' => $this->endDate
		];
		$count = 0;
		$more = true;
		if ( !is_null( $this->namespaces ) ) {
			$params['rcnamespace'] = implode( '|', $this->namespaces );
		}

		$this->output( "Retreiving list of changed pages...\n" );
		while ( $more ) {
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

				$pageInfo = [
					'pageid' => $entry['pageid'],
					'title' => $entry['title'],
					'ns' => $ns,
					'protection' => null,
				];
				if ( in_array( $entry['pageid'], $this->pagesProtected ) ) {
					$pageInfo['protection'] = true;
					# Remove from the array so we don't attempt to insert restrictions again
					array_slice( $this->pagesProtected, array_search( $entry['pageid'], $this->pagesProtected ), 1 );
				}
				$this->processPage( $pageInfo, $this->startDate );

				$count++;
			}
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['recentchanges'] ) ) {
				$params = array_merge( $params, $result['query-continue']['recentchanges'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
		}
		$this->output( "\n" );
	}

	/**
	 * Get delete/move/import changes
	 */
	function processRecentLogs() {
		$params = [
			'list' => 'logevents',
			'ledir' => 'newer',
			'lelimit' => 'max',
			'lestart' => $this->startDate,
			'leend' => $this->endDate
		];

		$count = 0;
		$more = true;

		$this->output( "Updating deleted and moved items...\n" );
		while ( $more ) {
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
						if ( !in_array( (string)$sourceTitle, $this->movedTitles ) ) {
							$this->output( "$sourceTitle was deleted; updating...\n" );
							# Delete our copy, move revisions -> archive
							$pageID = $this->getPageID( $ns, $title );
							if ( !$pageID ) {
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
						$pageInfo = [
							'pageid' => $pageID,
							'title' => $title,
							'ns' => $ns,
							'protection' => true,
						];
						$this->processPage( $pageInfo, null, false );
						if ( !in_array( $pageID, $this->pagesProcessed ) ) {
							$this->pagesProcessed[] = $pageID;
						}
						$this->output( "$sourceTitle processed.\n" );
					} elseif ( $logEntry['type'] == 'upload' ) { # action can be upload or reupload
						$this->output( "$sourceTitle was imported; updating....\n" );
						# Process as new
						if ( !$pageID ) {
							$pageID = null;
						}
						$pageInfo = [
							'pageid' => $pageID,
							'title' => $title,
							'ns' => $ns,
							'protection' => true,
						];
						$this->processPage( $pageInfo );
						if ( !in_array( $pageID, $this->pagesProcessed ) ) {
							$this->pagesProcessed[] = $pageID;
						}
					} elseif ( $logEntry['type'] == 'protect' ) {
						# Don't bother if there's no pageID
						if ( $pageID ) {
							$pageInfo = [
								'pageid' => $pageID,
								'title' => $title,
								'ns' => $ns,
								'protection' => null,
							];
							if ( $logEntry['action'] == 'unprotect' ) {
								# Remove protection info
								$this->dbw->delete(
									'page_restrictions',
									[ 'pr_page' => $pageID ],
									__METHOD__
								);
							} elseif ( !in_array( $pageID, $this->pagesProtected ) ) {
								$pageInfo['protection'] = true;
							}
							$this->processPage( $pageInfo, $this->startDate );
							if ( !in_array( $pageID, $this->pagesProcessed ) ) {
								$this->pagesProcessed[] = $pageID;
							}
						}
					}
					$count++;
				}
			}
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['logevents'] ) ) {
				$params = array_merge( $params, $result['query-continue']['logevents'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params,  $result['continue'] );
			} else {
				$more = false;
			}
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
		$pageID = $page['pageid'];
		$pageTitle = null;
		$pageDesignation = "id $pageID";
		if ( !$pageID ) {
			# We don't have page id... we need to use page title
			$pageTitle = (string)Title::makeTitle( $page['ns'], $page['title'] );
			$pageDesignation = $pageTitle;
		}

		$this->output( "Processing page $pageDesignation...\n" );

		$params = [
			'prop' => 'info|revisions',
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $this->endDate )
		];
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

		$result = $this->bot->query( $params );

		if ( !$result || isset( $result['error'] ) ) {
			$this->fatalError( "Error getting revision information from API for page $pageDesignation." );
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

		$page_e = [
			'namespace' => null,
			'title' => null,
			'is_redirect' => 0,
			'is_new' => 0,
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => 0,
			'content_model' => null
		];
		# Trim and convert displayed title to database page title
		# Get it from the returned value from api
		$page_e['namespace'] = $info_pages[0]['ns'];
		$page_e['title'] = $this->sanitiseTitle( $info_pages[0]['ns'], $info_pages[0]['title'] );
		$title = Title::makeTitle( $page_e['namespace'], $page_e['title'] );

		# Get other information from api info
		$page_e['is_redirect'] = ( isset( $info_pages[0]['redirect'] ) ? 1 : 0 );
		$page_e['is_new'] = ( isset( $info_pages[0]['new'] ) ? 1 : 0 );
		$page_e['len'] = $info_pages[0]['length'];
		$page_e['latest'] = $info_pages[0]['lastrevid'];
		$defaultModel = null;
		if ( isset( $info_pages[0]['contentmodel'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			#$defaultModel = ContentHandler::getDefaultModelFor( $title );
			$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()->
				getNamespaceContentModel( $info_pages[0]['ns'] ) || CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $info_pages[0]['contentmodel'] ) {
				$page_e['content_model'] = $info_pages[0]['contentmodel'];
			}
		}

		# Check if page is present
		$pageRow = $this->dbw->selectRow(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageID ],
			__METHOD__
		);

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		# Also, check the destination title before moving the page
		if ( $pageRow === false ||
			$pageRow->page_namespace != $page_e['namespace'] ||
			$pageRow->page_title != $page_e['title']
		) {
			$conflictingPageID = $this->getPageID( $page_e['namespace'], $page_e['title'] );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $page_e['namespace'], $page_e['title'], $pageID, $conflictingPageID );
			}
		}

		# Update page_restrictions (only if requested)
		if ( isset( $info_pages[0]['protection'] ) ) {
			$this->output( "Setting page_restrictions changes on page_id $pageID.\n" );
			# Delete first any existing protection
			$this->dbw->delete(
				'page_restrictions',
				[ 'pr_page' => $pageID ],
				__METHOD__
			);
			# insert current restrictions
			foreach ( $info_pages[0]['protection'] as $prot ) {
				# Skip protections inherited from cascade protections
				if ( !isset( $prot['source'] ) ) {
					$e = [
						'page' => $pageID,
						'type' => $prot['type'],
						'level' => $prot['level'],
						'cascade' => (int)isset( $prot['cascade'] ),
						'user' => null,
						'expiry' => ( $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] ) )
					];
					$this->dbw->insert(
						'page_restrictions',
						[
							'pr_page' => $e['page'],
							'pr_type' => $e['type'],
							'pr_level' => $e['level'],
							'pr_cascade' => $e['cascade'],
							'pr_user' => $e['user'],
							'pr_expiry' => $e['expiry']
						],
						__METHOD__
					);
				}
			}
		}

		$revisionsProcessed = false;
		while ( true ) {
			foreach ( $info_pages[0]['revisions'] as $revision ) {
				if ( !$skipPrevious || $revision['revid'] > $this->lastRevision) {
					$revisionsProcessed = $this->processRevision( $revision, $pageID, $title ) || $revisionsProcessed;
				} else {
					$this->output( sprintf( "Skipping the processRevision of revision %d minor or equal to the last revision of the database (%d).\n",
						$revision['revid'], $this->lastRevision ) );
				}
			}

			# Add continuation parameters
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['revisions'] ) ) {
				$params = array_merge( $params, $result['query-continue']['revisions'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( !$result || isset( $result['error'] ) ) {
				$this->fatalError( "Error getting revision information from API for page $pageDesignation." );
				return;
			}

			$info_pages = array_values( $result['query']['pages'] );
		}

		if ( !$revisionsProcessed ) {
			# We already processed the page before? page doesn't need updating, then
			return;
		}

		$insert_fields = [
			'page_namespace' => $page_e['namespace'],
			'page_title' => $page_e['title'],
			'page_is_redirect' => $page_e['is_redirect'],
			'page_is_new' => $page_e['is_new'],
			'page_random' => $page_e['random'],
			'page_touched' => $page_e['touched'],
			'page_latest' => $page_e['latest'],
			'page_len' => $page_e['len'],
			'page_content_model' => $page_e['content_model']
		];
		if ( $pageRow === false ) {
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
				[ 'page_id' => $pageID ],
				__METHOD__
			);
		}
		$this->dbw->commit();
	}

	function updateRestored( $ns, $title ) {
		# We need to delete content from the slots table too, otherwise
		# when we get the revisions from the remote wiki that already
		# exist here, we'll get duplicate errors
		$result = $this->dbw->select(
			'archive',
			[ 'ar_rev_id' ],
			[
				'ar_title' => $title,
				'ar_namespace' => $ns
			],
			__METHOD__
		);
		$revids = [];
		foreach ( $result as $row ) {
			$revids[] = $row->ar_rev_id;
		}
		if ( $revids ) {
			$this->dbw->delete(
				'slots',
				[ 'slot_revision_id' => $revids ],
				__METHOD__
			);
		}
		# Delete existing deleted revisions for page
		$this->dbw->delete(
			'archive',
			[
				'ar_title' => $title,
				'ar_namespace' => $ns
			],
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
		if ( !$this->canSeeDeletedRevs ) {
			$this->output( "Unable to see deleted revisions for title $pageTitle\n" );
			return;
		}

		# TODO: list=deletedrevs is deprecated in recent MediaWiki versions.
		# should try to use list=alldeletedrevisions first and fallback to deletedrevs
		$params = [
			'list' => 'deletedrevs',
			'titles' => (string)$pageTitle,
			'drprop' => 'revid|parentid|user|userid|comment|minor|len|content|tags',
			'drlimit' => 'max',
			'drdir' => 'newer'
		];

		$result = $this->bot->query( $params );

		if ( !$result || isset( $result['error'] ) ) {
			if ( isset( $result['error'] ) && $result['error']['code'] == 'drpermissiondenied' ) {
				$this->output( "Warning: Current user can't see deleted revisions.\n" .
					"Unable to see deleted revisions for title $pageTitle\n" );
				$this->canSeeDeletedRevs = false;
				return;
			}
			$this->fatalError( "Error getting deleted revision information from API for page $pageTitle." );
			return;
		}

		if ( count( $result['query']['deletedrevs'] ) === 0 ) {
			# No deleted revisions for that title, nothing to do
			return;
		}

		$info_deleted = $result['query']['deletedrevs'][0];

		while ( true ) {
			foreach ( $info_deleted['revisions'] as $revision ) {
				$revisionId = $revision['revid'];
				if ( !$revisionId ) {
					# Revision ID is mandatory with the new content tables and things will fail if not provided.
					$this->output( sprintf( "WARNING: Got revision without revision id, " .
						"with timestamp %s. Skipping!\n", $revision['timestamp'] ) );
					continue;
				}
				# Check if archived revision is already there to prevent duplicate entries
				$count = $this->dbw->selectRowCount(
					'archive',
					'1',
					[ 'ar_rev_id' => $revisionId ],
					__METHOD__
				);
				if ( !$count ) {
					$this->insertArchivedRevision( $revision, $pageTitle );
				}
			}

			# Add continuation parameters
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['deletedrevs'] ) ) {
				$params = array_merge( $params, $result['query-continue']['deletedrevs'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( !$result || isset( $result['error'] ) ) {
				$this->fatalError( "Error getting deleted revision information from API for page $pageTitle." );
				return;
			}

			$info_deleted = $result['query']['deletedrevs'][0];
		}
	}

	function processMove( $ns, $title ) {
		$sourceTitle = Title::makeTitle( $ns, $title );
		if ( !in_array( (string)$sourceTitle, $this->movedTitles ) ) {
			$this->movedTitles[] = (string)$sourceTitle;
		}
		$this->output( "Check whether $sourceTitle refers to the same page on both wikis...\n" );
		$pageID = $this->getPageID( $ns, $title );
		if ( $pageID ) {
			# There's a local page at the given title
			# Check if page exists on remote wiki
			$params = [
				'prop' => 'info',
				'pageids' => $pageID
			];
			$result = $this->bot->query( $params );

			if ( !$result || isset( $result['error'] ) ) {
				$this->fatalError( "Error getting information from API for page ID $pageID" );
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
					$this->resolveConflictingTitle( $remotePageNs, $remotePageTitle, $pageID, $conflictingPageID );
				}
				$this->output( sprintf( "Page ID $pageID has been moved on remote wiki. Moving $sourceTitle to %s...\n",
					Title::makeTitle( $remotePageNs, $remotePageTitle ) ) );
				$this->dbw->update(
					'page',
					[
						'page_namespace' => $remotePageNs,
						'page_title' => $remotePageTitle,
					],
					[ 'page_id' => $pageID ],
					__METHOD__
				);
				# Update revisions on the moved page
				$pageInfo = [
					'pageid' => $pageID,
					'title' => $remotePageTitle,
					'ns' => $remotePageNs,
					'protection' => true,
				];
				# Need to process also old revisions in case there were page restores
				$this->processPage( $pageInfo, null, false );
				if ( !in_array( $pageID, $this->pagesProcessed ) ) {
					$this->pagesProcessed[] = $pageID;
				}
			}
			# Now process the original title. If it exists on remote wiki, the
			# corresponding page will be created, otherwise nothing will be done
			$pageInfo = [
				'pageid' => null,
				'title' => $title,
				'ns' => $ns,
				'protection' => true,
			];
			# Need to process also old revisions in case there were page restores
			$this->processPage( $pageInfo, null, false );
		} else {
			# Local title doesn't exist. Should have been created after,
			# the move but we haven't processed it yet in recentchanges.
			# Or it's under another title. See if title exists on remote wiki
			$params = [
				'prop' => 'info',
				'titles' => (string)$sourceTitle
			];
			$result = $this->bot->query( $params );

			if ( !$result || isset( $result['error'] ) ) {
				$this->fatalError( "Error getting information from API for page $sourceTitle" );
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
				[
					'page_namespace',
					'page_title'
				],
				[ 'page_id' => $remoteID ],
				__METHOD__
			);
			if ( $row ) {
				# Page exists under a different title, move it
				$this->output( sprintf( "Page ID $remoteID has been moved on remote wiki. Moving %s to $sourceTitle...\n",
					Title::makeTitle( $row->page_namespace, $row->page_title ) ) );
				$this->dbw->update(
					'page',
					[
						'page_namespace' => $ns,
						'page_title' => $title,
					],
					[ 'page_id' => $remoteID ],
					__METHOD__
				);
			}
			# Do processPage. If we had the page and we've moved it, it'll add the
			# revisions of the move, otherwise it will create the page if needed
			$pageInfo = [
				'pageid' => $remoteID,
				'title' => $title,
				'ns' => $ns,
				'protection' => true,
			];
			# Need to process also old revisions in case there were page restores
			$this->processPage( $pageInfo, null, false );
			if ( !in_array( $remoteID, $this->pagesProcessed ) ) {
				$this->pagesProcessed[] = $remoteID;
			}
		}
	}
}

$maintClass = 'GrabNewText';
require_once RUN_MAINTENANCE_IF_MAIN;
