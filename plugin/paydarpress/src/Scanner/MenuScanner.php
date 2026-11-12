<?php
/**
 * Records navigation menu links in the link graph.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Scanner;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\UrlNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu links are stored with source_type `menu` and source_id = menu term ID.
 * They count toward menu_in, never toward internal_in (spec section 7.1, MENU_ONLY).
 */
final class MenuScanner {

	/**
	 * Rebuilds menu links. Returns the number of links stored.
	 */
	public function scan(): int {
		global $wpdb;

		$table = Schema::table( 'links' );
		$urls  = new UrlNormalizer( home_url( '/' ) );
		$home  = home_url( '/' );
		$count = 0;

		$wpdb->delete( $table, array( 'source_type' => 'menu' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$menus = wp_get_nav_menus();
		if ( ! is_array( $menus ) ) {
			return 0;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$url = $urls->absolute( (string) $item->url, $home );
				if ( null === $url || ! $urls->is_internal( $url ) || $urls->is_asset( $url ) ) {
					continue;
				}

				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'source_id'    => (int) $menu->term_id,
						'source_type'  => 'menu',
						'target_url'   => mb_substr( $url, 0, 500 ),
						'anchor'       => mb_substr( wp_strip_all_tags( (string) $item->title ), 0, 255 ),
						'link_type'    => 'internal',
						'target_state' => 'pending',
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Term ID of the menu assigned to the theme's first registered location, if any.
	 * Used by the profile builder to find key pages.
	 */
	public static function primary_menu_id(): ?int {
		$locations = get_nav_menu_locations();
		$registered = array_keys( get_registered_nav_menus() );

		foreach ( $registered as $location ) {
			if ( ! empty( $locations[ $location ] ) ) {
				return (int) $locations[ $location ];
			}
		}

		// No assigned location (e.g. block themes): use the menu with the most items.
		$best  = null;
		$count = 0;
		foreach ( (array) wp_get_nav_menus() as $menu ) {
			if ( (int) $menu->count > $count ) {
				$best  = (int) $menu->term_id;
				$count = (int) $menu->count;
			}
		}

		return $best;
	}
}
