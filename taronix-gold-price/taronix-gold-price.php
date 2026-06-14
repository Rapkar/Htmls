<?php
/**
 * Plugin Name: Taronix Gold Price
 * Description: دریافت قیمت طلا از API داریک، ذخیره امن در gold18_price و نمایش با شورت‌کد taronix_gold_price
 * Version: 1.2.4
 * Author: Taronix
 * Text Domain: taronix-gold-price
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TARONIX_GOLD_PRICE_VERSION', '1.2.4' );
define( 'TARONIX_GOLD_PRICE_FILE', __FILE__ );
define( 'TARONIX_GOLD_PRICE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TARONIX_GOLD_PRICE_URL', plugin_dir_url( __FILE__ ) );

require_once TARONIX_GOLD_PRICE_DIR . 'includes/class-daric-gold-logger.php';
require_once TARONIX_GOLD_PRICE_DIR . 'includes/class-daric-login-client.php';
require_once TARONIX_GOLD_PRICE_DIR . 'includes/class-daric-gold-sync.php';
require_once TARONIX_GOLD_PRICE_DIR . 'includes/class-daric-gold-cron-endpoint.php';

function taronix_gold_price_on_activate(): void {
	Daric_Gold_Cron_Endpoint::ensure_secret();
}
register_activation_hook( __FILE__, 'taronix_gold_price_on_activate' );

function taronix_gold_price_on_deactivate(): void {
	$timestamp = wp_next_scheduled( 'daric_gold_price_cron_sync' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'daric_gold_price_cron_sync' );
	}
}
register_deactivation_hook( __FILE__, 'taronix_gold_price_on_deactivate' );

function taronix_gold_price_register_cron_route(): void {
	Daric_Gold_Cron_Endpoint::register_routes();
}
add_action( 'rest_api_init', 'taronix_gold_price_register_cron_route' );

/**
 * @return int|false
 */
function taronix_gold_price_get_value() {
	$cached_price = get_transient( Daric_Gold_Sync::TRANSIENT_DISPLAY );

	if ( false !== $cached_price && Daric_Gold_Sync::normalize_price( $cached_price ) ) {
		return (int) $cached_price;
	}

	$price = Daric_Gold_Sync::get_stored_price();

	if ( null === $price ) {
		return false;
	}

	set_transient( Daric_Gold_Sync::TRANSIENT_DISPLAY, $price, 5 * MINUTE_IN_SECONDS );

	return $price;
}

function taronix_gold_price_to_persian_digits( string $value ): string {
	$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	return str_replace( range( 0, 9 ), $persian_digits, $value );
}

function taronix_gold_price_format( int $price ): string {
	$formatted = number_format( $price, 0, '.', ',' );

	return taronix_gold_price_to_persian_digits( $formatted );
}

/**
 * @param array<string, string>|string $atts
 */
function taronix_gold_price_render( $atts ): string {
	$price = taronix_gold_price_get_value();

	if ( false === $price ) {
		return '';
	}

	wp_enqueue_style( 'taronix-gold-price' );

	$formatted_price = taronix_gold_price_format( $price );

	ob_start();
	?>
	<div class="taronix-gold-price" dir="rtl">
		<div class="taronix-gold-price__bar">
			<span class="taronix-gold-price__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M12 3C10.34 3 9 4.34 9 6V7.1C6.84 7.56 5.25 9.5 5.25 12V17L4 18.25V19H20V18.25L18.75 17V12C18.75 9.5 17.16 7.56 15 7.1V6C15 4.34 13.66 3 12 3ZM12 21C10.9 21 10 20.1 10 19H14C14 20.1 13.1 21 12 21Z" fill="currentColor"/>
				</svg>
			</span>
			<span class="taronix-gold-price__label">نرخ لحظه‌ای طلا :</span>
			<strong class="taronix-gold-price__value"><?php echo esc_html( $formatted_price ); ?></strong>
			<span class="taronix-gold-price__currency">تومان</span>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

function taronix_gold_price_register_shortcode(): void {
	add_shortcode( 'taronix_gold_price', 'taronix_gold_price_render' );
}
add_action( 'init', 'taronix_gold_price_register_shortcode' );

function taronix_gold_price_register_assets(): void {
	wp_register_style(
		'taronix-gold-price',
		TARONIX_GOLD_PRICE_URL . 'assets/css/taronix-gold-price.css',
		array(),
		TARONIX_GOLD_PRICE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'taronix_gold_price_register_assets' );

function taronix_gold_price_clear_display_cache( $old_value, $value ): void {
	delete_transient( Daric_Gold_Sync::TRANSIENT_DISPLAY );
}
add_action( 'update_option_' . Daric_Gold_Sync::OPTION_PRICE, 'taronix_gold_price_clear_display_cache', 10, 2 );

/**
 * Admin settings for API credentials (optional; wp-config constants override).
 */
function taronix_gold_price_register_settings(): void {
	register_setting(
		'taronix_gold_price',
		'daric_gold_username',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'taronix_gold_price',
		'daric_gold_password',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'taronix_gold_price',
		'daric_gold_login_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername',
		)
	);
}
add_action( 'admin_init', 'taronix_gold_price_register_settings' );

function taronix_gold_price_settings_menu(): void {
	add_options_page(
		__( 'Taronix Gold Price', 'taronix-gold-price' ),
		__( 'Taronix Gold Price', 'taronix-gold-price' ),
		'manage_options',
		'taronix-gold-price',
		'taronix_gold_price_settings_page'
	);
}
add_action( 'admin_menu', 'taronix_gold_price_settings_menu' );

function taronix_gold_price_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$sync_message = '';
	if ( isset( $_POST['taronix_gold_regenerate_secret'] ) && check_admin_referer( 'taronix_gold_regenerate_secret' ) ) {
		Daric_Gold_Cron_Endpoint::regenerate_secret();
		$sync_message = '<div class="notice notice-success"><p>' . esc_html__( 'Cron secret regenerated.', 'taronix-gold-price' ) . '</p></div>';
	}

	$stored      = Daric_Gold_Sync::get_stored_price();
	$cron_secret = Daric_Gold_Cron_Endpoint::ensure_secret();
	$cron_url    = Daric_Gold_Cron_Endpoint::get_sync_url( $cron_secret );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Taronix Gold Price', 'taronix-gold-price' ); ?></h1>
		<?php echo wp_kses_post( $sync_message ); ?>
		<p>
			<?php
			echo esc_html__(
				'Stored price (gold18_price):',
				'taronix-gold-price'
			);
			?>
			<strong><?php echo null === $stored ? esc_html__( 'Not set', 'taronix-gold-price' ) : esc_html( number_format_i18n( $stored ) ); ?></strong>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'taronix_gold_price' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="daric_gold_login_url"><?php esc_html_e( 'Login URL', 'taronix-gold-price' ); ?></label></th>
					<td><input name="daric_gold_login_url" id="daric_gold_login_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'daric_gold_login_url', 'https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="daric_gold_username"><?php esc_html_e( 'Username', 'taronix-gold-price' ); ?></label></th>
					<td><input name="daric_gold_username" id="daric_gold_username" type="text" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'daric_gold_username', '' ) ); ?>" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="daric_gold_password"><?php esc_html_e( 'Password', 'taronix-gold-price' ); ?></label></th>
					<td><input name="daric_gold_password" id="daric_gold_password" type="password" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'daric_gold_password', '' ) ); ?>" autocomplete="new-password" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'taronix-gold-price' ) ); ?>
		</form>
		<h2><?php esc_html_e( 'External Cron Job', 'taronix-gold-price' ); ?></h2>
		<p><?php esc_html_e( 'gold18_price is updated only when this URL is called. Until then, the stored price never changes.', 'taronix-gold-price' ); ?></p>
		<p><?php esc_html_e( 'Call this URL from your server cron (every 1–5 minutes):', 'taronix-gold-price' ); ?></p>
		<p>
			<code style="display:block;direction:ltr;text-align:left;word-break:break-all;"><?php echo esc_html( $cron_url ); ?></code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Example crontab:', 'taronix-gold-price' ); ?>
			<code style="display:block;direction:ltr;text-align:left;white-space:pre-wrap;">*/5 * * * * curl -fsS "<?php echo esc_html( $cron_url ); ?>" &gt;/dev/null</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'You can also send the secret via header:', 'taronix-gold-price' ); ?>
			<code>X-Cron-Secret: <?php echo esc_html( $cron_secret ); ?></code>
		</p>
		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate cron secret? Update your crontab afterward.', 'taronix-gold-price' ) ); ?>');">
			<?php wp_nonce_field( 'taronix_gold_regenerate_secret' ); ?>
			<?php submit_button( __( 'Regenerate Cron Secret', 'taronix-gold-price' ), 'delete', 'taronix_gold_regenerate_secret', false ); ?>
		</form>

		<p class="description">
			<?php esc_html_e( 'Optional wp-config.php constants: DARIC_GOLD_USERNAME, DARIC_GOLD_PASSWORD, DARIC_GOLD_CRON_SECRET, DARIC_GOLD_LOG_DIR', 'taronix-gold-price' ); ?>
		</p>

		<h2><?php esc_html_e( 'Log File', 'taronix-gold-price' ); ?></h2>
		<p><?php esc_html_e( 'Errors and sync events are written to a dedicated log file (independent of WordPress debug settings):', 'taronix-gold-price' ); ?></p>
		<p>
			<code style="display:block;direction:ltr;text-align:left;word-break:break-all;"><?php echo esc_html( Daric_Gold_Logger::get_log_file() ); ?></code>
		</p>
		<p class="description">
			<?php esc_html_e( 'View on server:', 'taronix-gold-price' ); ?>
			<code style="display:block;direction:ltr;text-align:left;">tail -f <?php echo esc_html( Daric_Gold_Logger::get_log_file() ); ?></code>
		</p>
	</div>
	<?php
}
