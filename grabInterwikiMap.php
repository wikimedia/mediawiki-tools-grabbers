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

/**
 * Set the correct include path for PHP so that we can run this script from
 * $IP/extensions/ShoutWikiMaintenance and we don't need to move this file to
 * $IP/maintenance/.
 */
# ini_set( 'include_path', dirname( __FILE__ ) . '/../../maintenance' );

require_once( 'Maintenance.php' );
require_once( 'mediawikibot.class.php' );

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
			$this->error( 'The URL to the source wiki\'s api.php must be specified!', true );
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
				print "Logged in as $user...\n";
			} else {
				print "WARNING: Failed to log in as $user.\n";
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

		$params = array(
			'meta' => 'siteinfo',
			'siprop' => 'interwikimap'
		);
		$data = $bot->query( $params );

		# No entries -> bail out early
		if ( empty( $data['query']['interwikimap'] ) ) {
			$this->error( 'The site\'s interwiki map is empty, can\'t import from it!', true );
		}

		$dbw = wfGetDB( DB_MASTER );

		$i = 0;

		foreach ( $data['query']['interwikimap'] as $iwEntry ) {
			# Check if this is not an interlanguage if we only want interlanguage prefices
			if ( $this->getOption( 'interlang' ) && !$wgContLang->getLanguageName( $iwEntry['prefix'] ) ) {
				continue;
			}
			# Check if prefix already exists
			else if ( $dbw->fetchObject( $dbw->query( "SELECT * FROM `interwiki` WHERE `iw_prefix` = '{$iwEntry['prefix']}'" ) ) ) {
				continue;
			}

			$dbw->insert(
				'interwiki',
				array(
					'iw_prefix' => $iwEntry['prefix'],
					'iw_url' => $iwEntry['url'],
					# Boolean value indicating whether the wiki is in this project:
					'iw_local' => ( isset( $iwEntry['local'] ) ? 1 : 0 ),
					# Boolean value indicating whether interwiki transclusions are allowed:
					'iw_trans' => 0,
					# New crap, might not exist everywhere:
					'iw_api' => ( isset( $iwEntry['api'] ) ? $iwEntry['api'] : '' ),
					'iw_wikiid' => ( isset( $iwEntry['wikiid'] ) ? $iwEntry['wikiid'] : '' ),
				),
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
require_once( RUN_MAINTENANCE_IF_MAIN );
