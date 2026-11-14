import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import { get, post, errorMessage } from '../lib/api';
import { ago, date } from '../lib/format';
import faq from '../../../help/fa_IR/faq.json';

const config = window.paydarpress || {};
const canManage = () => !! ( config.caps && config.caps.manage );

/**
 * Copies text with a textarea fallback.
 *
 * @param {string} text Text.
 */
function copy( text ) {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		return window.navigator.clipboard.writeText( text );
	}
	const t = document.createElement( 'textarea' );
	t.value = text;
	t.style.position = 'fixed';
	t.style.opacity = '0';
	document.body.appendChild( t );
	t.select();
	document.execCommand( 'copy' );
	document.body.removeChild( t );
	return Promise.resolve();
}

/**
 * Links of one group; unpublished ones show "به‌زودی".
 *
 * @param {Object} props
 * @param {Array}  props.links Links.
 */
function LinkList( { links } ) {
	return (
		<ul className="pp-support__links">
			{ links.map( ( [ key, l ] ) => (
				<li key={ key }>
					{ l.url ? (
						<a href={ l.url } target="_blank" rel="noreferrer">{ l.label }</a>
					) : (
						<span className="pp-muted">{ l.label } <span className="pp-badge">به‌زودی</span></span>
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Technical report for tickets.
 */
function Report() {
	const [ site, setSite ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setCopied( false );
		get( `/support/report?site=${ site ? 1 : 0 }` ).then( ( r ) => setText( r.text ) ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ site ] );

	return (
		<section className="pp-card pp-card--section">
			<h2 className="pp-card__title">گزارش فنی برای پشتیبانی</h2>
			<p className="pp-muted">فقط نسخه‌ها، تنظیمات فنی و شمارش‌ها؛ بدون ایمیل، نام کاربری، محتوا، آدرس صفحات یا کلید. پیش از ارسال آن را ببینید.</p>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<CheckboxControl __nextHasNoMarginBottom label="نام دامنه سایت هم اضافه شود (برای بررسی سریع‌تر)" checked={ site } onChange={ setSite } />
			<textarea className="pp-support__report" aria-label="متن گزارش پشتیبانی" readOnly value={ text } rows={ 12 } dir="rtl" />
			<div className="pp-actions-row">
				<Button variant="primary" disabled={ ! text } onClick={ () => copy( text ).then( () => setCopied( true ) ) }>کپی گزارش فنی</Button>
				{ copied && <span className="pp-ok">کپی شد؛ در تیکت بچسبانید.</span> }
			</div>
		</section>
	);
}

/**
 * Training and support tab: PaydarWP, getting started, learning, FAQ, contact, report.
 */
export function SupportPage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ openQ, setOpenQ ] = useState( -1 );

	useEffect( () => {
		get( '/support' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	const group = ( g ) => Object.entries( data.brand.links ).filter( ( [ , l ] ) => g === l.group && l.url );
	const others = data ? data.products.filter( ( p ) => 'پایدارپرس' !== p.name ) : [];

	return (
		<Page title="آموزش و پشتیبانی" helpKey="support">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section pp-support__brand">
						<h2 className="pp-card__title">{ data.brand.name }</h2>
						{ data.brand.tagline && <p><strong>{ data.brand.tagline }</strong></p> }
						<p>{ data.brand.about }</p>
						<p className="pp-muted">پایدارپرس نسخه { data.version }</p>
						<LinkList links={ group( 'about' ) } />
						{ others.length > 0 && (
							<>
								<h3>محصولات دیگر پایدار وردپرس روی این سایت</h3>
								<ul className="pp-support__links">
									{ others.map( ( p ) => <li key={ p.name }><a href={ p.url }>{ p.name }</a>{ p.version && <span className="pp-muted"> — نسخه { p.version }</span> }{ p.description && <span className="pp-muted"> — { p.description }</span> }</li> ) }
								</ul>
							</>
						) }
					</section>

					<div className="pp-support__grid">
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">شروع کار</h2>
							<ol>
								<li><a href="#/welcome">راه‌اندازی قدم‌به‌قدم</a> (اسکن، پروفایل، نقشه دسته‌ها، روش تولید، سرچ کنسول)</li>
								<li><a href="#/tasks">کارهای این هفته</a>: هر روز چند کار کوتاه و مهم</li>
								<li><a href="#/auto">تولید محتوا</a>: خودکار با API یا دستی با هر دستیار هوش مصنوعی</li>
								<li><a href="#/links">صف لینک داخلی</a> و <a href="#/clusters">خوشه‌های محتوایی</a></li>
							</ol>
							<p className="pp-muted">در هر صفحه، دکمه «؟» کنار عنوان، توضیح همان صفحه را باز می‌کند.</p>
						</section>
						{ group( 'learn' ).length > 0 && (
							<section className="pp-card pp-card--section">
								<h2 className="pp-card__title">آموزش</h2>
								<LinkList links={ group( 'learn' ) } />
							</section>
						) }
						{ group( 'support' ).length > 0 && (
							<section className="pp-card pp-card--section">
								<h2 className="pp-card__title">ارتباط با پشتیبانی</h2>
								<LinkList links={ group( 'support' ) } />
								{ data.brand.hours && <p className="pp-muted">ساعات پاسخ‌گویی: { data.brand.hours }</p> }
								<p className="pp-muted">پیش از تماس، «گزارش فنی» پایین صفحه را کپی کنید تا مشکل سریع‌تر بررسی شود.</p>
							</section>
						) }
					</div>

					<section className="pp-card pp-card--section">
						<h2 className="pp-card__title">پرسش‌های متداول</h2>
						<ul className="pp-faq">
							{ faq.map( ( f, i ) => (
								<li key={ f.q } className={ openQ === i ? 'is-open' : '' }>
									<button type="button" onClick={ () => setOpenQ( openQ === i ? -1 : i ) } aria-expanded={ openQ === i }>{ f.q }</button>
									{ openQ === i && <p>{ f.a }</p> }
								</li>
							) ) }
						</ul>
					</section>

					{ canManage() && <Report /> }
				</>
			) }
		</Page>
	);
}

/**
 * License and updates.
 */
export function LicensePage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ key, setKey ] = useState( '' );
	const [ relay, setRelay ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		// Only site managers can read the license; others see a notice without a request.
		if ( canManage() ) {
			get( '/license' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
		}
	}, [] );

	const run = ( name, path, body, done ) => {
		setBusy( name );
		setError( '' );
		setNotice( '' );
		post( path, body )
			.then( ( r ) => {
				setData( r );
				if ( done ) {
					setNotice( done( r ) );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! canManage() ) {
		return <Page title="لایسنس و به‌روزرسانی" helpKey="license"><Notice status="warning" isDismissible={ false }>فقط مدیر سایت به این بخش دسترسی دارد.</Notice></Page>;
	}

	const u = data && data.update;
	const statusClass = data && 'active' === data.status ? 'success' : ( data && 'none' === data.status ? '' : 'warning' );

	return (
		<Page title="لایسنس و به‌روزرسانی" helpKey="license">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ notice && <Notice status="success" onRemove={ () => setNotice( '' ) }>{ notice }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section">
						<h2 className="pp-card__title">
							لایسنس <span className={ `pp-badge${ statusClass ? ` pp-badge--${ statusClass }` : '' }` }>{ data.status_label }</span>
						</h2>
						{ ! data.server_ready ? (
							<Notice status="info" isDismissible={ false }>
								فروش و فعال‌سازی لایسنس پایدار وردپرس به‌زودی آغاز می‌شود. تا آن زمان همه امکانات پایدارپرس بدون محدودیت فعال است و لازم نیست کاری انجام دهید.
							</Notice>
						) : (
							<>
								{ data.has_key && (
									<ul className="pp-decision__facts">
										<li><strong>کلید: </strong><code dir="ltr">{ data.key_hint }</code></li>
										{ data.plan && <li><strong>طرح: </strong>{ data.plan }</li> }
										{ data.expires && <li><strong>اعتبار تا: </strong>{ date( data.expires ) }</li> }
										{ data.checked_at && <li><strong>آخرین بررسی: </strong>{ ago( data.checked_at ) }</li> }
										{ data.message && <li><strong>پیام سرور: </strong>{ data.message }</li> }
									</ul>
								) }
								{ data.unreachable && <Notice status="warning" isDismissible={ false }>سرور لایسنس از { ago( data.unreachable ) } در دسترس نبوده است. وضعیت قبلی حفظ شده و هیچ کاری متوقف نمی‌شود.</Notice> }
								<div className="pp-form">
									<TextControl __nextHasNoMarginBottom __next40pxDefaultSize type="password" autoComplete="off" dir="ltr" label={ data.has_key ? 'کلید جدید' : 'کلید لایسنس' } value={ key } onChange={ setKey } placeholder="کلید را از حساب کاربری پایدار وردپرس کپی کنید" />
								</div>
								<div className="pp-actions-row">
									<Button variant="primary" isBusy={ 'activate' === busy } disabled={ !! busy || ! key.trim() } onClick={ () => run( 'activate', '/license/activate', { key }, ( r ) => {
										setKey( '' );
										return 'active' === r.status ? 'لایسنس فعال شد.' : `سرور پاسخ داد: ${ r.status_label }${ r.message ? ` — ${ r.message }` : '' }`;
									} ) }>فعال‌سازی</Button>
									{ data.has_key && <Button variant="secondary" isBusy={ 'check' === busy } disabled={ !! busy } onClick={ () => run( 'check', '/license/check', {}, () => 'بررسی شد.' ) }>بررسی دوباره</Button> }
									{ data.has_key && <Button variant="tertiary" isDestructive disabled={ !! busy } onClick={ () => window.confirm( 'فعال‌سازی این سایت آزاد و کلید از این سایت حذف شود؟ می‌توانید روی سایت دیگری فعالش کنید.' ) && run( 'deactivate', '/license/deactivate', {}, () => 'کلید از این سایت حذف شد.' ) }>غیرفعال‌سازی روی این سایت</Button> }
								</div>
								<p className="pp-muted">
									برای فعال‌سازی فقط این‌ها به سرور پایدار وردپرس فرستاده می‌شود: کلید لایسنس، نسخه پایدارپرس، نسخه وردپرس و PHP و نام دامنه (<code dir="ltr">{ data.site }</code>). هیچ ایمیل، کاربر، محتوا یا کلید API ارسال نمی‌شود. کلید رمزنگاری‌شده ذخیره می‌شود.
								</p>
								<details className="pp-support__relay">
									<summary>هاست به سرور لایسنس وصل نمی‌شود؟</summary>
									<TextControl __nextHasNoMarginBottom dir="ltr" label="آدرس واسط (اختیاری)" value={ null === relay ? data.relay : relay } onChange={ setRelay } placeholder="https://" help="فقط واسطی را بگذارید که به آن اعتماد دارید؛ کلید لایسنس از آن عبور می‌کند. خالی = اتصال مستقیم." />
									<Button variant="secondary" size="small" disabled={ !! busy || null === relay } onClick={ () => run( 'relay', '/license/relay', { relay }, () => {
										setRelay( null );
										return 'ذخیره شد.';
									} ) }>ذخیره واسط</Button>
								</details>
							</>
						) }
					</section>

					<section className="pp-card pp-card--section">
						<h2 className="pp-card__title">به‌روزرسانی</h2>
						<p>نسخه نصب‌شده: <strong>{ data.version }</strong></p>
						{ ! data.server_ready && <p className="pp-muted">تا راه‌اندازی سرور پایدار وردپرس، نسخه‌های تازه را از سایت پایدار وردپرس دریافت و از «افزونه‌ها ← افزودن ← بارگذاری» نصب کنید؛ تنظیمات و داده‌ها حفظ می‌شوند.</p> }
						{ data.server_ready && (
							<>
								{ u && u.error && <Notice status="warning" isDismissible={ false }>{ u.error }</Notice> }
								{ u && ! u.error && u.newer && (
									<Notice status="info" isDismissible={ false }>
										نسخه { u.version } آماده است.{ ' ' }
										{ u.package ? <a href="update-core.php">نصب از صفحه به‌روزرسانی‌های وردپرس</a> : 'برای نصب خودکار، لایسنس معتبر لازم است.' }
									</Notice>
								) }
								{ u && ! u.error && ! u.newer && u.version && <p className="pp-ok">آخرین نسخه نصب است.</p> }
								{ u && u.checked_at && <p className="pp-muted">آخرین بررسی: { ago( u.checked_at ) }</p> }
								<Button variant="secondary" isBusy={ 'update' === busy } disabled={ !! busy } onClick={ () => run( 'update', '/license/update-check', {}, ( r ) => ( r.update && r.update.newer ? `نسخه ${ r.update.version } پیدا شد.` : 'بررسی شد.' ) ) }>بررسی به‌روزرسانی</Button>
								<p className="pp-muted">به‌روزرسانی از مسیر عادی وردپرس نصب می‌شود؛ قبل از نصب، پشتیبان‌گیری توصیه می‌شود. داده‌ها و تنظیمات حفظ می‌شوند.</p>
							</>
						) }
					</section>
				</>
			) }
		</Page>
	);
}
