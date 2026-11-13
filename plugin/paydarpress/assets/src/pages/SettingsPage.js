import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { AiConnection, AiUsage } from '../components/AiConnection';
import { get, post, errorMessage } from '../lib/api';
import { num, ago, date } from '../lib/format';
import { setMode, useMode } from '../lib/uiMode';
import glossary from '../../../help/fa_IR/glossary.json';

const config = window.paydarpress || {};

/**
 * Settings fields per tab. `advanced` fields are shown only in pro mode.
 * `type`: toggle | number | ids | hour.
 */
const TABS = [
	{
		key: 'general',
		label: 'عمومی',
		fields: [
			{ path: 'ui.dashboard_widget', type: 'toggle', label: 'ویجت سلامت محتوا در پیشخوان وردپرس', help: 'امتیاز سلامت و تعداد مشکلات مهم را در صفحه اول پیشخوان وردپرس نشان می‌دهد. فقط داده ذخیره‌شده را می‌خواند و پیشخوان را کند نمی‌کند.' },
			{ path: 'ui.font_everywhere', type: 'toggle', advanced: true, label: 'فونت وزیرمتن در کل پیشخوان وردپرس', help: 'به‌طور پیش‌فرض فونت فقط در صفحات پایدارپرس بارگذاری می‌شود.' },
		],
	},
	{
		key: 'audit',
		label: 'ممیزی',
		intro: 'این عددها تعیین می‌کنند چه چیزی «مشکل» حساب شود. پس از ذخیره، ممیزی چند ثانیه بعد با مقادیر جدید دوباره اجرا می‌شود.',
		fields: [
			{ path: 'audit.thin_words', type: 'number', label: 'حداقل تعداد کلمات یک مطلب', suffix: 'کلمه', help: 'مطالب کوتاه‌تر از این عدد «محتوای کم‌حجم» شناخته می‌شوند.' },
			{ path: 'audit.stale_months', type: 'number', label: 'قدیمی شدن مطلب پس از', suffix: 'ماه', help: 'مطالبی که در این مدت ویرایش نشده‌اند، در پیشنهاد بازنویسی امتیاز بیشتری می‌گیرند.' },
			{ path: 'audit.weak_inbound_min', type: 'number', advanced: true, label: 'حداقل لینک ورودی صفحات کلیدی', suffix: 'لینک', help: 'صفحات کلیدی پروفایل که کمتر از این تعداد لینک داخلی دریافت کنند، گزارش می‌شوند.' },
			{ path: 'audit.weak_category_min', type: 'number', advanced: true, label: 'حداقل نوشته در هر دسته', suffix: 'نوشته', help: 'دسته‌های با نوشته کمتر «دسته ضعیف» شناخته می‌شوند.' },
			{ path: 'audit.tag_overuse_max', type: 'number', advanced: true, label: 'حداکثر برچسب برای هر نوشته', suffix: 'برچسب', help: 'نوشته‌هایی با برچسب بیشتر از این عدد گزارش می‌شوند؛ برچسب زیاد صفحات آرشیو ضعیف و تکراری می‌سازد.' },
			{ path: 'audit.inline_images_min_words', type: 'number', advanced: true, label: 'نیاز به تصویر داخل متن از', suffix: 'کلمه', help: 'مطالب بلندتر از این عدد بدون تصویر داخل متن گزارش می‌شوند.' },
			{ path: 'audit.gsc_min_impressions', type: 'number', advanced: true, label: 'حداقل نمایش برای فرصت سرچ کنسول', suffix: 'نمایش', help: 'صفحات با نمایش کمتر از این عدد در «نزدیک صفحه اول» و «رتبه خوب، کلیک کم» گزارش نمی‌شوند.' },
			{ path: 'audit.orphan_exclude_ids', type: 'ids', advanced: true, label: 'شناسه صفحاتی که یتیم حساب نشوند', help: 'شناسه نوشته یا برگه را با ویرگول جدا کنید؛ مثلاً صفحات فرود تبلیغاتی که عمداً لینک داخلی ندارند.' },
		],
	},
	{
		key: 'policy',
		label: 'سیاست محتوا',
		intro: 'این قواعد جلوی چرخه «تولید بی‌وقفه ← لینک‌سازی وصله‌ای ← تولید دوباره» را می‌گیرند. پایدارپرس در پیشنهاد موضوع، صف لینک، ممیزی و انتشار از آن‌ها پیروی می‌کند.',
		fields: [
			{ path: 'policy.site_stage', type: 'select', label: 'مرحله سایت', options: [ [ 'auto', 'تشخیص خودکار' ], [ 'new', 'تازه‌تأسیس' ], [ 'growing', 'در حال رشد' ], [ 'mature', 'بالغ' ] ], help: 'پایدارپرس مرحله را از تعداد مطالب منتشرشده تشخیص می‌دهد (کمتر از ۲۰ مقاله: تازه‌تأسیس، تا ۲۰۰: در حال رشد، بیشتر: بالغ). اگر تشخیص درست نیست، خودتان انتخاب کنید.' },
			{ path: 'policy.new_per_month', type: 'number', label: 'سقف مقاله جدید در ماه', suffix: 'مقاله', help: 'پیشنهاد موضوع و نوشتن مقاله وقتی این سهمیه پر شود، به‌جای مقاله جدید، بازنویسی پیشنهاد می‌کنند.' },
			{ path: 'policy.refresh_share', type: 'number', label: 'سهم بازنویسی از کار ماهانه', suffix: 'درصد', help: 'تا وقتی مقاله قدیمی نیازمند بازنویسی هست، این سهم از ظرفیت ماه به بهتر کردن محتوای موجود می‌رسد.' },
			{ path: 'policy.publish_per_day', type: 'number', label: 'انتشار پیشنهادی در روز', suffix: 'مطلب', help: 'فقط برای انتشار مطلب جدید است و مانع نمی‌شود: بیشتر از این عدد، در ویرایشگر پیشنهاد زمان‌بندی داده می‌شود. اصلاحیه‌ها سقف ندارند.' },
			{ path: 'policy.grace_days', type: 'number', label: 'مهلت جاافتادن مطلب تازه', suffix: 'روز', help: 'مطلب تازه‌منتشرشده در این مدت «یتیم» حساب نمی‌شود و برایش لینک ترمیمی ساخته نمی‌شود؛ لینک‌های ورودی‌اش باید همراه خودش ساخته شوند.' },
			{ path: 'policy.require_review', type: 'toggle', label: 'بازبینی انسانی پیش از انتشار (سایت‌های حساس)', help: 'در سایت‌های حساس (حقوقی، پزشکی، مالی)، پیش‌نویسی که با هوش مصنوعی ساخته شده تا «تأیید بازبینی» در ویرایشگر زده نشود منتشر نمی‌شود.' },
			{ path: 'policy.links_per_post_month', type: 'number', advanced: true, label: 'لینک تازه برای هر مقاله قدیمی', suffix: 'در ماه', help: 'هر مقاله قدیمی در ۳۰ روز حداکثر این تعداد لینک تازه می‌گیرد؛ تغییر پشت‌سرهم یک مقاله ارزش آن را پایین می‌آورد.' },
			{ path: 'policy.links_per_week', type: 'number', advanced: true, label: 'لینک ترمیمی پیشنهادی در هفته', suffix: 'لینک', help: 'فقط برای چیدن «کارهای این هفته» است و جلوی کار شما را نمی‌گیرد. اصلاحیه‌ها (متا، متن دسته، لینک، رفع مشکل) هیچ سقف روزانه‌ای ندارند.' },
			{ path: 'policy.words_per_link', type: 'number', advanced: true, label: 'تراکم لینک داخلی', suffix: 'کلمه برای هر لینک', help: 'مقاله‌ای که به این تراکم رسیده، لینک تازه نمی‌گیرد (حداقل ۳ لینک همیشه مجاز است).' },
			{ path: 'policy.edit_cooldown_days', type: 'number', advanced: true, label: 'فاصله بین دو بازنویسی', suffix: 'روز', help: 'مطلبی که در این مدت ویرایش شده، دوباره برای بازنویسی پیشنهاد نمی‌شود.' },
		],
	},
	{
		key: 'images',
		label: 'تصاویر',
		intro: 'تصاویری که هوش مصنوعی می‌سازد معمولاً PNG چند مگابایتی هستند. با این تنظیمات هر تصویر هنگام آپلود کوچک و به WebP سبک تبدیل می‌شود.',
		fields: [
			{ path: 'images.convert_webp', type: 'toggle', label: 'تبدیل خودکار PNG و JPEG به WebP هنگام آپلود', help: 'همه اندازه‌های تصویر (بندانگشتی، متوسط…) هم از فایل WebP ساخته می‌شوند.' },
			{ path: 'images.delete_original', type: 'toggle', label: 'حذف فایل اصلی پس از تبدیل', help: 'فایل PNG/JPEG اصلی نگه داشته نمی‌شود و فضای هاست اشغال نمی‌کند.' },
			{ path: 'images.max_dimension', type: 'number', label: 'حداکثر عرض یا ارتفاع', suffix: 'پیکسل', help: 'تصاویر بزرگ‌تر با حفظ نسبت کوچک می‌شوند. ۱۹۲۰ برای نمایش تمام‌عرض کافی است.' },
			{ path: 'images.webp_quality', type: 'number', advanced: true, label: 'کیفیت WebP', suffix: 'از ۱۰۰', help: '۷۵ تا ۸۵ تفاوت محسوسی با اصل ندارد و حجم را چند برابر کم می‌کند.' },
			{ path: 'images.min_kb', type: 'number', advanced: true, label: 'تبدیل نکردن فایل‌های کوچک‌تر از', suffix: 'کیلوبایت', help: 'آیکون‌ها و تصاویر خیلی کوچک تبدیل نمی‌شوند.' },
		],
	},
	{
		key: 'ai',
		label: 'هوش مصنوعی',
		intro: 'سقف ماهانه از هزینه ناخواسته جلوگیری می‌کند. وقتی سقف پر شود، تولید خودکار تا ماه بعد متوقف می‌شود و حالت دستی همچنان کار می‌کند. عدد ۰ یعنی خاموش.',
		fields: [
			{ path: 'ai.monthly_articles', type: 'number', label: 'سقف مقاله در ماه', suffix: 'مقاله', help: 'نوشتن مقاله جدید و بازنویسی کامل مقاله قدیمی، هر کدام یک مقاله حساب می‌شود.' },
			{ path: 'ai.monthly_requests', type: 'number', label: 'سقف همه درخواست‌ها در ماه', suffix: 'درخواست', help: 'شامل پیشنهاد موضوع، متا، متن لینک، متن دسته‌بندی و متن جایگزین تصویر هم می‌شود.' },
			{ path: 'ai.max_output_tokens', type: 'number', advanced: true, label: 'حداکثر طول پاسخ', suffix: 'توکن', help: 'برای مقاله ۱۵۰۰ تا ۲۵۰۰ کلمه‌ای فارسی حدود ۶۰۰۰ تا ۹۰۰۰ توکن لازم است. برخی مدل‌ها سقف کمتری دارند.' },
		],
	},
	{
		key: 'performance',
		label: 'عملکرد و صف',
		fields: [
			{ path: 'performance.shared_hosting', type: 'toggle', label: 'حالت هاست اشتراکی', help: 'اسکن در بسته‌های کوچک‌تر (۱۵ مطلب در هر مرحله) و آرام‌تر انجام می‌شود. برای هاست‌های ارزان یا شلوغ پیشنهاد می‌شود.' },
			{ path: 'performance.batch_size', type: 'number', advanced: true, label: 'تعداد مطلب در هر مرحله اسکن', suffix: 'مطلب', disabledBy: 'performance.shared_hosting', help: 'عدد کمتر یعنی فشار کمتر روی سرور و اسکن طولانی‌تر. برای سرورهای قوی ۵۰ تا ۱۰۰ مناسب است.' },
			{ path: 'performance.batch_time_limit', type: 'number', advanced: true, label: 'حداکثر زمان هر مرحله', suffix: 'ثانیه', disabledBy: 'performance.shared_hosting', help: 'باید کمتر از محدودیت زمان اجرای PHP هاست باشد (معمولاً ۳۰ ثانیه).' },
			{ path: 'performance.quiet_hours_only', type: 'toggle', advanced: true, label: 'کارهای روزانه فقط در ساعات خلوت', help: 'پاک‌سازی روزانه (گزارش‌های قدیمی و فایل‌های خروجی منقضی) در ساعت شروع بازه اجرا می‌شود. اسکن‌هایی که خودتان شروع می‌کنید همیشه فوری اجرا می‌شوند.' },
			{ path: 'performance.quiet_hours_start', type: 'hour', advanced: true, label: 'شروع ساعات خلوت', showIf: 'performance.quiet_hours_only', help: 'به وقت سایت (تنظیمات ← عمومی). ساعتی را انتخاب کنید که بازدید سایت کمترین است.' },
			{ path: 'performance.quiet_hours_end', type: 'hour', advanced: true, label: 'پایان ساعات خلوت', showIf: 'performance.quiet_hours_only', help: 'کارهای روزانه بعد از این ساعت شروع نمی‌شوند.' },
		],
	},
	{
		key: 'advanced',
		label: 'پیشرفته',
		proOnly: true,
		fields: [
			{ path: 'retention.activity_days', type: 'number', label: 'نگهداری گزارش فعالیت', suffix: 'روز', help: 'گزارش‌های قدیمی‌تر از این مدت هر روز پاک می‌شوند تا پایگاه داده بزرگ نشود.' },
			{ path: 'uninstall.delete_data', type: 'toggle', danger: true, label: 'حذف همه داده‌ها هنگام پاک کردن افزونه', help: 'اگر روشن باشد، با حذف افزونه از صفحه افزونه‌ها، جدول‌ها، پروفایل سایت، تنظیمات و خروجی‌ها برای همیشه پاک می‌شوند. غیرفعال کردن افزونه چیزی را پاک نمی‌کند.' },
		],
	},
	{ key: 'help', label: 'راهنما', fields: [] },
];

const HOURS = Array.from( { length: 24 }, ( _, h ) => ( { value: String( h ), label: `ساعت ${ num( h ) }` } ) );

function read( obj, path ) {
	return path.split( '.' ).reduce( ( acc, key ) => ( acc ? acc[ key ] : undefined ), obj );
}

function write( obj, path, value ) {
	const [ group, key ] = path.split( '.' );
	return { ...obj, [ group ]: { ...obj[ group ], [ key ]: value } };
}

function initialTab() {
	const match = window.location.hash.match( /[?&]tab=([a-z]+)/ );
	return match ? match[ 1 ] : 'general';
}

export default function SettingsPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const mode = useMode();
	const [ tab, setTab ] = useState( initialTab );
	const [ data, setData ] = useState( null );
	const [ values, setValues ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ aiState, setAiState ] = useState( null );

	useEffect( () => {
		if ( canManage ) {
			get( '/settings' )
				.then( ( d ) => {
					setData( d );
					setValues( d.settings );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) );
		}
	}, [ canManage ] );

	const visibleTabs = TABS.filter( ( t ) => ! t.proOnly || 'pro' === mode );
	const current = visibleTabs.find( ( t ) => t.key === tab ) || visibleTabs[ 0 ];

	const dirty = useMemo( () => {
		if ( ! data || ! values ) {
			return [];
		}
		return TABS.flatMap( ( t ) => t.fields )
			.filter( ( f ) => JSON.stringify( read( values, f.path ) ) !== JSON.stringify( read( data.settings, f.path ) ) )
			.map( ( f ) => f.path );
	}, [ data, values ] );

	if ( ! canManage ) {
		return (
			<Page title="تنظیمات" helpKey="settings">
				<div className="pp-card"><EmptyState title="دسترسی ندارید" text="تنظیمات فقط برای مدیر سایت قابل تغییر است." /></div>
			</Page>
		);
	}

	const save = () => {
		setSaving( true );
		setNotice( null );
		const payload = {};
		dirty.forEach( ( path ) => {
			const [ group, key ] = path.split( '.' );
			payload[ group ] = { ...( payload[ group ] || {} ), [ key ]: read( values, path ) };
		} );
		post( '/settings', { settings: payload } )
			.then( ( d ) => {
				setData( d );
				setValues( d.settings );
				setNotice( { status: 'success', text: 'تنظیمات ذخیره شد.' } );
				if ( 'ai' === current.key ) {
					get( '/ai' ).then( setAiState ).catch( () => {} );
				}
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) )
			.finally( () => setSaving( false ) );
	};

	const selectTab = ( key ) => {
		setTab( key );
		window.history.replaceState( null, '', `#/settings?tab=${ key }` );
	};

	const hiddenCount = current.fields.filter( ( f ) => f.advanced ).length;

	return (
		<Page title="تنظیمات" helpKey="settings">
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }

			<div className="pp-tabs" role="tablist" aria-label="بخش‌های تنظیمات">
				{ visibleTabs.map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						role="tab"
						aria-selected={ t.key === current.key }
						className={ `pp-tab${ t.key === current.key ? ' is-active' : '' }` }
						onClick={ () => selectTab( t.key ) }
					>
						{ t.label }
					</button>
				) ) }
			</div>

			{ ! values && <Spinner /> }

			{ 'ai' === current.key && <AiConnection onChange={ setAiState } /> }
			{ 'policy' === current.key && values && <StageCard key={ JSON.stringify( data.settings.policy ) } onApplied={ ( d ) => { setData( d ); setValues( d.settings ); } } /> }

			{ values && current.fields.length > 0 && (
				<section className="pp-card pp-card--section">
					{ current.intro && <p className="pp-help-text">{ current.intro }</p> }
					<div className="pp-form">
						{ current.fields
							.filter( ( f ) => ! f.advanced || 'pro' === mode )
							.filter( ( f ) => ! f.showIf || read( values, f.showIf ) )
							.map( ( f ) => (
								<SettingField
									key={ f.path }
									field={ f }
									value={ read( values, f.path ) }
									defaultValue={ read( data.defaults, f.path ) }
									limit={ data.limits[ f.path ] }
									disabled={ !! f.disabledBy && !! read( values, f.disabledBy ) }
									onChange={ ( v ) => setValues( write( values, f.path, v ) ) }
								/>
							) ) }
					</div>
					{ 'simple' === mode && hiddenCount > 0 && (
						<p className="pp-muted pp-advanced-hint">
							{ num( hiddenCount ) } گزینه پیشرفته دیگر در این بخش هست.{ ' ' }
							<Button variant="link" onClick={ () => setMode( 'pro' ) }>نمایش در حالت حرفه‌ای</Button>
						</p>
					) }
					<div className="pp-sticky-actions pp-sticky-actions--in-card">
						<span className="pp-muted">{ dirty.length ? `${ num( dirty.length ) } تنظیم تغییر کرده است.` : 'تغییری ذخیره‌نشده ندارید.' }</span>
						<div className="pp-sticky-actions__buttons">
							{ dirty.length > 0 && (
								<Button variant="tertiary" onClick={ () => setValues( data.settings ) } disabled={ saving }>انصراف</Button>
							) }
							<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving || 0 === dirty.length } __next40pxDefaultSize>
								ذخیره تنظیمات
							</Button>
						</div>
					</div>
				</section>
			) }

			{ 'ai' === current.key && <AiUsage state={ aiState } /> }
			{ values && 'performance' === current.key && <QueueStatus /> }
			{ values && 'advanced' === current.key && <SystemInfo /> }
			{ 'help' === current.key && <HelpTab /> }
		</Page>
	);
}

/**
 * Detected site stage and its recommended policy values.
 *
 * @param {Object}   props
 * @param {Function} props.onApplied Called with fresh settings after applying.
 */
function StageCard( { onApplied } ) {
	const [ info, setInfo ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		get( '/policy/stage' ).then( setInfo ).catch( () => {} );
	}, [] );
	if ( ! info ) {
		return null;
	}
	const LABELS = { new_per_month: 'مقاله جدید در ماه', publish_per_day: 'انتشار در روز', refresh_share: 'سهم بازنویسی (٪)', grace_days: 'مهلت جاافتادن (روز)' };
	const apply = () => {
		setBusy( true );
		post( '/policy/stage/apply' )
			.then( ( i ) => {
				setInfo( i );
				return get( '/settings' );
			} )
			.then( onApplied )
			.finally( () => setBusy( false ) );
	};
	return (
		<section className="pp-card pp-card--section pp-stage">
			<h2 className="pp-card__title">مرحله سایت: { info.label } { 'auto' === info.chosen && <span className="pp-badge">تشخیص خودکار</span> }</h2>
			<p className="pp-muted">{ info.reason }</p>
			<p>{ info.advice }</p>
			<table className="pp-table pp-table--compact">
				<thead><tr><th></th><th>پیشنهاد این مرحله</th><th>مقدار فعلی</th></tr></thead>
				<tbody>
					{ Object.keys( info.recommended ).map( ( k ) => (
						<tr key={ k } className={ info.recommended[ k ] === info.current[ k ] ? '' : 'is-diff' }>
							<th>{ LABELS[ k ] }</th>
							<td>{ num( info.recommended[ k ] ) }</td>
							<td>{ num( info.current[ k ] ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ info.matches ? (
				<p className="pp-ok">مقادیر فعلی با پیشنهاد این مرحله یکی است.</p>
			) : (
				<Button variant="secondary" onClick={ apply } isBusy={ busy } disabled={ busy }>اعمال مقادیر پیشنهادی این مرحله</Button>
			) }
		</section>
	);
}

/**
 * One settings control.
 *
 * @param {Object} props
 */
function SettingField( { field, value, defaultValue, limit, disabled, onChange } ) {
	const isDefault = JSON.stringify( value ) === JSON.stringify( defaultValue );
	let defaultText = '';
	if ( 'toggle' === field.type ) {
		defaultText = defaultValue ? 'روشن' : 'خاموش';
	} else if ( 'hour' === field.type ) {
		defaultText = `ساعت ${ num( defaultValue ) }`;
	} else if ( 'select' === field.type ) {
		defaultText = ( field.options.find( ( [ v ] ) => v === defaultValue ) || [ '', defaultValue ] )[ 1 ];
	} else if ( Array.isArray( defaultValue ) ) {
		defaultText = defaultValue.length ? defaultValue.join( '، ' ) : 'خالی';
	} else if ( undefined !== defaultValue ) {
		defaultText = `${ num( defaultValue ) }${ field.suffix ? ` ${ field.suffix }` : '' }`;
	}
	// The default is always visible next to the label, so changed values can be compared.
	const label = (
		<span className="pp-setting__label">
			{ field.label }
			{ defaultText && <span className={ `pp-default${ isDefault ? '' : ' is-changed' }` }>پیش‌فرض: { defaultText }</span> }
		</span>
	);
	const reset = ! isDefault && 'toggle' !== field.type && (
		<Button variant="link" className="pp-reset" onClick={ () => onChange( defaultValue ) }>
			بازگشت به پیش‌فرض ({ Array.isArray( defaultValue ) ? 'خالی' : num( defaultValue ) })
		</Button>
	);

	if ( 'toggle' === field.type ) {
		return (
			<div className={ `pp-setting${ field.danger && value ? ' pp-setting--danger' : '' }` }>
				<ToggleControl __nextHasNoMarginBottom label={ label } help={ field.help } checked={ !! value } onChange={ onChange } />
			</div>
		);
	}

	if ( 'select' === field.type ) {
		return (
			<div className="pp-setting">
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ label } help={ field.help } value={ String( value ) } options={ field.options.map( ( [ v, l ] ) => ( { value: v, label: l } ) ) } onChange={ onChange } />
			</div>
		);
	}

	if ( 'hour' === field.type ) {
		return (
			<div className="pp-setting">
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ label } help={ field.help } value={ String( value ) } options={ HOURS } onChange={ ( v ) => onChange( parseInt( v, 10 ) ) } />
			</div>
		);
	}

	if ( 'ids' === field.type ) {
		return (
			<div className="pp-setting">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ label }
					help={ field.help }
					dir="ltr"
					value={ ( value || [] ).join( ', ' ) }
					onChange={ ( v ) => onChange( v.split( /[\s,،]+/ ).map( ( x ) => parseInt( x, 10 ) ).filter( ( x ) => x > 0 ) ) }
				/>
				{ reset }
			</div>
		);
	}

	const help = [
		field.help,
		limit ? `بین ${ num( limit.min ) } تا ${ num( limit.max ) } ${ field.suffix || '' }.` : '',
		disabled ? 'در حالت هاست اشتراکی مقدار ثابت و کم استفاده می‌شود.' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className="pp-setting pp-setting--number">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				label={ label }
				help={ help }
				value={ String( value ) }
				min={ limit ? limit.min : undefined }
				max={ limit ? limit.max : undefined }
				disabled={ disabled }
				onChange={ ( v ) => onChange( '' === v ? 0 : parseInt( v, 10 ) ) }
			/>
			{ reset }
		</div>
	);
}

/**
 * Background queue health and "run now".
 */
function QueueStatus() {
	const [ queue, setQueue ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ ran, setRan ] = useState( null );

	useEffect( () => {
		get( '/queue' ).then( setQueue ).catch( () => {} );
	}, [] );

	const run = () => {
		setBusy( true );
		post( '/queue/run' )
			.then( ( d ) => {
				setQueue( d );
				setRan( d.processed );
			} )
			.catch( () => {} )
			.finally( () => setBusy( false ) );
	};

	if ( ! queue ) {
		return null;
	}

	return (
		<section className="pp-card pp-card--section">
			<h2 className="pp-card__title">وضعیت صف پس‌زمینه</h2>
			{ ! queue.healthy && (
				<Notice status="warning" isDismissible={ false }>
					{ num( queue.overdue ) } کار بیش از ۱۰ دقیقه منتظر مانده است{ queue.oldest_overdue ? ` (قدیمی‌ترین: ${ ago( queue.oldest_overdue ) })` : '' }. یعنی کارهای پس‌زمینه وردپرس (WP-Cron) روی این هاست اجرا نمی‌شوند. تا وقتی صفحه پایدارپرس باز است کارها انجام می‌شوند؛ برای اجرای خودکار، از پشتیبانی هاست بخواهید یک Cron Job برای wp-cron.php تنظیم کند.
				</Notice>
			) }
			{ queue.cron_disabled && queue.healthy && (
				<Notice status="info" isDismissible={ false }>
					WP-Cron در این سایت غیرفعال است (DISABLE_WP_CRON)؛ صف به Cron Job سرور وابسته است و در حال حاضر سالم کار می‌کند.
				</Notice>
			) }
			<dl className="pp-facts">
				<div><dt>کارهای در انتظار</dt><dd>{ num( queue.pending ) }</dd></div>
				<div><dt>کارهای عقب‌افتاده</dt><dd className={ queue.overdue ? 'pp-missing' : '' }>{ num( queue.overdue ) }</dd></div>
				<div><dt>خطا در ۷ روز اخیر</dt><dd className={ queue.failed_7d ? 'pp-missing' : '' }>{ num( queue.failed_7d ) }</dd></div>
				<div><dt>پاک‌سازی روزانه بعدی</dt><dd>{ queue.next_daily_at ? `${ date( queue.next_daily_at ) } ساعت ${ new Date( queue.next_daily_at ).toLocaleTimeString( 'fa-IR', { hour: '2-digit', minute: '2-digit' } ) }` : '—' }</dd></div>
			</dl>
			<div className="pp-form__actions">
				{ null !== ran && <span className="pp-muted">{ ran ? `${ num( ran ) } کار انجام شد.` : 'کار عقب‌افتاده‌ای نبود.' }</span> }
				<Button variant="secondary" onClick={ run } isBusy={ busy } disabled={ busy }>اجرای کارهای در انتظار</Button>
			</div>
		</section>
	);
}

/**
 * System info for support (copyable, no personal data).
 */
function SystemInfo() {
	const [ info, setInfo ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		get( '/system' ).then( setInfo ).catch( () => {} );
	}, [] );

	if ( ! info ) {
		return null;
	}

	const rows = [
		[ 'نسخه پایدارپرس', info.plugin_version ],
		[ 'نسخه پایگاه داده افزونه', info.db_version ],
		[ 'وردپرس', info.wordpress ],
		[ 'PHP', info.php ],
		[ 'پایگاه داده', info.database ],
		[ 'محدودیت حافظه PHP', info.memory_limit ],
		[ 'حداکثر زمان اجرا', info.max_execution ? `${ info.max_execution } s` : 'نامحدود' ],
		[ 'پوسته', `${ info.theme }${ info.block_theme ? ' (بلوکی)' : '' }` ],
		[ 'افزونه سئو', info.seo_plugin ],
		[ 'ووکامرس', info.woocommerce ? 'فعال' : 'غیرفعال' ],
		[ 'Action Scheduler', info.action_scheduler ],
		[ 'WP-Cron', info.wp_cron_disabled ? 'غیرفعال' : 'فعال' ],
		[ 'منطقه زمانی', info.timezone ],
		[ 'ردیف‌های جدول‌ها', Object.entries( info.table_rows ).map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '، ' ) ],
	];

	const copy = () => {
		const text = rows.map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '\n' );
		const done = () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		};
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( done ).catch( () => {} );
		}
	};

	return (
		<section className="pp-card pp-card--section">
			<h2 className="pp-card__title">اطلاعات سیستم</h2>
			<p className="pp-help-text">هنگام درخواست پشتیبانی این اطلاعات را بفرستید. ایمیل، نام کاربری و کلیدها در آن نیست.</p>
			<table className="pp-table pp-table--kv">
				<tbody>
					{ rows.map( ( [ k, v ] ) => (
						<tr key={ k }><th scope="row">{ k }</th><td><bdi dir="ltr">{ String( v ) }</bdi></td></tr>
					) ) }
				</tbody>
			</table>
			<div className="pp-form__actions">
				<Button variant="secondary" onClick={ copy }>{ copied ? 'کپی شد' : 'کپی اطلاعات' }</Button>
			</div>
		</section>
	);
}

/**
 * Help tab: reset help boxes, glossary.
 */
function HelpTab() {
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( false );
	const [ query, setQuery ] = useState( '' );

	const reset = () => {
		setBusy( true );
		post( '/help/reset' )
			.then( () => {
				config.dismissedHelp = [];
				setDone( true );
			} )
			.catch( () => {} )
			.finally( () => setBusy( false ) );
	};

	const items = glossary.filter( ( g ) => ! query || g.term.includes( query ) || g.definition.includes( query ) );

	return (
		<>
			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">راهنماهای صفحات</h2>
				<p className="pp-help-text">راهنماهایی که با «متوجه شدم» بسته‌اید، فقط برای حساب شما پنهان شده‌اند. با این دکمه همه دوباره نمایش داده می‌شوند.</p>
				<div className="pp-form__actions pp-form__actions--start">
					<Button variant="secondary" onClick={ reset } isBusy={ busy } disabled={ busy || done }>
						{ done ? 'همه راهنماها دوباره نمایش داده می‌شوند' : 'نمایش دوباره همه راهنماها' }
					</Button>
					<Button variant="tertiary" href="#/welcome">صفحه شروع کار</Button>
				</div>
			</section>
			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">واژه‌نامه</h2>
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label="جست‌وجو در واژه‌نامه" hideLabelFromVision placeholder="جست‌وجو در واژه‌نامه…" value={ query } onChange={ setQuery } />
				<dl className="pp-glossary">
					{ items.map( ( g ) => (
						<div key={ g.term }>
							<dt>{ g.term }</dt>
							<dd>{ g.definition }</dd>
						</div>
					) ) }
				</dl>
				{ 0 === items.length && <p className="pp-muted">موردی پیدا نشد.</p> }
			</section>
		</>
	);
}

