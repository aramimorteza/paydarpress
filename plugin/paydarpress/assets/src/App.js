import { useEffect, useState } from '@wordpress/element';
import Sidebar, { SectionTabs, useBadges } from './components/Sidebar';
import Dashboard from './pages/Dashboard';
import Welcome from './pages/Welcome';
import ActivityPage from './pages/ActivityPage';
import SettingsPage from './pages/SettingsPage';
import GscPage from './pages/GscPage';
import { ImportPage, TopicsPage } from './pages/ContentMasterPages';
import LinksPage from './pages/LinksPage';
import AutoPage from './pages/AutoPage';
import TasksPage from './pages/TasksPage';
import { ChangesPage, RedirectsPage } from './pages/ChangesPages';
import NotFoundPage from './pages/NotFoundPage';
import DecisionsPage from './pages/DecisionsPage';
import { SupportPage, LicensePage } from './pages/SupportPages';
import ClustersPage from './pages/ClustersPage';
import Inventory from './pages/Inventory';
import { WizardPage, ProfilePage, ExportPage } from './pages/ProfilePages';
import { AuditPage, ImagesPage, OrphansPage, RefreshPage, TaxonomiesPage, VisibilityPage } from './pages/AuditPages';
import { ROUTES } from './routes';

/**
 * Reads the current route from the URL hash (e.g. #/audit).
 */
function currentPath() {
	const hash = window.location.hash.replace( /^#/, '' );
	// Without a route, the plugin opens on "today" (this week's tasks).
	const path = hash.split( '?' )[ 0 ] || '/tasks';
	return ROUTES.some( ( route ) => route.path === path ) ? path : '/';
}

/**
 * Root component with hash-based routing.
 */
export default function App() {
	const [ path, setPath ] = useState( currentPath() );
	const badges = useBadges( path );

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
	} else if ( '/links' === path ) {
		page = <LinksPage key={ window.location.hash.split( '?' )[ 0 ] } />;
	} else if ( '/topics' === path ) {
		page = <TopicsPage />;
	} else if ( '/changes' === path ) {
		page = <ChangesPage />;
	} else if ( '/redirects' === path ) {
		page = <RedirectsPage />;
	} else if ( '/clusters' === path ) {
		page = <ClustersPage />;
	} else if ( '/support' === path ) {
		page = <SupportPage />;
	} else if ( '/license' === path ) {
		page = <LicensePage />;
	} else if ( '/decisions' === path ) {
		page = <DecisionsPage />;
	} else if ( '/notfound' === path ) {
		page = <NotFoundPage />;
	} else if ( '/tasks' === path ) {
		page = <TasksPage />;
	} else if ( '/auto' === path ) {
		page = <AutoPage />;
	} else if ( '/cm-import' === path ) {
		page = <ImportPage />;
	} else if ( '/gsc' === path ) {
		page = <GscPage />;
	} else if ( '/settings' === path ) {
		page = <SettingsPage />;
	} else {
		page = <Dashboard />;
	}

	return (
		<div className="pp-layout" dir="rtl">
			<Sidebar current={ path } badges={ badges } />
			<main className="pp-main">
				<SectionTabs current={ path } badges={ badges } />
				{ page }
			</main>
		</div>
	);
}
