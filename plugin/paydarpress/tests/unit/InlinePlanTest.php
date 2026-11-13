<?php
/**
 * Inline image count in proportion to the length.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Images\InlinePlan as P;

check( 0, P::expected( 600, 400, 5 ), 'inline: 600 words → none (featured image is enough)' );
check( 1, P::expected( 800, 400, 5 ), 'inline: 800 words → 1' );
check( 2, P::expected( 1200, 400, 5 ), 'inline: 1200 words → 2' );
check( 4, P::expected( 2000, 400, 5 ), 'inline: 2000 words → 4' );
check( 5, P::expected( 6000, 400, 5 ), 'inline: capped at maximum' );
check( 0, P::expected( 3000, 400, 0 ), 'inline: maximum 0 disables' );
check( 2, P::expected( 1800, 600, 5 ), 'inline: custom words per image' );
check( 'ok', P::verdict( 1200, 2, 400, 5 ), 'verdict: recommended count' );
check( 'ok', P::verdict( 1200, 1, 400, 5 ), 'verdict: one less is fine' );
check( 'none', P::verdict( 1200, 0, 400, 5 ), 'verdict: long text without images' );
check( 'few', P::verdict( 2400, 2, 400, 5 ), 'verdict: too few for the length' );
check( 'many', P::verdict( 1200, 5, 400, 5 ), 'verdict: too many for the length' );
check( 'ok', P::verdict( 500, 1, 400, 5 ), 'verdict: one image in a short text is fine' );
check( 'many', P::verdict( 500, 3, 400, 5 ), 'verdict: short text with many images' );
