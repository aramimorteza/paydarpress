<?php
/**
 * RobotsParser tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Crawl\RobotsParser;

$wp_default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://x.ir/wp-sitemap.xml";
$r          = new RobotsParser( $wp_default );
check( 'partial', $r->state( 'Googlebot' ), 'wp default is partial (admin only)' );
check( 'partial', $r->state( 'GPTBot' ), 'wildcard applies to GPTBot' );

$r = new RobotsParser( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /" );
check( 'blocked', $r->state( 'GPTBot' ), 'gptbot blocked' );
check( 'allowed', $r->state( 'ClaudeBot' ), 'others allowed' );

$r = new RobotsParser( "User-agent: ClaudeBot\nUser-agent: PerplexityBot\nDisallow: / # no AI\n" );
check( 'blocked', $r->state( 'claudebot' ), 'grouped agents, case-insensitive' );
check( 'blocked', $r->state( 'PerplexityBot' ), 'second agent in group' );
check( 'allowed', $r->state( 'Bingbot' ), 'no wildcard group means allowed' );

$r = new RobotsParser( "User-agent: *\nDisallow:" );
check( 'allowed', $r->state( 'Googlebot' ), 'empty disallow allows all' );

$r = new RobotsParser( "User-agent: *\nDisallow: /\n" );
check( 'blocked', $r->state( 'Googlebot' ), 'whole site blocked' );
