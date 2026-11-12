<?php
/**
 * Value object returned by content extractors.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Extractors;

/**
 * Structured content of one post. See spec section 6.2.
 */
final class Extracted {

	/** @var string Plain text. */
	public $text = '';

	/** @var array<int, array{level:int, text:string}> */
	public $headings = array();

	/** @var array<int, array{url:string, anchor:string}> */
	public $links = array();

	/**
	 * Images. `alt` is null when the attribute is missing (unknown), '' when empty.
	 *
	 * @var array<int, array{src:string, alt:?string, attachment_id:?int}>
	 */
	public $images = array();

	/** @var string[] Paragraph texts that appear before the first H2. */
	public $intro_paragraphs = array();

	/** @var string Which extractor produced this (`blocks` or `elementor`). */
	public $source = 'blocks';
}
