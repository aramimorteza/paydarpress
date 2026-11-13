import { useEffect, useState } from '@wordpress/element';
import { get } from '../lib/api';
import { num } from '../lib/format';
import { Button, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import TaxMapEditor from './TaxMapEditor';

/** Field name → key of the industry example used as placeholder. */
const EXAMPLE_KEYS = {
	business_offer: 'offer',
	primary_audience: 'audience',
	audience_problems: 'problems',
	forbidden_topics: 'forbidden',
};

/**
 * Placeholder for a field: fixed example from the definition, the industry's example, or
 * the industry's default value (goal, rules, patterns).
 *
 * @param {string} name     Field name.
 * @param {Object} def      Definition.
 * @param {Object} industry Industry entry (with examples).
 */
function example( name, def, industry ) {
	if ( def.example ) {
		return def.example;
	}
	if ( ! industry ) {
		return '';
	}
	if ( EXAMPLE_KEYS[ name ] ) {
		return `مثال: ${ industry.ex[ EXAMPLE_KEYS[ name ] ] }`;
	}
	const fromDefaults = { primary_content_goal: industry.goal, industry_rules: industry.rules, primary_keyword_patterns: industry.patterns }[ name ];
	return fromDefaults ? `مثال: ${ fromDefaults }` : '';
}

/**
 * One-line help plus an expandable "how to fill" guide.
 *
 * @param {Object} props
 * @param {string} props.help  Short help.
 * @param {string} props.guide Longer guide.
 * @param {string} props.note  Extra note (e.g. values suggested from the industry).
 */
export function FieldHelp( { help, guide, note } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<div className="pp-field-help">
			{ help && <span>{ help } </span> }
			{ guide && (
				<button type="button" className="pp-field-help__toggle" aria-expanded={ open } onClick={ () => setOpen( ! open ) }>
					{ open ? 'بستن راهنما' : 'چطور پر کنم؟' }
				</button>
			) }
			{ open && guide && <p className="pp-field-help__guide">{ guide }</p> }
			{ note && <p className="pp-field-help__note">{ note }</p> }
		</div>
	);
}

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
 * @param {Object}   props.industry Selected (or default) industry with examples.
 * @param {string}   props.note     Extra note under the field.
 */
export function ProfileField( { name, def, value, field, choices, onChange, industry, note } ) {
	const set = ( v ) => onChange( name, v );
	const readOnly = 'identity' === def.group;
	const label = (
		<span className="pp-field__label">
			{ def.label } { def.required && <span className="pp-required" title="الزامی">*</span> } <SourceBadge field={ field } />
		</span>
	);

	const help = <FieldHelp help={ def.help } guide={ def.guide } note={ note } />;
	const placeholder = example( name, def, industry );

	if ( readOnly ) {
		return (
			<div className="pp-field pp-field--readonly">
				{ label }
				<div className="pp-field__value">{ 'bool' === def.type ? ( value ? 'بله' : 'خیر' ) : value || '—' }</div>
				{ def.help && <div className="pp-field-help"><span>{ def.help }</span></div> }
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
						help={ help }
						value={ value || '' }
						options={ [ { value: '', label: 'انتخاب کنید…' }, ...Object.entries( def.options || {} ).map( ( [ v, l ] ) => ( { value: v, label: l } ) ) ] }
						onChange={ set }
					/>
				</div>
			);
		case 'textarea':
			return (
				<div className="pp-field">
					<TextareaControl __nextHasNoMarginBottom label={ label } help={ help } placeholder={ placeholder } value={ value || '' } onChange={ set } rows={ 3 } />
				</div>
			);
		case 'key_pages':
			return <KeyPagesEditor label={ label } help={ help } ex={ industry ? industry.ex : {} } value={ value || [] } pages={ choices.pages || [] } categories={ choices.categories || [] } onChange={ set } />;
		case 'cta_options':
			return <CtaEditor label={ label } help={ help } ex={ industry ? industry.ex : {} } value={ value || [] } onChange={ set } />;
		case 'categories':
			// Self-saving map of categories and tags (posts and products); see TaxMapEditor.
			return (
				<fieldset className="pp-field">
					<legend>{ label }</legend>
					<div className="pp-help-text">{ help }</div>
					<TaxMapEditor canEdit={ !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage ) } />
				</fieldset>
			);
		default:
			return (
				<div className="pp-field">
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ label } help={ help } placeholder={ placeholder } value={ value || '' } onChange={ set } />
				</div>
			);
	}
}

const PAGE_TYPES = [ [ 'service', 'صفحه خدمت' ], [ 'landing', 'صفحه فرود' ], [ 'product', 'محصول اصلی' ], [ 'category', 'دسته اصلی' ], [ 'contact', 'تماس' ], [ 'about', 'درباره ما' ], [ 'other', 'سایر' ] ];

const ANCHOR_TYPES = { exact: 'دقیق', partial: 'بخشی', branded: 'برند', generic: 'بی‌معنی', other: 'سایر' };

/**
 * Category multi-select: checkboxes with a filter for long lists.
 */
function CategoryPicker( { categories, value, onChange } ) {
	const [ filter, setFilter ] = useState( '' );
	const selected = new Set( ( value || [] ).map( Number ) );
	const toggle = ( id ) => {
		const next = new Set( selected );
		if ( next.has( id ) ) {
			next.delete( id );
		} else {
			next.add( id );
		}
		onChange( [ ...next ] );
	};
	const shown = categories.filter( ( c ) => ! filter || c.name.includes( filter ) );

	return (
		<div className="pp-catpick">
			<div className="pp-catpick__label">از مقالات کدام دسته‌ها به این صفحه لینک داده شود؟</div>
			<div className="pp-help-text">هیچ دسته‌ای انتخاب نشود یعنی «همه مقالات». پرامپت‌های هوش مصنوعی و صف لینک‌ها از این انتخاب پیروی می‌کنند.</div>
			{ categories.length > 12 && (
				<TextControl __nextHasNoMarginBottom placeholder="جست‌وجوی دسته…" value={ filter } onChange={ setFilter } />
			) }
			<div className="pp-catpick__list">
				{ shown.map( ( c ) => (
					<label key={ c.id } className={ `pp-chip${ selected.has( c.id ) ? ' is-on' : '' }` }>
						<input type="checkbox" checked={ selected.has( c.id ) } onChange={ () => toggle( c.id ) } />
						{ c.name } <small>({ c.count })</small>
					</label>
				) ) }
			</div>
			<div className="pp-catpick__summary">{ selected.size ? `${ selected.size } دسته انتخاب شده` : 'همه مقالات' }</div>
		</div>
	);
}

/**
 * Anchor distribution of one key page (from the last scan).
 */
function AnchorStats( { stats } ) {
	if ( ! stats ) {
		return null;
	}
	if ( ! stats.total ) {
		return <div className="pp-anchorstats pp-help-text">هنوز هیچ مقاله‌ای به این صفحه لینک نداده است.</div>;
	}
	const pct = Math.round( stats.exact_share * 100 );
	return (
		<div className={ `pp-anchorstats${ stats.over_cap ? ' is-warn' : '' }` }>
			<div className="pp-anchorstats__bar" aria-hidden="true">
				{ Object.keys( ANCHOR_TYPES ).map( ( k ) => stats.counts[ k ] > 0 && (
					<span key={ k } className={ `pp-anchorstats__seg is-${ k }` } style={ { flexGrow: stats.counts[ k ] } } title={ `${ ANCHOR_TYPES[ k ] }: ${ stats.counts[ k ] }` } />
				) ) }
			</div>
			<div className="pp-anchorstats__legend">
				{ stats.total } لینک ورودی — { Object.keys( ANCHOR_TYPES ).filter( ( k ) => stats.counts[ k ] > 0 ).map( ( k ) => `${ ANCHOR_TYPES[ k ] } ${ stats.counts[ k ] }` ).join( '، ' ) }
				{ ' ' }· متن دقیق: <strong>{ pct }٪</strong> (سقف ۳۰٪)
			</div>
			{ stats.warnings.map( ( w, i ) => <div key={ i } className="pp-anchorstats__warn">⚠ { w }</div> ) }
		</div>
	);
}

/**
 * Key pages: list with priority, link categories, anchors and anchor statistics.
 */
function KeyPagesEditor( { label, help, ex, value, pages, categories, onChange } ) {
	const [ stats, setStats ] = useState( {} );
	const [ suggestions, setSuggestions ] = useState( {} );
	const update = ( index, patch ) => onChange( value.map( ( p, i ) => ( i === index ? { ...p, ...patch } : p ) ) );
	const remove = ( index ) => onChange( value.filter( ( _, i ) => i !== index ) );
	const available = pages.filter( ( p ) => ! value.some( ( v ) => v.id === p.id ) );

	const [ clusters, setClusters ] = useState( {} );
	const [ types, setTypes ] = useState( {} );
	useEffect( () => {
		get( '/profile/key-pages/stats' ).then( ( r ) => setStats( Object.fromEntries( ( r.pages || [] ).map( ( s ) => [ s.id, s ] ) ) ) ).catch( () => {} );
		get( '/structure' ).then( ( r ) => {
			setClusters( Object.fromEntries( ( r.clusters || [] ).map( ( c ) => [ c.id, c ] ) ) );
			setTypes( r.types || {} );
		} ).catch( () => {} );
	}, [] );

	const suggest = ( id ) => {
		setSuggestions( { ...suggestions, [ id ]: 'loading' } );
		get( `/profile/key-pages/${ id }/anchors` )
			.then( ( r ) => setSuggestions( ( s ) => ( { ...s, [ id ]: r.anchors || [] } ) ) )
			.catch( () => setSuggestions( ( s ) => ( { ...s, [ id ]: [] } ) ) );
	};

	return (
		<fieldset className="pp-field pp-repeater">
			<legend>{ label }</legend>
			<div className="pp-help-text">{ help } هر مقاله حداکثر به ۲ صفحه کلیدی لینک می‌دهد.</div>
			{ value.map( ( page, index ) => {
				const anchors = page.anchors || [];
				const list = suggestions[ page.id ];
				return (
					<div key={ page.id } className="pp-repeater__item">
						<div className="pp-repeater__head">
							<strong>{ page.name || ( pages.find( ( p ) => p.id === page.id ) || {} ).title }</strong>
							{ 'menu' === page.detected_by && <span className="pp-badge">در منو</span> }
							{ 'gsc' === page.detected_by && <span className="pp-badge">از سرچ کنسول</span> }
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
						<div className="pp-keypage-meta">
							<SelectControl
								__nextHasNoMarginBottom
								label="نوع صفحه"
								value={ page.page_type || types[ page.id ] || 'service' }
								options={ PAGE_TYPES.map( ( [ v, l ] ) => ( { value: v, label: l } ) ) }
								onChange={ ( v ) => update( index, { page_type: v } ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								label="کلمه کلیدی اصلی این صفحه"
								help="عبارتی که این صفحه باید در گوگل برایش اول باشد. مقاله‌ها این عبارت را هدف نمی‌گیرند؛ پشتیبان این صفحه‌اند."
								value={ page.main_keyword || '' }
								onChange={ ( v ) => update( index, { main_keyword: v } ) }
							/>
						</div>
						{ clusters[ page.id ] && (
							<div className={ `pp-cluster${ clusters[ page.id ].gap > 0 ? ' is-short' : '' }` }>
								مقاله پشتیبان: { num( clusters[ page.id ].supports ) } مقاله به این صفحه لینک می‌دهند (هدف { num( clusters[ page.id ].target ) }).
								{ clusters[ page.id ].gap > 0 && ' کمبود در «کارهای این هفته» پیشنهاد می‌شود.' }
							</div>
						) }
						<CategoryPicker categories={ categories } value={ page.link_categories } onChange={ ( v ) => update( index, { link_categories: v } ) } />
						{ page.link_when && ! ( page.link_categories || [] ).length && (
							<div className="pp-help-text">توضیح قبلی شما: «{ page.link_when }» — دسته‌های مربوط را بالا انتخاب کنید.</div>
						) }
						<TextControl
							__nextHasNoMarginBottom
							label="متن‌های لینک (انکرتکست) — با ویرگول جدا کنید"
							help="۳ تا ۶ عبارت متنوع. حداکثر ۳۰٪ لینک‌ها باید دقیقاً عبارت اصلی صفحه باشند؛ بقیه بخشی، توصیفی یا با نام برند. «اینجا کلیک کنید» ننویسید."
							placeholder={ ex.anchors ? `مثال: ${ ex.anchors }` : '' }
							value={ anchors.join( '، ' ) }
							onChange={ ( v ) => update( index, { anchors: v.split( /[،,]/ ).map( ( a ) => a.trim() ).filter( Boolean ) } ) }
						/>
						<div className="pp-anchor-suggest">
							<Button variant="secondary" size="small" onClick={ () => suggest( page.id ) } isBusy={ 'loading' === list } disabled={ 'loading' === list }>
								پیشنهاد متن لینک
							</Button>
							{ Array.isArray( list ) && list.map( ( a ) => {
								const added = anchors.includes( a.anchor );
								return (
									<button
										type="button"
										key={ a.anchor }
										className={ `pp-chip${ added ? ' is-on' : '' }` }
										disabled={ added || anchors.length >= 6 }
										onClick={ () => update( index, { anchors: [ ...anchors, a.anchor ] } ) }
									>
										{ added ? '✓ ' : '+ ' }{ a.anchor } <small>({ ANCHOR_TYPES[ a.type ] || a.type })</small>
									</button>
								);
							} ) }
						</div>
						<AnchorStats stats={ stats[ page.id ] } />
					</div>
				);
			} ) }
			{ available.length > 0 && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label="افزودن صفحه کلیدی"
					value=""
					options={ [ { value: '', label: 'انتخاب صفحه…' }, ...available.map( ( p ) => ( { value: String( p.id ), label: `${ p.title }${ 'post' === p.type ? ' (نوشته)' : '' }` } ) ) ] }
					onChange={ ( id ) => id && onChange( [ ...value, { id: Number( id ), name: ( pages.find( ( p ) => String( p.id ) === id ) || {} ).title, priority: 'normal', link_categories: [], anchors: [], detected_by: 'manual' } ] ) }
				/>
			) }
		</fieldset>
	);
}

/**
 * CTA options.
 */
function CtaEditor( { label, help, ex, value, onChange } ) {
	const update = ( index, patch ) => onChange( value.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) ) );

	return (
		<fieldset className="pp-field pp-repeater">
			<legend>{ label }</legend>
			<div className="pp-help-text">{ help }</div>
			{ value.map( ( cta, index ) => (
				<div key={ index } className="pp-repeater__item pp-repeater__item--inline">
					<TextControl __nextHasNoMarginBottom label="عنوان" help="فعل کوتاه و مشخص." placeholder={ ex.cta ? `مثال: ${ ex.cta }` : '' } value={ cta.name || '' } onChange={ ( v ) => update( index, { name: v } ) } />
					<TextControl __nextHasNoMarginBottom label="آدرس" help="آدرس کامل صفحه مقصد در همین سایت." placeholder="https://" dir="ltr" value={ cta.url || '' } onChange={ ( v ) => update( index, { url: v } ) } />
					<TextControl __nextHasNoMarginBottom label="چه زمانی؟" help="در انتهای کدام مقالات پیشنهاد شود." placeholder={ ex.cta_when ? `مثال: ${ ex.cta_when }` : '' } value={ cta.use_when || '' } onChange={ ( v ) => update( index, { use_when: v } ) } />
					<Button variant="tertiary" isDestructive size="small" onClick={ () => onChange( value.filter( ( _, i ) => i !== index ) ) }>حذف</Button>
				</div>
			) ) }
			<Button variant="secondary" size="small" onClick={ () => onChange( [ ...value, { name: '', url: '', use_when: '' } ] ) }>
				افزودن دعوت به اقدام
			</Button>
		</fieldset>
	);
}

