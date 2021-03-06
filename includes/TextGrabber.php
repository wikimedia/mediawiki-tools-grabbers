<?php
/**
 * Base class used for text grabbers
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 5 August 2019
 * @version 1.1
 * @note Based on code by Calimonious the Estrange, Misza, Jack Phoenix and Edward Chernenko.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

require_once 'ExternalWikiGrabber.php';

abstract class TextGrabber extends ExternalWikiGrabber {

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Whether our wiki supports page counters, to use counters if remote wiki also has them
	 *
	 * @var bool
	 */
	protected $supportsCounters;

	/**
	 * Instance of the RevisionStore service
	 *
	 * @var RevisionStore
	 */
	protected $revisionStore;

	/**
	 * Instance of blob storage
	 *
	 * @var SqlBlobStore
	 */
	protected $blobStore = null;

	/**
	 * Instance of content model storage
	 *
	 * @var NameTableStore
	 */
	protected $contentModelStore = null;

	/**
	 * Instance of slot roles storage
	 *
	 * @var NameTableStore
	 */
	protected $slotRoleStore = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17, etc); defaults to current timestamp. May leave pages in inconsistent state if page moves are involved', false, true );
	}

	public function execute() {
		parent::execute();

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $this->endDate ) ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$services = MediaWikiServices::getInstance();
		$this->revisionStore = $services->getRevisionStore();
		$this->blobStore = $services->getBlobStore();
		$this->contentModelStore = $services->getContentModelStore();
		$this->slotRoleStore = $services->getSlotRoleStore();

		# Check if wiki supports page counters (removed from core in 1.25)
		$this->supportsCounters = $this->dbw->fieldExists( 'page', 'page_counter', __METHOD__ );
	}

	/**
	 * Process an individual page revision.
	 * NOTE: This does not populate the ip_changes tables.
	 *
	 * @param array $revision Array retrieved from the API, containing the revision
	 *     text, ID, timestamp, whether it was a minor edit or not and much more
	 * @param int $page_id Page ID number of the revision we are going to insert
	 * @param Title $title Title object of this page
	 * @return bool Whether revision has been inserted or not
	 */
	function processRevision( $revision, $page_id, $title ) {
		$revid = $revision['revid'];

		# Workaround check if it's already there.
		$rowCount = $this->dbw->selectRowCount(
			'revision',
			'rev_id',
			[ 'rev_id' => $revid ],
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
			$revdeleted = $revdeleted | RevisionRecord::DELETED_USER;
			if ( !isset( $revision['user'] ) ) {
				$revision['user'] = ''; # username removed
			}
			if ( !isset( $revision['userid'] ) ) {
				$revision['userid'] = 0;
			}
		}
		if ( isset( $revision['commenthidden'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_COMMENT;
			$comment = ''; # edit summary removed
		} else {
			$comment = $revision['comment'];
			if ( !$comment ) {
				$comment = '';
			}
		}
		if ( isset( $revision['texthidden'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_TEXT;
			$text = ''; # This content has been removed.
		} else {
			$text = $revision['*'];
		}
		if ( isset ( $revision['suppressed'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_RESTRICTED;
		}

		$this->output( "Inserting revision {$revid}\n" );

		$rev = new MutableRevisionRecord( $title );
		$content = ContentHandler::makeContent( $text, $title );
		$rev->setId( $revid );
		$rev->setContent( SlotRecord::MAIN, $content );
		$rev->setComment( CommentStoreComment::newUnsavedComment( $comment ) );
		$rev->setVisibility( $revdeleted );
		$rev->setTimestamp( $revision['timestamp'] );
		$rev->setMinorEdit( isset( $revision['minor'] ) );
		$userIdentity = $this->getUserIdentity( $revision['userid'], $revision['user'] );
		$rev->setUser( User::newFromIdentity( $userIdentity ) );
		$rev->setPageId( $page_id );
		$rev->setParentId( $revision['parentid'] );
		$this->revisionStore->insertRevisionOn( $rev, $this->dbw );

		# Insert tags, if any
		if ( isset( $revision['tags'] ) && count( $revision['tags'] ) > 0 ) {
			$this->insertTags( $revision['tags'], $revid );
		}

		$this->dbw->commit();

		return true;
	}

	/**
	 * Inserts individual revisions retrieved from the api to the archive table
	 *
	 * @param array $revision Array retrieved from the API, containing the revision
	 *     text, ID, timestamp, whether it was a minor edit or not and much more
	 * @param Title $title Title object of this page
	 * @return bool Whether revision has been inserted or not
	 */
	function insertArchivedRevision( $revision, $title ) {
		global $wgMultiContentRevisionSchemaMigrationStage;

		$revisionId = $revision['revid'];
		$timestamp = wfTimestamp( TS_MW, $revision['timestamp'] );
		$parentID = null;
		if ( isset( $revision['parentid'] ) ) {
			$parentID = $revision['parentid'];
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$comment = '';
		$text = '';
		$revdeleted = 0;
		if ( isset( $revision['userhidden'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_USER;
			if ( !isset( $revision['user'] ) ) {
				$revision['user'] = ''; # username removed
			}
			if ( !isset( $revision['userid'] ) ) {
				$revision['userid'] = 0;
			}
		}
		if ( isset( $revision['commenthidden'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_COMMENT;
		}
		if ( isset( $revision['comment'] ) ) {
			$comment = $revision['comment'];
		}
		if ( isset( $revision['texthidden'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_TEXT;
		}
		if ( isset( $revision['*'] ) ) {
			$text = $revision['*'];
		}
		if ( isset ( $revision['suppressed'] ) ) {
			$revdeleted = $revdeleted | RevisionRecord::DELETED_RESTRICTED;
		}

		# This can probably break if user was suppressed and we don't have permissions to view it
		$performer = User::newFromIdentity( $this->getUserIdentity( (int)$revision['userid'], $revision['user'] ) );

		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $this->dbw, 'ar_comment', $comment );
		$actorMigration = ActorMigration::newMigration();
		$actorFields = $actorMigration->getInsertValues( $this->dbw, 'ar_user', $performer );

		$e = [
			'ar_namespace' => $title->getNamespace(),
			'ar_title' => $title->getDBkey(),
			#'ar_comment' => $comment,
			#'ar_user' => $revision['userid'],
			#'ar_user_text' => $revision['user'],
			'ar_timestamp' => $timestamp,
			'ar_minor_edit' => ( isset( $revision['minor'] ) ? 1 : 0 ),
			'ar_rev_id' => $revisionId,
			'ar_deleted' => $revdeleted,
			'ar_len' => strlen( $text ),
			'ar_sha1' => SlotRecord::base36Sha1( $text ),
			#'ar_page_id' => NULL, # Not requred and unreliable from api
			'ar_parent_id' => $parentID,
		] + $commentFields + $actorFields;

		# Create content object
		$content = ContentHandler::makeContent( $text, $title );
		$slot = SlotRecord::newUnsaved( SlotRecord::MAIN, $content );

		# Insert text (blob)
		# From RevisionStore::storeContentBlob()
		$blobAddress = $this->blobStore->storeBlob(
			$content->serialize( $content->getDefaultFormat() )
		);

		# Insert content
		$this->output( "Inserting archived revision {$revisionId}\n" );

		# From RevisionStore::insertSlotOn()
		# Write the main slot's text ID to the revision table for backwards compatibility
		if ( $wgMultiContentRevisionSchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) {
			$textId = $this->blobStore->getTextIdFromAddress( $blobAddress );
			$e['ar_text_id'] = $textId;
		}

		if ( $wgMultiContentRevisionSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
			# From RevisionStore::insertContentRowOn()
			$contentRow = [
				'content_size' => $slot->getSize(),
				'content_sha1' => $slot->getSha1(),
				'content_model' => $this->contentModelStore->acquireId( $slot->getModel() ),
				'content_address' => $blobAddress,
			];
			$this->dbw->insert( 'content', $contentRow, __METHOD__ );
			$contentId = intval( $this->dbw->insertId() );

			# From RevisionStore::insertSlotRowOn()
			$slotRow = [
				'slot_revision_id' => $revisionId,
				'slot_role_id' => $this->slotRoleStore->acquireId( $slot->getRole() ),
				'slot_content_id' => $contentId,
				'slot_origin' => $revisionId,
			];
			$this->dbw->insert( 'slots', $slotRow, __METHOD__, [ 'IGNORE' ] );
			if ( $this->dbw->affectedRows() === 0 ) {
				$this->output( "slot_revision_id {$revisionId} already exists in slots table; skipping\n" );
				return false;
			}
		}

		$this->dbw->insert(
			'archive',
			$e,
			__METHOD__,
			[ 'IGNORE' ] // in case of duplicates?!
		);
		if ( $this->dbw->affectedRows() === 0 ) {
			$this->output( "Revision {$revisionId} already exists in archive table; skipping\n" );
			return false;
		}

		# Insert tags, if any
		if ( isset( $revision['tags'] ) && count( $revision['tags'] ) > 0 ) {
			$this->insertTags( $revision['tags'], $revisionId, null );
		}
		$this->dbw->commit();

		return true;
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
		if ( !in_array( (string)$pageTitle, $this->movedTitles ) ) {
			$this->movedTitles[] = (string)$pageTitle;
		}

		# Get current title of the existing local page ID and move it to where it belongs
		$params = [
			'prop' => 'info',
			'pageids' => $conflictingPageID
		];
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
			if ( !in_array( (string)$resultingPageTitle, $this->movedTitles ) ) {
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
						[ 'page_id' => $resultingPageID ],
						__METHOD__
					);
					$this->dbw->delete(
						'page',
						[ 'page_id' => $resultingPageID ],
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
				[
					'page_namespace' => $resultingNs,
					'page_title' => $resultingTitle,
				],
				[ 'page_id' => $conflictingPageID ],
				__METHOD__
			);
		}
		return $pageObj;
	}
}
