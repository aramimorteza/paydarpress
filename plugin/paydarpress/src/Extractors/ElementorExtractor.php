<?php
/**
 * Extracts content from Elementor's `_elementor_data` JSON.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Extractors;

/**
 * Converts Elementor widgets to equivalent HTML, then reuses HtmlExtractor.
 * This keeps heading, link, image and intro-paragraph logic in one place. See spec section 6.3.
 */
final class ElementorExtractor {

	/** @var HtmlExtractor */
	private $html;

	/**
	 * @param HtmlExtractor|null $html HTML extractor.
	 */
	public function __construct( ?HtmlExtractor $html = null ) {
		$this->html = $html ?? new HtmlExtractor();
	}

	/**
	 * Whether the given meta value is usable Elementor data.
	 *
	 * @param mixed $data Raw `_elementor_data` meta (JSON string or array).
	 */
	public static function decode( $data ): ?array {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}

		return is_array( $data ) && array() !== $data ? $data : null;
	}

	/**
	 * Extracts structure from decoded Elementor data.
	 *
	 * @param array $elements Decoded `_elementor_data`.
	 */
	public function extract( array $elements ): Extracted {
		$html   = $this->to_html( $elements );
		$result = $this->html->extract( $html );

		$result->source = 'elementor';

		return $result;
	}

	/**
	 * Recursively converts elements to HTML.
	 *
	 * @param array $elements Elements.
	 */
	private function to_html( array $elements ): string {
		$out = '';

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$out .= $this->widget_html( (string) ( $element['widgetType'] ?? '' ), (array) ( $element['settings'] ?? array() ) );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$out .= $this->to_html( $element['elements'] );
			}
		}

		return $out;
	}

	/**
	 * HTML for one widget.
	 *
	 * @param string $type     Widget type.
	 * @param array  $settings Widget settings.
	 */
	private function widget_html( string $type, array $settings ): string {
		switch ( $type ) {
			case 'heading':
				$tag   = $this->heading_tag( (string) ( $settings['header_size'] ?? 'h2' ) );
				$title = (string) ( $settings['title'] ?? '' );
				$html  = '<' . $tag . '>' . $this->esc( $title ) . '</' . $tag . '>';
				return $this->wrap_link( $html, $settings['link'] ?? null );

			case 'text-editor':
				return '<div>' . (string) ( $settings['editor'] ?? '' ) . '</div>';

			case 'image':
				return $this->image_html( $settings['image'] ?? null, $settings['link'] ?? null );

			case 'button':
				return $this->link_html( $settings['link'] ?? null, (string) ( $settings['text'] ?? '' ) );

			case 'icon-list':
				$out = '<ul>';
				foreach ( (array) ( $settings['icon_list'] ?? array() ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$text = $this->esc( (string) ( $item['text'] ?? '' ) );
					$out .= '<li>' . $this->wrap_link( $text, $item['link'] ?? null ) . '</li>';
				}
				return $out . '</ul>';

			case 'accordion':
			case 'toggle':
				$out = '';
				foreach ( (array) ( $settings['tabs'] ?? array() ) as $tab ) {
					if ( ! is_array( $tab ) ) {
						continue;
					}
					$out .= '<h3>' . $this->esc( (string) ( $tab['tab_title'] ?? '' ) ) . '</h3>';
					$out .= '<div>' . (string) ( $tab['tab_content'] ?? '' ) . '</div>';
				}
				return $out;

			default:
				return $this->generic_html( $settings );
		}
	}

	/**
	 * Fallback: known text fields and links of unknown widgets.
	 *
	 * @param array $settings Widget settings.
	 */
	private function generic_html( array $settings ): string {
		$out = '';

		foreach ( array( 'title', 'title_text', 'description_text', 'description', 'text' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$out .= '<p>' . $this->esc( $settings[ $key ] ) . '</p>';
			}
		}

		if ( isset( $settings['editor'] ) && is_string( $settings['editor'] ) ) {
			$out .= '<div>' . $settings['editor'] . '</div>';
		}

		if ( isset( $settings['link'] ) ) {
			$out .= $this->link_html( $settings['link'], '' );
		}

		return $out;
	}

	/**
	 * Image HTML. `alt` is only emitted if Elementor stored it; otherwise the scanner resolves it.
	 *
	 * @param mixed $image Elementor image setting.
	 * @param mixed $link  Elementor link setting.
	 */
	private function image_html( $image, $link ): string {
		if ( ! is_array( $image ) || empty( $image['url'] ) ) {
			return '';
		}

		$attrs = ' src="' . $this->esc( (string) $image['url'] ) . '"';
		if ( ! empty( $image['id'] ) ) {
			$attrs .= ' data-id="' . (int) $image['id'] . '"';
		}
		if ( array_key_exists( 'alt', $image ) && is_string( $image['alt'] ) ) {
			$attrs .= ' alt="' . $this->esc( $image['alt'] ) . '"';
		}

		return $this->wrap_link( '<img' . $attrs . '>', $link );
	}

	/**
	 * Anchor HTML from an Elementor link setting.
	 *
	 * @param mixed  $link Link setting (array with `url`).
	 * @param string $text Anchor text.
	 */
	private function link_html( $link, string $text ): string {
		$url = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';
		if ( '' === trim( $url ) ) {
			return '' !== $text ? '<p>' . $this->esc( $text ) . '</p>' : '';
		}

		return '<a href="' . $this->esc( $url ) . '">' . $this->esc( $text ) . '</a>';
	}

	/**
	 * Wraps HTML in a link if a URL is set.
	 *
	 * @param string $html Inner HTML.
	 * @param mixed  $link Link setting.
	 */
	private function wrap_link( string $html, $link ): string {
		$url = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';

		return '' === trim( $url ) ? $html : '<a href="' . $this->esc( $url ) . '">' . $html . '</a>';
	}

	/**
	 * Valid heading tag.
	 *
	 * @param string $tag Configured tag.
	 */
	private function heading_tag( string $tag ): string {
		$tag = strtolower( $tag );

		return in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'p';
	}

	/**
	 * HTML escaping (WordPress-independent).
	 *
	 * @param string $value Value.
	 */
	private function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
