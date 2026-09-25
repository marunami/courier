<?php
/**
 * Recaptcha class file
 *
 * @package courier
 */

namespace Courier_Plugin\Features;

use Alley\WP\Types\Feature;

/**
 * Loads reCAPTCHA v3 and the token-injection script network-wide.
 */
final readonly class Recaptcha implements Feature {
	/**
	 * Boot the feature.
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', $this->enqueue_scripts( ... ) );
	}

	/**
	 * Enqueue the reCAPTCHA API and the token-injection script.
	 */
	public function enqueue_scripts(): void {
		$site_key = vip_get_env_var( 'COURIER_RECAPTCHA_SITE_KEY', '' );

		if ( ! is_string( $site_key ) || $site_key === '' ) {
			return;
		}

		wp_enqueue_script( 'courier-recaptcha-js' );

		wp_add_inline_script(
			'courier-recaptcha-js',
			'var courierRecaptchaSiteKey = ' . wp_json_encode( $site_key ) . ';',
			'before',
		);
	}
}
