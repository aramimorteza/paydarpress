<?php
/**
 * Search Console parser tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Gsc\GscParser as G;
use PaydarWP\PaydarPress\Gsc\CsvReader;

// Numbers.
check( 1234.0, G::number( '1,234' ), 'number: thousands comma' );
check( 1234.0, G::number( '۱٬۲۳۴' ), 'number: persian digits and separator' );
check( 3.5, G::number( '3.5%' ), 'number: percent' );
check( 3.5, G::number( '۳٫۵٪' ), 'number: persian percent' );
check( 3.5, G::number( '3,5' ), 'number: decimal comma' );
check( 12.4, G::number( '١٢٫٤' ), 'number: arabic digits' );
check( null, G::number( '' ), 'number: empty' );
check( null, G::number( 'abc' ), 'number: text' );

// CSV reader.
$csv = CsvReader::parse( "\xEF\xBB\xBFa;b\n1;2\n\n3;4\n" );
check( array( array( 'a', 'b' ), array( '1', '2' ), array( '3', '4' ) ), $csv['rows'], 'csv: bom, semicolon, blank line' );
check( true, CsvReader::parse( "a\n1\n2\n3\n", 2 )['truncated'], 'csv: row limit' );
check( array( array( 'x, y', 'z' ) ), CsvReader::parse( "\"x, y\",z\n" )['rows'], 'csv: quoted comma' );

// English export (no comparison).
$en = G::parse(
	array(
		'Pages.csv'   => "Top pages,Clicks,Impressions,CTR,Position\nhttps://vakilkaraj.net/mahrieh/,120,3400,3.53%,7.2\nhttps://vakilkaraj.net/,80,900,8.89%,2.1\n",
		'Queries.csv' => "Top queries,Clicks,Impressions,CTR,Position\nمهریه,40,1200,3.33%,6.5\n",
		'Dates.csv'   => "Date,Clicks,Impressions,CTR,Position\n2026-08-01,5,100,5%,8\n2026-10-30,6,110,5.45%,7.5\n",
		'Filters.csv' => "Filter,Value\nSearch type,Web\nDate,Last 3 months\n",
		'Devices.csv' => "Device,Clicks,Impressions,CTR,Position\nMobile,10,100,10%,5\n",
	)
);
check( 2, count( $en['pages'] ), 'en: pages' );
check( 1, count( $en['queries'] ), 'en: queries' );
check( 'https://vakilkaraj.net/mahrieh/', $en['pages'][0]['key'], 'en: page url' );
check( 120.0, $en['pages'][0]['clicks'], 'en: clicks' );
check( 0.0353, $en['pages'][0]['ctr'], 'en: ctr as fraction' );
check( 7.2, $en['pages'][0]['position'], 'en: position' );
check( false, $en['compare'], 'en: no compare' );
check( null, $en['pages'][0]['prev_clicks'], 'en: no prev' );
check( '2026-08-01', $en['period']['start'], 'en: period start' );
check( 91, $en['period']['days'], 'en: period days' );
check( 'Last 3 months', $en['period']['label'], 'en: period label' );
check( array(), $en['warnings'], 'en: no warnings' );
check( array( 'Pages.csv', 'Queries.csv', 'Dates.csv', 'Filters.csv' ), $en['files'], 'en: devices ignored' );

// Persian interface, comparison mode, Persian digits.
$fa = G::parse(
	array(
		'صفحه‌ها.csv' => "صفحه‌های برتر,کلیک‌ها در ۳ ماه گذشته,کلیک‌ها در ۳ ماه قبل,نمایش‌ها در ۳ ماه گذشته,نمایش‌ها در ۳ ماه قبل,CTR در ۳ ماه گذشته,CTR در ۳ ماه قبل,موقعیت در ۳ ماه گذشته,موقعیت در ۳ ماه قبل\n\"https://vakilkaraj.net/%D9%85%D9%87%D8%B1%DB%8C%D9%87/\",۱۲,۴۰,۱٬۲۰۰,۱٬۳۰۰,۱٪,۳٫۱٪,۱۱٫۲,۸٫۴\n",
	)
);
check( true, $fa['compare'], 'fa: compare detected' );
check( 12.0, $fa['pages'][0]['clicks'], 'fa: current clicks' );
check( 40.0, $fa['pages'][0]['prev_clicks'], 'fa: previous clicks' );
check( 1200.0, $fa['pages'][0]['impressions'], 'fa: impressions' );
check( 11.2, $fa['pages'][0]['position'], 'fa: position' );
check( 8.4, $fa['pages'][0]['prev_position'], 'fa: previous position' );
check( 0.01, $fa['pages'][0]['ctr'], 'fa: ctr' );

// Renamed file detected by content; filtered export warns; CTR derived when missing.
$odd = G::parse(
	array(
		'export (3).csv' => "Page,Clicks,Impressions,Position\nhttps://x.ir/a/,10,200,4\n",
		'Filters.csv'    => "Filter,Value\nPage,+/blog/\n",
	)
);
check( 1, count( $odd['pages'] ), 'renamed: detected as pages' );
check( 0.05, $odd['pages'][0]['ctr'], 'renamed: ctr derived' );
check( true, in_array( 'filtered:Page +/blog/', $odd['warnings'], true ), 'filtered export warns' );

check( array( 'no_data' ), G::parse( array( 'readme.txt' => 'hello' ) )['warnings'], 'no data warning' );
