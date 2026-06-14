<?php
/**
 * Fetches Daric gold price and safely updates WordPress options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daric_Gold_Sync {

	public const OPTION_PRICE         = 'gold18_price';
	public const TRANSIENT_INITIAL    = 'h7a_get_initial_data';
	public const TRANSIENT_DISPLAY    = 'taronix_gold18_price';
	public const LOG_SOURCE           = 'daric-gold-sync';

	/** Minimum plausible 18k gold price in Toman (reject obvious garbage). */
	private const MIN_PRICE = 100000;

	/** Maximum plausible 18k gold price in Toman. */
	private const MAX_PRICE = 999999999;

	/**
	 * Update gold18_price only when triggered by the external cron endpoint.
	 *
	 * @return array<string, mixed>
	 */
	public static function sync_from_cron(): array {
		return self::sync( true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function sync( bool $from_cron ): array {
		if ( ! $from_cron ) {
			return self::failure_result(
				self::get_stored_price(),
				'cron_only',
				'Price can only be updated by calling the cron endpoint.'
			);
		}

		$previous = self::get_stored_price();

		$credentials = self::get_credentials();
		if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
			self::log( 'Missing API credentials; keeping previous price.' );
			return self::failure_result( $previous, 'missing_credentials', 'Daric API credentials are not configured.' );
		}

		$client = new Daric_Login_Client(
			$credentials['login_url'],
			$credentials['username'],
			$credentials['password']
		);

		$response = $client->get_gold_price();
		$new_price = self::extract_price( $response );

		if ( null === $new_price ) {
			$error = $response['error'] ?? 'Invalid or empty gold price response';
			self::log(
				sprintf(
					'Price fetch failed (http=%s): %s',
					(string) ( $response['http_code'] ?? 0 ),
					$error
				)
			);
			return self::failure_result( $previous, 'fetch_failed', $error, $response );
		}

		if ( ! self::is_price_sane_vs_previous( $new_price, $previous ) ) {
			self::log(
				sprintf(
					'Rejected suspicious price %d (previous=%s). Keeping stored value.',
					$new_price,
					null === $previous ? 'none' : (string) $previous
				)
			);
			return self::failure_result(
				$previous,
				'suspicious_price',
				'Fetched price failed sanity check against previous value.',
				$response
			);
		}

		if ( $previous === $new_price ) {
			return array(
				'success'      => true,
				'updated'      => false,
				'price'        => $new_price,
				'previous'     => $previous,
				'message'      => 'Price unchanged.',
				'api_response' => $response,
			);
		}

		$updated = update_option( self::OPTION_PRICE, $new_price, false );
		delete_transient( self::TRANSIENT_INITIAL );
		delete_transient( self::TRANSIENT_DISPLAY );

		self::log( sprintf( 'Updated gold18_price: %s -> %d', null === $previous ? 'none' : (string) $previous, $new_price ) );

		return array(
			'success'      => true,
			'updated'      => (bool) $updated,
			'price'        => $new_price,
			'previous'     => $previous,
			'message'      => 'Price updated successfully.',
			'api_response' => $response,
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_credentials(): array {
		$defaults = array(
			'login_url' => 'https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername',
			'username'  => '',
			'password'  => '',
		);

		if ( defined( 'DARIC_GOLD_USERNAME' ) ) {
			$defaults['username'] = (string) DARIC_GOLD_USERNAME;
		}
		if ( defined( 'DARIC_GOLD_PASSWORD' ) ) {
			$defaults['password'] = (string) DARIC_GOLD_PASSWORD;
		}
		if ( defined( 'DARIC_GOLD_LOGIN_URL' ) ) {
			$defaults['login_url'] = (string) DARIC_GOLD_LOGIN_URL;
		}

		$from_options = array(
			'login_url' => (string) get_option( 'daric_gold_login_url', $defaults['login_url'] ),
			'username'  => (string) get_option( 'daric_gold_username', $defaults['username'] ),
			'password'  => (string) get_option( 'daric_gold_password', $defaults['password'] ),
		);

		/**
		 * Filter Daric API credentials.
		 *
		 * @param array{login_url:string,username:string,password:string} $credentials
		 */
		return apply_filters( 'daric_gold_credentials', wp_parse_args( $from_options, $defaults ) );
	}

	public static function get_stored_price(): ?int {
		$price = get_option( self::OPTION_PRICE );

		return self::normalize_price( $price );
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public static function extract_price( array $response ): ?int {
		if ( empty( $response['ok'] ) ) {
			return null;
		}

		$json = $response['raw_json'] ?? null;
		if ( ! is_array( $json ) || empty( $json['IsSuccess'] ) ) {
			return null;
		}

		$data = $json['Data'] ?? array();
		if ( ! is_array( $data ) ) {
			return null;
		}

		$candidates = array(
			$data['BestSellPrice'] ?? null,
			$data['BestBuyPrice'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			$price = self::normalize_price( $candidate );
			if ( null !== $price ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * @param mixed $raw
	 */
	public static function normalize_price( $raw ): ?int {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return null;
		}

		$text = self::to_ascii_digits( (string) $raw );
		$text = preg_replace( '/[^\d.]/', '', $text );

		if ( null === $text || '' === $text || ! is_numeric( $text ) ) {
			return null;
		}

		$price = (int) round( (float) $text );
		if ( $price <= 0 || $price < self::MIN_PRICE || $price > self::MAX_PRICE ) {
			return null;
		}

		return $price;
	}

	public static function is_price_sane_vs_previous( int $new_price, ?int $previous ): bool {
		if ( null === $previous || $previous <= 0 ) {
			return true;
		}

		$min_ratio = (float) apply_filters( 'daric_gold_min_price_ratio', 0.5, $previous, $new_price );
		$max_ratio = (float) apply_filters( 'daric_gold_max_price_ratio', 2.0, $previous, $new_price );

		$ratio = $new_price / $previous;

		return $ratio >= $min_ratio && $ratio <= $max_ratio;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function failure_result( ?int $previous, string $code, string $message, ?array $api_response = null ): array {
		return array(
			'success'      => false,
			'updated'      => false,
			'price'        => $previous,
			'previous'     => $previous,
			'code'         => $code,
			'message'      => $message,
			'api_response' => $api_response,
		);
	}

	private static function to_ascii_digits( string $value ): string {
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

		return str_replace( $persian, range( 0, 9 ), str_replace( $arabic, range( 0, 9 ), $value ) );
	}

	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => self::LOG_SOURCE ) );
			return;
		}

		error_log( '[' . self::LOG_SOURCE . '] ' . $message );
	}
}
