<?php
/**
 * Integration tests against a real WordPress site with PaydarPress active.
 * Usage: php tests/integration.php /path/to/wordpress
 *
 * Every fixture is created here and removed at the end (posts, terms, redirects, changes,
 * link suggestions, options); the site's own content and settings are not changed.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

$root = rtrim( $argv[1] ?? '', '/' );
if ( '' === $root || ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/integration.php /path/to/wordpress\n" );
	exit( 2 );
}
require $root . '/wp-load.php';

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Improve\ArticleProgram;
use PaydarWP\PaydarPress\Improve\LinkProgram;
use PaydarWP\PaydarPress\Improve\SlugProgram;
use PaydarWP\PaydarPress\Improve\TagProgram;
use PaydarWP\PaydarPress\Scanner\ContentScanner;
use PaydarWP\PaydarPress\Strategy\ChangeLog;
use PaydarWP\PaydarPress\Strategy\Redirects;

$GLOBALS['pp_it'] = array( 'pass' => 0, 'fail' => 0 );

/**
 * Asserts a condition.
 *
 * @param bool   $ok   Condition.
 * @param string $name Test name.
 * @param string $info Extra output on failure.
 */
function it( bool $ok, string $name, string $info = '' ): void {
	if ( $ok ) {
		++$GLOBALS['pp_it']['pass'];
		return;
	}
	++$GLOBALS['pp_it']['fail'];
	echo "FAIL: {$name}" . ( '' !== $info ? "\n  {$info}" : '' ) . "\n";
}

global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admin ) {
	fwrite( STDERR, "No administrator on this site.\n" );
	exit( 2 );
}
wp_set_current_user( (int) $admin[0] );

$mark    = 'ppit' . wp_rand( 1000, 9999 );
$posts   = array();
$terms   = array();
$users   = array();
$started = gmdate( 'Y-m-d H:i:s', time() - 5 );
$scanner = new ContentScanner();
$make    = static function ( array $args ) use ( &$posts, $scanner ): int {
	$id      = (int) wp_insert_post( $args + array( 'post_status' => 'publish', 'post_type' => 'post' ) );
	$posts[] = $id;
	$scanner->scan( $id );
	return $id;
};

// 1. REST permissions: nobody but a manager can change anything.
foreach ( array( 'subscriber', 'author', 'editor' ) as $role ) {
	$users[ $role ] = (int) wp_insert_user( array( 'user_login' => $mark . $role, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => $mark . $role . '@example.invalid' ) );
}
$open = array();
foreach ( rest_get_server()->get_routes( 'paydarpress/v1' ) as $route => $handlers ) {
	foreach ( $handlers as $h ) {
		foreach ( array_keys( $h['methods'] ) as $method ) {
			$path = (string) preg_replace( '/\(\?P<\w+>[^)]*\)/', '1', $route );
			foreach ( array( 0 ) + $users as $role => $uid ) {
				wp_set_current_user( (int) $uid );
				$cb = $h['permission_callback'] ?? null;
				$ok = true === ( $cb ? call_user_func( $cb, new WP_REST_Request( $method, $path ) ) : true );
				$own_prefs = in_array( $route, array( '/paydarpress/v1/help/dismiss', '/paydarpress/v1/help/reset', '/paydarpress/v1/ui-mode' ), true );
				if ( $ok && '/paydarpress/v1' !== $route && ( 'GET' !== $method || 'editor' !== $role ) && ! ( 'editor' === $role && $own_prefs ) ) {
					$open[] = ( $role ?: 'guest' ) . " {$method} {$route}";
				}
			}
		}
	}
}
wp_set_current_user( (int) $admin[0] );
it( array() === $open, 'rest: no route open to non-managers', implode( ', ', $open ) );

// 2. No secret or email in any readable response.
$prev = get_option( 'paydarpress_ai', null );
$key  = 'sk-' . $mark . 'SECRET0123456789';
\PaydarWP\PaydarPress\Ai\Credentials::save( array( 'key' => $key ) );
$leaks = array();
foreach ( array( (int) $admin[0], $users['editor'] ) as $uid ) {
	wp_set_current_user( $uid );
	foreach ( rest_get_server()->get_routes( 'paydarpress/v1' ) as $route => $handlers ) {
		foreach ( $handlers as $h ) {
			if ( ! isset( $h['methods']['GET'] ) || str_contains( $route, '(?P' ) ) {
				continue;
			}
			$body = (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data() );
			if ( str_contains( $body, $mark . 'SECRET' ) || str_contains( $body, '@example.invalid' ) ) {
				$leaks[] = $route;
			}
		}
	}
}
wp_set_current_user( (int) $admin[0] );
null === $prev ? delete_option( 'paydarpress_ai' ) : update_option( 'paydarpress_ai', $prev, false );
it( array() === $leaks, 'rest: no API key or email in responses', implode( ', ', $leaks ) );

// 3. Address program: Persian slug → English with 301, internal links updated, change recorded.
// Plain permalinks have no slug in the address; the program is idle there (checked first).
$permalinks = (string) get_option( 'permalink_structure' );
if ( '' === $permalinks ) {
	it( array() === SlugProgram::items(), 'slug: idle on plain permalinks' );
	update_option( 'permalink_structure', '/%postname%/' );
	$GLOBALS['wp_rewrite']->init();
}
$target = $make( array( 'post_title' => 'آزمون آدرس ' . $mark, 'post_name' => 'آزمون-آدرس-' . $mark ) );
$old    = (string) get_permalink( $target );
$source = $make( array( 'post_title' => 'منبع ' . $mark, 'post_content' => '<p>متن <a href="' . esc_url( $old ) . '">آزمون آدرس</a> پایان</p>' ) );
$slug   = 'address-test-' . strtolower( $mark );
$res    = SlugProgram::apply( array( array( 'id' => $target, 'slug' => $slug ) ) );
it( 1 === count( $res['done'] ), 'slug: applied', wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) );
it( $slug === get_post_field( 'post_name', $target ), 'slug: post_name changed' );
it( str_contains( (string) get_post_field( 'post_content', $source ), '/' . $slug . '/' ), 'slug: internal link updated' );
$redirect = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'redirects' ) . ' WHERE target_id = %d ORDER BY id DESC LIMIT 1', $target ), ARRAY_A ); // phpcs:ignore WordPress.DB
it( is_array( $redirect ) && str_contains( rawurldecode( (string) $redirect['source_path'] ), 'آزمون-آدرس' ), 'slug: 301 redirect from the old address' );
it( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'changes' ) . " WHERE kind = 'slug' AND object_id = %d", $target ) ) > 0, 'slug: change recorded' ); // phpcs:ignore WordPress.DB
$bad = SlugProgram::apply( array( array( 'id' => $source, 'slug' => 'نامک-فارسی' ) ) );
it( 0 === count( $bad['done'] ) && count( $bad['errors'] ) > 0, 'slug: invalid slug refused' );
$good = $make( array( 'post_title' => 'English ' . $mark, 'post_name' => 'good-english-' . strtolower( $mark ) ) );
it( ! in_array( $good, array_column( SlugProgram::items(), 'id' ), true ), 'slug: a good English slug is never offered' );

// 4. Merge: retired article becomes a draft, redirects 301, links point to the one that stays.
$keep  = $make( array( 'post_title' => 'زرافه آبی کوهستان ' . $mark, 'post_content' => '<p>متن اصلی</p>' ) );
$from  = $make( array( 'post_title' => 'زرافه آبی کوهستان ' . $mark . ' دوم', 'post_content' => '<p>متن دوم</p>' ) );
$from_url = (string) get_permalink( $from );
$linker   = $make( array( 'post_title' => 'لینک‌دهنده ' . $mark, 'post_content' => '<p><a href="' . esc_url( $from_url ) . '">مقاله دوم</a></p>' ) );
( new \PaydarWP\PaydarPress\Links\LinkResolver() )->resolve_pending(); // As after a scan.
$groups = ArticleProgram::competing( 200 );
$found  = array_filter( $groups, static fn( $g ) => in_array( $g['keep']['id'], array( $keep, $from ), true ) );
it( 1 === count( $found ), 'compete: near-identical titles grouped' );
$r = Redirects::merge( $from, $keep );
it( 'draft' === get_post_status( $from ), 'merge: retired article is a draft' );
it( 1 === $r['links'] && str_contains( (string) get_post_field( 'post_content', $linker ), (string) get_permalink( $keep ) ), 'merge: links point to the article that stays', wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) . ' ' . get_post_field( 'post_content', $linker ) . ' links=' . wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, target_url FROM ' . Schema::table( 'links' ) . ' WHERE source_id = %d', $linker ) ) ) );

// 5. Keep apart: a pair the owner keeps is not offered again.
$a  = $make( array( 'post_title' => 'پنگوئن نارنجی صحرا ' . $mark ) );
$b  = $make( array( 'post_title' => 'پنگوئن نارنجی صحرا ' . $mark . ' جدید' ) );
$in = static fn() => (bool) array_filter( ArticleProgram::competing( 200 ), static fn( $g ) => in_array( $a, array_merge( array( $g['keep']['id'] ), array_column( $g['merge'], 'id' ) ), true ) );
it( $in(), 'compete: pair found before decision' );
$skip_before = get_option( ArticleProgram::OPTION_SKIP, null );
ArticleProgram::keep_apart( $a, $b );
it( ! $in(), 'compete: kept-apart pair not offered again' );
null === $skip_before ? delete_option( ArticleProgram::OPTION_SKIP ) : update_option( ArticleProgram::OPTION_SKIP, $skip_before, false );

// 6. Tags: the same word written two ways is one duplicate group.
$t1 = wp_insert_term( 'آزمون' . $mark . ' ها', 'post_tag' );
$t2 = wp_insert_term( 'آزمون' . $mark . '‌ها', 'post_tag' );
foreach ( array( $t1, $t2 ) as $t ) {
	if ( is_array( $t ) ) {
		$terms[] = (int) $t['term_id'];
	}
}
delete_option( 'paydarpress_tag_dups' );
$dups = array_filter( TagProgram::duplicates( 'post_tag' ), static fn( $g ) => in_array( $g['keep']['id'], $terms, true ) );
it( 1 === count( $dups ), 'tags: half-space and space versions grouped' );
it( 1 === count( $dups ) && str_contains( reset( $dups )['keep']['name'], '‌' ), 'tags: the correctly spelled one (half-space) stays' );

// 7. Link program: a suggestion is found where the target's phrase already appears.
$lt  = $make( array( 'post_title' => 'مهریه ' . $mark, 'post_content' => '<p>درباره مهریه</p>' ) );
$ls  = $make( array( 'post_title' => 'حقوق مالی مهریه زن ' . $mark, 'post_content' => '<p>در این مطلب از مهریه ' . $mark . ' و نفقه حرف می‌زنیم و مهریه ' . $mark . ' را توضیح می‌دهیم.</p>' . str_repeat( '<p>متن نمونه برای طول کافی مقاله که سیاست محتوا را راضی کند و جای لینک داشته باشد.</p>', 30 ) ) );
$res = ( new \PaydarWP\PaydarPress\Links\LinkQueue() )->generate_for( array( $lt => 3 ), 5, 'آزمون' );
$sug = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'link_suggestions' ) . ' WHERE source_id = %d AND target_id = %d', $ls, $lt ) ); // phpcs:ignore WordPress.DB
it( $sug > 0, 'links: suggestion created from an existing phrase', wp_json_encode( $res ) . ' src=' . $ls . ' cands=' . wp_json_encode( array_column( ( new \PaydarWP\PaydarPress\Links\LinkSuggester() )->for_target( $lt, 8 ), 'id' ) ) . ' block=' . wp_json_encode( ( new \PaydarWP\PaydarPress\Links\LinkQueue() )->budget_block( get_post( $ls ), false ) ) );
it( LinkProgram::target_for( LinkProgram::target(), true ) === 2 * LinkProgram::target(), 'links: key pages need twice the target' );

// 8. CSV export: a title that starts with "=" opens as text.
$f = $make( array( 'post_title' => '=HYPERLINK("http://example.invalid","' . $mark . '")' ) );
ChangeLog::record( 'meta', $f, 'آزمون ' . $mark );
$csv  = ChangeLog::csv( array() );
$line = (string) current( array_filter( explode( "\n", $csv ), static fn( $l ) => str_contains( $l, $mark ) && str_contains( $l, 'HYPERLINK' ) ) );
it( str_contains( $line, "'=HYPERLINK" ), 'csv: formula cell neutralised', $line );

// Clean up every fixture.
update_option( 'permalink_structure', $permalinks );
$GLOBALS['wp_rewrite']->init();
foreach ( $posts as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $terms as $id ) {
	wp_delete_term( $id, 'post_tag' );
}
foreach ( $users as $uid ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
}
$ids = implode( ',', array_map( 'intval', $posts ) );
// phpcs:disable WordPress.DB
$wpdb->query( 'DELETE FROM ' . Schema::table( 'redirects' ) . " WHERE target_id IN ({$ids})" );
$wpdb->query( 'DELETE FROM ' . Schema::table( 'changes' ) . " WHERE object_id IN ({$ids})" );
$wpdb->query( 'DELETE FROM ' . Schema::table( 'link_suggestions' ) . " WHERE source_id IN ({$ids}) OR target_id IN ({$ids})" );
$wpdb->query( 'DELETE FROM ' . Schema::table( 'inventory' ) . " WHERE object_id IN ({$ids})" );
$wpdb->query( 'DELETE FROM ' . Schema::table( 'links' ) . " WHERE source_id IN ({$ids})" );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'activity' ) . ' WHERE message LIKE %s', '%' . $wpdb->esc_like( $mark ) . '%' ) );
// phpcs:enable
delete_option( 'paydarpress_tag_dups' );

printf( "\n%d passed, %d failed\n", $GLOBALS['pp_it']['pass'], $GLOBALS['pp_it']['fail'] );
exit( $GLOBALS['pp_it']['fail'] > 0 ? 1 : 0 );
