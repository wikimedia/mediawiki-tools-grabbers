<?php
/**
 * Now with dumb hacks!
 *
 */

class MediaWikiBotHacked extends MediaWikiBot {

	/** Like curl_post, but for dumb hacks (grabDeletedFiles screenscraping, specifically)
	 */
	public function curl_get( $url ) {
		# open the connection
		$ch = curl_init();
		# set the url, stuff
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_USERAGENT, USERAGENT );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_FAILONERROR, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE );
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
		curl_setopt( $ch, CURLOPT_COOKIEFILE, COOKIES );
		curl_setopt( $ch, CURLOPT_COOKIEJAR, COOKIES );

		# execute the get
		$results = curl_exec( $ch );
		$error = curl_errno( $ch );
		if ( $error !== 0 ) {
			$results = [ false, sprintf( "%s", curl_error( $ch ) ) ];
		} else {
			$results = [ true, $results ];
		}
		# close the connection
		curl_close( $ch );
		# return the unserialized results
		return $results;
	}
}
