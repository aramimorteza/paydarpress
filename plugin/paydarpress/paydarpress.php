<?php
/**
 * Plugin Name:       PaydarPress | پایدارپرس
 * Plugin URI:        https://paydarwp.ir/paydarpress
 * Description:       شناخت سایت، ممیزی سلامت محتوا و مدیریت هوشمند محتوا برای وردپرس.
 * Version:           0.15.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            PaydarWP
 * Author URI:        https://paydarwp.ir
 * Text Domain:       paydarpress
 * Domain Path:       /languages
 *
 * @package PaydarPress
 */

// This file must stay parseable by old PHP versions so the requirement notice can be shown.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'PAYDARPRESS_VERSION' ) ) {
	return;
}

define( 'PAYDARPRESS_VERSION', '0.15.0' );
define( 'PAYDARPRESS_FILE', __FILE__ );
define( 'PAYDARPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYDARPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYDARPRESS_MIN_PHP', '8.0' );
define( 'PAYDARPRESS_MIN_WP', '6.5' );

/**
 * Returns a list of unmet requirements (empty when everything is fine).
 *
 * @return array
 */
function paydarpress_unmet_requirements() {
	$errors = array();

	if ( version_compare( PHP_VERSION, PAYDARPRESS_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$errors[] = sprintf( __( 'پایدارپرس به PHP نسخه %1$s یا بالاتر نیاز دارد. نسخه فعلی: %2$s', 'paydarpress' ), PAYDARPRESS_MIN_PHP, PHP_VERSION );
	}

	if ( version_compare( get_bloginfo( 'version' ), PAYDARPRESS_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$errors[] = sprintf( __( 'پایدارپرس به وردپرس نسخه %1$s یا بالاتر نیاز دارد. نسخه فعلی: %2$s', 'paydarpress' ), PAYDARPRESS_MIN_WP, get_bloginfo( 'version' ) );
	}

	if ( ! file_exists( PAYDARPRESS_DIR . 'vendor/autoload.php' ) ) {
		$errors[] = __( 'فایل‌های وابستگی پایدارپرس (پوشه vendor) یافت نشد. لطفاً نسخه کامل افزونه را نصب کنید.', 'paydarpress' );
	}

	return $errors;
}

$paydarpress_errors = paydarpress_unmet_requirements();

if ( ! empty( $paydarpress_errors ) ) {
	add_action(
		'admin_notices',
		function () use ( $paydarpress_errors ) {
			echo '<div class="notice notice-error"><p><strong>PaydarPress</strong></p><ul>';
			foreach ( $paydarpress_errors as $paydarpress_error ) {
				echo '<li>' . esc_html( $paydarpress_error ) . '</li>';
			}
			echo '</ul></div>';
		}
	);
	return;
}

unset( $paydarpress_errors );

require_once PAYDARPRESS_DIR . 'vendor/autoload.php';

// Bundled Action Scheduler (lib/action-scheduler). It must be loaded from the main plugin file;
// if WooCommerce or another plugin ships a newer copy, Action Scheduler loads the newest one itself.
require_once PAYDARPRESS_DIR . 'lib/action-scheduler/action-scheduler.php';

// Shared PaydarWP Hub (menu, fonts, design tokens). Registers this copy; the newest copy wins.
require_once PAYDARPRESS_DIR . 'hub/hub.php';

register_activation_hook( __FILE__, array( \PaydarWP\PaydarPress\Database\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \PaydarWP\PaydarPress\Database\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\PaydarWP\PaydarPress\Plugin::instance()->boot();
	},
	20
);
