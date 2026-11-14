import { lazy, Suspense, useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import Sidebar, { SectionTabs, useBadges } from './components/Sidebar';
import { ROUTES } from './routes';

/*
 * Pages load on demand: the first screen downloads only the shell and its own page,
 * every other page arrives as a separate chunk when it is opened.
 */
const Dashboard = lazy( () => import( /* webpackChunkName: "dashboard" */ './pages/Dashboard' ) );
const Welcome = lazy( () => import( /* webpackChunkName: "welcome" */ './pages/Welcome' ) );
const ActivityPage = lazy( () => import( /* webpackChunkName: "activity-page" */ './pages/ActivityPage' ) );
const SettingsPage = lazy( () => import( /* webpackChunkName: "settings-page" */ './pages/SettingsPage' ) );
const GscPage = lazy( () => import( /* webpackChunkName: "gsc-page" */ './pages/GscPage' ) );
const ImportPage = lazy( () => import( /* webpackChunkName: "content-master-pages" */ './pages/ContentMasterPages' ).then( ( m ) => ( { default: m.ImportPage } ) ) );
const TopicsPage = lazy( () => import( /* webpackChunkName: "content-master-pages" */ './pages/ContentMasterPages' ).then( ( m ) => ( { default: m.TopicsPage } ) ) );
const LinksPage = lazy( () => import( /* webpackChunkName: "links-page" */ './pages/LinksPage' ) );
const AutoPage = lazy( () => import( /* webpackChunkName: "auto-page" */ './pages/AutoPage' ) );
const TasksPage = lazy( () => import( /* webpackChunkName: "tasks-page" */ './pages/TasksPage' ) );
const ChangesPage = lazy( () => import( /* webpackChunkName: "changes-pages" */ './pages/ChangesPages' ).then( ( m ) => ( { default: m.ChangesPage } ) ) );
const RedirectsPage = lazy( () => import( /* webpackChunkName: "changes-pages" */ './pages/ChangesPages' ).then( ( m ) => ( { default: m.RedirectsPage } ) ) );
const NotFoundPage = lazy( () => import( /* webpackChunkName: "not-found-page" */ './pages/NotFoundPage' ) );
const DecisionsPage = lazy( () => import( /* webpackChunkName: "decisions-page" */ './pages/DecisionsPage' ) );
const BrokenPage = lazy( () => import( /* webpackChunkName: "broken-page" */ './pages/BrokenPage' ) );
const ProgressPage = lazy( () => import( /* webpackChunkName: "progress-page" */ './pages/ProgressPage' ) );
const SupportPage = lazy( () => import( /* webpackChunkName: "support-pages" */ './pages/SupportPages' ).then( ( m ) => ( { default: m.SupportPage } ) ) );
const LicensePage = lazy( () => import( /* webpackChunkName: "support-pages" */ './pages/SupportPages' ).then( ( m ) => ( { default: m.LicensePage } ) ) );
const ClustersPage = lazy( () => import( /* webpackChunkName: "clusters-page" */ './pages/ClustersPage' ) );
const Inventory = lazy( () => import( /* webpackChunkName: "inventory" */ './pages/Inventory' ) );
const WizardPage = lazy( () => import( /* webpackChunkName: "profile-pages" */ './pages/ProfilePages' ).then( ( m ) => ( { default: m.WizardPage } ) ) );
const ProfilePage = lazy( () => import( /* webpackChunkName: "profile-pages" */ './pages/ProfilePages' ).then( ( m ) => ( { default: m.ProfilePage } ) ) );
const ExportPage = lazy( () => import( /* webpackChunkName: "profile-pages" */ './pages/ProfilePages' ).then( ( m ) => ( { default: m.ExportPage } ) ) );
const AuditPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.AuditPage } ) ) );
const ImagesPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.ImagesPage } ) ) );
const OrphansPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.OrphansPage } ) ) );
const RefreshPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.RefreshPage } ) ) );
const TaxonomiesPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.TaxonomiesPage } ) ) );
const VisibilityPage = lazy( () => import( /* webpackChunkName: "audit-pages" */ './pages/AuditPages' ).then( ( m ) => ( { default: m.VisibilityPage } ) ) );

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
	} else if ( '/progress' === path ) {
		page = <ProgressPage />;
	} else if ( '/broken' === path ) {
		page = <BrokenPage />;
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
				<Suspense fallback={ <div className="pp-page pp-page--loading"><Spinner /></div> }>{ page }</Suspense>
			</main>
		</div>
	);
}
