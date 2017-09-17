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

class GrabText extends Maintenance {

	/**
	 * Whether our wiki supports page counters, to use counters if remote wiki also has them
	 *
	 * @var bool
	 */
	protected $supportsCounters;

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Last text id in the current db
	 *
	 * @var int
	 */
	protected $lastTextId = 0;

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

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab text from an external wiki and import it into one of ours.\nDon't use this on a large wiki unless you absolutely must; it will be incredibly slow.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		$this->addOption( 'start', 'Page at which to start, useful if the script stopped at this point', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", 1 );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $this->endDate ) ) {
				$this->error( "Invalid enddate format.\n", 1 );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );

		# Check if wiki supports page counters (removed from core in 1.25)
		$this->supportsCounters = $this->dbw->fieldExists( 'page', 'page_counter', __METHOD__ );

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
				$this->error( "Failed to log in as $user.\n", 1 );
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

		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = array(
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics|namespacealiases'
		);
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->error( 'No siteinfo data found', 1 );
		}

		$textNamespaces = array();
		if ( $this->hasOption( 'namespaces' ) ) {
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			$grabFromAllNamespaces = false;
		} else {
			$grabFromAllNamespaces = true;
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( !$textNamespaces ) {
			$this->error( 'Got no namespaces', 1 );
		}

		if ( $grabFromAllNamespaces ) {
			# Get list of live pages from namespaces and continue from there
			$pageCount = $siteinfo['statistics']['pages'];
			$this->output( "Generating page list from all namespaces - $pageCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating page list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$start = $this->getOption( 'start' );
		if ( $start ) {
			$title = Title::newFromText( $start );
			if ( is_null( $title ) ) {
				$this->error( 'Invalid title provided for the start parameter', 1 );
			}
			$this->output( sprintf( "Trying to resume import from page %s\n", $title ) );
		}

		$pageCount = 0;
		foreach ( $textNamespaces as $ns ) {
			$continueTitle = null;
			if ( isset( $title ) && ! is_null( $title ) ) {
				if ( $title->getNamespace() === (int)$ns ) {
					# The apfrom parameter doesn't have namespace!!
					$continueTitle = $title->getText();
					$title = null;
				} else {
					continue;
				}
			}
			$pageCount += $this->processPagesFromNamespace( (int)$ns, $continueTitle );
		}
		$this->output( "\nDone - found $pageCount total pages.\n" );
		# Done.
	}

	/**
	 * Grabs all pages from a given namespace
	 *
	 * @param int $ns Namespace to process.
	 * @param string $continueTitle Title to start from (optional).
	 * @return int Number of pages processed.
	 */
	function processPagesFromNamespace( $ns, $continueTitle = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );
		$doneCount = 0;
		$nsPageCount = 0;
		$more = true;
		$params = array(
			'generator' => 'allpages',
			'gaplimit' => 'max',
			'prop' => 'info',
			'inprop' => 'protection',
			'gapnamespace' => $ns
		);
		if ( $continueTitle ) {
			$params['gapfrom'] = $continueTitle;
		}
		do {
			$result = $this->bot->query( $params );

			# Skip empty namespaces
			if ( isset( $result['query'] ) ) {
				$pages = $result['query']['pages'];

				$resultsCount = 0;
				foreach ( $pages as $page ) {
					$this->processPage( $page );
					$doneCount++;
					if ( $doneCount % 500 === 0 ) {
						$this->output( "$doneCount\n" );
					}
					$resultsCount++;
				}
				$nsPageCount += $resultsCount;

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['allpages'] ) ) {
					# Add continuation parameters
					$params = array_merge( $params, $result['query-continue']['allpages'] );
				} else {
					$more = false;
				}
			} else {
				$more = false;
			}
		} while ( $more );

		$this->output( "$nsPageCount pages found in namespace $ns.\n" );

		return $nsPageCount;
	}

	/**
	 * Handle an individual page.
	 *
	 * @param array $page: Array retrieved from the API, containing pageid,
	 *     page title, namespace, protection status and more...
	 */
	function processPage( $page ) {
		global $wgContentHandlerUseDB;

		$pageID = $page['pageid'];

		$this->output( "Processing page id $pageID...\n" );

		$params = array(
			'prop' => 'info|revisions',
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $this->endDate )
		);
		$params['pageids'] = $pageID;
		if ( $page['protection'] ) {
			$params['inprop'] = 'protection';
		}
		if ( $wgContentHandlerUseDB ) {
			$params['rvprop'] = $params['rvprop'] . '|contentmodel';
		}

		$result = $this->bot->query( $params );

		if ( ! $result || isset( $result['error'] ) ) {
			$this->error( "Error getting revision information from API for page id $pageID.", 1 );
			return;
		}

		if ( isset( $params['inprop'] ) ) {
			unset( $params['inprop'] );
		}

		$info_pages = array_values( $result['query']['pages'] );
		if ( isset( $info_pages[0]['missing'] ) ) {
			$this->output( "Page id $pageID not found.\n" );
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
			$this->output( "Setting page_restrictions on page_id $pageID.\n" );
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
				$revisionsProcessed = $this->processRevision( $revision, $pageID, $defaultModel ) || $revisionsProcessed;
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['revisions'] ) ) {
				# Add continuation parameters
				$params = array_merge( $params, $result['query-continue']['revisions'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( ! $result || isset( $result['error'] ) ) {
				$this->error( "Error getting revision information from API for page id $pageID.", 1 );
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

$maintClass = 'GrabText';
require_once RUN_MAINTENANCE_IF_MAIN;
