<?php
/**
 * Category-structure suggestions imported from the assistant. Stored as a to-do list; nothing
 * changes on the site until the owner does it.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed list (small: at most a few dozen items).
 */
final class StructurePlan {

	public const OPTION = 'paydarpress_structure_plan';

	public const ACTIONS = array(
		'merge'   => 'ادغام',
		'split'   => 'تقسیم',
		'create'  => 'دسته تازه',
		'rename'  => 'تغییر نام نمایشی',
		'noindex' => 'خارج کردن از گوگل (noindex)',
		'keep'    => 'بدون تغییر',
	);

	/**
	 * How to carry out each action safely.
	 */
	public const HOWTO = array(
		'merge'   => 'نوشته‌های این دسته را به دسته مقصد منتقل کنید (ویرایش گروهی نوشته‌ها)، سپس دسته قدیمی را حذف کنید و آدرس آن را با ۳۰۱ به دسته مقصد ریدایرکت کنید (افزونه سئو یا Redirection).',
		'split'   => 'دسته تازه بسازید و فقط نوشته‌هایی را که به موضوع تازه تعلق دارند منتقل کنید؛ دسته فعلی و آدرسش می‌ماند.',
		'create'  => 'دسته را بسازید، متن معرفی بنویسید و اهمیتش را در پروفایل تعیین کنید؛ پایدارپرس برایش موضوع پیشنهاد می‌دهد.',
		'rename'  => 'فقط «نام» را در ویرایش دسته عوض کنید؛ «نامک» (آدرس) را تغییر ندهید.',
		'noindex' => 'در تنظیمات افزونه سئو، این آرشیو را noindex کنید (یا دسته را با دسته مرتبط ادغام کنید).',
		'keep'    => '',
	);

	/**
	 * All items.
	 */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? array_values( $v ) : array();
	}

	/**
	 * Replaces open items with a new set (done/rejected history is kept).
	 *
	 * @param array $items Checked suggestions.
	 */
	public static function add( array $items ): int {
		$keep = array_values( array_filter( self::all(), static fn( $i ) => 'open' !== $i['status'] ) );
		$n    = 0;
		foreach ( $items as $i ) {
			if ( 'keep' === $i['action'] ) {
				continue;
			}
			$keep[] = array(
				'id'         => strtolower( wp_generate_password( 10, false, false ) ),
				'action'     => $i['action'],
				'taxonomy'   => $i['taxonomy'],
				'term_id'    => $i['term_id'],
				'name'       => $i['name'],
				'new_name'   => $i['new_name'],
				'into_id'    => $i['into_term_id'],
				'into_name'  => $i['into_name'],
				'reason'     => $i['reason'],
				'priority'   => $i['priority'],
				'status'     => 'open',
				'created_at' => gmdate( 'c' ),
			);
			++$n;
		}
		update_option( self::OPTION, array_slice( $keep, -60 ), false );
		return $n;
	}

	/**
	 * Marks an item done or rejected (or open again).
	 *
	 * @param string $id     Item id.
	 * @param string $status open|done|rejected.
	 */
	public static function set( string $id, string $status ): void {
		$items = self::all();
		foreach ( $items as &$i ) {
			if ( $i['id'] === $id ) {
				$i['status'] = $status;
			}
		}
		unset( $i );
		update_option( self::OPTION, $items, false );
	}

	/**
	 * Items for the UI with labels, edit links and how-to.
	 */
	public static function view(): array {
		$order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		$items = self::all();
		usort( $items, static fn( $a, $b ) => array( 'open' !== $a['status'], $order[ $a['priority'] ] ?? 1 ) <=> array( 'open' !== $b['status'], $order[ $b['priority'] ] ?? 1 ) );
		return array_map(
			static function ( $i ) {
				$i['action_label'] = self::ACTIONS[ $i['action'] ] ?? $i['action'];
				$i['howto']        = self::HOWTO[ $i['action'] ] ?? '';
				$i['edit_url']     = $i['term_id'] ? (string) get_edit_term_link( (int) $i['term_id'], $i['taxonomy'] ) : '';
				$i['new_url']      = 'create' === $i['action'] || 'split' === $i['action'] ? admin_url( 'edit-tags.php?taxonomy=' . $i['taxonomy'] . ( 'product_cat' === $i['taxonomy'] ? '&post_type=product' : '' ) ) : '';
				return $i;
			},
			$items
		);
	}
}
