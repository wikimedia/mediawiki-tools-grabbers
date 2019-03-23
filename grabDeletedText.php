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

require_once __DIR__ . '/../maintenance/Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabDeletedText extends Maintenance {

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Actual start point if bad drcontinues force having to continue from earlier
	 * (mw1.19- issue)
	 *
	 * @var string
	 */
	protected $badStart;

	/**
	 * Last title to get; useful for working around content with a namespace/interwiki
	 * on top of it in mw1.19-
	 *
	 * @var string
	 */
	protected $lastTitle;

	/**
	 * API limits to use instead of max
	 *
	 * @var int
	 */
	protected $apiLimits;

	/**
	 * Array of namespaces to grab deleted revisions
	 *
	 * @var Array
	 */
	protected $namespaces = null;

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
		$this->mDescription = "Grab deleted text from an external wiki and import it into one of ours.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', true, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', true, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		# $this->addOption( 'start', 'Revision at which to start', false, true );
		#$this->addOption( 'startdate', 'Not yet implemented.', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'drcontinue', 'API continue to restart deleted revision process', false, true );
		$this->addOption( 'apilimits', 'API limits to use. Maximum limits for the user will be used by default', false, true );
		$this->addOption( 'lasttitle', 'Last title to get; useful for working around content with a namespace/interwiki on top of it in mw1.19-', false, true );
		$this->addOption( 'badstart', 'Actual start point if bad drcontinues force having to continue from earlier (mw1.19- issue)', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		global $wgDBname;

		$this->lastTitle = $this->getOption( 'lasttitle' );
		$this->badStart = $this->getOption( 'badstart' );
		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->fatalError( 'The URL to the source wiki\'s api.php must be specified!' );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		# End date isn't necessarily supported by source wikis, but we'll deal with that later.
		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			$this->endDate = wfTimestamp( TS_MW, $this->endDate );
			if ( !$this->endDate ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$apiLimits = $this->getOption( 'apilimits' );
		if ( !is_null( $apiLimits ) && is_numeric( $apiLimits ) && (int)$apiLimits > 0 ) {
			$this->apiLimits = (int)$apiLimits;
		} else {
			$this->apiLimits = null;
		}

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, [], $this->getOption( 'db', $wgDBname ) );
		# Get last text id
		$this->lastTextId = (int)$this->dbw->selectField(
			'text',
			'old_id',
			[],
			__METHOD__,
			[ 'ORDER BY' => 'old_id DESC' ]
		);

		# bot class and log in
		$this->bot = new MediaWikiBot(
			$url,
			'json',
			$user,
			$password,
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);
		if ( !$this->bot->login() ) {
			$this->output( "Logged in as $user...\n" );
			# Commented out, this doesn't work on Wikia. Simply let the api
			# error out on first deletedrevs access
			## Does the user have deletion rights?
			#$params = [
			#	'list' => 'allusers',
			#	'aulimit' => '1',
			#	'auprop' => 'rights',
			#	'aufrom' => $user
			#];
			#$result = $this->bot->query( $params );
			#if ( !in_array( 'deletedtext', $result['query']['allusers'][0]['rights'] ) ) {
			#	$this->fatalError( "$user does not have required rights to fetch deleted revisions." );
			#}
		} else {
			$this->fatalError( "Failed to log in as $user." );
		}

		$this->output( "\n" );

		$this->output( "Retreiving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics|namespacealiases'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found...' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
		} else {
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces...' );
		}

		# Get deleted revisions
		$this->output( "\nSaving deleted revisions...\n" );
		$revisions_processed = 0;

		foreach ( $textNamespaces as $ns ) {
			$more = true;
			$drcontinue = $this->getOption( 'drcontinue' );
			if ( !$drcontinue ) {
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
					$this->output( "Skipping $ns\n" );
					continue;
				} elseif ( $nsStart != $ns ) {
					$drcontinue = null;
				}
			}
			# Count revisions
			$nsRevisions = 0;

			# TODO: list=deletedrevs is deprecated in recent MediaWiki versions.
			# should try to use list=alldeletedrevisions first and fallback to deletedrevs
			$params = [
				'list' => 'deletedrevs',
				'drnamespace' => $ns,
				'drlimit' => $this->getApiLimit(),
				'drdir' => 'newer',
				'drprop' => 'revid|user|userid|comment|minor|len|content|parentid',
			];

			while ( $more ) {
				if ( $drcontinue === null ) {
					unset( $params['drcontinue'] );
				} else {
					# Check for 1.19 bug with the drcontinue that causes the query to jump backward on colonspaces, but we need something to compare back to for this...
					if ( isset( $params['drcontinue'] ) ) {
						$oldcontinue = $params['drcontinue'];
						if ( substr( str_replace( ' ', '_', $drcontinue ), 0, -15 ) < substr( str_replace( ' ', '_', $oldcontinue ), 0, -15 ) ) {
							$this->fatalError( 'Bad drcontinue; ' . str_replace( ' ', '_', $drcontinue ) . ' < ' . str_replace( ' ', '_', $oldcontinue ) );
						}
					}
					$params['drcontinue'] = $drcontinue;

				}
				$result = $this->bot->query( $params );
				if ( $result && isset( $result['error'] ) && $result['error']['code'] == 'drpermissiondenied' ) {
					$this->fatalError( "$user does not have required rights to fetch deleted revisions." );
				}
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
					$nsRevisions = $this->processDeletedRevisions( $pageChunk, $nsRevisions );

					if ( isset( $result['query-continue'] ) ) {
						# TODO: Document what is this for. Examples welcome
						$drcontinue = str_replace( '&', '%26', $result['query-continue']['deletedrevs']['drcontinue'] );
					} else {
						$drcontinue = null;
					}
					$more = !( $drcontinue === null );
				}
				$this->output( "drcontinue = $drcontinue\n" );
			}
			$this->output( "$nsRevisions chunks of revisions processed in namespace $ns.\n" );
			$revisions_processed += $nsRevisions;
		}

		$this->output( "\n" );
		$this->output( "Saved $revisions_processed deleted revisions.\n" );

		# Done.
	}

	/**
	 * Add deleted revisions to the archive and text tables
	 * Takes results in chunks because that's how the API returns pages - with chunks of revisions.
	 *
	 * @param Array $pageChunk Chunk of revisions, represents a deleted page
	 * @param int $nsRevisions Count of deleted revisions for this namespace, for progress reports
	 * @returns int $nsRevisions updated
	 */
	function processDeletedRevisions( $pageChunk, $nsRevisions ) {
		global $wgContLang;

		# Go back if we're not actually to the start point yet.
		if ( $this->badStart ) {
			if ( str_replace( ' ', '_', $badStart ) > str_replace( ' ', '_', $pageChunk['title'] ) ) {
				return $nsRevisions;
			} else {
				# We're now at the correct position, clear the flag and continue
				$this->badStart = null;
			}
		}

		$ns = $pageChunk['ns'];
		$title = $this->sanitiseTitle( $ns, $pageChunk['title'] );

		# TODO: Document this whith examples if possible
		if ( $this->lastTitle && ( str_replace( ' ', '_', $pageChunk['title'] ) > str_replace( ' ', '_', $this->lastTitle ) ) ) {
			$this->fatalError( "Stopping at {$pageChunk['title']}; lasttitle reached." );
		}
		$this->output( "Processing {$pageChunk['title']}\n" );

		$revisions = $pageChunk['revisions'];
		foreach ( $revisions as $revision ) {
			if ( $nsRevisions % 500 == 0 && $nsRevisions !== 0 ) {
				$this->output( "$nsRevisions revisions inserted\n" );
			}
			# Stop if past the enddate
			$timestamp = wfTimestamp( TS_MW, $revision['timestamp'] );
			if ( $timestamp > $this->endDate ) {
				return $nsRevisions;
			}

			$text = $revision['*'];
			if ( isset( $revision['parentid'] ) ) {
				$parentID = $revision['parentid'];
			} else {
				$parentID = null;
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
			}
			if ( isset( $revision['comment'] ) ) {
				$comment = $revision['comment'];
				if ( $comment ) {
					$comment = $wgContLang->truncateForDatabase( $comment, 255 );
				}
			} else {
				$comment = '';
			}
			if ( isset( $revision['texthidden'] ) ) {
				$revdeleted = $revdeleted | Revision::DELETED_TEXT;
			}
			if ( isset( $revision['*'] ) ) {
				$text = $revision['*'];
			} else {
				$text = '';
			}
			if ( isset ( $revision['suppressed'] ) ) {
				$revdeleted = $revdeleted | Revision::DELETED_RESTRICTED;
			}

			$e = [
				'namespace' => $ns,
				'title' => $title,
				'comment' => $comment,
				'user' => $revision['userid'],
				'user_text' => $revision['user'],
				'timestamp' => $timestamp,
				'minor_edit' => ( isset( $revision['minor'] ) ? 1 : 0 ),
				'rev_id' => $revision['revid'],
				'deleted' => $revdeleted,
				'len' => strlen( $text ),
				'sha1' => Revision::base36Sha1( $text ),
				'parent_id' => $parentID,
				'text_id' => $this->lastTextId + 1 // we actually increment it in the insertText if this insert works at all
			];

			# $this->output( "Going to commit changes into the 'archive' table...\n" );

			$this->dbw->insert(
				'archive',
				[
					'ar_namespace' => $e['namespace'],
					'ar_title' => $e['title'],
					'ar_comment' => $e['comment'],
					'ar_user' => $e['user'],
					'ar_user_text' => $e['user_text'],
					'ar_timestamp' => $e['timestamp'],
					'ar_minor_edit' => $e['minor_edit'],
					'ar_rev_id' => $e['rev_id'],
					'ar_text_id' => $e['text_id'],
					'ar_deleted' => $e['deleted'],
					'ar_len' => $e['len'],
					'ar_sha1' => $e['sha1'],
					#'ar_page_id' => NULL, # Not requred and unreliable from api
					'ar_parent_id' => $e['parent_id']
				],
				__METHOD__,
				[ 'IGNORE' ] // in case of duplicates?!
			);
			if ( $this->dbw->affectedRows() === 0 ) {
				$this->output( "Revision {$revision['revid']} already exists in archive table; skipping\n" );
			} else {
				if ( $e['text_id'] !== $this->storeText( $text, $e['sha1'] ) ) {
					// I have no idea how this could possibly happen, but...
					$this->fatalError( 'AH FUCK text_id IS OUT OF SYNC PANIC' );
				}
				$nsRevisions++;
			}

			# $this->output( "Changes committed to the database!\n" );
		}

		return $nsRevisions;
	}

	/**
	 * Stores revision text in the text table.
	 * If configured, stores text in external storage
	 *
	 * @param string $text Text of the revision to store
	 * @param string $sha1 computed sha1 of the text
	 * @return int text id of the inserted text
	 */
	function storeText( $text, $sha1 ) {
		global $wgDefaultExternalStore;

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

		$e = [
			'id' => $this->lastTextId,
			'text' => $text,
			'flags' => $flags
		];

		$this->dbw->insert(
			'text',
			[
				'old_id' => $e['id'],
				'old_text' => $e['text'],
				'old_flags' => $e['flags']
			],
			__METHOD__
		);

		return $e['id'];
	}

	/**
	 * Returns the standard api result limit for queries
	 *
	 * @returns int limit provided by user, or 'max' to use the maximum
	 *          allowed for the user querying the api
	 */
	function getApiLimit() {
		if ( is_null( $this->apiLimits ) ) {
			return 'max';
		}
		return $this->apiLimits;
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

$maintClass = 'GrabDeletedText';
require_once RUN_MAINTENANCE_IF_MAIN;
