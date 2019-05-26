<?php
/**
 * Maintenance script to grab namespace info from a wiki to use with a new wiki.
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @version 1.1
 * @date 5 August 2019
 */

require_once 'includes/ExternalWikiGrabber.php';

# Custom namespaces - make a list as these will need to be added to the localsettings/whatever
class GrabNamespaceInfo extends ExternalWikiGrabber {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Get namespace info from a source wiki to add to your LocalSettings.php";
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|namespacealiases'
		];
		$result = $this->bot->query( $params );
		if ( !$result['query'] ) {
			$this->fatalError( 'Got no namespaces...' );
		}
		$namespaces = $result['query']['namespaces'];
		$customNamespaces = [];

		$contentNamespaces = [];
		$subpageNamespaces = [];
		$namespaceAliases = [];	# $wgNamespaceAliases['WP'] = NS_PROJECT;
		foreach ( array_keys( $namespaces ) as $ns ) {
			# Content?
			if ( isset( $namespaces[$ns]['content'] ) ) {
				$contentNamespaces[] = $ns;
			}
			# Subpages?
			if ( isset( $namespaces[$ns]['subpages'] ) ) {
				$subpageNamespaces[] = $ns;
			}
			if ( $ns >= 100 ) {
				$customNamespaces[$ns] = $namespaces[$ns]['*'];
				# Wikis in languages other than English where the namespaces are
				# provided by extensions, have localized namespace in *, use it as
				# the primary name and add canonical as an alias.
				if ( $namespaces[$ns]['*'] != $namespaces[$ns]['canonical'] ) {
					$namespaceAliases[$namespaces[$ns]['canonical']] = $ns;
				}
			}
		}
		foreach ( $result['query']['namespacealiases'] as $nsa ) {
			$namespaceAliases[$nsa['*']] = $nsa['id'];
		}

		# Show stuff
		$this->output( "# Extra namespaces\n" );
		foreach ( array_keys( $customNamespaces ) as $ns ) {
			$namespaceName = str_replace( ' ', '_', $customNamespaces[$ns] );
			$this->output( '$wgExtraNamespaces[' . $ns . '] = "' . $namespaceName . '";' . "\n" );
		}

		# Print content namespace configuration if any
		if ( count( $contentNamespaces ) > 1 ) {
			$this->output( "\n# Content namespaces\n" );
			$this->output( '$wgContentNamespaces = array_merge(' . "\n" );
			$this->output( "\t" . '$wgContentNamespaces,' . "\n" );
			$this->output( "\t" . '[ ' );
			$this->output( $contentNamespaces[1] );

			foreach ( array_keys( $contentNamespaces ) as $i ) {
				if ( $i > 1 ) {
					$this->output( ", {$contentNamespaces[$i]}" );
				}
			}
			$this->output( " ]\n" );
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
require_once RUN_MAINTENANCE_IF_MAIN;
