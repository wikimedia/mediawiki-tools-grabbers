<?php
/**
 * Maintenance script to grab images from a wiki and import them to another
 * wiki.
 * Translated from Misza's python version.
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @version 0.5
 * @date 14 June 2015
 */

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/grabbers/ and we don't need to move this file to $IP/maintenance/.
 */
ini_set( 'include_path', dirname( __FILE__ ) . '/../maintenance' );

require_once( 'Maintenance.php' );

class GrabImages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grab images from an external wiki and import them into one of ours.';
		$this->addOption( 'import', 'Import images after grabbing them?', false, false, 'i' );
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'from', 'Name of file to start from', false /* required? */, true /* withArg */ );
	}

	/**
	 * The function to grab images from a specified URL
	 */
	public function execute() {
		$wikiURL = $this->getOption( 'url' );
		if ( !$wikiURL ) {
			$this->error( 'The URL to the source wiki\'s api.php must be specified!', true );
		}

		if ( $this->getOption( 'from' ) ) {
			$aifrom = $this->getOption( 'from' );
		} else {
			$aifrom = null;
		}

		$conf = $this->getOption( 'conf' );
		$doImport = $this->hasOption( 'import' );

		ini_set( 'allow_url_fopen', 1 );

		$folder = $this->getWorkingDirectory();
		wfMkdirParents( $folder );

		if( !file_exists( $folder ) ) {
			$this->error( "Error creating temporary folder {$folder}\n", true );
			return false;
		}

		$this->output( 'The directory where images will be stored in is: ' . $folder . "\n" );
		$imgGrabbed = 0;
		$imgOK = 0;

		$params = array(
			'action' => 'query',
			'format' => 'json',
			'list' => 'allimages',
			'aiprop' => 'url|sha1',
			'ailimit' => '500'
		);

		$more = true;
		$images = array();

		$i = 0;

		do {
			if ( $aifrom === null ) {
				$FUCKING_SECURITY_REDIRECT_BULLSHIT = '';
				unset( $params['aifrom'] );
			} else {
				$FUCKING_SECURITY_REDIRECT_BULLSHIT = '&amp;*';
				$params['aifrom'] = $aifrom;
			}
			// Anno Domini 2015 and we (who's we?) still give a crap about IE6,
			// apparently. Giving a crap about ancient IEs also means taking a
			// dump over my API requests, it seems. Without this fucking bullshit
			// param in the URL, $result will be the kind of HTML described in
			// https://phabricator.wikimedia.org/T91439#1085120 instead of the
			// JSON we're expecting. Lovely. Just fucking lovely.
			// --an angry ashley on 14 June 2015
			// @see https://phabricator.wikimedia.org/T91439
			$q = $this->getOption( 'url' ) . '?' . wfArrayToCGI( $params ) . $FUCKING_SECURITY_REDIRECT_BULLSHIT;
			$this->output( 'Going to query the URL ' . $q . "\n" );
			$result = Http::get( $q, 'default',
				// Fake up the user agent string, just in case...
				// Firefox's (thanks Solar Dragon!) because IE's is problematic
				// IE-like user agents are blocked from certain pages, "thanks"
				// to IE6's braindead handling of everything and its security
				// issues
				array( 'userAgent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1' )
			);
			$data = json_decode( $result, true );

			// No images -> bail out early
			if ( empty( $data['query']['allimages'] ) ) {
				$this->error(
					'Got no images or other files -> nothing to do!',
					true
				);
			}

			$this->output( "In do loop (instance {$i})...\n" );

			foreach ( $data['query']['allimages'] as $img ) {
				$this->output( "in foreach (instance {$i})... \n" );
				# bad translation of the original python code that is fucking up shit
				# sorry, I don't know how this should be done, someone who's
				# more python-savvy can fix this. --ashley 20 June 2012
				#if ( preg_match( '/\//', $img['name'] ) != -1 ) { # FAIL! FAIL! FAIL!
				#	continue;
				#}
				if ( substr( $img['name'], 0, 1 ) == ':' ) {
					continue;
				}

				$imgGrabbed += 1;

				// Check for the presence of Wikia's Vignette's parameters and
				// if they're there, remove 'em to ensure that the files are
				// saved under their correct names.
				// @see http://community.wikia.com/wiki/User_blog:Nmonterroso/Introducing_Vignette,_Wikia%27s_New_Thumbnailer
				if ( preg_match( '/\/revision\/latest\?cb=(.*)$/', $img['url'], $matches ) ) {
					$img['url'] = preg_replace( '/\/revision\/latest\?cb=(.*)$/', '', $img['url'] );
				}
				$this->saveFile( $img['url'] );

				$hash = sha1_file( $folder . '/' . $img['name'] );
				if ( $img['sha1'] && $img['sha1'] == $hash ) {
					$imgOK += 1;
				} else {
					$this->output( $img['name'] . '- HASH NOT OK (expected: ' . $img['sha1'] . ' but got ' . $hash . ")\n" );
				}

				if ( isset( $data['query-continue'] ) ) {
					$aifrom = $data['query-continue']['allimages']['aifrom'];
				} else {
					$aifrom = null;
				}

				$more = !( $aifrom === null );
				$i++;
			}
		} while( $more );

		if ( $imgGrabbed % 100 == 0 ) {
			$this->output( 'grabbed: ' . $imgGrabbed . ', errors: ' . ( $imgGrabbed - $imgOK ) . "\n" );
		}

		if ( $images && $doImport ) {
			global $IP, $wgFileExtensions, $wgPhpCli;
			$this->output( "Commencing importImages script...\n" );

			$fileExtensions = implode( ' ', $wgFileExtensions );

			$command = "{$wgPhpCli} {$IP}/maintenance/importImages.php {$folder} {$fileExtensions} --conf {$conf}";
			$result = wfShellExec( $command, $retval );

			if( $retval ) {
				$this->output( "importImages script failed - returned value was: $retval\n" );
				return false;
			} else {
				$this->output( "importImages script executed successfully\n" );
				return true;
			}
		} elseif ( $images && !$doImport ) {
			$this->output( 'Grabbed ' . count( $images ) . " successfully!\n" );
		}
	}

	/**
	 * Simple wrapper for Http::get & file_put_content.
	 * Some basic checking is provided.
	 *
	 * @param $url String: image URL
	 * @param $path String: image local path, if null will take last part of URL
	 * @return Boolean: status
	 */
	function saveFile( $url, $path = null ) {
		if ( is_null( $path ) ) {
			$elements = explode( '/', $url );
			$path = array_pop( $elements );
			$path = sprintf( '%s/%s', $this->getWorkingDirectory(), urldecode( $path ) );
		}

		$status = file_put_contents( $path, Http::get( $url, 60 ) );

		if ( !empty( $status ) ) {
			$this->output( "Store {$url} as {$path} OK\n" );
			return true;
		} else {
			$this->output( "Store {$url} as {$path} FAILED\n" );
			return false;
		}
	}

	/**
	 * Get the path to the directory where the grabbed images will be stored in.
	 * On Windows, we place them into a subdirectory in this folder, because
	 * Windows is Windows. In reality that's just because I don't care much
	 * about Windows compatibility but I need to be able to test this script
	 * on Windows.
	 *
	 * The wiki's subdomain (e.g. "foo" in "foo.example.com/w/api.php) will
	 * be used as the directory name.
	 *
	 * @return String: path to the place where the images will be stored
	 */
	function getWorkingDirectory() {
		// First remove the protocol from the URL...
		$newURL = str_replace( 'http://', '', $this->getOption( 'url' ) );
		// Then turn it into an array...
		$urlArray = explode( '.', $newURL );
		// And grab its first element, that will be the subdomain
		$workingDirectory = $urlArray[0];

		$retVal = ( wfIsWindows() ?
			dirname( __FILE__ ) . '/image-grabber/' . $workingDirectory :
			'/tmp/image-grabber/' . $workingDirectory );

		return $retVal;
	}
}

$maintClass = 'GrabImages';
require_once( RUN_MAINTENANCE_IF_MAIN );
