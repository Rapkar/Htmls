<?php
/**
 * Dedicated file logger — independent of WordPress debug settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daric_Gold_Logger {

	public const LOG_FILENAME = 'daric-gold.log';

	/**
	 * @param array<string, mixed> $context
	 */
	public static function error( string $source, string $message, array $context = array() ): void {
		self::write( 'error', $source, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function warning( string $source, string $message, array $context = array() ): void {
		self::write( 'warning', $source, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function info( string $source, string $message, array $context = array() ): void {
		self::write( 'info', $source, $message, $context );
	}

	public static function get_log_dir(): string {
		if ( defined( 'DARIC_GOLD_LOG_DIR' ) && is_string( DARIC_GOLD_LOG_DIR ) && '' !== DARIC_GOLD_LOG_DIR ) {
			return rtrim( DARIC_GOLD_LOG_DIR, '/\\' );
		}

		/**
		 * Filter log directory path.
		 *
		 * @param string $dir Absolute path.
		 */
		return (string) apply_filters( 'daric_gold_log_dir', WP_CONTENT_DIR . '/taronix-gold-logs' );
	}

	public static function get_log_file(): string {
		return self::get_log_dir() . '/' . self::LOG_FILENAME;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function write( string $level, string $source, string $message, array $context = array() ): void {
		if ( ! self::ensure_log_dir() ) {
			return;
		}

		$line = sprintf(
			'[%s] [%s] [%s] %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$source,
			$message
		);

		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( self::redact_context( $context ), JSON_UNESCAPED_UNICODE );
		}

		$line .= PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( self::get_log_file(), $line, FILE_APPEND | LOCK_EX );
	}

	private static function ensure_log_dir(): bool {
		$dir = self::get_log_dir();

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			self::write_protective_files( $dir );
		}

		if ( ! is_writable( $dir ) ) {
			return false;
		}

		return true;
	}

	private static function write_protective_files( string $dir ): void {
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function redact_context( array $context ): array {
		$sensitive_keys = array(
			'password',
			'access_token',
			'refresh_token',
			'key',
			'secret',
			'authorization',
			'token',
		);

		$redacted = array();

		foreach ( $context as $key => $value ) {
			$lower_key = strtolower( (string) $key );

			if ( in_array( $lower_key, $sensitive_keys, true ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_context( $value );
				continue;
			}

			if ( is_string( $value ) && strlen( $value ) > 120 && preg_match( '/^eyJ/', $value ) ) {
				$redacted[ $key ] = '[redacted-jwt]';
				continue;
			}

			$redacted[ $key ] = $value;
		}

		return $redacted;
	}
}
