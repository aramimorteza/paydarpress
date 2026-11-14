<?php
// Usage: php uninstall-check.php <site> <keep|delete>
require $argv[1] . '/wp-load.php';
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
update_user_meta( 1, 'paydarpress_ui_mode', 'pro' );
update_option( 'paydarwp_hub_probe', 'keep' );
update_option( 'paydarpress_ai_batch_9', 'x' );
set_transient( 'paydarpress_tasks_cache', 1, 600 );
if ( 'delete' === ( $argv[2] ?? 'keep' ) ) {
	$s = get_option( 'paydarpress_settings', array() );
	$s['uninstall']['delete_data'] = true;
	update_option( 'paydarpress_settings', $s );
}
deactivate_plugins( 'paydarpress/paydarpress.php' );
$r = delete_plugins( array( 'paydarpress/paydarpress.php' ) );
echo 'delete: ', is_wp_error( $r ) ? $r->get_error_message() : var_export( $r, true ), "\n";
global $wpdb;
$tables = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%paydarpress%'" );
echo 'tables left: ', count( $tables ), "\n";
echo 'options left: ', implode( ',', $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%paydarpress%'" ) ) ?: 'none', "\n";
echo 'hub option kept: ', get_option( 'paydarwp_hub_probe' ), "\n";
echo 'usermeta left: ', implode( ',', $wpdb->get_col( "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%paydarpress%'" ) ) ?: 'none', "\n";
$role = get_role( 'administrator' ); echo 'caps left: ', implode( ',', array_filter( array_keys( $role->capabilities ), fn( $c ) => str_contains( $c, 'paydarpress' ) ) ) ?: 'none', "\n";
echo 'pending actions left: ', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status='pending' AND hook LIKE 'paydarpress%'" ), "\n";
