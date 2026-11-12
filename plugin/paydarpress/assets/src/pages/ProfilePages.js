import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { ProfileField } from '../components/ProfileFields';
import { get, post, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

const canManage = () => !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );

/**
 * Loads the profile and tracks edits.
 */
function useProfile() {
	const [ data, setData ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ dirty, setDirty ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const apply = ( d ) => {
		setData( d );
		const v = {};
		Object.keys( d.fields ).forEach( ( k ) => ( v[ k ] = d.fields[ k ].value ) );
		setValues( v );
		setDirty( {} );
	};

	useEffect( () => {
		get( '/profile' ).then( apply ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	const [ filled, setFilled ] = useState( [] );

	const change = ( name, value ) => {
		if ( 'industry_module' === name && data && data.industries && data.industries[ value ] ) {
			// Fill empty fields (or fields still holding the previous industry's suggestion)
			// with the new industry's defaults; the user can edit them.
			const next = data.industries[ value ];
			const prev = data.industries[ values.industry_module ] || {};
			const defaults = ( ind ) => ( {
				primary_content_goal: ind.goal,
				fact_sensitivity: undefined === ind.ymyl ? undefined : ( ind.ymyl ? 'YMYL' : 'normal' ),
				knowledge_level: ind.knowledge,
				industry_rules: ind.rules,
				primary_keyword_patterns: ind.patterns,
			} );
			const nextDefaults = defaults( next );
			const prevDefaults = defaults( prev );
			const patch = { [ name ]: value };
			const changed = [];
			Object.keys( nextDefaults ).forEach( ( key ) => {
				const current = values[ key ];
				if ( nextDefaults[ key ] && ( ! current || current === prevDefaults[ key ] ) && current !== nextDefaults[ key ] ) {
					patch[ key ] = nextDefaults[ key ];
					changed.push( data.defs[ key ] ? data.defs[ key ].label : key );
				}
			} );
			setValues( ( p ) => ( { ...p, ...patch } ) );
			setDirty( ( p ) => ( { ...p, ...Object.fromEntries( Object.keys( patch ).map( ( k ) => [ k, true ] ) ) } ) );
			setFilled( changed );
			return;
		}
		setValues( ( prev ) => ( { ...prev, [ name ]: value } ) );
		setDirty( ( prev ) => ( { ...prev, [ name ]: true } ) );
	};

	/**
	 * Saves the given fields (confirming them even if unchanged).
	 *
	 * @param {string[]} names      Field names.
	 * @param {boolean}  wizardDone Mark the wizard as finished.
	 */
	const save = ( names, wizardDone = false ) => {
		const payload = {};
		names.forEach( ( n ) => ( payload[ n ] = values[ n ] ) );
		setSaving( true );
		setError( '' );
		return post( '/profile', { values: payload, wizard_done: wizardDone } )
			.then( ( d ) => {
				apply( d );
				return true;
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				return false;
			} )
			.finally( () => setSaving( false ) );
	};

	const infer = () => {
		setSaving( true );
		return post( '/profile/infer' )
			.then( apply )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setSaving( false ) );
	};

	return { data, values, dirty, error, saving, change, save, infer, filled };
}

/**
 * Completion bar.
 *
 * @param {Object} props
 * @param {Object} props.completion {percent, missing}.
 * @param {Object} props.defs       Field definitions.
 */
function Completion( { completion, defs } ) {
	return (
		<div className="pp-completion">
			<div className="pp-completion__head">
				<strong>تکمیل پروفایل: { num( completion.percent ) }٪</strong>
				{ completion.missing.length > 0 && <span className="pp-muted">مانده: { completion.missing.map( ( m ) => ( defs[ m ] ? defs[ m ].label : m ) ).join( '، ' ) }</span> }
			</div>
			<div className="pp-bar"><span style={ { width: `${ Math.max( 3, completion.percent ) }%` } } /></div>
		</div>
	);
}

const STEPS = [
	{ title: 'اطلاعات سایت', text: 'این اطلاعات خودکار از سایت خوانده شد. حوزه و حساسیت محتوا را بررسی کنید.', fields: [ 'site_name', 'domain', 'seo_plugin', 'page_builder', 'woocommerce', 'industry_module', 'fact_sensitivity' ] },
	{ title: 'صفحات کلیدی و دعوت به اقدام', text: 'مقالات به این صفحات لینک می‌دهند. صفحات منوی اصلی و صفحات تماس و خدمات پیشنهاد شده‌اند.', fields: [ 'key_pages', 'cta_options' ] },
	{ title: 'دسته‌بندی‌ها', text: 'برای هر دسته بنویسید چه موضوعاتی را پوشش می‌دهد تا موضوعات جدید درست دسته‌بندی شوند.', fields: [ 'categories_scope' ] },
	{ title: 'کسب‌وکار و مخاطب', text: 'این بخش را فقط شما می‌دانید؛ پاسخ‌های کوتاه هم کافی است.', fields: [ 'business_offer', 'geographic_scope', 'primary_content_goal', 'primary_audience', 'audience_problems', 'knowledge_level', 'tone_of_voice' ] },
	{ title: 'قوانین محتوا', text: 'چه چیزهایی هرگز نباید نوشته شود و چه اصطلاحاتی ترجیح دارند.', fields: [ 'forbidden_topics', 'industry_rules', 'brand_glossary', 'primary_keyword_patterns' ] },
];

/**
 * Five-step setup wizard (architecture section 4.5).
 */
export function WizardPage() {
	const profile = useProfile();
	const [ step, setStep ] = useState( 0 );
	const { data, values, error, saving } = profile;

	if ( ! data ) {
		return (
			<Page title="راه‌اندازی پروفایل سایت" helpKey="profile">
				{ error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <Spinner /> }
			</Page>
		);
	}

	const current = STEPS[ step ];
	const editable = current.fields.filter( ( f ) => data.defs[ f ] && 'identity' !== data.defs[ f ].group );
	const last = step === STEPS.length - 1;

	const next = () =>
		profile.save( editable, last ).then( ( ok ) => {
			if ( ! ok ) {
				return;
			}
			if ( last ) {
				window.location.hash = '#/export';
			} else {
				setStep( step + 1 );
				window.scrollTo( 0, 0 );
			}
		} );

	return (
		<Page title="راه‌اندازی پروفایل سایت" helpKey="profile">
			<ol className="pp-stepper">
				{ STEPS.map( ( s, i ) => (
					<li key={ s.title } className={ `${ i === step ? 'is-current' : '' }${ i < step ? ' is-done' : '' }` }>
						<button type="button" onClick={ () => setStep( i ) }>
							<span className="pp-stepper__num">{ num( i + 1 ) }</span> { s.title }
						</button>
					</li>
				) ) }
			</ol>

			<Completion completion={ data.completion } defs={ data.defs } />

			<section className="pp-card">
				<h2 className="pp-card__title">{ current.title }</h2>
				<p className="pp-muted">{ current.text }</p>
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<div className="pp-form">
					{ current.fields.map( ( name ) =>
						data.defs[ name ] ? (
							<ProfileField key={ name } name={ name } def={ data.defs[ name ] } value={ values[ name ] } field={ data.fields[ name ] } choices={ data.choices } onChange={ profile.change } industry={ ( data.industries || {} )[ values.industry_module || data.default_industry ] } note={ 'industry_module' === name && profile.filled.length ? `بر اساس این حوزه پیشنهاد شد (قابل ویرایش): ${ profile.filled.join( '، ' ) }` : '' } />
						) : null
					) }
				</div>
				<div className="pp-form__actions">
					{ step > 0 && <Button variant="tertiary" onClick={ () => setStep( step - 1 ) }>مرحله قبل</Button> }
					<Button variant="tertiary" onClick={ () => ( last ? ( window.location.hash = '#/' ) : setStep( step + 1 ) ) }>فعلاً رد شو</Button>
					{ canManage() && (
						<Button variant="primary" onClick={ next } isBusy={ saving } disabled={ saving } __next40pxDefaultSize>
							{ last ? 'تأیید و پایان' : 'تأیید و ادامه' }
						</Button>
					) }
				</div>
			</section>
		</Page>
	);
}

/**
 * Full profile editor.
 */
/**
 * Suggestions from the imported Search Console queries. Clicking adds them to the form;
 * nothing is stored until the user saves.
 */
function GscSuggestions( { values, onChange } ) {
	const [ data, setData ] = useState( null );
	const [ used, setUsed ] = useState( {} );

	useEffect( () => {
		get( '/gsc/insights' ).then( setData ).catch( () => setData( null ) );
	}, [] );

	if ( ! data ) {
		return null;
	}
	if ( ! data.available ) {
		return (
			<div className="pp-card pp-gsc-suggest pp-gsc-suggest--empty">
				<strong>پر کردن پروفایل با داده‌های گوگل:</strong> اگر فایل خروجی سرچ کنسول را در صفحه «سرچ کنسول» وارد کنید، جست‌وجوهای واقعی کاربران اینجا به الگوی کلمات کلیدی، سؤال‌های مخاطب، محدوده جغرافیایی و صفحات کلیدی تبدیل می‌شوند. <a href="#/gsc">ورود فایل سرچ کنسول</a>
			</div>
		);
	}

	const append = ( field, text, key ) => {
		const current = ( values[ field ] || '' ).trim();
		const lines = text.split( '\n' ).filter( ( l ) => l && ! current.includes( l.split( ' (' )[ 0 ] ) );
		if ( lines.length ) {
			onChange( field, current ? `${ current }\n${ lines.join( '\n' ) }` : lines.join( '\n' ) );
		}
		setUsed( ( u ) => ( { ...u, [ key ]: true } ) );
	};
	const addKeyPage = ( page ) => {
		const list = values.key_pages || [];
		if ( ! list.some( ( p ) => p.id === page.id ) ) {
			onChange( 'key_pages', [ ...list, { id: page.id, name: page.title, priority: 'normal', link_categories: [], anchors: [], detected_by: 'gsc' } ] );
		}
		setUsed( ( u ) => ( { ...u, [ `kp${ page.id }` ]: true } ) );
	};

	const f = data.fields || {};
	const hasAny = f.primary_keyword_patterns || f.audience_problems || f.geographic_scope || ( data.key_pages || [] ).length;
	if ( ! hasAny ) {
		return null;
	}

	return (
		<section className="pp-card pp-gsc-suggest">
			<h2 className="pp-card__title">پیشنهاد از سرچ کنسول</h2>
			<p className="pp-muted">از { num( data.queries ) } جست‌وجوی واقعی که سایت شما در گوگل برایشان دیده شده (جست‌وجوی نام برند حذف شده). با «افزودن»، متن به فیلد اضافه می‌شود؛ سپس ویرایش و ذخیره کنید.</p>

			{ f.primary_keyword_patterns && (
				<div className="pp-gsc-suggest__row">
					<div>
						<strong>الگوی کلمات کلیدی</strong>
						<ul>{ data.patterns.map( ( p ) => <li key={ p.pattern }><code>{ p.pattern }</code> — { num( p.queries ) } جست‌وجو، { num( p.impressions ) } نمایش؛ مثل «{ p.examples.slice( 0, 2 ).join( '»، «' ) }»</li> ) }</ul>
					</div>
					<Button variant="secondary" size="small" disabled={ used.patterns } onClick={ () => append( 'primary_keyword_patterns', f.primary_keyword_patterns, 'patterns' ) }>{ used.patterns ? 'اضافه شد' : 'افزودن' }</Button>
				</div>
			) }
			{ f.audience_problems && (
				<div className="pp-gsc-suggest__row">
					<div>
						<strong>سؤال‌ها و مشکلات مخاطب</strong>
						<ul>{ data.questions.slice( 0, 8 ).map( ( q ) => <li key={ q.query }>{ q.query } <span className="pp-muted">({ num( q.impressions ) } نمایش)</span></li> ) }</ul>
					</div>
					<Button variant="secondary" size="small" disabled={ used.problems } onClick={ () => append( 'audience_problems', f.audience_problems, 'problems' ) }>{ used.problems ? 'اضافه شد' : 'افزودن' }</Button>
				</div>
			) }
			{ f.geographic_scope && (
				<div className="pp-gsc-suggest__row">
					<div>
						<strong>محدوده جغرافیایی</strong>
						<div>{ data.places.map( ( p ) => `${ p.place } (${ num( p.impressions ) } نمایش)` ).join( '، ' ) }</div>
						{ values.geographic_scope && <div className="pp-muted">مقدار فعلی: { values.geographic_scope }</div> }
					</div>
					<Button variant="secondary" size="small" disabled={ used.geo || values.geographic_scope === f.geographic_scope } onClick={ () => { onChange( 'geographic_scope', f.geographic_scope ); setUsed( ( u ) => ( { ...u, geo: true } ) ); } }>
						{ values.geographic_scope ? `جایگزینی با «${ f.geographic_scope }»` : `ثبت «${ f.geographic_scope }»` }
					</Button>
				</div>
			) }
			{ ( data.key_pages || [] ).length > 0 && (
				<div className="pp-gsc-suggest__row">
					<div>
						<strong>صفحات پربازدید که صفحه کلیدی نیستند</strong>
						<ul>
							{ data.key_pages.map( ( p ) => (
								<li key={ p.id }>
									{ p.title } <span className="pp-muted">({ num( p.clicks ) } کلیک)</span>{ ' ' }
									<Button variant="link" disabled={ used[ `kp${ p.id }` ] } onClick={ () => addKeyPage( p ) }>{ used[ `kp${ p.id }` ] ? 'اضافه شد' : 'افزودن به صفحات کلیدی' }</Button>
								</li>
							) ) }
						</ul>
					</div>
				</div>
			) }
		</section>
	);
}

export function ProfilePage() {
	const profile = useProfile();
	const { data, values, dirty, error, saving } = profile;
	const [ saved, setSaved ] = useState( false );

	if ( ! data ) {
		return (
			<Page title="پروفایل سایت" helpKey="profile">
				{ error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <Spinner /> }
			</Page>
		);
	}

	const dirtyNames = Object.keys( dirty );
	const save = () => profile.save( dirtyNames ).then( ( ok ) => ok && setSaved( true ) );

	return (
		<Page
			title="پروفایل سایت"
			helpKey="profile"
			actions={
				canManage() && (
					<>
						<a className="button" href="#/wizard">ویزارد راه‌اندازی</a>
						<Button variant="secondary" onClick={ profile.infer } disabled={ saving }>تشخیص مجدد خودکار</Button>
					</>
				)
			}
		>
			<Completion completion={ data.completion } defs={ data.defs } />
			{ canManage() && <GscSuggestions values={ values } onChange={ profile.change } /> }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ saved && 0 === dirtyNames.length && <Notice status="success" onRemove={ () => setSaved( false ) }>تغییرات ذخیره شد.</Notice> }

			{ Object.entries( data.groups ).map( ( [ group, title ] ) => (
				<section key={ group } className="pp-card pp-card--section">
					<h2 className="pp-card__title">{ title }</h2>
					<div className={ `pp-form${ 'identity' === group ? ' pp-form--grid' : '' }` }>
						{ Object.keys( data.defs )
							.filter( ( name ) => data.defs[ name ].group === group )
							.map( ( name ) => (
								<ProfileField key={ name } name={ name } def={ data.defs[ name ] } value={ values[ name ] } field={ data.fields[ name ] } choices={ data.choices } onChange={ profile.change } industry={ ( data.industries || {} )[ values.industry_module || data.default_industry ] } note={ 'industry_module' === name && profile.filled.length ? `بر اساس این حوزه پیشنهاد شد (قابل ویرایش): ${ profile.filled.join( '، ' ) }` : '' } />
							) ) }
					</div>
				</section>
			) ) }

			{ canManage() && (
				<div className="pp-sticky-actions">
					<span className="pp-muted">{ dirtyNames.length ? `${ num( dirtyNames.length ) } فیلد تغییر کرده است.` : 'تغییری ذخیره‌نشده ندارید.' }</span>
					<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving || 0 === dirtyNames.length } __next40pxDefaultSize>
						ذخیره و تأیید تغییرات
					</Button>
				</div>
			) }
			{ data.updated_at && <p className="pp-muted">آخرین تغییر پروفایل { ago( data.updated_at ) }</p> }
		</Page>
	);
}

/**
 * Formats bytes in Persian.
 *
 * @param {number} bytes Size.
 */
function size( bytes ) {
	return bytes > 1048576 ? `${ ( bytes / 1048576 ).toLocaleString( 'fa-IR', { maximumFractionDigits: 1 } ) } مگابایت` : `${ Math.ceil( bytes / 1024 ).toLocaleString( 'fa-IR' ) } کیلوبایت`;
}

/**
 * JSON export for ContentMaster.
 */
export function ExportPage() {
	const canExport = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.export );
	const [ data, setData ] = useState( null );
	const [ drafts, setDrafts ] = useState( false );
	const [ compact, setCompact ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ defs, setDefs ] = useState( {} );

	useEffect( () => {
		if ( canExport ) {
			get( '/export' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
			get( '/profile' ).then( ( p ) => setDefs( p.defs ) ).catch( () => {} );
		}
	}, [ canExport ] );

	const create = () => {
		setBusy( true );
		setError( '' );
		post( '/export', { include_drafts: drafts, compact: !! useCompact } )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! canExport ) {
		return (
			<Page title="خروجی JSON" helpKey="export">
				<div className="pp-card"><EmptyState title="دسترسی ندارید" text="ساخت خروجی فقط برای مدیر سایت مجاز است." /></div>
			</Page>
		);
	}

	const last = data && data.last;
	const completion = data && data.completion;
	const suggestCompact = !! data && data.items > data.compact_at;
	const useCompact = null === compact ? suggestCompact : compact;

	return (
		<Page title="خروجی JSON برای ContentMaster" helpKey="export">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && <Spinner /> }

			{ completion && completion.percent < 100 && (
				<Notice status="warning" isDismissible={ false }>
					پروفایل سایت { num( completion.percent ) }٪ تکمیل شده است. خروجی ساخته می‌شود، اما ContentMaster این موارد را از شما می‌پرسد: { completion.missing.map( ( m ) => ( defs[ m ] ? defs[ m ].label : m ) ).join( '، ' ) }. <a href="#/wizard">تکمیل پروفایل</a>
				</Notice>
			) }

			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">ساخت فایل</h2>
				<p className="pp-muted">فایل شامل پروفایل سایت، دسته‌ها و برچسب‌ها، فهرست نوشته‌ها و برگه‌ها (بدون متن کامل)، کلمات کلیدی، لینک‌ها و مشکلات هر مطلب است. ایمیل، نام کاربری و اطلاعات کاربران در فایل نیست.</p>
				<CheckboxControl __nextHasNoMarginBottom label="پیش‌نویس‌ها هم اضافه شوند" checked={ drafts } onChange={ setDrafts } />
				<CheckboxControl
					__nextHasNoMarginBottom
					label="خروجی فشرده"
					help={ suggestCompact
						? `سایت شما ${ num( data.items ) } نوشته دارد؛ خروجی فشرده پیشنهاد می‌شود تا فایل در حافظه هوش مصنوعی جا شود. سرفصل‌ها، خلاصه و عنوان سئو هر نوشته حذف می‌شوند.`
						: 'برای سایت‌های بزرگ (بیش از ۳۰۰ نوشته). سرفصل‌ها، خلاصه و عنوان سئو هر نوشته حذف می‌شوند تا فایل کوچک‌تر شود.' }
					checked={ useCompact }
					onChange={ setCompact }
				/>
				<div className="pp-form__actions">
					<Button variant="primary" onClick={ create } isBusy={ busy } disabled={ busy } __next40pxDefaultSize>
						{ last ? 'ساخت خروجی جدید' : 'ساخت خروجی' }
					</Button>
				</div>
			</section>

			{ last && (
				<section className="pp-card pp-card--section pp-export">
					<h2 className="pp-card__title">آخرین خروجی</h2>
					<dl className="pp-facts">
						<div><dt>فایل</dt><dd dir="ltr">{ last.filename }</dd></div>
						<div><dt>حجم</dt><dd>{ size( last.size ) }{ last.compact ? ' (فشرده)' : '' }</dd></div>
						<div><dt>ساخته شده</dt><dd>{ ago( last.created_at ) }</dd></div>
						<div><dt>محتوا</dt><dd>{ num( last.counts.posts ) } نوشته، { num( last.counts.pages ) } برگه{ last.counts.products ? `، ${ num( last.counts.products ) } محصول` : '' }</dd></div>
					</dl>
					{ last.expired ? (
						<p className="pp-missing">این فایل منقضی شده است (فایل‌ها پس از ۲۴ ساعت حذف می‌شوند). یک خروجی جدید بسازید.</p>
					) : (
						<a className="button button-primary pp-download" href={ last.download_url }>دانلود فایل JSON</a>
					) }
				</section>
			) }

			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">نحوه استفاده در ContentMaster</h2>
				<ol className="pp-howto">
					<li>در Claude یک Project برای این سایت بسازید و متن ContentMaster را در Instructions پروژه قرار دهید.</li>
					<li>فایل JSON دانلودشده را به فایل‌های پروژه اضافه کنید (هر بار پس از تغییرات مهم، فایل جدید را جایگزین کنید).</li>
					<li>گفتگو را با «منو» یا «موضوع جدید» شروع کنید. ContentMaster پروفایل و فهرست محتوا را از فایل می‌خواند و فقط اطلاعات ناقص را می‌پرسد.</li>
				</ol>
			</section>
		</Page>
	);
}
