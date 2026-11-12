<?php
/**
 * Uninstall handler.
 *
 * Data is deleted only when the administrator enabled
 * "حذف همه داده‌ها هنگام حذف افزونه" in settings. Shared PaydarWP Hub data is never touched.
 *
 * @package PaydarPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$paydarpress_settings = get_option( 'paydarpress_settings', array() );

if ( empty( $paydarpress_settings['uninstall']['delete_data'] ) ) {
	return;
}

$paydarpress_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $paydarpress_autoload ) ) {
	return;
}

require_once $paydarpress_autoload;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'paydarpress' );
}

\PaydarWP\PaydarPress\Database\Installer::uninstall();
