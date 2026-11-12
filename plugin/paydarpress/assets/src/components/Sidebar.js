import { useEffect, useState } from '@wordpress/element';
import { Dashicon } from '@wordpress/components';
import { setMode, useMode } from '../lib/uiMode';
import { get } from '../lib/api';
import { num } from '../lib/format';
import { NAV, NAV_FOOTER } from '../routes';

const STORE = 'paydarpress.nav.closed';

/**
 * Groups the user collapsed (remembered in this browser only).
 */
function readClosed() {
	try {
		const v = JSON.parse( window.localStorage.getItem( STORE ) || '[]' );
		return Array.isArray( v ) ? v : [];
	} catch ( e ) {
		return [];
	}
}

function writeClosed( list ) {
	try {
		window.localStorage.setItem( STORE, JSON.stringify( list ) );
	} catch ( e ) {}
}

/**
 * Right-hand navigation sidebar (RTL): workflow groups that open and close, with counts of
 * things waiting for the user.
 *
 * @param {Object} props
 * @param {string} props.current Current route path.
 */
export default function Sidebar( { current } ) {
	const mode = useMode();
	const [ closed, setClosed ] = useState( readClosed );
	const [ badges, setBadges ] = useState( ( window.paydarpress && window.paydarpress.badges ) || {} );

	// Counts change after scans, imports and approvals; refresh them when the page changes.
	useEffect( () => {
		get( '/badges' ).then( setBadges ).catch( () => {} );
	}, [ current ] );

	const toggle = ( id ) => {
		const next = closed.includes( id ) ? closed.filter( ( g ) => g !== id ) : [ ...closed, id ];
		setClosed( next );
		writeClosed( next );
	};

	const link = ( item ) => {
		const count = item.badge ? Number( badges[ item.badge ] || 0 ) : 0;
		return (
			<li key={ item.path }>
				<a
					href={ `#${ item.path }` }
					className={ `pp-nav__link${ current === item.path ? ' is-active' : '' }` }
					aria-current={ current === item.path ? 'page' : undefined }
				>
					<Dashicon icon={ item.icon } />
					<span className="pp-nav__text">{ item.label }</span>
					{ count > 0 && (
						<span className={ `pp-nav__badge${ item.accent ? ' is-accent' : '' }` } title={ `${ num( count ) } ${ item.badgeTitle || '' }` }>
							{ count > 999 ? '+۹۹۹' : num( count ) }
						</span>
					) }
				</a>
			</li>
		);
	};

	return (
		<nav className="pp-nav" aria-label="ناوبری پایدارپرس">
			<div className="pp-nav__inner">
				<div className="pp-nav__brand">پایدارپرس</div>
				<ul className="pp-nav__list">
					{ NAV.map( ( entry, index ) => {
						if ( ! entry.items ) {
							return link( entry );
						}
						const hasCurrent = entry.items.some( ( i ) => i.path === current );
						const open = hasCurrent || ! closed.includes( entry.id );
						const total = entry.items.reduce( ( sum, i ) => sum + ( i.badge ? Number( badges[ i.badge ] || 0 ) : 0 ), 0 );
						return (
							<li key={ entry.id } className={ `pp-nav__group${ open ? ' is-open' : '' }` }>
								<button
									type="button"
									className="pp-nav__group-toggle"
									aria-expanded={ open }
									aria-controls={ `pp-nav-${ entry.id }` }
									title={ entry.hint }
									onClick={ () => ! hasCurrent && toggle( entry.id ) }
								>
									<span className="pp-nav__step">{ num( NAV.filter( ( e, i ) => e.items && i <= index ).length ) }</span>
									<span className="pp-nav__group-label">{ entry.group }</span>
									{ ! open && total > 0 && <span className="pp-nav__dot" aria-label={ `${ num( total ) } مورد در انتظار` } /> }
									<Dashicon icon={ open ? 'arrow-up-alt2' : 'arrow-down-alt2' } className="pp-nav__chevron" />
								</button>
								{ open && (
									<ul id={ `pp-nav-${ entry.id }` }>
										{ entry.items.map( ( item, i ) => [
											item.section && item.section !== ( entry.items[ i - 1 ] || {} ).section && (
												<li key={ `s-${ item.section }` } className="pp-nav__section" aria-hidden="true">{ item.section }</li>
											),
											link( item ),
										] ) }
									</ul>
								) }
							</li>
						);
					} ) }
				</ul>
				<ul className="pp-nav__list pp-nav__footer">{ NAV_FOOTER.map( link ) }</ul>
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
