<?php
/**
 * PersianNormalizer tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Text\PersianNormalizer as N;

check( 'کیک', N::normalize( 'كيك' ), 'arabic kaf and yeh' );
check( 'نفقه زن در 1402', N::normalize( 'نفقه زن در ۱۴۰۲' ), 'persian digits' );
check( '2023', N::normalize( '٢٠٢٣' ), 'arabic digits' );
check( 'محمد', N::normalize( 'مُحَمَّد' ), 'diacritics removed' );
check( 'سلام', N::normalize( 'ســـلام' ), 'tatweel removed' );
check( "می\u{200C}شود", N::normalize( "می \u{200C} شود" ), 'spaces around zwnj' );
check( "می\u{200C}شود", N::normalize( "می\u{200B}شود" ), 'zwsp becomes zwnj' );
check( 'a b', N::normalize( "  A\u{00A0}\n B " ), 'whitespace and lowercase' );
check( 'طلاق توافقی', N::normalize( 'طلاق&nbsp;توافقی' ), 'html entity' );

check( 'می شود', N::for_key( "می\u{200C}شود" ), 'key: zwnj to space' );
check( 'می شود', N::for_key( 'می شود!' ), 'key: punctuation removed' );
check( N::for_key( 'اجرت المثل زن چیست؟' ), N::for_key( 'اجرت‌المثل زن چیست' ), 'key: equivalent titles' );
check( 'میشود', N::compact( "می\u{200C}شود" ), 'compact' );
check( N::compact( 'وکیل کرج' ), N::compact( 'وکیل‌کرج' ), 'compact tags equal' );

check( 'سال 1402', N::latin_digits( 'سال ۱۴۰۲' ), 'latin digits only' );
check( 'كيك', N::latin_digits( 'كيك' ), 'latin_digits keeps letters' );

check( 4, N::word_count( "طلاق توافقی چگونه است؟" ), 'word count' );
check( 3, N::word_count( "می\u{200C}خواهم طلاق بگیرم" ), 'zwnj joins word' );
check( 0, N::word_count( "   " ), 'empty word count' );
check( '۱۸', N::fa_number( 18 ), 'fa_number int' );
check( '۳٬۴۲۶', N::fa_number( 3426 ), 'fa_number thousands' );
check( '۲٫۵', N::fa_number( 2.5 ), 'fa_number decimal' );
