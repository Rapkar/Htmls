<?php
/**
 * Shortcode: [taronix_gold_price]
 * Add to functions.php or require this file once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function taronix_gold_price_get_value() {
	$transient_key = 'taronix_gold18_price';
	$cached_price  = get_transient( $transient_key );

	if ( false !== $cached_price ) {
		return $cached_price;
	}

	$price = get_option( 'gold18_price' );

	if ( false === $price || '' === $price || ! is_numeric( $price ) ) {
		return false;
	}

	$price = (float) $price;

	set_transient( $transient_key, $price, 5 * MINUTE_IN_SECONDS );

	return $price;
}

function taronix_gold_price_to_persian_digits( $value ) {
	$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	return str_replace( range( 0, 9 ), $persian_digits, $value );
}

function taronix_gold_price_format( $price ) {
	$formatted = number_format( (float) $price, 0, '.', ',' );

	return taronix_gold_price_to_persian_digits( $formatted );
}

function taronix_gold_price_render( $atts ) {
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
	return ob_get_clean();
}

function taronix_gold_price_register_shortcode() {
	add_shortcode( 'taronix_gold_price', 'taronix_gold_price_render' );
}
add_action( 'init', 'taronix_gold_price_register_shortcode' );

function taronix_gold_price_register_assets() {
	wp_register_style(
		'taronix-gold-price',
		get_template_directory_uri() . '/assets/css/taronix-gold-price.css',
		array(),
		'1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'taronix_gold_price_register_assets' );

function taronix_gold_price_clear_cache( $old_value, $value ) {
	delete_transient( 'taronix_gold18_price' );
}
add_action( 'update_option_gold18_price', 'taronix_gold_price_clear_cache', 10, 2 );
