<?php
/**
 * MediaWiki page data importer.
 *
 * This file is adapted from the REL1_39 branch of core WikiImporter class,
 * codes related to log and file import are removed. With some other changes
 * to preservethe revision-deleted flags, and provide raw infomation that
 * our importDump.php needs.
 *
 * Copyright © 2003,2005 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use Wikimedia\NormalizedException\NormalizedException;

/**
 * XML file reader for the page data importer.
 */
class WikiImportHandler {
	/** @var XMLReader */
	private $reader;

	/** @var array|null */
	private $foreignNamespaces = null;

	/** @var callable|null */
	private $mRevisionCallback;

	/** @var callable|null */
	private $mPageCallback;

	/** @var callable|null */
	private $mSiteInfoCallback;

	/** @var callable|null */
	private $mPageOutCallback;

	/** @var callable|null */
	private $mNoticeCallback;

	/** @var bool|null */
	private $mDebug;

	/** @var bool */
	private $mNoUpdates = true;

	/** @var int */
	private $pageOffset = 0;

	/**
	 * Creates an ImportXMLReader drawing from the source provided
	 * @param ImportSource $source
	 * @throws MWException
	 */
	public function __construct( ImportSource $source ) {
		if ( !in_array( 'uploadsource', stream_get_wrappers() ) ) {
			stream_wrapper_register( 'uploadsource', UploadSourceAdapter::class );
		}
		$id = UploadSourceAdapter::registerSource( $source );

		// Enable the entity loader, as it is needed for loading external URLs via
		// XMLReader::open (T86036)
		// phpcs:ignore Generic.PHP.NoSilencedErrors -- suppress deprecation per T268847
		$oldDisable = @libxml_disable_entity_loader( false );
		if ( PHP_VERSION_ID >= 80000 ) {
			// A static call is now preferred, and avoids https://github.com/php/php-src/issues/11548
			$reader = XMLReader::open(
				"uploadsource://$id", null, LIBXML_PARSEHUGE );
			if ( $reader instanceof XMLReader ) {
				$this->reader = $reader;
				$status = true;
			} else {
				$status = false;
			}
		} else {
			// A static call generated a deprecation warning prior to PHP 8.0
			$this->reader = new XMLReader;
			$status = $this->reader->open(
				"uploadsource://$id", null, LIBXML_PARSEHUGE );
		}
		if ( !$status ) {
			$error = libxml_get_last_error();
			// phpcs:ignore Generic.PHP.NoSilencedErrors
			@libxml_disable_entity_loader( $oldDisable );
			throw new MWException( 'Encountered an internal error while initializing WikiImporter object: ' .
				$error->message );
		}
		// phpcs:ignore Generic.PHP.NoSilencedErrors
		@libxml_disable_entity_loader( $oldDisable );
	}

	/**
	 * @return null|XMLReader
	 */
	public function getReader() {
		return $this->reader;
	}

	/**
	 * @param string $err
	 */
	public function throwXmlError( $err ) {
		$this->debug( "FAILURE: $err" );
		wfDebug( "WikiImporter XML error: $err" );
	}

	/**
	 * @param string $data
	 */
	public function debug( $data ) {
		if ( $this->mDebug ) {
			wfDebug( "IMPORT: $data" );
		}
	}

	/**
	 * @param string $data
	 */
	public function warn( $data ) {
		wfDebug( "IMPORT: $data" );
	}

	/**
	 * @param string $msg
	 * @param mixed ...$params
	 */
	public function notice( $msg, ...$params ) {
		if ( is_callable( $this->mNoticeCallback ) ) {
			call_user_func( $this->mNoticeCallback, $msg, $params );
		} else { # No ImportReporter -> CLI
			// T177997: the command line importers should call setNoticeCallback()
			// for their own custom callback to echo the notice
			wfDebug( wfMessage( $msg, $params )->text() );
		}
	}

	/**
	 * Set debug mode...
	 * @param bool $debug
	 */
	public function setDebug( $debug ) {
		$this->mDebug = $debug;
	}

	/**
	 * Sets 'pageOffset' value. So it will skip the first n-1 pages
	 * and start from the nth page. It's 1-based indexing.
	 * @param int $nthPage
	 * @since 1.29
	 */
	public function setPageOffset( $nthPage ) {
		$this->pageOffset = $nthPage;
	}

	/**
	 * Set a callback that displays notice messages
	 *
	 * @param callable $callback
	 * @return callable
	 */
	public function setNoticeCallback( $callback ) {
		return wfSetVar( $this->mNoticeCallback, $callback );
	}

	/**
	 * Sets the action to perform as each new page in the stream is reached.
	 * @param callable|null $callback
	 * @return callable|null
	 */
	public function setPageCallback( $callback ) {
		$previous = $this->mPageCallback;
		$this->mPageCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each page in the stream is completed.
	 * Callback accepts the page title (as a Title object), a second object
	 * with the original title form (in case it's been overridden into a
	 * local namespace), and a count of revisions.
	 *
	 * @param callable|null $callback
	 * @return callable|null
	 */
	public function setPageOutCallback( $callback ) {
		$previous = $this->mPageOutCallback;
		$this->mPageOutCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform as each page revision is reached.
	 * @param callable|null $callback
	 * @return callable|null
	 */
	public function setRevisionCallback( $callback ) {
		$previous = $this->mRevisionCallback;
		$this->mRevisionCallback = $callback;
		return $previous;
	}

	/**
	 * Sets the action to perform when site info is encountered
	 * @param callable $callback
	 * @return callable
	 */
	public function setSiteInfoCallback( $callback ) {
		$previous = $this->mSiteInfoCallback;
		$this->mSiteInfoCallback = $callback;
		return $previous;
	}

	/**
	 * Notify the callback function of site info
	 * @param array $siteInfo
	 * @return mixed|false
	 */
	private function siteInfoCallback( $siteInfo ) {
		if ( isset( $this->mSiteInfoCallback ) ) {
			return call_user_func_array(
				$this->mSiteInfoCallback,
				[ $siteInfo, $this ]
			);
		} else {
			return false;
		}
	}

	/**
	 * Notify the callback function when a new "<page>" is reached.
	 * @param array $pageInfo
	 * @return bool Whether to import this page
	 */
	private function pageCallback( $pageInfo ) {
		if ( isset( $this->mPageCallback ) ) {
			return call_user_func( $this->mPageCallback, $pageInfo ) ?? true;
		} else {
			return true;
		}
	}

	/**
	 * Notify the callback function when a "</page>" is closed.
	 * @param array $pageInfo Associative array of page information
	 */
	private function pageOutCallback( $pageInfo, $revisionInfo ) {
		if ( isset( $this->mPageOutCallback ) ) {
			call_user_func_array( $this->mPageOutCallback, func_get_args() );
		}
	}

	/**
	 * Notify the callback function of a revision
	 * @param array $pageInfo
	 * @param array $revisionInfo
	 * @return bool|mixed
	 */
	private function revisionCallback( $pageInfo, $revisionInfo ) {
		if ( isset( $this->mRevisionCallback ) ) {
			return call_user_func_array(
				$this->mRevisionCallback,
				func_get_args()
			);
		} else {
			return false;
		}
	}

	/**
	 * Retrieves the contents of the named attribute of the current element.
	 * @param string $attr The name of the attribute
	 * @return string The value of the attribute or an empty string if it is not set in the current
	 * element.
	 */
	public function nodeAttribute( $attr ) {
		return $this->reader->getAttribute( $attr ) ?? '';
	}

	/**
	 * Shouldn't something like this be built-in to XMLReader?
	 * Fetches text contents of the current element, assuming
	 * no sub-elements or such scary things.
	 * @return string
	 * @internal
	 */
	public function nodeContents() {
		if ( $this->reader->isEmptyElement ) {
			return "";
		}
		$buffer = "";
		while ( $this->reader->read() ) {
			switch ( $this->reader->nodeType ) {
				case XMLReader::TEXT:
				case XMLReader::CDATA:
				case XMLReader::SIGNIFICANT_WHITESPACE:
					$buffer .= $this->reader->value;
					break;
				case XMLReader::END_ELEMENT:
					return $buffer;
			}
		}

		$this->reader->close();
		return '';
	}

	/**
	 * Primary entry point
	 * @throws Exception
	 * @throws MWException
	 * @return bool
	 */
	public function doImport() {
		// Calls to reader->read need to be wrapped in calls to
		// libxml_disable_entity_loader() to avoid local file
		// inclusion attacks (T48932).
		// phpcs:ignore Generic.PHP.NoSilencedErrors -- suppress deprecation per T268847
		$oldDisable = @libxml_disable_entity_loader( true );
		try {
			$this->reader->read();

			if ( $this->reader->localName != 'mediawiki' ) {
				// phpcs:ignore Generic.PHP.NoSilencedErrors
				@libxml_disable_entity_loader( $oldDisable );
				$error = libxml_get_last_error();
				if ( $error ) {
					throw new NormalizedException( "XML error at line {line}: {message}", [
						'line' => $error->line,
						'message' => $error->message,
					] );
				} else {
					throw new MWException(
						"Expected '<mediawiki>' tag, got '<{$this->reader->localName}>' tag."
					);
				}
			}
			$this->debug( "<mediawiki> tag is correct." );

			$this->debug( "Starting primary dump processing loop." );

			$keepReading = $this->reader->read();
			$skip = false;
			$pageCount = 0;
			while ( $keepReading ) {
				$tag = $this->reader->localName;
				if ( $this->pageOffset ) {
					if ( $tag === 'page' ) {
						$pageCount++;
					}
					if ( $pageCount < $this->pageOffset ) {
						$keepReading = $this->reader->next();
						continue;
					}
				}
				$type = $this->reader->nodeType;

				if ( $tag == 'mediawiki' && $type == XMLReader::END_ELEMENT ) {
					break;
				} elseif ( $tag == 'siteinfo' ) {
					$this->handleSiteInfo();
				} elseif ( $tag == 'page' ) {
					$this->handlePage();
				} elseif ( $tag != '#text' ) {
					$this->warn( "Unhandled top-level XML tag $tag" );

					$skip = true;
				}

				if ( $skip ) {
					$keepReading = $this->reader->next();
					$skip = false;
					$this->debug( "Skip" );
				} else {
					$keepReading = $this->reader->read();
				}
			}
		} finally {
			// phpcs:ignore Generic.PHP.NoSilencedErrors
			@libxml_disable_entity_loader( $oldDisable );
			$this->reader->close();
		}

		return true;
	}

	private function handleSiteInfo() {
		$this->debug( "Enter site info handler." );
		$siteInfo = [];

		// Fields that can just be stuffed in the siteInfo object
		$normalFields = [ 'sitename', 'base', 'generator', 'case' ];

		while ( $this->reader->read() ) {
			if ( $this->reader->nodeType == XMLReader::END_ELEMENT &&
					$this->reader->localName == 'siteinfo' ) {
				break;
			}

			$tag = $this->reader->localName;

			if ( $tag == 'namespace' ) {
				$this->foreignNamespaces[$this->nodeAttribute( 'key' )] =
					$this->nodeContents();
			} elseif ( in_array( $tag, $normalFields ) ) {
				$siteInfo[$tag] = $this->nodeContents();
			}
		}

		$siteInfo['_namespaces'] = $this->foreignNamespaces;
		$this->siteInfoCallback( $siteInfo );
	}

	private function handlePage() {
		// Handle page data.
		$this->debug( "Enter page handler." );
		$pageInfo = [ 'revisionCount' => 0, 'successfulRevisionCount' => 0 ];
		$revisionInfo = null;

		// Fields that can just be stuffed in the pageInfo object
		$normalFields = [ 'title', 'ns', 'id', 'redirect', 'restrictions' ];

		$skip = false;
		$skipPage = null;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XMLReader::END_ELEMENT &&
					$this->reader->localName == 'page' ) {
				break;
			}

			$skip = false;

			$tag = $this->reader->localName;

			if ( $skipPage ) {
				// bail out of this page
				$skip = true;
			} elseif ( in_array( $tag, $normalFields ) ) {
				// An XML snippet:
				// <page>
				//     <id>123</id>
				//     <title>Page</title>
				//     <redirect title="NewTitle"/>
				//     ...
				// Because the redirect tag is built differently, we need special handling for that case.
				if ( $tag == 'redirect' ) {
					$pageInfo[$tag] = $this->nodeAttribute( 'title' );
				} else {
					$pageInfo[$tag] = $this->nodeContents();
				}
			} elseif ( $tag == 'revision' ) {
				// First revision of this page
				if ( $skipPage === null ) {
					$skipPage = !$this->pageCallback( $pageInfo );
					$skip = $skipPage;
				}

				if ( !$skip ) {
					$revisionInfo = $this->handleRevision( $pageInfo );
				}
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled page XML tag $tag" );
				$skip = true;
			}
		}

		// Not skipped and have revisions processed
		if ( $skipPage === false && $revisionInfo ) {
			$this->pageOutCallback( $pageInfo, $revisionInfo );
		}
	}

	/**
	 * @param array &$pageInfo
	 */
	private function handleRevision( &$pageInfo ) {
		$this->debug( "Enter revision handler" );
		$revisionInfo = [];

		$normalFields = [ 'id', 'parentid', 'timestamp', 'comment', 'minor', 'origin',
			'model', 'format', 'text', 'sha1' ];
		$fieldsWithDeleted = [ 'contributor', 'comment', 'text' ];

		$skip = false;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XMLReader::END_ELEMENT &&
					$this->reader->localName == 'revision' ) {
				break;
			}

			$tag = $this->reader->localName;

			if ( in_array( $tag, $fieldsWithDeleted ) ) {
				$revisionInfo['deleted'][$tag] = $this->nodeAttribute( 'deleted' ) === 'deleted';
			}

			if ( in_array( $tag, $normalFields ) ) {
				$revisionInfo[$tag] = $this->nodeContents();
			} elseif ( $tag == 'content' ) {
				// We can have multiple content tags, so make this an array.
				$revisionInfo[$tag][] = $this->handleContent();
			} elseif ( $tag == 'contributor' ) {
				$revisionInfo['contributor'] = $this->handleContributor();
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled revision XML tag $tag" );
				$skip = true;
			}
		}

		$pageInfo['revisionCount']++;
		if ( !$this->revisionCallback( $pageInfo, $revisionInfo ) ) {
			return false;
		}
		$pageInfo['successfulRevisionCount']++;

		return $revisionInfo;
	}

	private function handleContent() {
		$this->debug( "Enter content handler" );
		$contentInfo = [];

		$normalFields = [ 'role', 'origin', 'model', 'format', 'text' ];

		$skip = false;

		while ( $skip ? $this->reader->next() : $this->reader->read() ) {
			if ( $this->reader->nodeType == XMLReader::END_ELEMENT &&
				$this->reader->localName == 'content' ) {
				break;
			}

			$tag = $this->reader->localName;

			if ( in_array( $tag, $normalFields ) ) {
				$contentInfo[$tag] = $this->nodeContents();
			} elseif ( $tag != '#text' ) {
				$this->warn( "Unhandled content XML tag $tag" );
				$skip = true;
			}
		}

		return $contentInfo;
	}

	/**
	 * @return array
	 */
	private function handleContributor() {
		$this->debug( "Enter contributor handler." );

		if ( $this->reader->isEmptyElement ) {
			return [];
		}

		$fields = [ 'id', 'ip', 'username' ];
		$info = [];

		while ( $this->reader->read() ) {
			if ( $this->reader->nodeType == XMLReader::END_ELEMENT &&
					$this->reader->localName == 'contributor' ) {
				break;
			}

			$tag = $this->reader->localName;

			if ( in_array( $tag, $fields ) ) {
				$info[$tag] = $this->nodeContents();
			}
		}

		return $info;
	}
}
