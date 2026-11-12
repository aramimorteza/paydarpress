import { useEffect, useState } from '@wordpress/element';
import Sidebar from './components/Sidebar';
import Dashboard from './pages/Dashboard';
import Welcome from './pages/Welcome';
import Placeholder from './pages/Placeholder';
import Inventory from './pages/Inventory';
import { AuditPage, ImagesPage, OrphansPage, RefreshPage, TaxonomiesPage, VisibilityPage } from './pages/AuditPages';
import { ROUTES } from './routes';

const HELP_KEYS = {
	'/profile': 'profile',
	'/inventory': 'inventory',
	'/taxonomies': 'taxonomies',
	'/audit': 'audit',
	'/orphans': 'orphans',
	'/images': 'images',
	'/refresh': 'refresh',
	'/visibility': 'visibility',
	'/export': 'export',
	'/activity': 'activity',
	'/settings': 'settings',
	'/wizard': 'profile',
};

/**
 * Reads the current route from the URL hash (e.g. #/audit).
 */
function currentPath() {
	const hash = window.location.hash.replace( /^#/, '' );
	const path = hash.split( '?' )[ 0 ] || '/';
	return ROUTES.some( ( route ) => route.path === path ) ? path : '/';
}

/**
 * Root component with hash-based routing.
 */
export default function App() {
	const [ path, setPath ] = useState( currentPath() );

	useEffect( () => {
		const onChange = () => {
			setPath( currentPath() );
			window.scrollTo( 0, 0 );
		};
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	let page;
	if ( '/' === path ) {
		page = <Dashboard />;
	} else if ( '/welcome' === path ) {
		page = <Welcome />;
	} else if ( '/inventory' === path ) {
		page = <Inventory />;
	} else if ( '/audit' === path ) {
		page = <AuditPage key={ window.location.hash } />;
	} else if ( '/orphans' === path ) {
		page = <OrphansPage />;
	} else if ( '/images' === path ) {
		page = <ImagesPage />;
	} else if ( '/refresh' === path ) {
		page = <RefreshPage />;
	} else if ( '/taxonomies' === path ) {
		page = <TaxonomiesPage />;
	} else if ( '/visibility' === path ) {
		page = <VisibilityPage />;
	} else {
		const route = ROUTES.find( ( item ) => item.path === path );
		page = <Placeholder key={ path } title={ route ? route.label : '' } helpKey={ HELP_KEYS[ path ] } />;
	}

	return (
		<div className="pp-layout" dir="rtl">
			<Sidebar current={ path } />
			<main className="pp-main">{ page }</main>
		</div>
	);
}
