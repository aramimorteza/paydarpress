<?php
/**
 * Improvement programs: pure rules (tags, addresses, articles, links).
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Improve\ArticleProgram as A;
use PaydarWP\PaydarPress\Improve\LinkProgram as L;
use PaydarWP\PaydarPress\Improve\SlugProgram as S;
use PaydarWP\PaydarPress\Improve\TagProgram as T;

// Tags: the same word written differently has one key.
check( T::dup_key( 'طلاق‌ها' ), T::dup_key( 'طلاق ها' ), 'tag key: half-space vs space' );
check( T::dup_key( 'طلاق' ), T::dup_key( 'طلاق‌ها' ), 'tag key: plural ها' );
check( T::dup_key( 'وکیل' ), T::dup_key( 'وکیل‌های' ), 'tag key: plural های' );
check( T::dup_key( 'مهريه' ), T::dup_key( 'مهریه' ), 'tag key: Arabic ye' );
check( true, T::dup_key( 'طلاق' ) !== T::dup_key( 'حضانت' ), 'tag key: different words differ' );
check( true, T::one_edit( 'وکیل طلاق', 'وکیل طلاقق' ), 'one edit: insertion' );
check( true, T::one_edit( 'حضانت فرزند', 'حضانت فرزن' ), 'one edit: deletion' );
check( true, T::one_edit( 'مهریه', 'مهرپه' ), 'one edit: substitution' );
check( false, T::one_edit( 'مهریه', 'مهریه' ), 'one edit: identical is not an edit' );
check( false, T::one_edit( 'طلاق', 'حضانت' ), 'one edit: different words' );
check( false, T::one_edit( 'abcd', 'abxy' ), 'one edit: two substitutions' );

// Addresses: only short, meaningful English slugs pass.
check( true, S::valid( 'consensual-divorce' ), 'slug: english words' );
check( true, S::valid( 'divorce-cost-2026' ), 'slug: with a number' );
check( false, S::valid( 'طلاق-توافقی' ), 'slug: persian' );
check( false, S::valid( 'Divorce-Cost' ), 'slug: capitals' );
check( false, S::valid( 'divorce--cost' ), 'slug: double dash' );
check( false, S::valid( '-divorce' ), 'slug: leading dash' );
check( false, S::valid( 'a-b-c-d-e-f-g-h-i' ), 'slug: more than 8 words' );
check( false, S::valid( str_repeat( 'a', 71 ) ), 'slug: longer than 70' );
check( false, S::valid( 'divorce cost' ), 'slug: space' );

// Articles: weak pages Google already shows come first, but demand does not bury everything.
check( 40, A::priority( 40, 0 ), 'priority: no impressions = score' );
check( true, A::priority( 40, 1000 ) > A::priority( 60, 0 ), 'priority: shown page beats unseen worse page' );
check( true, A::priority( 20, 100000 ) < A::priority( 60, 100 ), 'priority: high demand does not bury a much weaker page' );
check( 0, A::priority( -5, -10 ), 'priority: negative input' );
check( true, A::similar( array( 'هزینه', 'وکیل', 'طلاق' ), array( 'هزینه', 'وکیل', 'طلاق', 'کرج' ) ), 'compete: near-identical titles' );
check( false, A::similar( array( 'هزینه', 'وکیل', 'طلاق' ), array( 'شرایط', 'حضانت', 'فرزند' ) ), 'compete: different topics' );
check( false, A::similar( array( 'طلاق', 'توافقی', 'مدارک', 'لازم', 'مراحل' ), array( 'طلاق', 'غیابی', 'مدت', 'زمان', 'هزینه' ) ), 'compete: one shared word only' );
check( false, A::similar( array( 'طلاق' ), array( 'طلاق' ) ), 'compete: one-word titles are not judged' );

// Links: key pages and pillars need twice as many inbound links.
check( 3, L::target_for( 3, false ), 'inbound target: article' );
check( 6, L::target_for( 3, true ), 'inbound target: key page' );
check( 1, L::target_for( 0, false ), 'inbound target: at least 1' );
