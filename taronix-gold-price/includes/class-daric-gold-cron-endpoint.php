<?php
/**
 * Secret-key HTTP endpoint for external server cron jobs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daric_Gold_Cron_Endpoint {

	public const OPTION_SECRET     = 'daric_gold_cron_secret';
	public const TRANSIENT_RUNNING = 'daric_gold_cron_running';
	public const ROUTE_NAMESPACE   = 'taronix-gold/v1';
	public const ROUTE_SYNC        = '/sync';

	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_SYNC,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_sync' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sync( WP_REST_Request $request ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new WP_Error(
				'cron_secret_missing',
				'Cron secret is not configured.',
				array( 'status' => 500 )
			);
		}

		$provided = (string) $request->get_param( 'key' );
		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x-cron-secret' );
		}

		if ( '' === $provided || ! hash_equals( $secret, $provided ) ) {
			Daric_Gold_Logger::warning( 'daric-cron', 'Invalid cron secret received.' );

			return new WP_Error(
				'cron_secret_invalid',
				'Invalid cron secret.',
				array( 'status' => 403 )
			);
		}

		if ( get_transient( self::TRANSIENT_RUNNING ) ) {
			Daric_Gold_Logger::warning( 'daric-cron', 'Sync skipped: another sync is already running.' );

			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'already_running',
					'message' => 'Gold price sync is already running.',
					'price'   => Daric_Gold_Sync::get_stored_price(),
				),
				409
			);
		}

		set_transient( self::TRANSIENT_RUNNING, 1, 60 );

		try {
			$result = Daric_Gold_Sync::sync_from_cron();
		} finally {
			delete_transient( self::TRANSIENT_RUNNING );
		}

		$status = ! empty( $result['success'] ) ? 200 : 502;

		return new WP_REST_Response( self::format_public_response( $result ), $status );
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function format_public_response( array $result ): array {
		return array(
			'success'  => (bool) ( $result['success'] ?? false ),
			'updated'  => (bool) ( $result['updated'] ?? false ),
			'price'    => $result['price'] ?? Daric_Gold_Sync::get_stored_price(),
			'previous' => $result['previous'] ?? null,
			'code'     => $result['code'] ?? null,
			'message'  => (string) ( $result['message'] ?? '' ),
		);
	}

	public static function get_secret(): string {
		if ( defined( 'DARIC_GOLD_CRON_SECRET' ) && is_string( DARIC_GOLD_CRON_SECRET ) ) {
			return DARIC_GOLD_CRON_SECRET;
		}

		return (string) get_option( self::OPTION_SECRET, '' );
	}

	public static function ensure_secret(): string {
		$existing = self::get_secret();
		if ( '' !== $existing ) {
			return $existing;
		}

		$secret = wp_generate_password( 48, false, false );
		update_option( self::OPTION_SECRET, $secret, false );

		return $secret;
	}

	public static function regenerate_secret(): string {
		if ( defined( 'DARIC_GOLD_CRON_SECRET' ) ) {
			return self::get_secret();
		}

		$secret = wp_generate_password( 48, false, false );
		update_option( self::OPTION_SECRET, $secret, false );

		return $secret;
	}

	public static function get_sync_url( ?string $secret = null ): string {
		$secret = null === $secret ? self::get_secret() : $secret;

		return add_query_arg(
			'key',
			rawurlencode( $secret ),
			rest_url( self::ROUTE_NAMESPACE . self::ROUTE_SYNC )
		);
	}
}
