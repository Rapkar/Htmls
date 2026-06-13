<?php
/**
 * Plugin Name: Taronix Gold Price
 * Description: نمایش نرخ لحظه‌ای طلا با شورت‌کد taronix_gold_price
 * Version: 1.0.0
 * Author: Taronix
 * Text Domain: taronix-gold-price
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TARONIX_GOLD_PRICE_VERSION', '1.0.0' );
define( 'TARONIX_GOLD_PRICE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TARONIX_GOLD_PRICE_URL', plugin_dir_url( __FILE__ ) );

require_once TARONIX_GOLD_PRICE_PATH . 'includes/shortcode.php';

/**
 * Register shortcode and assets.
 */
function taronix_gold_price_init() {
	add_shortcode( 'taronix_gold_price', 'taronix_gold_price_render' );
}
add_action( 'init', 'taronix_gold_price_init' );

/**
 * Enqueue styles when shortcode is present on the page.
 *
 * @param string $content Post content.
 * @return string
 */
function taronix_gold_price_enqueue_assets( $content ) {
	if ( ! is_singular() || ! has_shortcode( $content, 'taronix_gold_price' ) ) {
		return $content;
	}

	wp_enqueue_style(
		'taronix-gold-price',
		TARONIX_GOLD_PRICE_URL . 'assets/css/taronix-gold-price.css',
		array(),
		TARONIX_GOLD_PRICE_VERSION
	);

	return $content;
}
add_filter( 'the_content', 'taronix_gold_price_enqueue_assets', 9 );

/**
 * Invalidate cache when the gold price option is updated.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 */
function taronix_gold_price_clear_cache( $old_value, $value ) {
	delete_transient( 'taronix_gold18_price' );
}
add_action( 'update_option_gold18_price', 'taronix_gold_price_clear_cache', 10, 2 );
