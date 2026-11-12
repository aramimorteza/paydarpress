import { Button, SelectControl, TextControl, TextareaControl } from '@wordpress/components';

const SOURCE = {
	detected: [ 'info', 'خودکار' ],
	inferred: [ 'warning', 'پیشنهادی — بررسی و تأیید کنید' ],
	manual: [ 'success', 'تأییدشده' ],
};

/**
 * Badge showing where a value came from.
 *
 * @param {Object} props
 * @param {Object} props.field Field state {source, confirmed}.
 */
export function SourceBadge( { field } ) {
	if ( ! field ) {
		return null;
	}
	const key = field.confirmed && 'inferred' === field.source ? 'manual' : field.source;
	const [ cls, label ] = SOURCE[ key ] || SOURCE.manual;
	if ( 'manual' === key && ! field.confirmed ) {
		return null;
	}
	return <span className={ `pp-badge pp-badge--${ cls }` }>{ label }</span>;
}

/**
 * Renders one profile field.
 *
 * @param {Object}   props
 * @param {string}   props.name     Field name.
 * @param {Object}   props.def      Definition from the API.
 * @param {*}        props.value    Current (edited) value.
 * @param {Object}   props.field    Stored field state.
 * @param {Object}   props.choices  Choices (pages).
 * @param {Function} props.onChange Change handler (name, value).
 */
export function ProfileField( { name, def, value, field, choices, onChange } ) {
	const set = ( v ) => onChange( name, v );
	const readOnly = 'identity' === def.group;
	const label = (
		<span className="pp-field__label">
			{ def.label } { def.required && <span className="pp-required" title="الزامی">*</span> } <SourceBadge field={ field } />
		</span>
	);

	if ( readOnly ) {
		return (
			<div className="pp-field pp-field--readonly">
				{ label }
				<div className="pp-field__value">{ 'bool' === def.type ? ( value ? 'بله' : 'خیر' ) : value || '—' }</div>
			</div>
		);
	}

	switch ( def.type ) {
		case 'select':
			return (
				<div className="pp-field">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ label }
						help={ def.help }
						value={ value || '' }
						options={ [ { value: '', label: 'انتخاب کنید…' }, ...Object.entries( def.options || {} ).map( ( [ v, l ] ) => ( { value: v, label: l } ) ) ] }
						onChange={ set }
					/>
				</div>
			);
		case 'textarea':
			return (
				<div className="pp-field">
					<TextareaControl __nextHasNoMarginBottom label={ label } help={ def.help } value={ value || '' } onChange={ set } rows={ 3 } />
				</div>
			);
		case 'key_pages':
			return <KeyPagesEditor label={ label } help={ def.help } value={ value || [] } pages={ choices.pages || [] } onChange={ set } />;
		case 'cta_options':
			return <CtaEditor label={ label } help={ def.help } value={ value || [] } onChange={ set } />;
		case 'categories':
			return <CategoriesEditor label={ label } help={ def.help } value={ value || [] } onChange={ set } />;
		default:
			return (
				<div className="pp-field">
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ label } help={ def.help } value={ value || '' } onChange={ set } />
				</div>
			);
	}
}

/**
 * Key pages: list with priority, "link when" and anchors.
 */
function KeyPagesEditor( { label, help, value, pages, onChange } ) {
	const update = ( index, patch ) => onChange( value.map( ( p, i ) => ( i === index ? { ...p, ...patch } : p ) ) );
	const remove = ( index ) => onChange( value.filter( ( _, i ) => i !== index ) );
	const available = pages.filter( ( p ) => ! value.some( ( v ) => v.id === p.id ) );

	return (
		<fieldset className="pp-field pp-repeater">
			<legend>{ label }</legend>
			<p className="pp-help-text">{ help }</p>
			{ value.map( ( page, index ) => (
				<div key={ page.id } className="pp-repeater__item">
					<div className="pp-repeater__head">
						<strong>{ page.name || ( pages.find( ( p ) => p.id === page.id ) || {} ).title }</strong>
						{ 'menu' === page.detected_by && <span className="pp-badge">در منو</span> }
						<SelectControl
							__nextHasNoMarginBottom
							label="اولویت"
							hideLabelFromVision
							value={ page.priority || 'normal' }
							options={ [ { value: 'high', label: 'اولویت بالا' }, { value: 'normal', label: 'اولویت عادی' } ] }
							onChange={ ( v ) => update( index, { priority: v } ) }
						/>
						<Button variant="tertiary" isDestructive size="small" onClick={ () => remove( index ) }>حذف</Button>
					</div>
					<TextControl __nextHasNoMarginBottom label="چه زمانی لینک داده شود؟" placeholder="مثال: در همه مقالات خانواده و کیفری" value={ page.link_when || '' } onChange={ ( v ) => update( index, { link_when: v } ) } />
					<TextControl __nextHasNoMarginBottom label="انکرتکست‌های مجاز (با ویرگول جدا کنید)" placeholder="مثال: مشاوره حقوقی در کرج، مشاوره با وکیل" value={ ( page.anchors || [] ).join( '، ' ) } onChange={ ( v ) => update( index, { anchors: v.split( /[،,]/ ).map( ( a ) => a.trim() ).filter( Boolean ) } ) } />
				</div>
			) ) }
			{ available.length > 0 && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label="افزودن صفحه کلیدی"
					value=""
					options={ [ { value: '', label: 'انتخاب صفحه…' }, ...available.map( ( p ) => ( { value: String( p.id ), label: `${ p.title }${ 'post' === p.type ? ' (نوشته)' : '' }` } ) ) ] }
					onChange={ ( id ) => id && onChange( [ ...value, { id: Number( id ), name: ( pages.find( ( p ) => String( p.id ) === id ) || {} ).title, priority: 'normal', link_when: '', anchors: [], detected_by: 'manual' } ] ) }
				/>
			) }
		</fieldset>
	);
}

/**
 * CTA options.
 */
function CtaEditor( { label, help, value, onChange } ) {
	const update = ( index, patch ) => onChange( value.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) ) );

	return (
		<fieldset className="pp-field pp-repeater">
			<legend>{ label }</legend>
			<p className="pp-help-text">{ help }</p>
			{ value.map( ( cta, index ) => (
				<div key={ index } className="pp-repeater__item pp-repeater__item--inline">
					<TextControl __nextHasNoMarginBottom label="عنوان" value={ cta.name || '' } onChange={ ( v ) => update( index, { name: v } ) } />
					<TextControl __nextHasNoMarginBottom label="آدرس" dir="ltr" value={ cta.url || '' } onChange={ ( v ) => update( index, { url: v } ) } />
					<TextControl __nextHasNoMarginBottom label="چه زمانی؟" value={ cta.use_when || '' } onChange={ ( v ) => update( index, { use_when: v } ) } />
					<Button variant="tertiary" isDestructive size="small" onClick={ () => onChange( value.filter( ( _, i ) => i !== index ) ) }>حذف</Button>
				</div>
			) ) }
			<Button variant="secondary" size="small" onClick={ () => onChange( [ ...value, { name: '', url: '', use_when: '' } ] ) }>
				افزودن دعوت به اقدام
			</Button>
		</fieldset>
	);
}

/**
 * Category scopes.
 */
function CategoriesEditor( { label, help, value, onChange } ) {
	const update = ( index, patch ) => onChange( value.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) ) );

	return (
		<fieldset className="pp-field pp-repeater">
			<legend>{ label }</legend>
			<p className="pp-help-text">{ help }</p>
			{ 0 === value.length && <p className="pp-muted">دسته‌بندی دارای مطلب پیدا نشد. پس از اسکن، دسته‌ها اینجا نمایش داده می‌شوند.</p> }
			{ value.map( ( cat, index ) => (
				<div key={ cat.id } className="pp-repeater__item">
					<div className="pp-repeater__head">
						<strong>{ cat.name }</strong>
						{ undefined !== cat.count && <span className="pp-muted">{ Number( cat.count ).toLocaleString( 'fa-IR' ) } مطلب</span> }
					</div>
					<TextareaControl __nextHasNoMarginBottom label="این دسته چه موضوعاتی را پوشش می‌دهد؟" placeholder="مثال: طلاق توافقی و یک‌طرفه، مهریه، نفقه، حضانت و ملاقات فرزند" rows={ 2 } value={ cat.scope || '' } onChange={ ( v ) => update( index, { scope: v } ) } />
					<TextControl __nextHasNoMarginBottom label="الگوهای جستجوی رایج (اختیاری)" placeholder="مثال: هزینه [X] در کرج، [X] چیست" value={ cat.search_patterns || '' } onChange={ ( v ) => update( index, { search_patterns: v } ) } />
				</div>
			) ) }
		</fieldset>
	);
}
