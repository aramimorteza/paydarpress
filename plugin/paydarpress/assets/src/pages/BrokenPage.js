import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import BrokenFixes from '../components/BrokenFixes';
import { get, post, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

const config = window.paydarpress || {};

/**
 * Broken links, images and files across the site, with one-click fixes.
 */
export default function BrokenPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const only = Number( ( window.location.hash.match( /[?&]post=(\d+)/ ) || [] )[ 1 ] || 0 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = () => get( `/broken${ only ? `?post=${ only }` : '' }` ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const runExternal = () => {
		setBusy( true );
		post( '/broken/external-run' ).then( () => load() ).catch( ( e ) => setError( errorMessage( e ) ) ).finally( () => setBusy( false ) );
	};
	const ext = data && data.external;

	return (
		<Page title="لینک‌ها و تصاویر خراب" helpKey="broken">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					{ data.groups.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title"><span className="dashicons dashicons-randomize" aria-hidden="true" /> یک ریدایرکت، چند اصلاح</h2>
							<p className="pp-muted">این آدرس‌ها از چند صفحه لینک گرفته‌اند. یک ریدایرکت ۳۰۱ همه را یکجا درست می‌کند؛ بعد هم می‌توانید لینک‌ها را یکی‌یکی به آدرس تازه تغییر دهید.</p>
							<ul className="pp-broken-list">
								{ data.groups.map( ( g ) => (
									<li key={ g.url } className="pp-broken">
										<span className="dashicons dashicons-dismiss" aria-hidden="true" />
										<div className="pp-broken__body"><bdi dir="ltr" className="pp-url">{ g.label.replace( /^https?:\/\/[^/]+/, '' ) }</bdi> <span className="pp-muted">— { num( g.pages ) } صفحه</span></div>
										{ canManage && 'replace' === g.fix.action && <Button variant="secondary" size="small" onClick={ () => post( '/broken/fix', { post: 0, url: g.url, action: 'redirect', target: g.fix.target_id } ).then( load ).catch( ( e ) => setError( errorMessage( e ) ) ) }>ریدایرکت به «{ g.fix.title }»</Button> }
									</li>
								) ) }
							</ul>
						</section>
					) }
					{ 0 === data.items.length ? (
						<div className="pp-card"><EmptyState title="لینک یا تصویر خرابی پیدا نشد" text="لینک‌های داخلی، تصاویر و فایل‌های داخل متن در هر اسکن بدون درخواست بیرونی بررسی می‌شوند." /></div>
					) : (
						data.items.map( ( it ) => (
							<section key={ it.id } className="pp-card pp-card--section">
								<div className="pp-broken__head">
									<strong>{ it.title }</strong>
									<a className="pp-icon-link" href={ it.edit_url } title="ویرایش"><span className="dashicons dashicons-edit" aria-hidden="true" /><span className="pp-icon-link__text">ویرایش</span></a>
									<a className="pp-icon-link" href={ it.url } target="_blank" rel="noreferrer" title="مشاهده"><span className="dashicons dashicons-external" aria-hidden="true" /><span className="pp-icon-link__text">مشاهده</span></a>
									<span className="pp-badge pp-badge--warning">{ num( it.problems.length ) } مورد</span>
								</div>
								<BrokenFixes postId={ it.id } problems={ it.problems } />
							</section>
						) )
					) }
					<section className="pp-card pp-card--section">
						<h2 className="pp-card__title"><span className="dashicons dashicons-external" aria-hidden="true" /> لینک‌های خارجی</h2>
						{ ! ext.enabled ? (
							<p className="pp-muted">بررسی لینک‌های خارجی خاموش است. اگر می‌خواهید، از <a href="#/settings?tab=links">تنظیمات ← لینک‌ها</a> روشنش کنید.</p>
						) : (
							<>
								<p className="pp-muted">
									{ ext.last ? `آخرین بررسی ${ ago( ext.last.iso ) }: ${ num( ext.last.checked ) } لینک، ${ num( ext.last.broken || 0 ) } خراب، ${ num( ext.last.unreachable || 0 ) } در دسترس نبود.` : 'هنوز بررسی نشده است.' }
									{ ' ' }خراب: { num( ( ext.counts || {} ).broken || 0 ) } · در انتظار تأیید دوم: { num( ( ext.counts || {} ).fail1 || 0 ) }
								</p>
								{ ext.last && ext.last.offline && <Notice status="warning" isDismissible={ false }>بیشتر لینک‌های خارجی از هاست شما در دسترس نبودند؛ احتمالاً هاست به سایت‌های خارجی دسترسی ندارد. این لینک‌ها خراب حساب نمی‌شوند.</Notice> }
								{ canManage && <Button variant="secondary" size="small" isBusy={ busy } disabled={ busy } onClick={ runExternal }>بررسی الان</Button> }
							</>
						) }
					</section>
				</>
			) }
		</Page>
	);
}
