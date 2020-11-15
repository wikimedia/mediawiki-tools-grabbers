<?php
/**
 * Updates an existing database that has imported files from a pre-existing
 * to its current state.
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 5 August 2019
 * @version 1.0
 * @note Based on code by Calimonious the Estrange, Misza, Jack Phoenix and Edward Chernenko.
 */

require_once 'includes/FileGrabber.php';

class GrabNewFiles extends FileGrabber {

	/**
	 * Start date
	 *
	 * @var MWTimestamp
	 */
	protected $startDate;

	/**
	 * End date
	 *
	 * @var MWTimestamp
	 */
	protected $endDate;

	/**
	 * A list of page titles of images that have uploads pending
	 *
	 * @var array
	 */
	protected $pendingUploads = [];

	/**
	 * A list of page titles of images that need checking for deletions and restores.
	 * Associative array with reason and user, necessary for deleting files
	 *
	 * @var array
	 */
	protected $pendingDeletions = [];

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grabs updates to files from an external wiki\nFor use when files have been imported already and want to keep track of new uploads.";
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc); note that this cannot go back further than 1-3 months on most projects.', true /* required? */, true /* withArg */ );
		$this->addOption( 'enddate', 'Date after which to ignore new files (20121222142317, 2012-12-22T14:23:17Z, etc); note that the process may fail to process existing files that have been moved after this date', false, true );
	}

	public function execute() {
		parent::execute();

		$this->startDate = $this->getOption( 'startdate' );
		if ( $this->startDate ) {
			$this->startDate = wfTimestamp( TS_MW, $this->startDate );
			if ( !$this->startDate ) {
				$this->fatalError( 'Invalid startdate format.' );
			}
		} else {
			$this->fatalError( 'A timestamp to start from is required.' );
		}

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			$this->endDate = wfTimestamp( TS_MW, $this->endDate );
			if ( !$this->endDate ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$params = [
			'list' => 'logevents',
			'leprop' => 'ids|title|type|timestamp|details|comment|userid',
			'leend' => (string)$this->endDate,
			'ledir' => 'newer',
			'lestart' => (string)$this->startDate,
			'lelimit' => 'max'
		];

		$more = true;
		$count = 0;

		$this->output( "Processing and downloading changes from files...\n" );
		while ( $more ) {
			$result = $this->bot->query( $params );
			if ( empty( $result['query']['logevents'] ) ) {
				$this->output( "No changes found...\n" );
				break;
			}

			# This is very fragile if people start to mess with moves,
			# deletions and restores.
			# Our strategy: Do it step by step to minimize inconsistencies.
			# Remote wiki can have file renames applied that conflict with our
			# titles, so delay uploads of affected titles to the end, while we
			# can keep track of moves of existing files from our database.
			foreach ( $result['query']['logevents'] as $logEntry ) {
				if ( $logEntry['ns'] == NS_FILE ) {
					$title = $logEntry['title'];
					if ( $logEntry['type'] == 'upload' ) {
						# This is for uploads, reuploads and reverts
						$this->processNewUpload( $title, $logEntry['timestamp'] );
					} elseif ( $logEntry['type'] == 'delete' ) {
						# This affects deletions of the entire page but also
						# deleting a file version, or restores
						$this->processDeletion( $title, $logEntry );
					} elseif ( $logEntry['type'] == 'move' ) {
						if ( isset( $logEntry['move'] ) ) {
							$newTitle = $logEntry['move']['new_title'];
						} else {
							$newTitle = $logEntry['params']['target_title'];
						}
						$this->processMove( $title, $newTitle, (int)$logEntry['userid'] );
					}
				}
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['logevents'] ) ) {
				$params = array_merge( $params, $result['query-continue']['logevents'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
		}
		# Process pending queues now
		foreach ( $this->pendingUploads as $title ) {
			$this->processPendingUploads( $title );
		}
		foreach ( $this->pendingDeletions as $title => $deleteParams ) {
			$this->processPendingDeletions( $title, $deleteParams['reason'], $deleteParams['user'] );
		}
		$this->output( "Done.\n" );
	}

	/**
	 * Process a new upload from the log. If it's a reupload, moves our
	 * current file to oldimage
	 *
	 * @param string $title Title of the file page to process
	 * @param string $timestamp Timestamp of the upload returned by API
	 */
	function processNewUpload( $title, $timestamp ) {
		# Return early if it has been queued
		if ( in_array( $title, $this->pendingUploads ) || array_key_exists( $title, $this->pendingDeletions ) ) {
			return;
		}

		$name = $this->sanitiseTitle( NS_FILE, $title );

		# Check current timestamp of the file on local wiki
		$img_timestamp = $this->dbw->selectField(
			'image',
			'img_timestamp',
			[ 'img_name' => $name ],
			__METHOD__
		);

		$endtimestamp = $timestamp;
		$moveOldImage = false;

		if ( $img_timestamp ) {
			# File exists on local wiki, need to move local file to oldimage,
			# and for that we need the timestamp of the archived version

			# Ensure we don't have a more recent version, this should never happen
			if ( wfTimestamp( TS_MW, $timestamp ) < $img_timestamp ) {
				$this->output( "Current file $name is more recent than remote wiki\n" );
				return;
			}
			$endtimestamp = wfTimestamp( TS_ISO_8601, $img_timestamp );
			$moveOldImage = true;
		}

		$params = [
			'titles' => $title,
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|metadata|archivename|bitdepth|mediatype',
			# Limit information to the exact time the upload happened
			'iistart' => $timestamp,
			'iiend' => $endtimestamp,
			# We don't care about continuation, it shouldn't be more than 2 files here
			'iilimit' => 'max'
		];
		$result = $this->bot->query( $params );

		# Api always returns an entry for title
		$page = array_values( $result['query']['pages'] )[0];

		if ( !isset( $page['imageinfo'] ) || empty( $page['imageinfo'] ) ) {
			# Image does not exist, or there's no revision from the time range
			# This may happen if the file has been renamed after this change
			$this->output( "File $name with timestamp $timestamp not found.\n" );
			$this->pendingUploads[] = $title;
			return;
		}
		# Find file revision at the timestamp of the log, and also
		# what we have as our current file
		$currentEntry = null;
		$oldArchiveName = null;
		foreach ( $page['imageinfo'] as $fileVersion ) {
			if ( is_null( $currentEntry ) ) {
				if ( $fileVersion['timestamp'] == $timestamp ) {
					$currentEntry = $fileVersion;

					# Check for Wikia's videos
					if ( $this->isWikiaVideo( $fileVersion ) ) {
						$this->output( "...this appears to be a video, skipping it.\n" );
						return 0;
					}
				} else {
					$this->output( "File $name with timestamp $timestamp not found.\n" );
					$this->pendingUploads[] = $title;
					return;
				}
			}
			if ( $moveOldImage && $fileVersion['timestamp'] == $endtimestamp && isset( $fileVersion['archivename'] ) ) {
				# Our current revision is found, move to archive to leave room for the upload
				$oldArchiveName = $fileVersion['archivename'];
				$ok = $this->moveToOldFile( $name, $oldArchiveName, $img_timestamp );
				if ( !$ok ) {
					$this->output( "Skipping file update of $name from $timestamp\n" );
					return;
				}
				break;
			}
		}
		if ( $moveOldImage && is_null( $oldArchiveName ) ) {
			# Local file doesn't seem to exist on remote wiki (anymore)
			# May be deleted after this change
			# Simply kill it from database before importing. The physical
			# file will be overwritten/moved on upload
			$moveOldImage = false;
			$this->dbw->delete(
				'image',
				[ 'img_name' => $name ],
				__METHOD__
			);
		}
		$this->newUpload( $name, $currentEntry );
	}

	/**
	 * Archives the current version of a file to oldimage in the
	 * database and also moves the physical file to the archive
	 *
	 * @param string $name Name of the file
	 * @param string $oldArchiveName Archive name
	 * @param string $oldTimestamp Archive timestamp in TS_MW format
	 * @return bool Whether succeeded or not
	 */
	function moveToOldFile( $name, $oldArchiveName, $oldTimestamp ) {
		global $wgActorTableSchemaMigrationStage;

		$this->output( "Moving current file $name to archive $oldArchiveName\n" );

		$fields = [
			'oi_name' => 'img_name',
			'oi_archive_name' => $this->dbw->addQuotes( $oldArchiveName ),
			'oi_size' => 'img_size',
			'oi_width' => 'img_width',
			'oi_height' => 'img_height',
			'oi_bits' => 'img_bits',
			'oi_timestamp' => 'img_timestamp',
			#'oi_description' => 'img_description',
			#'oi_user' => 'img_user',
			#'oi_user_text' => 'img_user_text',
			'oi_metadata' => 'img_metadata',
			'oi_media_type' => 'img_media_type',
			'oi_major_mime' => 'img_major_mime',
			'oi_minor_mime' => 'img_minor_mime',
			'oi_sha1' => 'img_sha1'
		];

		# This is from LocalFile::recordUpload2()
		if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) {
			$fields['oi_user'] = 'img_user';
			$fields['oi_user_text'] = 'img_user_text';
		}
		if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
			$fields['oi_actor'] = 'img_actor';
		}

		$this->dbw->begin();
		$this->dbw->insertSelect( 'oldimage', 'image',
			$fields,
			[ 'img_name' => $name ],
			__METHOD__
		);
		$this->dbw->delete(
			'image',
			[ 'img_name' => $name ],
			__METHOD__
		);
		$file = $this->localRepo->newFile( $name );
		$oldFile = $this->localRepo->newFile( $name, $oldTimestamp );
		$status = $oldFile->publish( $file->getPath(), File::DELETE_SOURCE );
		if ( !$status->isOK() ) {
			$this->output( sprintf( "Error moving current file %s to archive: %s\n",
				$name, $status->getWikiText() ) );
			$this->dbw->rollback();
			return false;
		}
		# Remove thumbnails (if any)
		$file->purgeThumbnails();
		$this->dbw->commit();
		return true;
	}

	/**
	 * Moves a file from one title to another. If target exists, deletes it
	 *
	 * @param $title string Old title of the file
	 * @param $newTitle string New title of the file
	 * @param $userId int User id which performed the move, used only when
	 *          the target title needs to be deleted
	 */
	function processMove( $title, $newTitle, $userId ) {
		$this->output( "Processing move of $title to $newTitle... " );
		# If the target page existed, it was deleted
		# If we have the new title queued, remove it
		if ( in_array( $newTitle, $this->pendingUploads ) ) {
			array_splice( $this->pendingUploads, array_search( $newTitle, $this->pendingUploads ), 1 );
		}
		# Reflect the rename in pendingUploads
		if ( in_array( $title, $this->pendingUploads ) ) {
			if ( !in_array( $newTitle, $this->pendingUploads ) ) {
				$this->pendingUploads[] = $newTitle;
			}
			array_splice( $this->pendingUploads, array_search( $title, $this->pendingUploads ), 1 );
		}
		# Same in pendingDeletions
		if ( array_key_exists( $title, $this->pendingDeletions ) ) {
			if ( !array_key_exists( $newTitle, $this->pendingDeletions ) ) {
				$this->pendingDeletions[$newTitle] = $this->pendingDeletions[$title];
			}
			unset( $this->pendingDeletions[$title] );
		}
		# Check if we have a file with the new name and delete it
		$newName = $this->sanitiseTitle( NS_FILE, $newTitle );
		$file = $this->localRepo->newFile( $newName );
		if ( $file->exists() ) {
			$this->output( "$newTitle aleady exists. Deleting to make room for the move. " );
			$reason = wfMessage( 'delete_and_move_reason', $newTitle );
			# NOTE: File::delete takes care to do the related changes
			# in the database. For instance, move rows to filearchive
			# Use the user that performed the move for the deletion
			$status = $file->delete( $reason, false, User::newFromId( $userId ) );
			if ( !$status->isOK() ) {
				$this->fatalError( sprintf( "Failed to delete %s on move: %s",
					$newName, implode( '. ', $status->getWikiText() ) ) );
			}
		}
		# Perform the move if the file with old name exists
		$name = $this->sanitiseTitle( NS_FILE, $title );
		$file = $this->localRepo->newFile( $name );
		if ( !$file->exists() ) {
			# File may be uploaded and moved since startDate. Nothing to do
			# If it has been deleted after the move, we'll catch it later
			$this->output( "$title doesn't exist on our wiki, will process it later.\n" );
			return;
		}
		# NOTE: File::move() takes care to do the related changes in the
		# database. For instance, rename the file in image and oldimage
		$status = $file->move( Title::makeTitle( NS_FILE, $newName ) );
		if ( !$status->isOK() ) {
			$this->fatalError( sprintf( "Failed to move %s: %s",
				$name, implode( '. ', $status->getWikiText() ) ) );
		}
		$this->output( "Done\n" );
	}

	/**
	 * Adds to the queue of pending deletions, removing from the
	 * pending uploads if necessary
	 *
	 * @param $title string title of the file
	 * @param $logEntry Array log entry
	 */
	function processDeletion( $title, $logEntry ) {
		$this->output( "$title has been deleted or restored. We'll take care of it later.\n" );
		# Remove it from pending uploads since we're going to recheck everything
		if ( in_array( $title, $this->pendingUploads ) ) {
			array_splice( $this->pendingUploads, array_search( $title, $this->pendingUploads ), 1 );
		}
		# We add it to the array even if it exists already
		if ( $logEntry['action'] == 'delete' ) {
			# Update deletion reason and user, in case we had a restore action
			$this->pendingDeletions[$title] = [
				'reason' => $logEntry['comment'],
				'user' => User::newFromId( (int)$logEntry['userid'] )
			];
		} elseif ( !array_key_exists( $title, $this->pendingDeletions ) ) {
			# Check to avoid overwritting the deletion reason or user on restore,
			# which we don't need on file restore
			$this->pendingDeletions[$title] = [
				'reason' => '',
				'user' => null
			];
		}
	}

	/**
	 * Process pending uploads once we're sorted out file move operations
	 * Uploads file revisions between startDate and endDate including current
	 * version of the file
	 *
	 * @param $title string Title of the file to process
	 */
	function processPendingUploads( $title ) {
		$name = $this->sanitiseTitle( NS_FILE, $title );
		$this->output( "Processing pending uploads for $name..." );

		# We'll need to move our current image to oldimage, but we still
		# don't know the archive name. Store current values in memory
		$imageObj = $this->dbw->selectRow(
			'image',
			'*',
			[ 'img_name' => $name ],
			__METHOD__
		);

		# NOTE: imageinfo returns revisions from newer to older. startDate is older
		$timestamp = wfTimestamp( TS_ISO_8601, $this->endDate );
		$endtimestamp = wfTimestamp( TS_ISO_8601, $this->startDate );
		$moveOldImage = false;

		if ( $imageObj ) {
			# File exists on local wiki, need to move local file to oldimage,
			# and for that we need the timestamp of the archived version
			$endtimestamp = wfTimestamp( TS_ISO_8601, $imageObj->img_timestamp );
			$moveOldImage = true;
		}

		$overwrittenArchiveName = null;
		$params = [
			'titles' => $title,
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|metadata|archivename|bitdepth|mediatype',
			'iiend' => $endtimestamp,
			'iilimit' => 'max'
		];
		$iistart = $timestamp;
		$more = true;
		$count = 0;
		while ( $more ) {
			$params['iistart'] = $iistart;
			$result = $this->bot->query( $params );
			# Api always returns an entry for title
			$page = array_values( $result['query']['pages'] )[0];
			if ( !isset( $page['imageinfo'] ) || empty( $page['imageinfo'] ) ) {
				# Image does not exist, or there's no revision from the time range
				$this->output( "File $name does not have uploads for the time range.\n" );
				return;
			}
			foreach ( $page['imageinfo'] as $fileVersion ) {
				# Api returns file revisions from new to old.
				# WARNING: If a new version of a file is uploaded after the start of the script
				# (or endDate), the file and all its previous revisions would be skipped,
				# potentially leaving pages that were using the old image with redlinks.
				# To prevent this, we'll skip only more recent versions, and mark the first
				# one before the end date as the latest
				if ( !$count && isset( $fileVersion['archivename'] ) ) {
					unset( $fileVersion['archivename'] );
				}

				# Check for Wikia's videos
				if ( $this->isWikiaVideo( $fileVersion ) ) {
					$this->output( "...this appears to be a video, skipping it.\n" );
					return;
				}

				# The code will enter this conditional only on the last iteration,
				# or before if it happens that there are multiple files with
				# the same timestamp than our latest version
				if ( $moveOldImage && $fileVersion['timestamp'] == $endtimestamp ) {
					# Our current revision is found.
					# It should've been archived somewhere after being replaced.
					# Move the archived file to where it belongs
					if ( $count > 0 && !is_null( $overwrittenArchiveName ) ) {
						$this->output( sprintf( 'Moving overwritten archive %s to timestamp %s... ',
							$overwrittenArchiveName, $imageObj->img_timestamp ) );
						$zombieFile = $this->localRepo->newFromArchiveName( $name, $overwrittenArchiveName );
						$archivedFile = $this->localRepo->newFile( $name, $imageObj->img_timestamp );
						$archivedFile->publish( $zombieFile->getPath(), File::DELETE_SOURCE );
						$this->output( "Done\n" );
					}
					break;
				}

				if ( $count > 0 ) {
					# Old upload
					$status = $this->oldUpload( $name, $fileVersion );
				} else {
					# New upload
					$this->dbw->begin();
					if ( $moveOldImage ) {
						# Delete existing row from image before importing
						$this->dbw->delete(
							'image',
							[ 'img_name' => $name ],
							__METHOD__
						);
					}
					$status = $this->newUpload( $name, $fileVersion );
					if ( !$status->isOK() ) {
						$this->dbw->rollback();
						return;
					} else {
						$this->dbw->commit();
						# When overwritting a file, it returns the generated
						# archive name in the status value
						if ( $moveOldImage && (string)$status->getValue() != '' ) {
							$overwrittenArchiveName = (string)$status->getValue();
							$this->output( "Upload resulted in an overwrite. Marking $overwrittenArchiveName for restore.\n" );
						}
					}
				}
				$count++;
			}
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['imageinfo'] ) ) {
				$params = array_merge( $params, $result['query-continue']['imageinfo'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
			$count++;
		}
	}

	/**
	 * Process files where a deletion or restore has been involved.
	 * Checks the entire remote file history against ours, updating as needed
	 *
	 * @param $title string Title of the file to process
	 * @param $reason string Last deletion reason
	 * @param $user User User which performed the last deletion
	 */
	function processPendingDeletions( $title, $reason, $user ) {
		$name = $this->sanitiseTitle( NS_FILE, $title );
		$this->output( "Processing pending deletions for $name..." );

		# We'll need to move our current image to oldimage, but we still
		# don't know the archive name. Store current values in memory
		$imageObj = $this->dbw->selectRow(
			'image',
			'*',
			[ 'img_name' => $name ],
			__METHOD__
		);

		$moveOldImage = false;
		$fileHistoryResult = null;
		$currentHistoryEntry = false;
		$idsToRestore = []; # filearchive IDs
		if ( $imageObj ) {
			$ourtimestamp = wfTimestamp( TS_ISO_8601, $imageObj->img_timestamp );
			$moveOldImage = true;

			# Get file history
			$fileHistoryResult = $this->dbw->select(
				'oldimage',
				[ 'oi_timestamp' ],
				[ 'oi_name' => $name ],
				__METHOD__,
				[ 'ORDER BY' => 'oi_timestamp DESC' ]
			);
			$currentHistoryEntry = $fileHistoryResult ? $fileHistoryResult->fetchRow() : false;
		}

		$overwrittenArchiveName = null;
		$params = [
			'titles' => $title,
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|metadata|archivename|bitdepth|mediatype',
			'iilimit' => 'max'
		];
		$iistart = null;
		$more = true;
		$count = 0;
		# NOTE: imageinfo returns revisions from newer to older
		while ( $more ) {
			$result = $this->bot->query( $params );
			# Api always returns an entry for title
			$page = array_values( $result['query']['pages'] )[0];
			if ( !isset( $page['imageinfo'] ) || empty( $page['imageinfo'] ) ) {
				# Image does not exist, or there's no revision
				# If we have the image, delete it
				if ( $moveOldImage ) {
					$this->output( "File $name does not have uploads. Deleting our file... " );
					$file = $this->localRepo->newFile( $name );
					$file->delete( $reason, false, $user );
					$this->output( "Done\n" );
				} else {
					$this->output( "File $name does not have uploads and doesn't exist in our wiki.\n" );
				}
				return;
			}
			foreach ( $page['imageinfo'] as $fileVersion ) {
				$timestamp = wfTimestamp( TS_MW, $fileVersion['timestamp'] );

				# Check for Wikia's videos
				if ( $this->isWikiaVideo( $fileVersion ) ) {
					$this->output( "...this appears to be a video, skipping it.\n" );
					return;
				}

				if ( $moveOldImage && $fileVersion['timestamp'] == $ourtimestamp ) {
					# Our current revision is found.
					# It should've been archived somewhere after being replaced.
					# Move the archived file to where it belongs if it's not the top version
					if ( $count > 0 && !is_null( $overwrittenArchiveName ) ) {
						$this->output( sprintf( 'Moving overwritten archive %s to timestamp %s... ',
							$overwrittenArchiveName, $imageObj->img_timestamp ) );
						$zombieFile = $this->localRepo->newFromArchiveName( $name, $overwrittenArchiveName );
						$archivedFile = $this->localRepo->newFile( $name, $imageObj->img_timestamp );
						$archivedFile->publish( $zombieFile->getPath(), File::DELETE_SOURCE );
						$this->output( "Done\n" );
					}
					# Mark it as already processed, even if we haven't changed anything.
					# For example if both wikis have the same top file version
					$count++;
					$moveOldImage = false;
					continue;
				}

				if ( $count > 0 ) {
					# Old upload
					# Advance the iterator until the timestamp of this revision to see if we already have it
					# We're going from newer to older, so oi_timestamp is decreasing
					while ( $currentHistoryEntry && $currentHistoryEntry['oi_timestamp'] > $timestamp ) {
						# $count = 0 is for image, $count = 1 would be the
						# first version of oldimage. We need to stay on the
						# current row the first time to avoid skipping the
						# first oldimage version
						if ( $count > 1 ) {
							$currentHistoryEntry = $fileHistoryResult->fetchRow();
						}
						if ( $currentHistoryEntry['oi_timestamp'] > $timestamp ) {
							# Still newer? It means this revision is not on the remote wiki
							# Delete it
							$this->output( "Deleting old version with timestamp {$currentHistoryEntry['oi_timestamp']}..." );
							$file = $this->localRepo->newFile( $name, $currentHistoryEntry['oi_timestamp'] );
							$file->deleteOld( $file->getArchiveName(), $reason, false, $user );
							$this->output( "Done\n" );
							# Increase $count so we don't get stuck in an infinite loop on first oldimage version
							$count++;
						}
					}
					if ( $currentHistoryEntry && $currentHistoryEntry['oi_timestamp'] == $timestamp ) {
						# We already have this revision, skip it
						continue;
					}
					# The revision isn't on our database
					# See if it's deleted
					$id = $this->dbw->selectField(
						'filearchive',
						'fa_id',
						[ 'fa_name' => $name, 'fa_timestamp' => $timestamp ],
						__METHOD__
					);
					if ( $id ) {
						$this->output( "Marking version {$fileVersion['timestamp']} for restore.\n" );
						$idsToRestore[] = $id;
						$status = Status::newGood();
					} else {
						$status = $this->oldUpload( $name, $fileVersion );
					}
				} else {
					# New upload
					$this->dbw->begin();
					if ( $moveOldImage ) {
						if ( $fileVersion['timestamp'] == $ourtimestamp ) {
							# If the most recent image is already our latest
							# one, nothing to overwrite
							$moveOldImage = false;
							continue;
						}
						# Delete existing row from image before importing
						$this->dbw->delete(
							'image',
							[ 'img_name' => $name ],
							__METHOD__
						);
					}
					# See if it's deleted
					$id = $this->dbw->selectField(
						'filearchive',
						'fa_id',
						[ 'fa_name' => $name, 'fa_timestamp' => $timestamp ],
						__METHOD__
					);
					if ( $id ) {
						$this->output( "Marking version {$fileVersion['timestamp']} for restore.\n" );
						$idsToRestore[] = $id;
						$status = Status::newGood();
					} else {
						$status = $this->newUpload( $name, $fileVersion );
					}
					if ( !$status->isOK() ) {
						$this->dbw->rollback();
						return;
					} else {
						$this->dbw->commit();
						# When overwritting a file, it returns the generated
						# archive name in the status value
						if ( $moveOldImage && (string)$status->getValue() != '' ) {
							$overwrittenArchiveName = (string)$status->getValue();
							$this->output( "Upload resulted in an overwrite. Marking $overwrittenArchiveName for restore.\n" );
						}
					}
				}
				$count++;
			}
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['imageinfo'] ) ) {
				$params = array_merge( $params, $result['query-continue']['imageinfo'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
			$count++;
		}
		if ( count( $idsToRestore ) > 0 ) {
			$this->output( sprintf( 'Restoring filearchive IDs %s... ', implode( ',', $idsToRestore ) ) );
			$file->restore( $idsToRestore, true );
			$this->output( "Done\n" );
		}
	}

}

$maintClass = 'GrabNewFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
