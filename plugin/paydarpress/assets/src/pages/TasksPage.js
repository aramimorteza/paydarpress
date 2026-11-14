import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import AiButton from '../components/AiButton';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { refreshCommand, metaCommand, supportCommand, topicArticleCommand, topicsCommand, termCommand, termTextCommand, altCommand } from '../lib/aiCommands';

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
				<div>
					<strong>گوگل (۳ ماه): </strong>
					رتبه میانگین { num( g.position ) } · { num( g.impressions ) } نمایش · { num( g.clicks ) } کلیک · نرخ کلیک { num( g.ctr ) }٪
					{ null !== g.delta && 0 !== g.delta && <span className={ g.delta > 0 ? 'pp-ok' : 'pp-missing' }> { g.delta > 0 ? '↑' : '↓' } { num( Math.abs( g.delta ) ) } نسبت به بارگذاری قبلی</span> }
				</div>
			) }
			{ details.queries && details.queries.length > 0 && (
				<div>
					<strong>عبارت‌های احتمالی: </strong>
					{ details.queries.map( ( q, i ) => (
						<span key={ q.query } className="pp-task__query">
							{ i > 0 && ' · ' }«{ q.query }» رتبه { num( q.position ) }، { num( q.impressions ) } نمایش
							{ q.opportunity && <span className="pp-badge pp-badge--warning">فرصت</span> }
						</span>
					) ) }
					<span className="pp-muted" title="خروجی سرچ کنسول صفحه و عبارت را جدا می‌دهد؛ این عبارت‌ها بر اساس کلمه کلیدی و عنوان صفحه تخمین زده شده‌اند."> (تخمینی)</span>
				</div>
			) }
			{ details.facts && <div className="pp-muted">{ details.facts.join( ' · ' ) }</div> }
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
			<a className="pp-task__view" href={ details.view.url } target="_blank" rel="noreferrer" title={ details.view.title }>{ details.view.label } ↗</a>
			{ details.gsc_link && <a className="pp-task__view" href={ details.gsc_link } target="_blank" rel="noreferrer" title="گزارش Performance سرچ کنسول با فیلتر همین صفحه">عبارت‌های دقیق در سرچ کنسول ↗</a> }
		</>
	);
}

/**
 * One task card.
 */
function TaskCard( { task, index, onMark, canManage } ) {
	const [ label, cls ] = TYPE_LABELS[ task.type ] || [ task.type, 'info' ];
	const ai = aiFor( task.ai );
	return (
		<li className={ `pp-task${ 0 === index ? ' is-next' : '' }` }>
			<div className="pp-task__num">{ num( index + 1 ) }</div>
			<div className="pp-task__body">
				<div className="pp-task__head">
					<strong>{ task.title }</strong>
					<span className={ `pp-badge pp-badge--${ cls }` }>{ label }</span>
					<span className="pp-muted">حدود { num( task.minutes ) } دقیقه</span>
				</div>
				<p className="pp-task__why">{ task.why }</p>
				<TaskDetails details={ task.details } />
				<div className="pp-task__actions">
					<TaskAction action={ task.action } variant={ 0 === index ? 'primary' : 'secondary' } />
					{ ai && <AiButton command={ ai } /> }
					<TaskLinks details={ task.details } />
					{ canManage && (
						<>
							<Button variant="tertiary" size="small" onClick={ () => onMark( task.key, 'done' ) }>انجام شد</Button>
							<Button variant="tertiary" size="small" onClick={ () => onMark( task.key, 'later' ) }>هفته بعد</Button>
						</>
					) }
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
						{ data.stage && <a className="pp-muted" href="#/settings?tab=policy">مرحله سایت: { data.stage.label }</a> }
					</div>
					{ data.stage && 'new' === data.stage.stage && <Notice status="info" isDismissible={ false }>{ data.stage.advice }</Notice> }
					{ 0 === data.week.length ? (
						<div className="pp-card"><EmptyState title="کاری برای این هفته نمانده است" text="همه موارد مهم انجام شده یا به هفته بعد رفته‌اند. پایدارپرس پس از اسکن و ورود داده تازه، کارهای جدید را اینجا می‌آورد." /></div>
					) : (
						<ol className="pp-tasks">
							{ data.week.map( ( t, i ) => <TaskCard key={ t.key } task={ t } index={ i } onMark={ mark } canManage={ canManage } /> ) }
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
