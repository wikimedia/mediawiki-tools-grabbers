<?php
/**
 * Grabs logs from a pre-existing wiki into a new wiki.
 * Useless without the correct revision table entries and whatnot (because
 * otherwise MediaWiki can't tell that page with the ID 231 is
 * "User:Jack Phoenix").
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>,
 *         Jesús Martínez <martineznovo@gmail.com>
 * @version 2.0
 * @date 5 August 2019
 * @note Based on code by:
 * - Edward Chernenko <edwardspec@gmail.com> (MediaWikiDumper 1.1.5, logs.pl)
 */

use MediaWiki\Linker\LinkTarget;

require_once 'includes/ExternalWikiGrabber.php';

class GrabLogs extends ExternalWikiGrabber {

	/**
	 * API limits to use instead of max
	 *
	 * @var int
	 */
	protected $apiLimits;

	/**
	 * API limits to use instead of max
	 *
	 * @var array
	 */
	protected $validLogTypes;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs logs from a pre-existing wiki into a new wiki.';
		$this->addOption( 'start', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc)', false, true );
		$this->addOption( 'end', 'Log time at which to stop (20121222142317, 2012-12-22T14:23:17Z, etc)', false, true );
		$this->addOption( 'apilimits', 'API limits to use. Maximum limits for the user will be used by default', false, true );
		$this->addOption( 'logtypes', 'Process only logs of those types (pipe separated list). All logs will be processed by default', false, true );
	}

	public function execute() {
		parent::execute();

		$apiLimits = $this->getOption( 'apilimits' );
		if ( !is_null( $apiLimits ) && is_numeric( $apiLimits ) && (int)$apiLimits > 0 ) {
			$this->apiLimits = (int)$apiLimits;
		} else {
			$this->apiLimits = null;
		}

		$validLogTypes = $this->getOption( 'logtypes' );
		if ( !is_null( $validLogTypes ) ) {
			$this->validLogTypes = explode( '|', $validLogTypes );
		}

		$params = [
			'list' => 'logevents',
			'lelimit' => $this->getApiLimit(),
			'ledir' => 'newer',
			'leprop' => 'ids|title|type|user|userid|timestamp|comment|details|tags',
		];

		$lestart = $this->getOption( 'start' );
		if ( $lestart ) {
			$lestart = wfTimestamp( TS_ISO_8601, $lestart );
			if ( !$lestart ) {
				$this->fatalError( 'Invalid start timestamp format.' );
			}
		}
		$leend = $this->getOption( 'end' );
		if ( $leend ) {
			$leend = wfTimestamp( TS_ISO_8601, $leend );
			if ( !$leend ) {
				$this->fatalError( 'Invalid end timestamp format.' );
			}
		}
		$more = true;
		$i = 0;

		# List of log IDs processed in the last batch, as a guard against
		# primary key errors. Api paginates by timestamp, but a maintenance
		# script could've added lots of entries with the same timestamp, and
		# pagination may repeat entries from previous batch if pagination cuts
		# in the middle. We're going to store the log IDs processed in the
		# previous batch to avoid primary key errors (it should be faster than
		# querying the database to see if it's already inserted).
		# Note that if we just store the last log ID and check against it, it
		# may fail because entries with the same timestamp are not guaranteed
		# to be returned in the same order. This has been solved in recent
		# MediaWiki versions where continuation parameters also contains log ID
		$processedInPrevBatch = [];

		$this->output( "Fetching log events...\n" );
		do {
			if ( $lestart === null ) {
				unset( $params['lestart'] );
			} else {
				$params['lestart'] = $lestart;
			}
			if ( !is_null( $leend ) ) {
				$params['leend'] = $leend;
			}
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['logevents'] ) ) {
				$this->output( "No log events found...\n" );
				break;
			}

			$currentIDs = [];

			foreach ( $result['query']['logevents'] as $logEntry ) {
				if ( !in_array( $logEntry['logid'], $processedInPrevBatch ) &&
					( is_null( $this->validLogTypes ) ||
					in_array( $logEntry['type'], $this->validLogTypes ) )
				) {
					$this->processEntry( $logEntry );
				}
				$currentIDs[] = $logEntry['logid'];

				if ( isset( $result['query-continue'] ) ) {
					$lestart = $result['query-continue']['logevents']['lestart'];
				} else {
					$lestart = null;
				}

				$more = !( $lestart === null );
				$i++;
				if ( $i % 500 == 0 ) {
					$this->output( "{$i} logs fetched...\n" );
				}
			}

			$processedInPrevBatch = $currentIDs;

		} while ( $more );

		$this->output( "Done. {$i} logs fetched.\n" );
	}

	public function processEntry( $entry ) {
		# Handler for reveleted stuff or some such
		$revdeleted = 0;
		if ( isset( $entry['actionhidden'] ) ) {
			$revdeleted = $revdeleted | LogPage::DELETED_ACTION;
			if ( !isset( $entry['title'] ) ) {
				$entry['title'] = '';
				$entry['ns'] = 0;
			}
		}
		if ( isset( $entry['commenthidden'] ) ) {
			$revdeleted = $revdeleted | LogPage::DELETED_COMMENT;
			if ( !isset( $entry['comment'] ) ) {
				$entry['comment'] = '';
			}
		}
		if ( isset( $entry['userhidden'] ) ) {
			$revdeleted = $revdeleted | LogPage::DELETED_USER;
			if ( !isset( $entry['user'] ) ) {
				$entry['user'] = '';
				$entry['userid'] = 0;
			}
		}
		if ( isset( $entry['suppressed'] ) ) {
			$revdeleted = $revdeleted | LogPage::DELETED_RESTRICTED;
		}

		$title = $entry['title'];
		$ns = $entry['ns'];
		$title = $this->sanitiseTitle( $ns, $title );

		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );
		if ( $ts < 20080000000000 && preg_match( '/^Wikia\-/', $entry['user'], $matches ) ) {
			# A tiny bug on Wikia in 2006-2007, affects ~10 log entries only
			if ( isset( $matches[0] ) ) {
				$entry['user'] = substr( $entry['user'], 0, 6 );
			}
		}

		$e = [
			'log_id' => $entry['logid'],
			'log_type' => $entry['type'],
			'log_action' => $entry['action'],
			'log_timestamp' => $ts,
			'log_namespace' => $ns,
			'log_title' => $title,
			# This is now handled using builtin MediaWiki code below...
			#'log_user' => $entry['userid'],
			#'log_user_text' => $entry['user'],
			#'log_comment' => $wgContLang->truncateForDatabase( $entry['comment'], 255 ),
			'log_params' => $this->encodeLogParams( $entry ),
			'log_deleted' => $revdeleted
		];

		# May not be set in older MediaWiki instances. This field can be null
		# Note that it contains the page id at the time the log was inserted,
		# not the current page id of the title.
		if ( isset( $entry['logpage'] ) ) {
			$e['log_page'] = $entry['logpage'];
		}

		# Bits of code picked from ManualLogEntry::insert()
		$e += CommentStore::getStore()->insert( $this->dbw, 'log_comment', $entry['comment'] );
		$performer = User::newFromIdentity( $this->getUserIdentity( (int)$entry['userid'], $entry['user'] ) );
		$e += ActorMigration::newMigration()
			->getInsertValues( $this->dbw, 'log_user', $performer );

		$this->dbw->insert( 'logging', $e, __METHOD__ );

		# Insert tags, if any
		if ( isset( $entry['tags'] ) && count( $entry['tags'] ) > 0 ) {
			$this->insertTags( $entry['tags'], null, $entry['logid']);
		}

		$this->dbw->commit();
	}

	/**
	 * Get the params from a log entry from the api and returns the parameters
	 * as they should be stored in the log_params field of the database
	 *
	 * @param array $entry Log entry as returned from the api
	 * @return string Parameters serialized to store in DB
	 */
	function encodeLogParams( $entry ) {
		$encodedParams = '';

		# There are 2 formats of log parameters:
		# - serialized array: they come as an associative array inside a
		#   property with the same name as the action (old MW),
		#   or a "params" property.
		# - plain text with newlines: they come as numeric indexes inside
		#   the entry, each index is a line. This is a legacy format

		$explicitParams = null;
		if ( isset( $entry['params'] ) ) {
			$explicitParams = $entry['params'];
		} elseif ( isset( $entry[$entry['type']] ) ) {
			$explicitParams = $entry[$entry['type']];
		}
		if ( !is_null( $explicitParams ) && count( $explicitParams ) > 0 ) {
			# Api does some transformations to array parameters, we need to encode
			# them again to store them in database.
			# This sucks horribly.
			# Checked against MediaWiki 1.29
			$unserializedParams = [];
			switch ( $entry['type'] ) {
				case 'move':
					# Since MediaWiki 1.25 target title comes as target_title/target_ns.
					# It was new_title/new_ns before.
					if ( isset( $explicitParams['target_title'] ) ) {
						$unserializedParams['4::target'] = $explicitParams['target_title'];
					} elseif ( isset( $explicitParams['new_title'] ) ) {
						$unserializedParams['4::target'] = $explicitParams['new_title'];
					}
					# Since MediaWiki 1.25 it's suppressredirect.
					# It was suppressedredirect before.
					$unserializedParams['5::noredir'] = (int)(
						isset( $explicitParams['suppressredirect'] ) ||
						isset( $explicitParams['suppressedredirect'] )
					);
					break;
				case 'patrol':
					# Since MediaWiki 1.25 it's curid/previd.
					# It was cur/prev before
					if ( isset( $explicitParams['curid'] ) ) {
						$unserializedParams['4::curid'] = (int)$explicitParams['curid'];
					} elseif ( isset( $explicitParams['cur'] ) ) {
						$unserializedParams['4::curid'] = (int)$explicitParams['cur'];
					}
					if ( isset( $explicitParams['previd'] ) ) {
						$unserializedParams['5::previd'] = (int)$explicitParams['previd'];
					} elseif ( isset( $explicitParams['prev'] ) ) {
						$unserializedParams['5::previd'] = (int)$explicitParams['prev'];
					}
					$unserializedParams['6::auto'] = (int)isset( $explicitParams['auto'] );
					break;
				case 'rights':
					# Since MediaWiki 1.25 it's oldgroups/newgroups (in array format).
					# It was old/new before (in comma separated list format)
					if ( isset( $explicitParams['oldgroups'] ) ) {
						$unserializedParams['4::oldgroups'] = $explicitParams['oldgroups'];
					} elseif ( isset( $explicitParams['old'] ) ) {
						$unserializedParams['4::oldgroups'] = $this->makeGroupArray( $explicitParams['old'] );
					}
					if ( isset( $explicitParams['newgroups'] ) ) {
						$unserializedParams['5::newgroups'] = $explicitParams['newgroups'];
					} elseif ( isset( $explicitParams['new'] ) ) {
						$unserializedParams['5::newgroups'] = $this->makeGroupArray( $explicitParams['new'] );
					}
					if ( isset( $explicitParams['oldmetadata'] ) ) {
						# Transform metadata: Discard group, transform expriry timestamp
						$unserializedParams['oldmetadata'] = $this->mapToTransformations(
							$explicitParams['oldmetadata'],
							[],
							[ 'expiry' ]
						);
					}
					if ( isset( $explicitParams['newmetadata'] ) ) {
						# Transform metadata: Discard group, transform expriry timestamp
						$unserializedParams['newmetadata'] = $this->mapToTransformations(
							$explicitParams['newmetadata'],
							[],
							[ 'expiry' ]
						);
					}
					break;
				case 'block':
					if ( isset( $explicitParams['duration'] ) ) {
						$unserializedParams['5::duration'] = $explicitParams['duration'];
					}
					if ( isset( $explicitParams['flags'] ) ) {
						# Since MediaWiki 1.25 it comes as an array. Comma separated list otherwise
						if ( is_array( $explicitParams['flags'] ) ) {
							$unserializedParams['6::flags'] = implode( ',', $explicitParams['flags'] );
						} else {
							$unserializedParams['6::flags'] = $explicitParams['flags'];
						}
					}
					break;
				case 'protect':
					if ( isset( $explicitParams['description'] ) ) {
						$unserializedParams['4::description'] = $explicitParams['description'];
					}
					$unserializedParams['5:bool:cascade'] = isset( $explicitParams['cascade'] );
					$unserializedParams['details'] = $this->mapToTransformations(
							$explicitParams['details'],
							[ 'type', 'level' ],
							[ 'expiry' ],
							[ 'cascade' ]
						);
					break;
				case 'delete':
					if ( isset( $explicitParams['count'] ) ) {
						$unserializedParams[':assoc:count'] = $explicitParams['count'];
					}
					if ( isset( $explicitParams['type'] ) ) {
						$unserializedParams['4::type'] = $explicitParams['type'];
					}
					if ( isset( $explicitParams['ids'] ) ) {
						$unserializedParams['5::ids'] = $explicitParams['ids'];
					}
					if ( isset( $explicitParams['old'] ) ) {
						$unserializedParams['6::ofield'] = $explicitParams['old']['bitmask'];
					}
					if ( isset( $explicitParams['new'] ) ) {
						$unserializedParams['7::nfield'] = $explicitParams['new']['bitmask'];
					}
					break;
				case 'upload':
					$unserializedParams = $this->mapToTransformations(
							$explicitParams,
							[ 'img_sha1' ],
							[ 'img_timestamp' ]
						);
					break;
				case 'merge':
					if ( isset( $explicitParams['dest_title'] ) ) {
						$unserializedParams['4::dest'] = $explicitParams['dest_title'];
					}
					if ( isset( $explicitParams['mergepoint'] ) ) {
						$unserializedParams['5::mergepoint'] = wfTimestamp( TS_MW, $explicitParams['mergepoint'] );
					}
					break;
				default:
					# Since MediaWiki 1.25, legacy plain text format is also
					# returned as an array inside the params object, with
					# numeric keys. Detect this case to use the legacy format.
					if ( count( $explicitParams ) == count( array_filter(
						$explicitParams,
						function( $key ) { return is_numeric( $key ); } ) )
					)
					{
						$index = 0;
						$lines = [];
						while ( isset( $entry[(string)$index] ) ) {
							$lines[] = $entry[(string)$index];
							$index++;
						}
						return $encodedParams = implode( "\n", $lines );
					}
					# Otherwise just pass through...
					# It may insert parameters using the wrong format if they
					# provide custom formatters, since we're not aware of them
					$unserializedParams = $explicitParams;
					break;
			}
			$encodedParams = serialize( $unserializedParams );
		} else {
			$index = 0;
			$lines = [];
			while ( isset( $entry[(string)$index] ) ) {
				$lines[] = $entry[(string)$index];
				$index++;
			}
			if ( count( $lines ) > 0 ) {
				$encodedParams = implode( "\n", $lines );
			}
		}
		return $encodedParams;
	}

	/**
	 * Function from RightsLogFormatter.php
	 */
	private function makeGroupArray( $group ) {
		# Migrate old group params from string to array
		if ( $group === '' ) {
			$group = [];
		} elseif ( is_string( $group ) ) {
			$group = array_map( 'trim', explode( ',', $group ) );
		}
		return $group;
	}

	/**
	 * Pass an array containing associative arrays, creating a new array with
	 * transformations defined by parameters. Keys not defined in parameters
	 * won't be copied.
	 *
	 * @param array $origin Array containing the associative arrays
	 *        to transform.
	 * @param array $passThroughKeys List of keys that will be copied unmodified.
	 * @param array $timestampKeys List of keys to be transformed as timestamps
	 * @param array $booleanKeys List of keys to be transformed as booleans
	 * @return array Modified array
	 */
	private function mapToTransformations( $origin, $passThroughKeys, $timestampKeys = [], $booleanKeys = [] ) {
		$transformed = array_map( function( $item ) use ( $passThroughKeys, $timestampKeys, $booleanKeys ) {
			$result = [];
			foreach ( $passThroughKeys as $key ) {
				if ( isset( $item[$key] ) ) {
					$result[$key] = $item[$key];
				}
			}
			foreach ( $timestampKeys as $key ) {
				if ( isset( $item[$key] ) ) {
					if ( $item[$key] == 'infinity' ) {
						$result[$key] = null;
					} else {
						$result[$key] = wfTimestamp( TS_MW, $item[$key] );
					}
				}
			}
			foreach ( $booleanKeys as $key ) {
				$result[$key] = isset( $item[$key] );
			}
			return $result;
		}, array_values( $origin ) );
		return $transformed;
	}

	/**
	 * Returns the standard api result limit for queries
	 *
	 * @return int limit provided by user, or 'max' to use the maximum
	 *          allowed for the user querying the api
	 */
	function getApiLimit() {
		if ( is_null( $this->apiLimits ) ) {
			return 'max';
		}
		return $this->apiLimits;
	}
}

$maintClass = 'GrabLogs';
require_once RUN_MAINTENANCE_IF_MAIN;
