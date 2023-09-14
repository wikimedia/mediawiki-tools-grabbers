<?php
/**
 * Maintenance script to grab the page restrictions from a wiki (to which we have only read-only access instead of
 * full database access). It's worth noting that grabText.php and grabNewText.php already import page restrictions,
 * so this script is only really useful if you're using an XML dump that doesn't include page restrictions.
 *
 * @file
 * @ingroup Maintenance
 * @author Jayden Bailey <jayden@weirdgloop.org>
 * @version 1.0
 * @date 10 September 2023
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/ExternalWikiGrabber.php';

class GrabPageRestrictions extends ExternalWikiGrabber {

	/**
	 * Current number of pages retrieved
	 *
	 * @var int
	 */
	protected int $pageCount = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grabs page restrictions from a pre-existing wiki into a new wiki.' );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found' );
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
			$this->fatalError( 'Got no namespaces' );
		}

		foreach ( $textNamespaces as $ns ) {
			$this->processNamespace( $ns );
		}

		$this->output( "Done: $this->pageCount entries processed\n" );
	}

	public function processNamespace( $ns ) {
		$params = [
			'generator' => 'allpages',
			'gaplimit' => 'max',
			'prop' => 'info',
			'inprop' => 'protection',
			'gapprtype' => 'edit|move|upload',
			'gapnamespace' => $ns
		];

		$more = true;

		$this->output( "Grabbing pages with restrictions for namespace $ns...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['pages'] ) ) {
				return;
			}

			foreach ( $result['query']['pages'] as $page ) {
				$this->processPage( $page );
				$this->pageCount++;

				if ( isset( $result['query-continue']['pages'] ) ) {
					$params = array_merge( $params, $result['query-continue']['pages'] );
					$this->output( "$this->pageCount entries processed.\n" );
				} elseif ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
					$this->output( "$this->pageCount entries processed.\n" );
				} else {
					$more = false;
				}
			}

		} while ( $more );
	}

	public function processPage( $page ) {
		$pageStore = MediaWikiServices::getInstance()->getPageStore();
		$ourPage = $pageStore->getPageById( $page['pageid'] );

		if ( is_null( $ourPage ) ) {
			// This page doesn't exist in our database, so ignore it.
			return;
		}

		// Delete first any existing protection
		$this->dbw->delete(
			'page_restrictions',
			[ 'pr_page' => $page['pageid'] ],
			__METHOD__
		);

		$this->output( "Setting page_restrictions on page_id {$page['pageid']}.\n" );

		// insert current restrictions
		foreach ( $page['protection'] as $prot ) {
			// Skip protections inherited from cascade protections
			if ( !isset( $prot['source'] ) ) {
				$expiry = $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] );
				$this->dbw->insert(
					'page_restrictions',
					[
						'pr_page' => $page['pageid'],
						'pr_type' => $prot['type'],
						'pr_level' => $prot['level'],
						'pr_cascade' => (int)isset( $prot['cascade'] ),
						'pr_expiry' => $expiry
					],
					__METHOD__
				);
			}
		}
	}
}

$maintClass = 'GrabPageRestrictions';
require_once RUN_MAINTENANCE_IF_MAIN;
