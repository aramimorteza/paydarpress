<?php
/**
 * Technical report for support tickets: versions, environment and counts only.
 *
 * Never included: emails, usernames, user data, comments, post titles or content, keys,
 * IPs or full URLs. The site's domain is added only when the owner ticks it.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Commerce;

use PaydarWP\PaydarPress\Ai\Credentials;
use PaydarWP\PaydarPress\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text report.
 */
final class SupportReport {

	/**
	 * Report lines as label => value.
	 *
	 * @param bool $with_site Include the site's domain.
	 */
	public static function data( bool $with_site = false ): array {
		global $wpdb;

		$ai     = Credentials::public_view();
		$lic    = License::stored();
		$seo    = \PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->key();
		$owner  = \PaydarWP\PaydarPress\Strategy\RedirectOwner::detect();
		$inv    = Schema::table( 'inventory' );
		$jobs   = Schema::table( 'ai_jobs' );
		$scans  = Schema::table( 'scans' );
		$host   = '' !== $ai['base_url'] ? (string) wp_parse_url( $ai['base_url'], PHP_URL_HOST ) : 'پیش‌فرض ' . $ai['provider'];
		$theme  = wp_get_theme();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv}" );
		$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'failed' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)" );
		$scan   = $wpdb->get_row( "SELECT status, started_at FROM {$scans} ORDER BY id DESC LIMIT 1", ARRAY_A );
		// phpcs:enable

		$as_pending = '';
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$as_pending = (string) count( as_get_scheduled_actions( array( 'group' => 'paydarpress', 'status' => 'pending', 'per_page' => 100 ), 'ids' ) );
		}

		$plugins = array_map( static fn( $p ) => dirname( (string) $p ), (array) get_option( 'active_plugins', array() ) );
		$known   = array_values( array_intersect( $plugins, array( 'wordpress-seo', 'wordpress-seo-premium', 'seo-by-rank-math', 'seo-by-rank-math-pro', 'redirection', 'woocommerce', 'litespeed-cache', 'wp-rocket', 'w3-total-cache', 'elementor', 'polylang', 'sitepress-multilingual-cms', 'wp-parsidate', 'persian-woocommerce' ) ) );

		$out = array(
			'پایدارپرس'           => PAYDARPRESS_VERSION . ' (پایگاه داده ' . get_option( 'paydarpress_db_version', '?' ) . ')',
			'وردپرس'              => get_bloginfo( 'version' ) . ( is_multisite() ? ' (چندسایته)' : '' ) . ' — ' . get_locale(),
			'PHP'                 => PHP_VERSION . ' — حافظه ' . ini_get( 'memory_limit' ) . ' — زمان اجرا ' . ini_get( 'max_execution_time' ) . 's',
			'پایگاه داده'         => $wpdb->db_server_info() ? preg_replace( '/[^0-9A-Za-z.\- ]/', '', (string) $wpdb->db_server_info() ) : '?',
			'پوسته'               => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'افزونه‌های فعال'     => count( $plugins ) . ( $known ? ' — ' . implode( '، ', $known ) : '' ),
			'افزونه سئو'          => $seo,
			'مالک ریدایرکت'       => $owner ? $owner['key'] : 'پایدارپرس',
			'کرون وردپرس'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'غیرفعال (کرون سرور)' : 'فعال',
			'کارهای پس‌زمینه در صف' => '' !== $as_pending ? $as_pending : '?',
			'آخرین اسکن'          => $scan ? $scan['status'] . ' — ' . $scan['started_at'] . ' UTC' : 'انجام نشده',
			'مطالب شناسایی‌شده'   => (string) $items,
			'هوش مصنوعی'          => $ai['configured'] ? 'وصل — ' . $ai['provider'] . ' — ' . $host . ' — ' . $ai['model'] : 'وصل نیست (حالت دستی)',
			'درخواست‌های ناموفق ۳۰ روز' => (string) $failed,
			'لایسنس'              => License::STATUS_LABELS[ $lic['status'] ] . ( '' !== $lic['unreachable'] ? ' (سرور در دسترس نبود)' : '' ),
			'HTTPS'               => is_ssl() ? 'بله' : 'خیر',
			'تاریخ گزارش'         => gmdate( 'Y-m-d H:i' ) . ' UTC',
		);
		if ( $with_site ) {
			$out = array( 'دامنه' => License::site() ) + $out;
		}
		return $out;
	}

	/**
	 * Plain text for pasting into a ticket.
	 *
	 * @param bool $with_site Include the domain.
	 */
	public static function text( bool $with_site = false ): string {
		$lines = array( '— گزارش فنی پایدارپرس —' );
		foreach ( self::data( $with_site ) as $k => $v ) {
			$lines[] = $k . ': ' . $v;
		}
		return implode( "\n", $lines );
	}
}
