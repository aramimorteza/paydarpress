<?php
/**
 * Stores the site profile with per-field source and confirmation.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Profile;

use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option `paydarpress_profile` (autoload: no):
 * [ 'fields' => [ name => [ 'value' => mixed, 'source' => detected|inferred|manual, 'confirmed' => bool ] ], 'updated_at' => ISO ]
 */
final class ProfileRepository {

	public const OPTION = 'paydarpress_profile';

	/**
	 * Full profile with every defined field present.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$fields = isset( $stored['fields'] ) && is_array( $stored['fields'] ) ? $stored['fields'] : array();

		foreach ( Fields::all() as $name => $def ) {
			if ( ! isset( $fields[ $name ] ) || ! is_array( $fields[ $name ] ) ) {
				$fields[ $name ] = array(
					'value'     => self::empty_value( $def['type'] ),
					'source'    => 'manual',
					'confirmed' => false,
				);
			}
		}

		return array(
			'fields'     => $fields,
			'updated_at' => $stored['updated_at'] ?? null,
		);
	}

	/**
	 * Value of one field.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 */
	public static function value( string $name ) {
		return self::get()['fields'][ $name ]['value'] ?? null;
	}

	/**
	 * Saves values entered by the user. Saved fields become confirmed. A field keeps its
	 * detected/inferred source when the user confirms it without changing the value.
	 *
	 * @param array $values Field name → raw value.
	 * @return array Updated profile.
	 */
	public static function save_user( array $values ): array {
		$profile = self::get();
		$defs    = Fields::all();

		foreach ( $values as $name => $raw ) {
			if ( ! isset( $defs[ $name ] ) || 'identity' === $defs[ $name ]['group'] ) {
				continue; // Detected identity fields are read-only.
			}

			$value   = self::sanitize( $defs[ $name ], $raw );
			$current = $profile['fields'][ $name ];
			$same    = wp_json_encode( $value ) === wp_json_encode( $current['value'] );

			$profile['fields'][ $name ] = array(
				'value'     => $value,
				'source'    => ( $same && 'manual' !== $current['source'] ) ? $current['source'] : 'manual',
				'confirmed' => true,
			);
		}

		self::store( $profile );

		return $profile;
	}

	/**
	 * Applies detected and inferred values. Never overwrites confirmed non-identity fields.
	 *
	 * @param array $detected Field → value (always applied, confirmed).
	 * @param array $inferred Field → value (applied only to unconfirmed fields).
	 * @return array Updated profile.
	 */
	public static function apply_inference( array $detected, array $inferred ): array {
		$profile = self::get();
		$defs    = Fields::all();

		foreach ( $detected as $name => $value ) {
			if ( isset( $defs[ $name ] ) ) {
				$profile['fields'][ $name ] = array(
					'value'     => $value,
					'source'    => 'detected',
					'confirmed' => true,
				);
			}
		}

		foreach ( $inferred as $name => $value ) {
			if ( ! isset( $defs[ $name ] ) || ! empty( $profile['fields'][ $name ]['confirmed'] ) ) {
				continue;
			}
			// Plain emptiness here (not is_empty(): a category list with blank scopes must still be
			// stored so the user can fill the scopes in).
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$profile['fields'][ $name ] = array(
				'value'     => self::sanitize( $defs[ $name ], $value ),
				'source'    => 'inferred',
				'confirmed' => false,
			);
		}

		self::store( $profile );

		return $profile;
	}

	/**
	 * Completion: share of required fields that are confirmed and non-empty.
	 *
	 * @param array|null $profile Profile (loaded when null).
	 * @return array{percent:int, missing:string[]}
	 */
	public static function completion( ?array $profile = null ): array {
		$profile  = $profile ?? self::get();
		$required = array_keys( array_filter( Fields::all(), static fn( $d ) => $d['required'] ) );
		$missing  = array();

		foreach ( $required as $name ) {
			$field = $profile['fields'][ $name ];
			if ( empty( $field['confirmed'] ) || self::is_empty( $field['value'] ) ) {
				$missing[] = $name;
			}
		}

		$done = count( $required ) - count( $missing );

		return array(
			'percent' => count( $required ) > 0 ? (int) round( $done * 100 / count( $required ) ) : 100,
			'missing' => $missing,
		);
	}

	/**
	 * IDs of key pages (confirmed or inferred), for the WEAK_INBOUND rule.
	 *
	 * @return int[]
	 */
	public static function key_page_ids(): array {
		$pages = self::value( 'key_pages' );

		return array_values( array_filter( array_map( static fn( $p ) => (int) ( $p['id'] ?? 0 ), is_array( $pages ) ? $pages : array() ) ) );
	}

	/**
	 * Sanitizes a value according to its field type.
	 *
	 * @param array $def Field definition.
	 * @param mixed $raw Raw value.
	 * @return mixed
	 */
	public static function sanitize( array $def, $raw ) {
		switch ( $def['type'] ) {
			case 'text':
				return mb_substr( sanitize_text_field( (string) $raw ), 0, 300 );

			case 'textarea':
				return mb_substr( sanitize_textarea_field( (string) $raw ), 0, 2000 );

			case 'select':
				$raw = (string) $raw;
				return isset( $def['options'][ $raw ] ) ? $raw : '';

			case 'bool':
				return (bool) $raw;

			case 'key_pages':
				$out = array();
				foreach ( array_slice( (array) $raw, 0, 20 ) as $item ) {
					$id   = (int) ( is_array( $item ) ? ( $item['id'] ?? 0 ) : 0 );
					$post = $id > 0 ? get_post( $id ) : null;
					if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
						continue;
					}
					$anchors = array_slice( array_values( array_filter( array_map( static fn( $a ) => mb_substr( sanitize_text_field( (string) $a ), 0, 80 ), (array) ( $item['anchors'] ?? array() ) ) ) ), 0, 6 );
					$out[ $id ] = array(
						'id'          => $id,
						'name'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
						'url'         => (string) get_permalink( $post ),
						'link_when'   => mb_substr( sanitize_text_field( (string) ( $item['link_when'] ?? '' ) ), 0, 200 ),
						// Categories whose articles should link here; empty = every article.
						'link_categories' => array_values( array_unique( array_filter( array_map( 'intval', array_slice( (array) ( $item['link_categories'] ?? array() ), 0, 30 ) ), static fn( $c ) => $c > 0 && term_exists( $c, 'category' ) ) ) ),
						'anchors'     => $anchors,
						'priority'    => 'high' === ( $item['priority'] ?? '' ) ? 'high' : 'normal',
						'page_type'   => in_array( $item['page_type'] ?? '', array( 'service', 'landing', 'product', 'category', 'contact', 'about', 'other' ), true ) ? $item['page_type'] : self::guess_page_type( $post ),
						'main_keyword' => mb_substr( sanitize_text_field( (string) ( $item['main_keyword'] ?? '' ) ), 0, 120 ),
						'detected_by' => sanitize_key( (string) ( $item['detected_by'] ?? 'manual' ) ),
					);
				}
				return array_values( $out );

			case 'cta_options':
				$out = array();
				foreach ( array_slice( (array) $raw, 0, 8 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$name = mb_substr( sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 0, 80 );
					$url  = esc_url_raw( (string) ( $item['url'] ?? '' ), array( 'http', 'https', 'tel', 'mailto' ) );
					if ( '' === $name || '' === $url ) {
						continue;
					}
					$out[] = array(
						'name'     => $name,
						'url'      => $url,
						'use_when' => mb_substr( sanitize_text_field( (string) ( $item['use_when'] ?? '' ) ), 0, 200 ),
					);
				}
				return $out;

			case 'categories':
				$out = array();
				foreach ( array_slice( (array) $raw, 0, 200 ) as $item ) {
					$id   = (int) ( is_array( $item ) ? ( $item['id'] ?? 0 ) : 0 );
					$term = $id > 0 ? get_term( $id ) : null;
					if ( ! $term instanceof WP_Term ) {
						continue;
					}
					$link       = get_term_link( $term );
					$out[ $id ] = array(
						'id'              => $id,
						'name'            => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'url'             => is_string( $link ) ? $link : '',
						'count'           => (int) $term->count,
						'scope'           => mb_substr( sanitize_textarea_field( (string) ( $item['scope'] ?? '' ) ), 0, 500 ),
						'search_patterns' => mb_substr( sanitize_text_field( (string) ( $item['search_patterns'] ?? '' ) ), 0, 300 ),
						'importance'      => in_array( $item['importance'] ?? '', array( 'high', 'normal', 'low' ), true ) ? $item['importance'] : 'normal',
					);
				}
				return array_values( $out );
		}

		return null;
	}

	/**
	 * Page type from the address and title (contact / about / product / category / service).
	 *
	 * @param WP_Post $post Page.
	 */
	public static function guess_page_type( WP_Post $post ): string {
		if ( 'product' === $post->post_type ) {
			return 'product';
		}
		$text = \PaydarWP\PaydarPress\Text\PersianNormalizer::normalize( rawurldecode( $post->post_name ) . ' ' . $post->post_title );
		foreach ( array(
			'contact' => array( 'contact', 'تماس', 'ارتباط با' ),
			'about'   => array( 'about', 'درباره', 'معرفی ما' ),
			'landing' => array( 'landing', 'campaign', 'جشنواره', 'تخفیف', 'رزرو', 'نوبت', 'مشاوره رایگان' ),
		) as $type => $words ) {
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $text, $w ) ) {
					return $type;
				}
			}
		}
		return 'post' === $post->post_type ? 'other' : 'service';
	}

	/**
	 * Whether a value is empty for completion purposes. Category lists need at least one scope.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			if ( array() === $value ) {
				return true;
			}
			$first = reset( $value );
			if ( is_array( $first ) && array_key_exists( 'scope', $first ) ) {
				return array() === array_filter( $value, static fn( $c ) => '' !== trim( (string) $c['scope'] ) );
			}
			return false;
		}

		return null === $value || '' === trim( (string) $value );
	}

	/**
	 * Empty value for a field type.
	 *
	 * @param string $type Type.
	 * @return mixed
	 */
	private static function empty_value( string $type ) {
		if ( in_array( $type, array( 'key_pages', 'cta_options', 'categories' ), true ) ) {
			return array();
		}

		return 'bool' === $type ? false : '';
	}

	/**
	 * Writes the option.
	 *
	 * @param array $profile Profile.
	 */
	private static function store( array $profile ): void {
		$profile['updated_at'] = gmdate( 'c' );

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $profile, '', false );
			return;
		}

		update_option( self::OPTION, $profile, false );
	}
}
