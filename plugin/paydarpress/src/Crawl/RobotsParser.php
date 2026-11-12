<?php
/**
 * robots.txt parser. WordPress-independent.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Crawl;

/**
 * Determines whether a crawler is allowed, partially blocked or blocked from the whole site.
 */
final class RobotsParser {

	/** @var array<int, array{agents:string[], rules:array<int, array{0:string, 1:string}>}> */
	private $groups = array();

	/**
	 * @param string $content robots.txt content.
	 */
	public function __construct( string $content ) {
		$this->parse( $content );
	}

	/**
	 * State for a user agent: `allowed`, `partial` or `blocked`.
	 *
	 * @param string $agent User-agent token, e.g. `GPTBot`.
	 */
	public function state( string $agent ): string {
		$rules = $this->rules_for( $agent );

		$root_blocked = false;
		$root_allowed = false;
		$partial      = false;

		foreach ( $rules as list( $type, $path ) ) {
			if ( 'disallow' === $type ) {
				if ( '/' === $path || '/*' === $path ) {
					$root_blocked = true;
				} elseif ( '' !== $path ) {
					$partial = true;
				}
			} elseif ( 'allow' === $type && ( '/' === $path || '/*' === $path ) ) {
				$root_allowed = true;
			}
		}

		if ( $root_blocked && ! $root_allowed ) {
			return 'blocked';
		}

		return ( $partial || $root_blocked ) ? 'partial' : 'allowed';
	}

	/**
	 * Rules of the most specific matching group (or `*`).
	 *
	 * @param string $agent User agent.
	 * @return array<int, array{0:string, 1:string}>
	 */
	private function rules_for( string $agent ): array {
		$agent    = strtolower( $agent );
		$wildcard = null;

		foreach ( $this->groups as $group ) {
			foreach ( $group['agents'] as $name ) {
				if ( '*' === $name ) {
					$wildcard = $wildcard ?? array();
					$wildcard = array_merge( $wildcard, $group['rules'] );
				} elseif ( $name === $agent ) {
					return $group['rules'];
				}
			}
		}

		return $wildcard ?? array();
	}

	/**
	 * Parses groups of User-agent lines followed by Allow/Disallow lines.
	 *
	 * @param string $content robots.txt content.
	 */
	private function parse( string $content ): void {
		$current     = null;
		$last_was_ua = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				if ( null === $current || ! $last_was_ua ) {
					if ( null !== $current ) {
						$this->groups[] = $current;
					}
					$current = array(
						'agents' => array(),
						'rules'  => array(),
					);
				}
				$current['agents'][] = strtolower( $value );
				$last_was_ua         = true;
				continue;
			}

			$last_was_ua = false;

			if ( null !== $current && ( 'allow' === $field || 'disallow' === $field ) ) {
				$current['rules'][] = array( $field, $value );
			}
		}

		if ( null !== $current ) {
			$this->groups[] = $current;
		}
	}
}
