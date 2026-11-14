import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import AiButton from '../components/AiButton';
import BrokenFixes from '../components/BrokenFixes';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { refreshCommand, metaCommand, supportCommand, topicArticleCommand, topicsCommand, termCommand, termTextCommand, altCommand, slugsCommand } from '../lib/aiCommands';

const config = window.paydarpress || {};

export const TYPE_LABELS = {
	setup: [ 'راه‌اندازی', 'info' ],
	fix: [ 'رفع مشکل', 'danger' ],
	publish: [ 'انتشار', 'success' ],
	links: [ 'لینک داخلی', 'info' ],
	refresh: [ 'بازنویسی', 'warning' ],
	meta: [ 'متا', 'warning' ],
	support: [ 'محتوای یتیم', 'warning' ],
	write: [ 'مقاله جدید', 'info' ],
};

/** One icon per kind of work, used on cards everywhere (WordPress dashicons). */
export const TYPE_ICONS = {
	setup: 'admin-generic',
	fix: 'warning',
	publish: 'upload',
	links: 'admin-links',
	refresh: 'update',
	meta: 'search',
	support: 'admin-links',
	write: 'edit-page',
};

/**
 * Small icon link with a tooltip.
 *
 * @param {Object} props
 * @param {string} props.icon  Dashicon name.
 * @param {string} props.label Tooltip / screen reader text.
 * @param {string} props.href  Link.
 * @param {boolean} props.blank New tab.
 */
export function IconLink( { icon, label, text = '', href, blank = false } ) {
	return (
		<a className="pp-icon-link" href={ href } title={ label } aria-label={ label } { ...( blank ? { target: '_blank', rel: 'noreferrer' } : {} ) }>
			<span className={ `dashicons dashicons-${ icon }` } aria-hidden="true" />
			{ text && <span className="pp-icon-link__text">{ text }</span> }
		</a>
	);
}

/**
 * AI action of a task, built with the same command builders as the rest of the app.
 *
 * @param {Object|null} ai Descriptor from the API.
 */
export function aiFor( ai ) {
	if ( ! ai ) {
		return null;
	}
	switch ( ai.kind ) {
		case 'refresh':
			return refreshCommand( ai.title, ai.url, ai.reasons || [], ai.id, ai.type );
		case 'meta':
			return metaCommand( ai.title, ai.url, ai.id );
		case 'support':
			return supportCommand( ai.title, ai.url, ai.id );
		case 'topic':
			return topicArticleCommand( { id: ai.id, title: ai.title } );
		case 'topics':
			return topicsCommand();
		case 'category':
			return termCommand( ai.title, 'category', ai.id );
		case 'termtext':
			return termTextCommand( ai.title, ai.id );
		case 'alt':
			return altCommand( ai.title || '', ai.id || 0 );
		case 'slugs':
			return slugsCommand();
	}
	return null;
}

/**
 * Link or button for a task's main action.
 *
 * @param {Object} props
 * @param {Object} props.action  { route | url, label }.
 * @param {string} props.variant Button variant.
 */
export function TaskAction( { action, variant = 'secondary' } ) {
	const href = action.route ? `#${ action.route }` : action.url;
	return <Button variant={ variant } size="small" href={ href }>{ action.label }</Button>;
}

/**
 * Search Console line, probable queries and content facts of a card.
 *
 * @param {Object} props
 * @param {Object} props.details Details from the server.
 */
export function TaskDetails( { details } ) {
	if ( ! details || ( ! details.gsc && ! details.queries && ! details.facts ) ) {
		return null;
	}
	const g = details.gsc;
	return (
		<div className="pp-task__details">
			{ g && (
				<span className="pp-stat" title="آمار ۳ ماه سرچ کنسول">
					<span className="dashicons dashicons-chart-line" aria-hidden="true" />
					رتبه <b>{ num( g.position ) }</b>
					{ null !== g.delta && 0 !== g.delta && <span className={ g.delta > 0 ? 'pp-ok' : 'pp-missing' } title="نسبت به بارگذاری قبلی"> { g.delta > 0 ? '▲' : '▼' }{ num( Math.abs( g.delta ) ) }</span> }
					{ ' · ' }{ num( g.impressions ) } نمایش · { num( g.clicks ) } کلیک · { num( g.ctr ) }٪
				</span>
			) }
			{ details.facts && <span className="pp-stat pp-muted"><span className="dashicons dashicons-media-text" aria-hidden="true" />{ details.facts.join( ' · ' ) }</span> }
			{ details.queries && details.queries.length > 0 && (
				<span className="pp-stat" title="خروجی سرچ کنسول صفحه و عبارت را جدا می‌دهد؛ این عبارت‌ها بر اساس کلمه کلیدی و عنوان صفحه تخمین زده شده‌اند.">
					<span className="dashicons dashicons-search" aria-hidden="true" />
					{ details.queries.map( ( q ) => (
						<span key={ q.query } className={ `pp-qchip${ q.opportunity ? ' is-opp' : '' }` }>{ q.query } <b>{ num( q.position ) }</b>{ q.opportunity && ' فرصت' }</span>
					) ) }
				</span>
			) }
		</div>
	);
}

/**
 * Typed "view" and Search Console links of a card.
 *
 * @param {Object} props
 * @param {Object} props.details Details.
 */
export function TaskLinks( { details } ) {
	if ( ! details || ! details.view ) {
		return null;
	}
	return (
		<>
			<IconLink icon="external" label={ `${ details.view.label } — ${ details.view.title }` } text={ details.view.label } href={ details.view.url } blank />
			{ details.gsc_link && <IconLink icon="chart-bar" label="عبارت‌های دقیق این صفحه در سرچ کنسول" text="سرچ کنسول" href={ details.gsc_link } blank /> }
		</>
	);
}

/**
 * One task card.
 */
function TaskCard( { task, index, onMark, canManage, onReload } ) {
	const [ label, cls ] = TYPE_LABELS[ task.type ] || [ task.type, 'info' ];
	const ai = aiFor( task.ai );
	const details = task.details || {};
	const action = task.action || {};
	const isEdit = 'ویرایش' === action.label;
	return (
		<li className={ `pp-task${ 0 === index ? ' is-next' : '' }` }>
			<div className="pp-task__num" title={ label }><span className={ `dashicons dashicons-${ TYPE_ICONS[ task.type ] || 'yes' }` } aria-hidden="true" /></div>
			<div className="pp-task__body">
				<div className="pp-task__head">
					<strong>{ num( index + 1 ) }. { task.title }</strong>
					<span className={ `pp-badge pp-badge--${ cls }` }>{ label }</span>
					<span className="pp-muted pp-task__time"><span className="dashicons dashicons-clock" aria-hidden="true" />{ num( task.minutes ) } دقیقه</span>
					{ canManage && (
						<span className="pp-task__marks">
							<button type="button" className="pp-icon-link" title="انجام شد" aria-label="انجام شد" onClick={ () => onMark( task.key, 'done' ) }><span className="dashicons dashicons-yes" aria-hidden="true" /><span className="pp-icon-link__text">انجام شد</span></button>
							<button type="button" className="pp-icon-link" title="هفته بعد" aria-label="هفته بعد" onClick={ () => onMark( task.key, 'later' ) }><span className="dashicons dashicons-calendar-alt" aria-hidden="true" /><span className="pp-icon-link__text">هفته بعد</span></button>
						</span>
					) }
				</div>
				<p className="pp-task__why">{ task.why }</p>
				<TaskDetails details={ details } />
				{ details.broken && <BrokenFixes postId={ Number( String( task.key ).split( ':' )[ 1 ] ) } problems={ details.broken } onDone={ onReload } /> }
				<div className="pp-task__actions">
					{ ai ? <AiButton command={ ai } variant={ 0 === index ? 'primary' : 'secondary' } /> : ! isEdit && <TaskAction action={ action } variant={ 0 === index ? 'primary' : 'secondary' } /> }
					{ ai && ! isEdit && <TaskAction action={ action } variant="tertiary" /> }
					{ isEdit && <IconLink icon="edit" label="ویرایش در وردپرس" text="ویرایش" href={ action.url } /> }
					<TaskLinks details={ details } />
				</div>
			</div>
		</li>
	);
}

export default function TasksPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ showLater, setShowLater ] = useState( false );
	const [ setup, setSetup ] = useState( null );

	useEffect( () => {
		const load = () => get( '/tasks' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
		load();
		get( '/onboarding' ).then( setSetup ).catch( () => {} );
		window.addEventListener( 'paydarpress:imported', load );
		return () => window.removeEventListener( 'paydarpress:imported', load );
	}, [] );

	const mark = ( key, action ) => post( '/tasks/mark', { key, action } ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );

	return (
		<Page title="کارهای این هفته" helpKey="tasks">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ setup && ! setup.dismissed && setup.done < setup.total && (
				<Notice status="info" isDismissible={ false } className="pp-setup-banner">
					راه‌اندازی: { num( setup.done ) } از { num( setup.total ) } قدم انجام شده. قدم بعد: «{ ( setup.steps.find( ( s ) => ! s.done ) || {} ).title }».{ ' ' }
					<a href="#/welcome">ادامه راه‌اندازی</a>
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<div className="pp-task-summary">
						<span>{ num( data.week.length ) } کار برای این هفته</span>
						{ data.done_week > 0 && <span className="pp-ok">✓ { num( data.done_week ) } کار در ۷ روز اخیر انجام شد</span> }
						<span className="pp-muted">مقاله جدید این ماه: { num( data.policy.published ) } از { num( data.policy.budget ) }</span>
						{ data.budget && <a className="pp-muted" href="#/settings?tab=improve" title="زمان کار هفتگی در تنظیمات ← برنامه بهبود">زمان این هفته: { num( data.minutes ) } از { num( data.budget ) } دقیقه</a> }
						{ data.stage && <a className="pp-muted" href="#/settings?tab=policy">مرحله سایت: { data.stage.label }</a> }
					</div>
					{ data.stage && 'new' === data.stage.stage && <Notice status="info" isDismissible={ false }>{ data.stage.advice }</Notice> }
					{ 0 === data.week.length ? (
						<div className="pp-card"><EmptyState title="کاری برای این هفته نمانده است" text="همه موارد مهم انجام شده یا به هفته بعد رفته‌اند. پایدارپرس پس از اسکن و ورود داده تازه، کارهای جدید را اینجا می‌آورد." /></div>
					) : (
						<ol className="pp-tasks">
							{ data.week.map( ( t, i ) => <TaskCard key={ t.key } task={ t } index={ i } onMark={ mark } canManage={ canManage } onReload={ () => get( '/tasks' ).then( setData ) } /> ) }
						</ol>
					) }
					{ data.later.length > 0 && (
						<section className="pp-card pp-card--section">
							<Button variant="link" onClick={ () => setShowLater( ! showLater ) }>
								{ showLater ? 'بستن' : `${ num( data.later.length ) } کار دیگر برای هفته‌های بعد` }
							</Button>
							{ showLater && (
								<ul className="pp-mini-list">
									{ data.later.map( ( t ) => <li key={ t.key }>{ t.title } <span className="pp-muted">({ ( TYPE_LABELS[ t.type ] || [ t.type ] )[ 0 ] })</span></li> ) }
								</ul>
							) }
							<p className="pp-muted">فهرست عمداً کوتاه است: سیاست محتوا تعیین می‌کند هر هفته چند مقاله، بازنویسی و لینک تازه معقول است.</p>
						</section>
					) }
				</>
			) }
		</Page>
	);
}
