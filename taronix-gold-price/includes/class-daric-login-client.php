<?php
/**
 * Daric SSO login + authorized API requests for WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daric_Login_Client {

	public string $url;
	public string $username;
	public string $password;

	public int $connect_timeout = 10;
	public int $timeout         = 30;

	public int $access_token_ttl = 600;
	public int $cooldown_on_fail = 60;
	public int $lock_ttl         = 15;

	private string $cache_prefix = 'daric_gold_';

	public function __construct( string $url, string $username, string $password ) {
		$this->url      = $url;
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_tokens(): array {
		$cached = $this->cache_get( 'tokens' );
		if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
			return array(
				'ok'                 => true,
				'source'             => 'cache',
				'access_token'       => $cached['access_token'],
				'refresh_token'      => $cached['refresh_token'] ?? null,
				'refresh_expiration' => $cached['refresh_expiration'] ?? null,
			);
		}

		$cooldown_until = (int) ( $this->cache_get( 'cooldown_until' ) ?? 0 );
		if ( $cooldown_until > time() ) {
			Daric_Gold_Logger::warning(
				'daric-login',
				'Login skipped: cooldown active after previous failure.',
				array( 'cooldown_left_sec' => $cooldown_until - time() )
			);

			return array(
				'ok'                 => false,
				'source'             => 'cooldown',
				'error'              => 'Login is in cooldown window (preventing rapid retries).',
				'cooldown_left_sec'  => $cooldown_until - time(),
				'access_token'       => null,
				'refresh_token'      => null,
				'refresh_expiration' => null,
			);
		}

		if ( ! $this->acquire_lock( 'login_lock', $this->lock_ttl ) ) {
			usleep( 300000 );
			$cached = $this->cache_get( 'tokens' );
			if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
				return array(
					'ok'                 => true,
					'source'             => 'cache_after_wait',
					'access_token'       => $cached['access_token'],
					'refresh_token'      => $cached['refresh_token'] ?? null,
					'refresh_expiration' => $cached['refresh_expiration'] ?? null,
				);
			}

			return array(
				'ok'                 => false,
				'source'             => 'lock_busy',
				'error'              => 'Login lock is busy; skipping to avoid repeated login calls.',
				'access_token'       => null,
				'refresh_token'      => null,
				'refresh_expiration' => null,
			);
		}

		try {
			$res = $this->login_once();

			if ( empty( $res['ok'] ) ) {
				Daric_Gold_Logger::error(
					'daric-login',
					'Login failed.',
					array(
						'http_code' => $res['http_code'] ?? 0,
						'error'     => $res['error'] ?? 'unknown',
					)
				);
				$this->cache_set( 'cooldown_until', time() + $this->cooldown_on_fail, $this->cooldown_on_fail );
				return $res;
			}

			Daric_Gold_Logger::info( 'daric-login', 'Login successful; access token cached.' );

			$this->cache_set(
				'tokens',
				array(
					'access_token'       => $res['access_token'],
					'refresh_token'      => $res['refresh_token'],
					'refresh_expiration' => $res['refresh_expiration'],
				),
				$this->access_token_ttl
			);

			$this->cache_delete( 'cooldown_until' );

			$res['source'] = 'login';
			return $res;
		} finally {
			$this->release_lock( 'login_lock' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_gold_price(): array {
		$url = $this->build_api_url( '/Loan/api/v1/Tara/GetGoldlPrice' );
		return $this->authorized_get( $url );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function authorized_get( string $url ): array {
		$tokens = $this->get_tokens();

		if ( empty( $tokens['ok'] ) || empty( $tokens['access_token'] ) ) {
			Daric_Gold_Logger::error(
				'daric-login',
				'Unable to get access token for price request.',
				array( 'token_result' => $tokens )
			);

			return array(
				'ok'           => false,
				'http_code'    => 0,
				'error'        => 'Unable to get access token',
				'token_result' => $tokens,
				'raw_json'     => null,
			);
		}

		$result = $this->send_get_request( $url, $tokens['access_token'] );

		if ( (int) ( $result['http_code'] ?? 0 ) === 401 ) {
			Daric_Gold_Logger::warning( 'daric-login', 'Price request returned 401; retrying after re-login.' );
			$this->cache_delete( 'tokens' );

			$tokens = $this->get_tokens();
			if ( empty( $tokens['ok'] ) || empty( $tokens['access_token'] ) ) {
				Daric_Gold_Logger::error( 'daric-login', 'Token expired and re-login failed.' );

				return array(
					'ok'           => false,
					'http_code'    => 401,
					'error'        => 'Token expired and re-login failed',
					'token_result' => $tokens,
					'raw_json'     => $result['raw_json'] ?? null,
				);
			}

			$result = $this->send_get_request( $url, $tokens['access_token'] );
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function send_get_request( string $url, string $access_token ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'httpversion' => '1.1',
			)
		);

		if ( is_wp_error( $response ) ) {
			Daric_Gold_Logger::error(
				'daric-login',
				'Gold price HTTP request failed.',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);

			return array(
				'ok'       => false,
				'http_code' => 0,
				'error'    => $response->get_error_message(),
				'url'      => $url,
				'raw_body' => null,
				'raw_json' => null,
				'data'     => null,
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( (string) $body, true );

		return array(
			'ok'        => ( $http_code >= 200 && $http_code < 300 ),
			'http_code' => $http_code,
			'error'     => null,
			'url'       => $url,
			'raw_body'  => $body,
			'raw_json'  => is_array( $decoded ) ? $decoded : null,
			'data'      => is_array( $decoded ) ? ( $decoded['Data'] ?? $decoded ) : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function login_once(): array {
		$response = wp_remote_post(
			$this->url,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'UserName' => $this->username,
						'Password' => $this->password,
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Daric_Gold_Logger::error(
				'daric-login',
				'SSO login HTTP request failed.',
				array( 'error' => $response->get_error_message() )
			);

			return array(
				'ok'                 => false,
				'http_code'          => 0,
				'error'              => $response->get_error_message(),
				'access_token'       => null,
				'refresh_token'      => null,
				'refresh_expiration' => null,
				'raw'                => null,
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			Daric_Gold_Logger::error(
				'daric-login',
				'SSO login returned invalid JSON.',
				array( 'http_code' => $http_code )
			);

			return array(
				'ok'                 => false,
				'http_code'          => $http_code,
				'error'              => 'Invalid JSON response',
				'access_token'       => null,
				'refresh_token'      => null,
				'refresh_expiration' => null,
				'raw'                => $body,
			);
		}

		$token_obj = $decoded['Data']['Token'] ?? null;
		if ( ! is_array( $token_obj ) ) {
			$token_obj = $decoded['Token'] ?? ( $decoded['Data'] ?? null );
		}

		$access_token  = is_array( $token_obj ) ? ( $token_obj['Accesstoken'] ?? $token_obj['AccessToken'] ?? null ) : null;
		$refresh_token = is_array( $token_obj ) ? ( $token_obj['RefreshToken'] ?? null ) : null;
		$refresh_exp   = is_array( $token_obj ) ? ( $token_obj['RefreshTokenExpirationDate'] ?? null ) : null;

		if ( empty( $access_token ) && ( $http_code >= 200 && $http_code < 300 ) ) {
			Daric_Gold_Logger::error(
				'daric-login',
				'SSO login succeeded but access token missing in response.',
				array( 'http_code' => $http_code )
			);
		}

		return array(
			'ok'                 => ( $http_code >= 200 && $http_code < 300 ) && ! empty( $access_token ),
			'http_code'          => $http_code,
			'error'              => empty( $access_token ) ? 'Access token missing in login response' : null,
			'access_token'       => $access_token,
			'refresh_token'      => $refresh_token,
			'refresh_expiration' => $refresh_exp,
			'raw'                => $decoded,
		);
	}

	private function build_api_url( string $path ): string {
		$parts  = wp_parse_url( $this->url );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';

		return $scheme . '://' . $host . '/' . ltrim( $path, '/' );
	}

	/**
	 * @return mixed
	 */
	private function cache_get( string $key ) {
		$value = get_transient( $this->cache_prefix . $key );
		return ( false === $value ) ? null : $value;
	}

	/**
	 * @param mixed $value
	 */
	private function cache_set( string $key, $value, int $ttl ): void {
		set_transient( $this->cache_prefix . $key, $value, max( 1, $ttl ) );
	}

	private function cache_delete( string $key ): void {
		delete_transient( $this->cache_prefix . $key );
	}

	private function acquire_lock( string $key, int $ttl ): bool {
		if ( $this->cache_get( $key ) ) {
			return false;
		}

		$this->cache_set( $key, 1, $ttl );
		return true;
	}

	private function release_lock( string $key ): void {
		$this->cache_delete( $key );
	}
}
