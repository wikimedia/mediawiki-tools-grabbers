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

class GrabDeletedText extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab deleted text from an external wiki and import it into one of ours.";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
		# $this->addOption( 'start', 'Revision at which to start', false, true );
		$this->addOption( 'startdate', 'Not yet implemented.', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17T, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'drcontinue', 'For the idiot brigade, API continue to restart deleted revision process', false, true );
		$this->addOption( 'carlb', 'Tells the script to use lower API limits', false, false );
		$this->addOption( 'lasttitle', 'Last title to get; useful for working around content with a namespace/interwiki on top of it in mw1.19-', false, true );
		$this->addOption( 'badstart', 'Actual start point if bad drcontinues force having to continue from earlier (mw1.19- issue)', false, true );
		$this->addOption( 'repair', 'Fill in holes in an existing import', false, false );
	}

	public function execute() {
		global $bot, $endDate, $wgDBname, $lastRevision, $endDate, $lastTitle, $badStart, $repair;

		$repair = $this->getOption( 'repair' );
		$carlb = $this->getOption( 'carlb' );
		$lastTitle = $this->getOption( 'lasttitle' );
		$badStart = $this->getOption( 'badstart' );
		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", true );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );
		if ( !$user || !$password ) {
			$this->error( "An admin username and password are required.\n", true );
		}

		$startDate = $this->getOption( 'startdate' );
		if ( $startDate && !wfTimestamp( TS_ISO_8601, $startDate ) ) {
			$this->error( "Invalid startdate format.\n", true );
		}
		# End date isn't necessarily supported by source wikis, but we'll deal with that later.
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			$endDate = wfTimestamp( TS_MW, $endDate );
			if ( !$endDate ) {
				$this->error( "Invalid enddate format.\n", true );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		# bot class and log in
		$bot = new MediaWikiBot(
			$url,
			'json',
			$user,
			$password,
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);
		if ( !$bot->login() ) {
			$this->output( "Logged in as $user...\n" );
			# Does the user have deletion rights?
			$params = array(
				'list' => 'allusers',
				'aulimit' => '1',
				'auprop' => 'rights',
				'aufrom' => $user
			);
			$result = $bot->query( $params );
			if ( !in_array( 'deletedtext', $result['query']['allusers'][0]['rights'] ) ) {
				$this->error( "$user does not have required rights to fetch deleted revisions.", true );
			}
		} else {
			$this->error( "Failed to log in as $user.", true );
		}

		$pageList = array();
		$this->output( "\n" );

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
			if ( $ns < 0 || $ns >= 400 ) {
				continue;
			}
			$textNamespaces[] = $ns;
		}
		if ( !$textNamespaces ) {
			$this->error( 'Got no namespaces...', true );
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

			$params = array(
				'list' => 'deletedrevs',
				'drnamespace' => $ns,
				'drlimit' => 'max',
				'drdir' => 'newer',
				'drprop' => 'revid|user|userid|comment|minor|content|parentid',
			);
			if ( $carlb ) {
				# 50 was apparently too much.
				$params['drlimit'] = 10;
			}

			while ( $more ) {
				if ( $drcontinue === null ) {
					unset( $params['drcontinue'] );
				} else {
					# Check for 1.19 bug with the drcontinue that causes the query to jump backward on colonspaces, but we need something to compare back to for this...
					if ( !$carlb && isset( $params['drcontinue'] ) ) {
						$oldcontinue = $params['drcontinue'];
						if ( substr( str_replace( ' ', '_', $drcontinue ), 0, -15 ) < substr( str_replace( ' ', '_', $oldcontinue ), 0, -15 ) ) {
							$this->error( 'Bad drcontinue; ' . str_replace( ' ', '_', $drcontinue ) . ' < ' . str_replace( ' ', '_', $oldcontinue ), true );
						}
					}
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
					$nsRevisions = $this->processDeletedRevisions( $pageChunk, $nsRevisions );

					if ( isset( $result['query-continue'] ) ) {
						$drcontinue = str_replace( '&', '%26', $result['query-continue']['deletedrevs']['drcontinue'] );
					} else {
						$drcontinue = null;
					}
					$more = !( $drcontinue === null );
				}
			}
			$this->output( "$nsRevisions chunks of revisions processed in namespace $ns.\n" );
			$revisions_processed += $nsRevisions;
		}

		$this->output( "\n" );
		$this->output( "Saved $revisions_processed deleted revisions.\n" );

		# Done.
	}

	# Stores revision texts in the text table.
	function storeText( $text ) {
		global $wgDBname;

		$dbw = wfGetDB( DB_MASTER, array(), $this->getOption( 'db', $wgDBname ) );
		$old_id = $dbw->nextSequenceValue( 'text_old_id_seq' );

		$dbw->insert(
			'text',
			array(
				'old_id' => $old_id,
				'old_text' => $text,
				'old_flags' => ''
			),
			__METHOD__
		);

		return $dbw->insertId();
	}

	# Add deleted revisions to the archive and text tables
	# Takes results in chunks because that's how the API returns pages - with chunks of revisions.
	function processDeletedRevisions( $pageChunk, $nsRevisions ) {
		global $wgContLang, $wgDBname, $endDate, $lastTitle, $badStart, $repair;

		# Go back if we're not actually to the start point yet.
		if ( $badStart && ( str_replace( ' ', '_', $badStart ) > str_replace( ' ', '_', $pageChunk['title'] ) ) ) {
			return $nsRevisions;
		}

		$ns = $pageChunk['ns'];
		$title = $this->sanitiseTitle( $ns, $pageChunk['title'] );

		if ( $lastTitle && ( str_replace( ' ', '_', $pageChunk['title'] ) > str_replace( ' ', '_', $lastTitle ) ) ) {
			$this->error( "Stopping at {$pageChunk['title']}; lasttitle reached.\n", true );
		}
		$this->output( "Processing {$pageChunk['title']}\n" );

		$revisions = $pageChunk['revisions'];
		foreach ( $revisions as $revision ) {
			if ( $nsRevisions % 500 == 0 ) {
				$this->output( "$nsRevisions revisions inserted\n" );
			}
			# Stop if past the enddate
			$timestamp = wfTimestamp( TS_MW, $revision['timestamp'] );
			if ( $timestamp > $endDate ) {
				return $nsRevisions;
			}
			# If this is a repair run, check if it's already present and skip if it is
			if ( $repair ) {
				$dbr = wfGetDB( DB_SLAVE, array(), $this->getOption( 'db', $wgDBname ) );
				$result = $dbr->select(
					'archive',
					'ar_title',
					array(
						'ar_title' => $title,
						'ar_timestamp' => $timestamp,
						'ar_rev_id' => $revision['revid']
					),
					__METHOD__
				);
				if ( $dbr->fetchObject( $result ) ) {
					continue;
				}
			}

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
				'timestamp' => $timestamp,
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

			$nsRevisions++;
			# $this->output( "Changes committed to the database!\n" );
		}

		return $nsRevisions;
	}

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
