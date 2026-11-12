import { Dashicon } from '@wordpress/components';
import { setMode, useMode } from '../lib/uiMode';
import { NAV } from '../routes';

/**
 * Right-hand navigation sidebar (RTL).
 *
 * @param {Object} props
 * @param {string} props.current Current route path.
 */
export default function Sidebar( { current } ) {
	const mode = useMode();
	const link = ( item ) => (
		<li key={ item.path }>
			<a
				href={ `#${ item.path }` }
				className={ `pp-nav__link${ current === item.path ? ' is-active' : '' }` }
				aria-current={ current === item.path ? 'page' : undefined }
			>
				<Dashicon icon={ item.icon } />
				<span>{ item.label }</span>
			</a>
		</li>
	);

	return (
		<nav className="pp-nav" aria-label="ناوبری پایدارپرس">
			<div className="pp-nav__brand">پایدارپرس</div>
			<ul className="pp-nav__list">
				{ NAV.map( ( entry ) =>
					entry.items ? (
						<li key={ entry.group } className="pp-nav__group">
							<span className="pp-nav__group-label">{ entry.group }</span>
							<ul>{ entry.items.map( link ) }</ul>
						</li>
					) : (
						link( entry )
					)
				) }
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
		</nav>
	);
}
