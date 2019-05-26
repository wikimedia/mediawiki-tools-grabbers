<?php
/**
 * Maintenance script to grab the interwiki map from a wiki and import it to
 * another wiki.
 *
 * This is pretty much a 1:1 translation of Edward's Perl script, except that
 * instead of writing to a file, I made this one add the entries to the
 * database straight away because we can.
 *
 * When using this on the ShoutWiki setup, run this with the --interlang option
 * to get only the interlanguage links and skip interwiki links, because on
 * ShoutWiki, interwikis are global and interlanguage links are local.
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @version 0.6
 * @date 5 August 2019
 * @note Based on code by:
 * - Edward Chernenko <edwardspec@gmail.com> (MediaWikiDumper 1.1.5, interwiki.pl)
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/ExternalWikiGrabber.php';

class GrabInterwikiMap extends ExternalWikiGrabber {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grab the interwiki map from an external wiki and import it into one of ours.';
		$this->addOption( 'interlang', 'Grab and insert only interlanguage links and nothing else', false, false );
	}

	/**
	 * The function to grab the interwiki map from a specified URL
	 */
	public function execute() {
		parent::execute();

		$this->output( "Starting up...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'interwikimap'
		];
		$data = $this->bot->query( $params );

		# No entries -> bail out early
		if ( empty( $data['query']['interwikimap'] ) ) {
			$this->fatalError( 'The site\'s interwiki map is empty, can\'t import from it!' );
		}

		$i = 0;
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();

		foreach ( $data['query']['interwikimap'] as $iwEntry ) {
			# Check if this is not an interlanguage if we only want interlanguage prefices
			if ( $this->getOption( 'interlang' ) && !$contLang->getLanguageName( $iwEntry['prefix'] ) ) {
				continue;
			}
			# Check if prefix already exists
			elseif ( $this->dbw->selectRowCount( 'interwiki', '1', [ 'iw_prefix' => $iwEntry['prefix'] ], __METHOD__ ) ) {
				continue;
			}

			$this->dbw->insert(
				'interwiki',
				[
					'iw_prefix' => $iwEntry['prefix'],
					'iw_url' => $iwEntry['url'],
					# Boolean value indicating whether the wiki is in this project:
					'iw_local' => ( isset( $iwEntry['local'] ) ? 1 : 0 ),
					# Boolean value indicating whether interwiki transclusions are allowed:
					'iw_trans' => 0,
					# New crap, might not exist everywhere:
					'iw_api' => ( isset( $iwEntry['api'] ) ? $iwEntry['api'] : '' ),
					'iw_wikiid' => ( isset( $iwEntry['wikiid'] ) ? $iwEntry['wikiid'] : '' ),
				],
				__METHOD__
			);
			$this->output( "Inserting {$iwEntry['prefix']} (URL: {$iwEntry['url']}) into the database...\n" );
			$i++;

		}
		$this->dbw->commit();

		$this->output( "Done — added {$i} entries into the interwiki table.\n" );
	}
}

$maintClass = 'GrabInterwikiMap';
require_once RUN_MAINTENANCE_IF_MAIN;
