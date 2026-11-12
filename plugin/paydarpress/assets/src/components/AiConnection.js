import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { get, post, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

/**
 * AI connection form: service, address, key, model, connection test.
 *
 * @param {Object}   props
 * @param {Function} props.onChange Called with the new state after a save/test.
 */
export function AiConnection( { onChange } ) {
	const [ state, setState ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ models, setModels ] = useState( [] );

	const apply = ( s ) => {
		setState( s );
		setForm( { provider: s.connection.provider, base_url: s.connection.base_url, model: s.connection.model, key: '' } );
		if ( onChange ) {
			onChange( s );
		}
	};

	useEffect( () => {
		get( '/ai' ).then( apply ).catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) );
	}, [] );

	if ( ! state || ! form ) {
		return notice ? <Notice status="error" isDismissible={ false }>{ notice.text }</Notice> : <Spinner />;
	}

	const c = state.connection;
	const set = ( patch ) => setForm( { ...form, ...patch } );
	const dirty = form.provider !== c.provider || form.base_url !== c.base_url || form.model !== c.model || '' !== form.key;

	const run = ( name, request, success ) => {
		setBusy( name );
		setNotice( null );
		return request()
			.then( success )
			.catch( ( e ) => setNotice( { status: 'error', text: errorMessage( e ) } ) )
			.finally( () => setBusy( '' ) );
	};

	const save = () => run( 'save', () => post( '/ai', form ), ( s ) => {
		apply( s );
		setNotice( { status: 'success', text: 'ذخیره شد. برای اطمینان «آزمایش اتصال» را بزنید.' } );
	} );
	const test = () => run( 'test', () => post( '/ai/test' ), ( r ) => {
		apply( r.state );
		setNotice( { status: 'success', text: `اتصال برقرار است. پاسخ مدل ${ r.model }: «${ r.reply }»` } );
	} );
	const listModels = () => run( 'models', () => post( '/ai/models' ), ( r ) => {
		setModels( r.models || [] );
		setNotice( { status: 'info', text: r.models && r.models.length ? `${ num( r.models.length ) } مدل پیدا شد؛ از فهرست انتخاب کنید.` : 'سرویس فهرست مدل‌ها را برنگرداند؛ نام مدل را دستی بنویسید.' } );
	} );
	const clearKey = () => run( 'clear', () => post( '/ai/clear-key' ), ( s ) => {
		apply( s );
		setNotice( { status: 'success', text: 'کلید حذف شد.' } );
	} );

	return (
		<section className="pp-card pp-card--section pp-ai-conn">
			<h2 className="pp-card__title">
				اتصال هوش مصنوعی{ ' ' }
				{ c.configured ? <span className="pp-badge pp-badge--success">تنظیم شده</span> : <span className="pp-badge pp-badge--warning">تنظیم نشده</span> }
			</h2>
			<p className="pp-help-text">
				برای «تولید خودکار» لازم است. بدون آن، حالت دستی (ContentMaster و ورود فایل) کامل کار می‌کند. کلید رمزنگاری‌شده ذخیره می‌شود، فقط مدیر سایت آن را تنظیم می‌کند و هرگز در خروجی JSON، گزارش فعالیت یا مرورگر نمایش داده نمی‌شود.
			</p>
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			{ c.key_unreadable && (
				<Notice status="warning" isDismissible={ false }>کلید ذخیره‌شده دیگر خوانده نمی‌شود (احتمالاً کلیدهای امنیتی wp-config.php عوض شده‌اند، مثلاً پس از انتقال سایت). کلید را دوباره وارد کنید.</Notice>
			) }

			<div className="pp-form">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label="سرویس"
					value={ form.provider }
					options={ Object.entries( c.providers ).map( ( [ value, label ] ) => ( { value, label } ) ) }
					onChange={ ( v ) => set( { provider: v } ) }
					help="«سازگار با OpenAI» برای هر سرویسی است که API شبیه OpenAI دارد: خود OpenAI، OpenRouter، DeepSeek، و درگاه‌های ایرانی که از داخل کشور در دسترس‌اند."
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label="آدرس سرویس (Base URL)"
					value={ form.base_url }
					placeholder={ c.default_urls[ form.provider ] }
					onChange={ ( v ) => set( { base_url: v } ) }
					help={ `خالی بگذارید تا ${ c.default_urls[ form.provider ] } استفاده شود. برای درگاه‌ها و OpenRouter، آدرسی را که سرویس داده بنویسید (معمولاً به /v1 ختم می‌شود).` }
					dir="ltr"
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="password"
					autoComplete="off"
					label="کلید API"
					value={ form.key }
					placeholder={ c.has_key ? `ذخیره شده (${ c.key_hint }) — برای تغییر، کلید جدید را بچسبانید` : 'کلید را از پنل سرویس کپی کنید' }
					onChange={ ( v ) => set( { key: v } ) }
					dir="ltr"
				/>
				<div className="pp-ai-conn__model">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label="مدل"
						value={ form.model }
						onChange={ ( v ) => set( { model: v } ) }
						list="pp-ai-models"
						dir="ltr"
						help="نام دقیق مدل در سرویس. برای مقاله فارسی بلند، یک مدل قوی (نه نسخه mini یا haiku) نتیجه بهتری می‌دهد."
					/>
					<datalist id="pp-ai-models">{ models.map( ( m ) => <option key={ m } value={ m } /> ) }</datalist>
					<Button variant="secondary" onClick={ listModels } isBusy={ 'models' === busy } disabled={ !! busy || ! c.has_key }>دریافت فهرست مدل‌ها</Button>
				</div>
			</div>

			<div className="pp-actions-row">
				<Button variant="primary" onClick={ save } isBusy={ 'save' === busy } disabled={ !! busy || ! dirty } __next40pxDefaultSize>ذخیره اتصال</Button>
				<Button variant="secondary" onClick={ test } isBusy={ 'test' === busy } disabled={ !! busy || dirty || ! c.configured } __next40pxDefaultSize>آزمایش اتصال</Button>
				{ c.has_key && <Button variant="tertiary" isDestructive onClick={ clearKey } disabled={ !! busy }>حذف کلید</Button> }
				{ dirty && <span className="pp-muted">ابتدا ذخیره کنید، سپس آزمایش.</span> }
			</div>
		</section>
	);
}

const TOKENS = ( n ) => ( n >= 1000 ? `${ num( Math.round( n / 100 ) / 10 ) } هزار` : num( n ) );

/**
 * This month's usage against the limits, plus recent requests.
 *
 * @param {Object} props
 * @param {Object} props.state State from /ai.
 */
export function AiUsage( { state } ) {
	if ( ! state ) {
		return null;
	}
	const { month, limits, recent } = state;
	const bar = ( used, max ) => ( max > 0 ? Math.min( 100, Math.round( ( used / max ) * 100 ) ) : 100 );

	return (
		<section className="pp-card pp-card--section">
			<h2 className="pp-card__title">مصرف این ماه</h2>
			<div className="pp-ai-usage">
				{ [
					[ 'مقاله (نوشتن و بازنویسی)', month.articles, limits.articles ],
					[ 'همه درخواست‌ها', month.requests, limits.requests ],
				].map( ( [ label, used, max ] ) => (
					<div key={ label } className="pp-ai-usage__item">
						<div className="pp-ai-usage__head"><span>{ label }</span><strong>{ num( used ) } از { 0 === max ? 'خاموش' : num( max ) }</strong></div>
						<div className={ `pp-progress${ bar( used, max ) >= 90 ? ' is-warn' : '' }` }><span style={ { width: `${ bar( used, max ) }%` } } /></div>
					</div>
				) ) }
				<div className="pp-ai-usage__item pp-muted">
					توکن ورودی { TOKENS( month.input_tokens ) } · توکن خروجی { TOKENS( month.output_tokens ) }
					{ month.errors > 0 && ` · ${ num( month.errors ) } درخواست ناموفق` }
					<div>هزینه واقعی را در پنل سرویس ببینید؛ قیمت هر مدل متفاوت است.</div>
				</div>
			</div>
			{ recent.length > 0 && (
				<table className="pp-table pp-table--compact">
					<thead>
						<tr><th>کار</th><th>مدل</th><th className="pp-num">توکن</th><th className="pp-num">زمان</th><th>وضعیت</th><th>کی</th></tr>
					</thead>
					<tbody>
						{ recent.map( ( r ) => (
							<tr key={ r.id }>
								<td>{ r.task_label }{ r.object_title && <div className="pp-muted">{ r.object_title }</div> }</td>
								<td dir="ltr">{ r.model }</td>
								<td className="pp-num">{ num( r.input_tokens + r.output_tokens ) }</td>
								<td className="pp-num">{ num( Math.round( r.duration_ms / 100 ) / 10 ) } ث</td>
								<td>{ 'ok' === r.status ? <span className="pp-badge pp-badge--success">موفق</span> : <span className="pp-badge pp-badge--danger" title={ r.error }>ناموفق</span> }{ r.error && <div className="pp-muted pp-ai-usage__err">{ r.error }</div> }</td>
								<td>{ ago( r.created_at ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}
