<?php
/**
 * Maintenance script to grab namespace info from a wiki to use with a new wiki.
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @version 0.6
 * @date 1 January 2013
 */

# Because we're in core/grabbers instead of core/maintenance
ini_set( 'include_path', dirname( __FILE__ ) . '/../maintenance' );

require_once( "Maintenance.php" );
require_once( "mediawikibot.class.php" );

class GrabNamespaceInfo extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Get namespace info from a source wiki to add to your LocalSettings.php";
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
	}

	public function execute() {
		global $bot, $wgDBname, $lastRevision;
		$url = $this->getOption( 'url' );
		if( !$url ) {
			$this->error( "The URL to the source wiki\'s api.php must be specified!\n", true );
		}

		# bot class
		$bot = new MediaWikiBot(
			$url,
			'json',
			'',
			'',
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);

		$this->output( "\n" );
		$this->parseNamespaces();

		# Done.
	}

	# Custom namespaces - make a list as these will need to be added to the localsettings/whatever
	function parseNamespaces() {
		global $bot;
		$params = array(
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|namespacealiases'
		);
		$result = $bot->query( $params );
		if ( !$result['query'] ) {
			$this->error( 'Got no namespaces...', true );
		}
		$namespaces = $result['query']['namespaces'];
		$customNamespaces = array();

		$contentNamespaces = array();
		$subpageNamespaces = array();
		foreach( array_keys( $namespaces ) as $ns ) {
			# Content?
			if ( isset( $namespaces[$ns]['content'] ) ) {
				$contentNamespaces[] = $ns;
			}
			# Subpages?
			if ( isset( $namespaces[$ns]['subpages'] ) ) {
				$subpageNamespaces[] = $ns;
			}
			if ( $ns >= 100 ) {
				$customNamespaces[$ns] = $namespaces[$ns]['canonical'];
			}
		}
		$namespaceAliases = array();	# $wgNamespaceAliases['WP'] = NS_PROJECT;
		foreach( $result['query']['namespacealiases'] as $nsa ) {
			$namespaceAliases[$nsa['*']] = $nsa['id'];
		}

		# Show stuff
		$this->output( "# Extra namespaces or some such\n" );
		foreach( array_keys( $customNamespaces ) as $ns ) {
			$namespaceName = str_replace( ' ', '_', $customNamespaces[$ns] );
			$this->output( '$wgExtraNamespaces[' . $ns . '] = "' . $namespaceName . '";' . "\n" );
		}

		# Print content namespace configuration if any
		if ( count( $contentNamespaces ) > 1 ) {
			$this->output( "\n# Content namespaces\n" );
			$this->output( '$wgContentNamespaces = array_merge(' . "\n" );
			$this->output( "\t" . '$wgContentNamespaces,' . "\n" );
			$this->output( "\t" . 'array( ' );
			$this->output( $contentNamespaces[1] );

			foreach( array_keys( $contentNamespaces ) as $i ) {
				if ( $i > 1 ) {
					$this->output( ", {$contentNamespaces[$i]}" );
				}
			}
			$this->output( " )\n" );
			$this->output( ");\n" );
		}

		# Print subpage namespace configuration if any
		# TO IMPLEMENT; currently the common default configuration just assumes all of them

		# Print namespaceAliases if any
		if ( count( $namespaceAliases ) > 2 ) {
			$this->output( "\n# Namespace aliases\n" );
			foreach ( array_keys( $namespaceAliases ) as $nsa ) {
				# Ignore if image/image talk; that's core
				if ( $namespaceAliases[$nsa] != 6 && $namespaceAliases[$nsa] != 7 ) {
					$this->output( '$wgNamespaceAliases["' . $nsa . '"] = ' . $namespaceAliases[$nsa] . ';' . "\n" );
				}
			}
		}

		$this->output( "\n" );
	}
}

$maintClass = 'GrabNamespaceInfo';
require_once( RUN_MAINTENANCE_IF_MAIN );
