<?php
/**
 * WordPress dashboard widget: stored health stats only (no queries beyond one option read).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Admin;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard widget.
 */
final class DashboardWidget {

	public const ID = 'paydarpress_health';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Adds the widget when enabled and allowed.
	 */
	public function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) || ! (bool) Settings::get( 'ui.dashboard_widget', true ) ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, 'پایدارپرس: سلامت محتوا', array( $this, 'render' ) );
	}

	/**
	 * Widget markup. Inline styles keep the dashboard free of extra stylesheet requests.
	 */
	public function render(): void {
		$stats = get_option( AuditEngine::OPTION_STATS, array() );
		$url   = admin_url( 'admin.php?page=' . AdminPage::SLUG );

		echo '<div dir="rtl" style="text-align:right">';

		if ( ! is_array( $stats ) || ! isset( $stats['health_score'] ) ) {
			echo '<p>' . esc_html( 'هنوز سایت اسکن نشده است. با یک اسکن، مشکلات محتوا، نوشته‌های یتیم و پیشنهاد بازنویسی را ببینید.' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $url . '#/welcome' ) . '">' . esc_html( 'شروع اسکن' ) . '</a></p></div>';
			return;
		}

		$score = (int) $stats['health_score'];
		$color = $score >= 80 ? '#1a7f37' : ( $score >= 50 ? '#9a6700' : '#cf222e' );
		$sev   = (array) ( $stats['by_severity'] ?? array() );
		$rows  = array(
			array( 'مشکلات مهم', (int) ( $sev['high'] ?? 0 ), '#/audit' ),
			array( 'محتوای یتیم', (int) ( $stats['orphans'] ?? 0 ), '#/orphans' ),
			array( 'پیشنهاد بازنویسی', (int) ( $stats['refresh'] ?? 0 ), '#/refresh' ),
		);

		printf(
			'<p style="display:flex;align-items:center;gap:12px;margin:0 0 12px"><span style="font-size:32px;font-weight:700;color:%1$s">%2$s</span><span>%3$s</span></p>',
			esc_attr( $color ),
			esc_html( PersianNormalizer::fa_number( (string) $score ) ),
			esc_html( 'امتیاز سلامت محتوا از ۱۰۰' )
		);

		echo '<ul style="margin:0 0 12px">';
		foreach ( $rows as $row ) {
			printf(
				'<li style="display:flex;justify-content:space-between"><a href="%1$s">%2$s</a><strong>%3$s</strong></li>',
				esc_url( $url . $row[2] ),
				esc_html( $row[0] ),
				esc_html( PersianNormalizer::fa_number( (string) $row[1] ) )
			);
		}
		echo '</ul>';

		// Weekly Search Console reminder (respects «later» on the task list).
		if ( \PaydarWP\PaydarPress\Gsc\GscRepository::needs_refresh() ) {
			$task = \PaydarWP\PaydarPress\Tasks\TaskBoard::gsc_refresh_task();
			if ( ! \PaydarWP\PaydarPress\Tasks\TaskBoard::is_hidden( $task['key'] ) ) {
				printf(
					'<p style="margin:0 0 12px;padding:8px 10px;background:#fdf1de;border-radius:4px">%1$s <a href="%2$s">%3$s</a></p>',
					esc_html( sprintf( '%s روز از آخرین ورود داده سرچ کنسول گذشته است.', PersianNormalizer::fa_number( (int) \PaydarWP\PaydarPress\Gsc\GscRepository::days_since() ) ) ),
					esc_url( $url . '#/gsc' ),
					esc_html( 'ورود خروجی تازه' )
				);
			}
		}

		printf( '<p style="margin:0"><a class="button" href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( 'باز کردن پایدارپرس' ) );
		echo '</div>';
	}
}
