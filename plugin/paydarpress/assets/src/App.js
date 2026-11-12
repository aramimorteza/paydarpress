import { useEffect, useState } from '@wordpress/element';
import Sidebar from './components/Sidebar';
import Dashboard from './pages/Dashboard';
import Welcome from './pages/Welcome';
import ActivityPage from './pages/ActivityPage';
import SettingsPage from './pages/SettingsPage';
import Inventory from './pages/Inventory';
import { WizardPage, ProfilePage, ExportPage } from './pages/ProfilePages';
import { AuditPage, ImagesPage, OrphansPage, RefreshPage, TaxonomiesPage, VisibilityPage } from './pages/AuditPages';
import { ROUTES } from './routes';

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
	} else if ( '/wizard' === path ) {
		page = <WizardPage />;
	} else if ( '/profile' === path ) {
		page = <ProfilePage />;
	} else if ( '/export' === path ) {
		page = <ExportPage />;
	} else if ( '/activity' === path ) {
		page = <ActivityPage />;
	} else if ( '/settings' === path ) {
		page = <SettingsPage />;
	} else {
		page = <Dashboard />;
	}

	return (
		<div className="pp-layout" dir="rtl">
			<Sidebar current={ path } />
			<main className="pp-main">{ page }</main>
		</div>
	);
}
