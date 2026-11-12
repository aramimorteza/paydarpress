<?php
/**
 * PaydarWP Hub loader.
 *
 * Every PaydarWP product ships its own copy of the Hub. Each copy registers itself here,
 * and on `plugins_loaded` only the newest registered version is loaded. This keeps the
 * shared "پایدار وردپرس" menu, fonts and design tokens consistent when several PaydarWP
 * products are active at the same time.
 *
 * This file must not declare classes or rely on Composer autoloading, because several
 * copies of it may be included.
 *
 * @package PaydarWP\Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Version of THIS copy of the Hub. Bump it whenever anything under hub/ changes.
$paydarwp_hub_this_version = '1.0.0';

if ( ! isset( $GLOBALS['paydarwp_hub_registry'] ) || ! is_array( $GLOBALS['paydarwp_hub_registry'] ) ) {
	$GLOBALS['paydarwp_hub_registry'] = array();
}

$GLOBALS['paydarwp_hub_registry'][ $paydarwp_hub_this_version ] = array(
	'dir' => __DIR__ . '/',
	'url' => plugin_dir_url( __FILE__ ),
);

unset( $paydarwp_hub_this_version );

if ( ! has_action( 'plugins_loaded', 'paydarwp_hub_bootstrap' ) && ! function_exists( 'paydarwp_hub_bootstrap' ) ) {

	/**
	 * Loads the newest registered Hub copy. Runs once.
	 */
	function paydarwp_hub_bootstrap() {
		if ( class_exists( '\PaydarWP\Hub\Hub', false ) || empty( $GLOBALS['paydarwp_hub_registry'] ) ) {
			return;
		}

		$versions = array_keys( $GLOBALS['paydarwp_hub_registry'] );
		usort( $versions, 'version_compare' );
		$newest = end( $versions );
		$copy   = $GLOBALS['paydarwp_hub_registry'][ $newest ];

		require_once $copy['dir'] . 'src/Hub.php';

		\PaydarWP\Hub\Hub::init( $newest, $copy['dir'], $copy['url'] );
	}

	add_action( 'plugins_loaded', 'paydarwp_hub_bootstrap', 1 );
}
