import { useEffect, useState } from '@wordpress/element';
import { Dashicon } from '@wordpress/components';
import { setMode, useMode } from '../lib/uiMode';
import { get } from '../lib/api';
import { num } from '../lib/format';
import { SECTIONS, sectionOf } from '../routes';

/**
 * Counts of things waiting for the user (refreshed when the page changes).
 *
 * @param {string} current Current route.
 */
export function useBadges( current ) {
	const [ badges, setBadges ] = useState( ( window.paydarpress && window.paydarpress.badges ) || {} );
	useEffect( () => {
		get( '/badges' ).then( setBadges ).catch( () => {} );
	}, [ current ] );
	return badges;
}

/**
 * Small count bubble.
 *
 * @param {Object}  props
 * @param {number}  props.count  Count.
 * @param {boolean} props.accent Filled style.
 */
export function Badge( { count, accent } ) {
	if ( ! count ) {
		return null;
	}
	return <span className={ `pp-nav__badge${ accent ? ' is-accent' : '' }` }>{ count > 999 ? '+۹۹۹' : num( count ) }</span>;
}

/**
 * Right-hand navigation (RTL): six sections; a section's other pages are tabs on the page.
 *
 * @param {Object} props
 * @param {string} props.current Current route path.
 * @param {Object} props.badges  Counts.
 */
export default function Sidebar( { current, badges } ) {
	const mode = useMode();
	const active = sectionOf( current );

	return (
		<nav className="pp-nav" aria-label="ناوبری پایدارپرس">
			<div className="pp-nav__inner">
				<div className="pp-nav__brand">پایدارپرس</div>
				<ul className="pp-nav__list">
					{ SECTIONS.map( ( s ) => {
						const isActive = active && active.id === s.id;
						// The first page with a badge gives the section's count (e.g. tasks this week).
						const main = s.pages.find( ( p ) => p.badge );
						const count = main ? Number( badges[ main.badge ] || 0 ) : 0;
						return (
							<li key={ s.id }>
								<a
									href={ `#${ s.pages[ 0 ].path }` }
									className={ `pp-nav__link${ isActive ? ' is-active' : '' }` }
									aria-current={ isActive ? 'page' : undefined }
									title={ s.hint }
								>
									<Dashicon icon={ s.icon } />
									<span className="pp-nav__text">{ s.label }</span>
									<Badge count={ count } accent={ main && main.accent } />
								</a>
							</li>
						);
					} ) }
				</ul>
				<div className="pp-mode" role="group" aria-label="حالت نمایش">
					<span className="pp-mode__label">حالت نمایش</span>
					<div className="pp-mode__switch">
						{ [ [ 'simple', 'ساده' ], [ 'pro', 'حرفه‌ای' ] ].map( ( [ key, label ] ) => (
							<button
								key={ key }
								type="button"
								className={ `pp-mode__option${ mode === key ? ' is-active' : '' }` }
								aria-pressed={ mode === key }
								onClick={ () => setMode( key ) }
							>
								{ label }
							</button>
						) ) }
					</div>
					<p className="pp-mode__hint">{ 'simple' === mode ? 'راهنماها باز و گزینه‌های پیشرفته پنهان‌اند.' : 'راهنماها جمع شده‌اند و همه گزینه‌ها نمایش داده می‌شوند.' }</p>
				</div>
			</div>
		</nav>
	);
}

/**
 * Tabs of the current section, shown above the page.
 *
 * @param {Object} props
 * @param {string} props.current Current route.
 * @param {Object} props.badges  Counts.
 */
export function SectionTabs( { current, badges } ) {
	const section = sectionOf( current );
	if ( ! section || section.pages.length < 2 ) {
		return null;
	}
	return (
		<nav className="pp-section-tabs" aria-label={ section.label }>
			{ section.pages.map( ( p ) => (
				<a key={ p.path } href={ `#${ p.path }` } className={ `pp-section-tabs__tab${ p.path === current ? ' is-active' : '' }` } aria-current={ p.path === current ? 'page' : undefined }>
					{ p.label }
					{ p.badge && <Badge count={ Number( badges[ p.badge ] || 0 ) } accent={ p.accent } /> }
				</a>
			) ) }
		</nav>
	);
}
