#!/usr/bin/env bash
# Usage: upgrade-test.sh <old-version> [wp69|wp65]
# Fresh site with an old PaydarPress zip, some content and a scan; then the current code is
# installed over it. Checks schema, options, PHP errors and runs the integration tests.
set -uo pipefail
OLD="$1"; WP="${2:-wp69}"; SITE=/tmp/siteup; PORT=8090
cd /home/claude/tools
./setup-site.sh /tmp/wp/$WP $SITE $PORT >/dev/null
mkdir -p $SITE/wp-content/plugins && unzip -q /home/claude/paydarpress/dist/paydarpress-$OLD.zip -d $SITE/wp-content/plugins/
cat > $SITE/pp-step.php <<'PHP'
<?php
require __DIR__ . '/wp-load.php';
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
$step = $argv[1];
if ( 'old' === $step ) {
	$r = activate_plugin( 'paydarpress/paydarpress.php' );
	if ( is_wp_error( $r ) ) { echo 'ACTIVATE ERROR ', $r->get_error_message(), "\n"; }
	$cat = wp_insert_term( 'طلاق', 'category' );
	for ( $i = 1; $i <= 12; $i++ ) {
		wp_insert_post( array( 'post_title' => "مقاله آزمایشی $i درباره طلاق توافقی", 'post_name' => "مقاله-$i", 'post_status' => 'publish', 'post_content' => str_repeat( "<p>متن مقاله $i درباره طلاق توافقی و مهریه و حضانت.</p>", 20 ) . ( $i > 1 ? '<p><a href="' . home_url( '/مقاله-' . ( $i - 1 ) . '/' ) . '">مقاله قبلی</a></p>' : '' ), 'post_category' => array( is_array( $cat ) ? $cat['term_id'] : 1 ), 'tags_input' => array( 'طلاق', 'مهریه' ) ) );
	}
}
do_action( 'admin_init' );
// Start a full scan through the REST API (the same in every version).
$res = rest_do_request( new WP_REST_Request( 'POST', '/paydarpress/v1/scan' ) );
if ( $res->is_error() ) { echo 'scan start: ', wp_json_encode( $res->get_data(), JSON_UNESCAPED_UNICODE ), "\n"; }
for ( $i = 0; $i < 30; $i++ ) {
	if ( class_exists( 'ActionScheduler_QueueRunner' ) ) { ActionScheduler_QueueRunner::instance()->run( 'pp-test' ); }
}
global $wpdb;
echo 'db_version=', get_option( 'paydarpress_db_version' ), ' inventory=', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paydarpress_inventory" ), ' links=', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paydarpress_links" ), ' findings=', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paydarpress_audit_findings" ), "\n";
if ( 'new' === $step ) {
	$want = \PaydarWP\PaydarPress\Database\Schema::statements();
	$missing = array();
	foreach ( $want as $sql ) {
		if ( ! preg_match( '/CREATE TABLE (\S+) \((.*)\)/s', $sql, $m ) ) { continue; }
		$cols = array();
		$cols = array_flip( (array) $wpdb->get_col( "SHOW COLUMNS FROM {$m[1]}" ) );
		if ( ! $cols ) { $missing[] = "table {$m[1]}"; continue; }
		foreach ( explode( "\n", $m[2] ) as $line ) {
			if ( preg_match( '/^\s*([a-z_]+) [a-z]/', $line, $c ) && ! in_array( $c[1], array( 'PRIMARY', 'KEY', 'UNIQUE' ), true ) && ! isset( $cols[ $c[1] ] ) ) { $missing[] = "{$m[1]}.{$c[1]}"; }
		}
	}
	echo 'schema missing: ', $missing ? implode( ', ', $missing ) : 'none', "\n";
	echo 'expected db_version=', \PaydarWP\PaydarPress\Database\Schema::VERSION ?? '?', "\n";
}
PHP
echo "== old $OLD"
php $SITE/pp-step.php old >/dev/null 2>&1; php $SITE/pp-step.php scan 2>&1 | tail -3
/home/claude/tools/install-plugin.sh $SITE
echo "== new"
php $SITE/pp-step.php new 2>&1 | tail -4
echo "== php errors from paydarpress:"
grep -i "paydarpress" $SITE/debug.log 2>/dev/null | grep -iE "fatal|warning|notice|deprecated|error" | sed 's/^\[[^]]*\] //' | sort | uniq -c | head -10
echo "== integration"
php /home/claude/paydarpress/plugin/paydarpress/tests/integration.php $SITE 2>&1 | tail -3
