import { Dashicon } from '@wordpress/components';
import { NAV } from '../routes';

/**
 * Right-hand navigation sidebar (RTL).
 *
 * @param {Object} props
 * @param {string} props.current Current route path.
 */
export default function Sidebar( { current } ) {
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
		</nav>
	);
}
