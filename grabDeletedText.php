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
use MediaWiki\Revision\SlotRecord;

require_once 'includes/TextGrabber.php';

class GrabDeletedText extends TextGrabber {

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

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab deleted text from an external wiki and import it into one of ours.";
		# $this->addOption( 'start', 'Revision at which to start', false, true );
		#$this->addOption( 'startdate', 'Not yet implemented.', false, true );
		$this->addOption( 'drcontinue', 'API continue to restart deleted revision process', false, true );
		$this->addOption( 'apilimits', 'API limits to use. Maximum limits for the user will be used by default', false, true );
		$this->addOption( 'lasttitle', 'Last title to get; useful for working around content with a namespace/interwiki on top of it in mw1.19-', false, true );
		$this->addOption( 'badstart', 'Actual start point if bad drcontinues force having to continue from earlier (mw1.19- issue)', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->lastTitle = $this->getOption( 'lasttitle' );
		$this->badStart = $this->getOption( 'badstart' );

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
				'drprop' => 'revid|parentid|user|userid|comment|minor|len|content|tags',
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
				if ( $result && isset( $result['error'] ) ) {
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
				}

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['deletedrevs'] ) ) {
					# Ancient way of api pagination
					# TODO: Document what is this for. Examples welcome
					$drcontinue = str_replace( '&', '%26', $result['query-continue']['deletedrevs']['drcontinue'] );
					$params = array_merge( $params, $result['query-continue']['deletedrevs'] );
				} elseif ( isset( $result['continue'] ) ) {
					# New pagination
					$drcontinue = $result['continue']['drcontinue'];
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
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

			$revisionId = $revision['revid'];
			if ( !$revisionId ) {
				# Revision ID is mandatory with the new content tables and things will fail if not provided.
				$this->output( sprintf( "WARNING: Got revision without revision id, " .
					"with timestamp %s. Skipping!\n", $revision['timestamp'] ) );
				continue;
			}

			$titleObj = Title::makeTitle( $ns, $title );
			if ( $this->insertArchivedRevision( $revision, $titleObj ) ) {
				$nsRevisions++;
			}
		}

		return $nsRevisions;
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

}

$maintClass = 'GrabDeletedText';
require_once RUN_MAINTENANCE_IF_MAIN;
