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
 * @version 0.5
 * @date 3 July 2012
 * @note Based on code by:
 * - Edward Chernenko <edwardspec@gmail.com> (MediaWikiDumper 1.1.5, interwiki.pl)
 */

require_once __DIR__ . '/../maintenance/Maintenance.php';
require_once 'includes/mediawikibot.class.php';

class GrabInterwikiMap extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grab the interwiki map from an external wiki and import it into one of ours.';
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'interlang', 'Grab and insert only interlanguage links and nothing else', false, false );
	}

	/**
	 * The function to grab the interwiki map from a specified URL
	 */
	public function execute() {
		global $wgContLang;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->fatalError( 'The URL to the source wiki\'s api.php must be specified!' );
		}
		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		$this->output( "Starting up...\n" );

		# Bot class and log in if requested
		if ( $user && $password ) {
			$bot = new MediaWikiBot(
				$url,
				'json',
				$user,
				$password,
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
			if ( !$bot->login() ) {
				$this->output( "Logged in as $user...\n" );
			} else {
				$this->output( "WARNING: Failed to log in as $user.\n" );
			}
		} else {
			$bot = new MediaWikiBot(
				$url,
				'json',
				'',
				'',
				'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
			);
		}

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'interwikimap'
		];
		$data = $bot->query( $params );

		# No entries -> bail out early
		if ( empty( $data['query']['interwikimap'] ) ) {
			$this->fatalError( 'The site\'s interwiki map is empty, can\'t import from it!' );
		}

		$dbw = wfGetDB( DB_MASTER );

		$i = 0;

		foreach ( $data['query']['interwikimap'] as $iwEntry ) {
			# Check if this is not an interlanguage if we only want interlanguage prefices
			if ( $this->getOption( 'interlang' ) && !$wgContLang->getLanguageName( $iwEntry['prefix'] ) ) {
				continue;
			}
			# Check if prefix already exists
			elseif ( $dbw->fetchObject( $dbw->query( "SELECT * FROM `interwiki` WHERE `iw_prefix` = '{$iwEntry['prefix']}'" ) ) ) {
				continue;
			}

			$dbw->insert(
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
		$dbw->commit();

		$this->output( "Done — added {$i} entries into the interwiki table.\n" );
	}
}

$maintClass = 'GrabInterwikiMap';
require_once RUN_MAINTENANCE_IF_MAIN;
