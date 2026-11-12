<?php
/**
 * Custom capabilities.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and removes PaydarPress capabilities. See docs/phase1-spec.md section 11.
 */
final class Capabilities {

	public const VIEW   = 'paydarpress_view';
	public const MANAGE = 'paydarpress_manage';
	public const EXPORT = 'paydarpress_export';

	/**
	 * Capabilities per role.
	 */
	public static function map(): array {
		return array(
			'administrator' => array( self::VIEW, self::MANAGE, self::EXPORT ),
			'editor'        => array( self::VIEW ),
		);
	}

	/**
	 * Adds capabilities to roles. Idempotent.
	 */
	public static function add(): void {
		foreach ( self::map() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes PaydarPress capabilities from every role.
	 */
	public static function remove(): void {
		$all = array( self::VIEW, self::MANAGE, self::EXPORT );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $all as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
