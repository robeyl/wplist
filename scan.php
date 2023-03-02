<?php
namespace dd32\FindWPSites;
require './vendor/autoload.php';

$stats = [];

file_put_contents( __DIR__ . '/list', '' );

const MAX_CONCURRENT_REQUESTS = 40;
const MAX_DOMAINS = 1000000;
const START_FROM  = 0;

$get_next_url = function() {
	static $handle = false;
	static $returned = 0;

	if ( ! $handle ) {
		$handle = fopen( __DIR__ . '/1000000', 'r' );
		//$handle = fopen( __DIR__ . '/custom', 'r' );
		if ( START_FROM ) {
			foreach ( range( 1, START_FROM ) as $i ) {
				fgetcsv( $handle );
			}
		}
	}
	if ( feof( $handle ) || $returned > MAX_DOMAINS ) {
		define( 'NO_MORE', true );
		return false;
	}

	$returned++;

	return fgetcsv( $handle );
};

class Checker {
	public $http;
	public $request;
	public $url;
	public $completed = false;
	public $user_agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36';

	public $process_next = true;

	public $ranked = 0;
	public $title = '';
	public $meta = [];
	public $version = false;
	public $is_wp = false;

	var $code;
	var $redirect = false;

	var $content_path = '';

	var $log = [];

	var $start = 0;

	var $requests = 0;
	var $domain = '';

	function __construct( $http, $ranked, $domain) {
		$this->http   = $http;
		$this->ranked = (int) $ranked;
		$this->domain = $domain;
		$this->url    = "https://$domain/";

		$this->title = $domain;

		$this->start = time();

		$this->execute();
	}

	function execute() {
		$this->requests++;
		echo "[{$this->domain} {$this->requests}] Requesting $this->url \n";
		$this->process_next = true;

		$this->log[] = $this->url;

		$this->request = $this->http->request(
			'GET',
			$this->url,
			[ "User-Agent: {$this->user_agent}" ]
		)->on(
			'response', [ $this, 'response' ]
		)->on(
			'error', [ $this, 'error' ]
		)->on(
			'close', [ $this, 'close' ]
		)->end();
	}

	function response( $response ) {
		$self = $this;
		$data = '';

		$this->process( $response, $data );

		$response->on( 'data', function( $chunk ) use( $self, &$data, $response ) {
			$data .= $chunk;
			$self->process( $response, $data );
		} );
	}

	function process( $response, $data = '' ) {
		if ( ! $this->process_next ) {
			return;
		}

		if ( ( time() - $this->start ) > 60 ) {
			$this->completed = '>60s??';
			$response->close();
			return;
		}
		if ( $this->requests > 5 ) {
			$this->completed = '> 5 redirects';
			$response->close();
			return;
		}

		$host    = parse_url( $this->url, PHP_URL_HOST );
		$code    = (int) $response->getCode();
		$headers = array_change_key_case( $response->getHeaders() );

		$this->code = $code;

		if ( $this->maybe_redirect( $response, $code, $headers, $data ) ) {
			return;
		}

		if ( preg_match( '/wp-(content|includes)/i', $data ) ) {
			$this->is_wp |= true;
		}

		if ( preg_match( '/<title>([^<]+)<\/title>/', $data, $m ) ) {
			$this->title = $m[1];
		}

		if ( preg_match_all( '/<meta[^>]+>/i', $data, $m ) ) {
			foreach ( $m[0] as $meta ) {
				preg_match( '/(name|property)=[\'"](?P<name>[^\'"]+)[\'"]/i', $meta, $names );
				preg_match( '/content=[\'"](?P<content>[^\'"]+)[\'"]/i', $meta, $contents );

				if ( $names && $contents ) {
					$this->meta[ $names['name'] ] = $contents['content'];

					if ( 'generator' === $names['name'] && preg_match( '/WordPress ([0-9\.]+)/i', $contents['content'], $versions ) ) {
						$this->version = $versions[1];
						$this->is_wp |= true;
					}
				}
			}
		}

		if ( preg_match( '/wp-emoji-release.min.js?ver=([0-9.]+)/', $data, $m ) ) {
			$this->is_wp |= true;
			$this->version = $m[1];
		}

		if ( $this->is_wp && $this->title && $this->version && $this->meta && strpos( $data, '<body' ) ) {
			// echo "Closing due to data {$this->url}\n";
			$this->completed = 'got data';
			$response->close();
			return;
		} elseif ( strlen( $data ) > 1024 * 1024 ) { // 1MB
			// $len = strlen( $data );
			// echo "Closing due to length {$this->url} = {$len}\n";
			$this->completed = '>1MB of data??';
			$response->close();
			return;
		}
	}

	function maybe_redirect( $response, $code, $headers, $data ) {
		if ( $code < 300 || $code > 399 ) {
			return;
		}

		if ( empty( $headers['location'] ) ) {
			return;
		}

//		echo "Got redirect from {$this->url} to {$headers['location']}\n";

		// Validate that we want to follow that redirect. Only scheme changes
//		if ( preg_replace( '#^\w+://#', '', $headers['location'] ) !== preg_replace( '#^\w+://#', '', $this->url ) ) {
//			return;
//		}

		if ( 0 !== stripos( $headers['location'], 'http' ) ) {
			// Relative?
			if ( '//' === substr( $headers['location'], 0, 2 ) ) {
				$headers['location'] = 'https:' . $headers['location'];
			} elseif ( '/' === substr( $headers['location'], 0,1 ) ) {
				$headers['location'] = rtrim( $this->url, '/' ) . $headers['location'];
			} else {
				return;
			}
		}

		$this->url  = $headers['location'];
		$this->code = false;

		$this->process_next = false;

		$response->close();

		$this->execute();

		return true;
	}

	function error( \Exception $e ) {
		$this->code      = 'error: ' . $e->getMessage();
		$this->completed = true;
	}

	function close() {
		if ( ! $this->process_next ) {
			return;
		}

		if ( ! $this->completed ) {
			$this->code      = 'close';
			$this->completed = true;
		}
	}

	function is_done() {
		return $this->completed;
	}

	function __destruct() {
		$status = $this->code;
		if ( $this->is_wp ) {
			$status = "WP {$this->version}";
		}

		echo "[{$this->domain}] " . implode( " => ", $this->log) . " = {$status}\n";
	/*	var_dump([
			$this->is_wp,
			$this->title,
			$this->meta,
			$this->version
		]); */

		if ( $this->is_wp ) {
			$desc = $this->meta['description'] ?? ( $this->meta['og:description'] ?? '' );

			$data = [
				'ranked' => $this->ranked,
				'url_history' => $this->log,
				'url' => $this->url,
				'title' => $this->title,
				'version' => $this->version,
				'description' => $desc,
			];

			file_put_contents( __DIR__ . '/list', json_encode( $data ) . "\n", FILE_APPEND );
		}
	}

}

$loop = \React\EventLoop\Factory::create();
$connector = new \React\Socket\Connector( $loop, array(
//	'dns' => '8.8.8.8',
	'timeout' => 15.0,
	'tls' => array(
		'verify_peer' => false,
		'verify_peer_name' => false
	)
));
$http = new \React\HttpClient\Client( $loop, $connector );

// Use a timer callback to ensure that we only queue as many as needed at any given point in time:
$loop->addPeriodicTimer( 3.0, function( $timer ) use( $loop, $http, $get_next_url ) {
	static $checkers = [];

	//echo '@';

	while (
		count( $checkers ) < MAX_CONCURRENT_REQUESTS &&
		$url = $get_next_url()
	) {
		$checkers[] = new Checker( $http, $url[0], $url[1] );
	//	echo "+\n";
	}

	foreach ( $checkers as $i => $checker ) {
		if ( $checker->is_done() ) {
	//		echo "-\n";
			unset( $checkers[ $i ] );
		}
	}

	if ( defined( 'NO_MORE' ) ) {
		$loop->cancelTimer( $timer );
	}
} );

// Use a timer callback to ensure that we only queue as many as needed at any given point in time:
/*
$loop->addPeriodicTimer( 60, function( $timer ) use( $loop ) {
	global $stats;

	if ( defined( 'NO_MORE' ) ) {
		$loop->cancelTimer( $timer );
	}
} );
*/

$loop->run();

var_dump( $stats );

