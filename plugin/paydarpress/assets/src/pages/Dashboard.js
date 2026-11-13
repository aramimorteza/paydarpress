import { useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import Page from '../components/Page';
import { TaskAction } from './TasksPage';
import EmptyState from '../components/EmptyState';
import ScanPanel from '../components/ScanPanel';
import { SeverityBadge } from '../components/FindingCard';
import useScan from '../lib/useScan';
import { get } from '../lib/api';
import { num, ago } from '../lib/format';

/**
 * Health score label and color.
 *
 * @param {number|null} score Score.
 */
function healthLevel( score ) {
	if ( null === score || undefined === score ) {
		return [ 'neutral', '—' ];
	}
	if ( score >= 80 ) {
		return [ 'success', 'خوب' ];
	}
	if ( score >= 50 ) {
		return [ 'warning', 'نیاز به توجه' ];
	}
	return [ 'danger', 'ضعیف' ];
}

/**
 * Dashboard with health, quick wins, orphans and refresh widgets.
 */
export default function Dashboard() {
	const scan = useScan();
	const [ data, setData ] = useState( null );
	const [ onboarding, setOnboarding ] = useState( null );
	const [ gsc, setGsc ] = useState( null );
	const [ pendingLinks, setPendingLinks ] = useState( 0 );
	const [ tasks, setTasks ] = useState( null );
	const scanStatus = scan.scan ? scan.scan.status : null;

	useEffect( () => {
		if ( 'running' !== scanStatus ) {
			get( '/dashboard' ).then( setData ).catch( () => {} );
			get( '/onboarding' ).then( setOnboarding ).catch( () => {} );
			get( '/gsc' ).then( setGsc ).catch( () => {} );
			get( '/tasks' ).then( setTasks ).catch( () => {} );
			get( '/links', { status: 'pending', per_page: 1 } ).then( ( d ) => setPendingLinks( d.counts.pending ) ).catch( () => {} );
		}
	}, [ scanStatus ] );

	if ( scan.loaded && ! scan.scan ) {
		return (
			<Page title="نمای کلی" helpKey="dashboard">
				<div className="pp-card pp-card--highlight">
					<EmptyState
						title="هنوز سایت اسکن نشده است"
						text="برای دیدن وضعیت محتوای سایت، اولین اسکن را اجرا کنید."
						action={ <a className="button button-primary" href="#/welcome">شروع کار</a> }
					/>
				</div>
			</Page>
		);
	}

	const stats = data && data.stats;
	const [ level, levelLabel ] = healthLevel( stats ? stats.health_score : null );

	return (
		<Page title="نمای کلی" helpKey="dashboard">
			{ ! data && <Spinner /> }

			{ tasks && tasks.week.length > 0 && (
				<section className="pp-card pp-next-task">
					<div>
						<div className="pp-muted">کار بعدی شما</div>
						<strong className="pp-next-task__title">{ tasks.week[ 0 ].title }</strong>
						<p className="pp-muted">{ tasks.week[ 0 ].why }</p>
					</div>
					<div className="pp-next-task__actions">
						<TaskAction action={ tasks.week[ 0 ].action } variant="primary" />
						<a href="#/tasks">همه کارهای این هفته ({ num( tasks.week.length ) })</a>
					</div>
				</section>
			) }

			{ onboarding && onboarding.done < onboarding.total && (
				<section className="pp-card pp-card--section pp-onboarding">
					<h2 className="pp-card__title">شروع کار ({ num( onboarding.done ) } از { num( onboarding.total ) })</h2>
					<ol className="pp-checklist">
						{ onboarding.steps.map( ( s ) => (
							<li key={ s.key } className={ s.done ? 'is-done' : '' }>
								<span className="pp-checklist__mark">{ s.done ? '✓' : '' }</span>
								{ s.done ? s.title : <a href={ s.link }>{ s.title }</a> }
							</li>
						) ) }
					</ol>
				</section>
			) }

			<div className="pp-dash">
				<section className="pp-card pp-health">
					<h2 className="pp-card__title">امتیاز سلامت محتوا</h2>
					{ stats ? (
						<>
							<div className={ `pp-health__score pp-health__score--${ level }` }>
								{ null === stats.health_score ? '—' : num( stats.health_score ) }
								<small>از ۱۰۰</small>
							</div>
							<span className={ `pp-badge pp-badge--${ level }` }>{ levelLabel }</span>
							<ul className="pp-health__counts">
								<li><SeverityBadge severity="high" /> { num( stats.by_severity.high ) }</li>
								<li><SeverityBadge severity="medium" /> { num( stats.by_severity.medium ) }</li>
								<li><SeverityBadge severity="low" /> { num( stats.by_severity.low ) }</li>
							</ul>
							<p className="pp-muted">{ num( stats.published ) } محتوای منتشرشده — آخرین ممیزی { ago( stats.audited_at ) }</p>
						</>
					) : (
						<p className="pp-muted">پس از اسکن محاسبه می‌شود.</p>
					) }
				</section>

				<section className="pp-card pp-quick">
					<h2 className="pp-card__title">۵ اقدام سریع پیشنهادی</h2>
					{ data && data.quick_wins.length ? (
						<ol className="pp-quick__list">
							{ data.quick_wins.map( ( q ) => (
								<li key={ q.code }>
									<a href={ `#/audit?code=${ q.code }` } className="pp-quick__title">
										{ q.title } <span className="pp-tab__count">{ num( q.count ) }</span>
									</a>
									<p className="pp-muted">{ q.fix }</p>
								</li>
							) ) }
						</ol>
					) : (
						<p className="pp-muted">{ data ? 'مشکلی پیدا نشد.' : '' }</p>
					) }
				</section>
			</div>

			<div className="pp-grid">
				<section className="pp-card">
					<h2 className="pp-card__title">تکمیل پروفایل</h2>
					<div className="pp-big">{ onboarding ? `${ num( onboarding.completion.percent ) }٪` : '—' }</div>
					<div className="pp-bar"><span style={ { width: `${ onboarding ? Math.max( 3, onboarding.completion.percent ) : 0 }%` } } /></div>
					<p className="pp-muted">پروفایل کامل یعنی محتوای دقیق‌تر در ContentMaster.</p>
					<a href="#/profile">مشاهده پروفایل ←</a>
				</section>
				<section className="pp-card">
					<h2 className="pp-card__title">محتوای یتیم</h2>
					<div className="pp-big">{ stats ? num( stats.orphans ) : '—' }</div>
					<p className="pp-muted">نوشته یا برگه‌ای که هیچ صفحه‌ای به آن لینک نداده است.</p>
					{ pendingLinks > 0 && <p><a href="#/links">{ num( pendingLinks ) } پیشنهاد لینک در انتظار تأیید ←</a></p> }
					<a href="#/orphans">مشاهده و پیشنهاد لینک ←</a>
				</section>
				<section className="pp-card">
					<h2 className="pp-card__title">پیشنهاد بازنویسی</h2>
					<div className="pp-big">{ stats ? num( stats.refresh ) : '—' }</div>
					{ data && data.refresh.length > 0 && (
						<ul className="pp-mini-list">
							{ data.refresh.slice( 0, 3 ).map( ( r ) => (
								<li key={ r.id }>
									<a href={ r.edit_url }>{ r.title }</a> <span className="pp-muted">({ r.action_label })</span>
								</li>
							) ) }
						</ul>
					) }
					<a href="#/refresh">مشاهده همه ←</a>
				</section>
				<section className="pp-card">
					<h2 className="pp-card__title">سرچ کنسول</h2>
					{ gsc && gsc.import ? (
						<>
							<div className="pp-big">{ num( gsc.import.summary.clicks ) } <span className="pp-big__unit">کلیک</span></div>
							<p className="pp-muted">
								{ num( gsc.counts.near ) } صفحه نزدیک صفحه اول
								{ gsc.counts.drop ? `، ${ num( gsc.counts.drop ) } افت کلیک` : '' }
								{ ' — داده ' }{ ago( gsc.import.imported_at ) }
							</p>
							{ gsc.stale && <p className="pp-missing">داده قدیمی است؛ خروجی تازه آپلود کنید.</p> }
							<a href="#/gsc">فرصت‌ها ←</a>
						</>
					) : (
						<>
							<p className="pp-muted">با آپلود خروجی گزارش «عملکرد» سرچ کنسول، صفحات نزدیک صفحه اول گوگل، افت کلیک و عبارت‌های جست‌وجو را ببینید.</p>
							<a href="#/gsc">ورود داده سرچ کنسول ←</a>
						</>
					) }
				</section>
				<section className="pp-card">
					<h2 className="pp-card__title">دسترسی خزنده‌ها</h2>
					{ data && data.crawlers ? (
						<>
							<div className="pp-big">
								{ Object.values( data.crawlers.bots ).filter( ( s ) => 'blocked' === s ).length ? (
									<span className="pp-missing">{ num( Object.values( data.crawlers.bots ).filter( ( s ) => 'blocked' === s ).length ) } مسدود</span>
								) : (
									<span className="pp-ok">آزاد</span>
								) }
							</div>
							<p className="pp-muted">{ data.crawlers.site_public ? 'سایت قابل ایندکس است.' : 'سایت از ایندکس خارج است!' }</p>
						</>
					) : (
						<p className="pp-muted">پس از اسکن بررسی می‌شود.</p>
					) }
					<a href="#/visibility">جزئیات ←</a>
				</section>
				<section className="pp-card">
					<h2 className="pp-card__title">آخرین اسکن</h2>
					<ScanPanel state={ scan } doneLink="#/audit" doneLabel="مشاهده ممیزی" />
				</section>
			</div>
		</Page>
	);
}
