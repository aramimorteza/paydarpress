import { Fragment, useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextareaControl } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { RewritePlan, RewriteResult, defaultPlans } from './RewriteParts';

const SECTIONS = [
	[ 'cluster_plans', 'نقشه خوشه' ],
	[ 'articles', 'مقاله' ],
	[ 'topics', 'موضوع' ],
	[ 'term_descriptions', 'متن دسته' ],
	[ 'seo_updates', 'عنوان و متا' ],
	[ 'link_suggestions', 'پیشنهاد لینک' ],
	[ 'structure_suggestions', 'پیشنهاد ساختار' ],
	[ 'image_alts', 'متن جایگزین' ],
	[ 'new_terms', 'برچسب تازه' ],
	[ 'classifications', 'طبقه‌بندی' ],
];

const LEVEL = { duplicate: 'pp-check--duplicate', error: 'pp-check--error', warning: 'pp-check--warning', info: 'pp-check--info' };

/**
 * Title of a preview item.
 *
 * @param {string} section Section.
 * @param {Object} item    Item.
 */
function itemTitle( section, item ) {
	if ( 'articles' === section ) {
		return item.rewrite_id ? `بازنویسی: ${ item.title }` : item.title;
	}
	if ( 'term_descriptions' === section ) {
		return `متن معرفی «${ item.name }»`;
	}
	if ( 'seo_updates' === section ) {
		return `متای «${ item.title || item.url }»`;
	}
	if ( 'link_suggestions' === section ) {
		return `لینک از «${ item.source_title || item.source_url }» به «${ item.target_title || item.target_url }»`;
	}
	if ( 'structure_suggestions' === section ) {
		return `${ { merge: 'ادغام', split: 'تقسیم', create: 'دسته تازه', rename: 'تغییر نام', noindex: 'noindex', keep: 'بدون تغییر' }[ item.action ] || item.action } «${ item.name || item.new_name }»${ item.into_name ? ` ← «${ item.into_name }»` : '' }${ item.new_name && item.name ? ` ← «${ item.new_name }»` : '' } — ${ item.reason }`;
	}
	if ( 'classifications' === section ) {
		const parts = [];
		if ( item.add_cat_names && item.add_cat_names.length ) {
			parts.push( `+ دسته: ${ item.add_cat_names.join( '، ' ) }` );
		}
		if ( item.primary_name ) {
			parts.push( `دسته اصلی: ${ item.primary_from ? `${ item.primary_from } ← ` : '' }${ item.primary_name }` );
		}
		const tags = [ ...( item.add_tag_names || [] ), ...( item.new_tags || [] ).map( ( t ) => `${ t } (تازه)` ) ];
		if ( tags.length ) {
			parts.push( `+ برچسب: ${ tags.join( '، ' ) }` );
		}
		return `«${ item.title }» — ${ parts.length ? parts.join( ' · ' ) : 'بدون تغییر' }`;
	}
	if ( 'cluster_plans' === section ) {
		return `«${ item.name }»${ item.cluster_id ? ' (تکمیل خوشه موجود)' : ' (خوشه تازه)' }`;
	}
	if ( 'topics' === section && ( item.cluster || ( item.tags || [] ).length ) ) {
		return `${ item.title }${ item.cluster ? ` — خوشه: ${ item.cluster }` : '' }${ ( item.tags || [] ).length ? ` — #${ item.tags.join( ' #' ) }` : '' }`;
	}
	if ( 'new_terms' === section ) {
		return `«${ item.name }» برای ${ item.count } مطلب`;
	}
	if ( 'image_alts' === section ) {
		return `«${ item.alt }» — ${ 'featured' === item.kind ? 'تصویر شاخص' : 'تصویر' } «${ item.post_title }»${ item.heading ? ` (بخش «${ item.heading }»)` : '' }`;
	}
	return item.title;
}

/**
 * Paste the assistant's JSON reply and import it without leaving the dialog.
 * Same preview, checks and commit as the import page.
 *
 * With `job`, the reply of an automatic-mode job is previewed straight from the server.
 *
 * @param {Object}   props
 * @param {number}   props.job       Automatic-mode job id.
 * @param {number}   props.batch     Automatic-mode batch id (all finished replies combined).
 * @param {Function} props.onClosed  Called after the job was imported or discarded.
 */
export default function InlineImport( { job = 0, batch = 0, onClosed } ) {
	const source = job || batch;
	const [ text, setText ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ plans, setPlans ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const check = () => {
		setBusy( true );
		setError( '' );
		post( '/cm/preview', job ? { job } : ( batch ? { batch } : { text } ) )
			.then( ( p ) => {
				setPreview( p );
				const sel = {};
				SECTIONS.forEach( ( [ s ] ) => {
					// Products and new tags are never pre-selected: the owner ticks them.
					sel[ s ] = ( p[ s ] || [] ).filter( ( i ) => ! i.duplicate && ! i.blocked && ! i.manual ).map( ( i ) => i.index );
				} );
				setPicked( sel );
				setPlans( defaultPlans( p.articles ) );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const commit = () => {
		setBusy( true );
		setError( '' );
		post( '/cm/commit', { token: preview.token, job: job || undefined, batch: batch || undefined, ...picked, plans } )
			.then( ( r ) => {
				setResult( r );
				setPreview( null );
				setText( '' );
				if ( onClosed ) {
					onClosed( 'imported' );
				}
				// Lists behind the dialog (topics, tasks…) reload themselves.
				window.dispatchEvent( new window.CustomEvent( 'paydarpress:imported', { detail: r } ) );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	useEffect( () => {
		if ( source ) {
			check();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ source ] );

	const discard = () => {
		setBusy( true );
		post( batch ? `/ai/batches/${ batch }/discard` : `/ai/jobs/${ job }/discard` )
			.then( () => onClosed && onClosed( 'discarded' ) )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const toggle = ( s, i, on ) => setPicked( { ...picked, [ s ]: on ? [ ...( picked[ s ] || [] ), i ] : ( picked[ s ] || [] ).filter( ( x ) => x !== i ) } );
	const total = Object.values( picked ).reduce( ( a, l ) => a + l.length, 0 );

	if ( result ) {
		return (
			<div className="pp-inline-import">
				<Notice status="success" isDismissible={ false }>
					وارد شد: { [
						result.articles.length ? `${ num( result.articles.length ) } پیش‌نویس` : '',
						result.topics ? `${ num( result.topics ) } موضوع` : '',
						result.terms ? `${ num( result.terms ) } متن دسته` : '',
						result.seo ? `${ num( result.seo ) } متا` : '',
						result.link_suggestions ? `${ num( result.link_suggestions ) } پیشنهاد لینک` : '',
						result.structure ? `${ num( result.structure ) } پیشنهاد ساختار (در «دسته‌ها و برچسب‌ها»)` : '',
						result.alts ? `${ num( result.alts ) } متن جایگزین تصویر` : '',
						result.classified ? `طبقه‌بندی ${ num( result.classified ) } مطلب` : '',
						result.clusters ? `${ num( result.clusters ) } خوشه` : '',
						result.new_terms ? `${ num( result.new_terms ) } برچسب تازه` : '',
					].filter( Boolean ).join( '، ' ) || 'موردی' }.
				</Notice>
				{ result.articles.length > 0 && (
					<ul className="pp-mini-list">
						{ result.articles.map( ( a ) => ( a.rewrite ? <RewriteResult key={ a.id } article={ a } /> : <li key={ a.id }><a href={ a.edit_url } target="_blank" rel="noreferrer">{ a.title }</a> <span className="pp-muted">(پیش‌نویس)</span></li> ) ) }
					</ul>
				) }
				{ result.notes && result.notes.length > 0 && <ul className="pp-checks">{ result.notes.map( ( n, i ) => <li key={ i } className="pp-check pp-check--info">{ n }</li> ) }</ul> }
				{ result.classified > 0 && <p className="pp-muted">هر وقت لازم شد، این مرحله از «دسته‌ها و برچسب‌ها ← طبقه‌بندی محتوای موجود» یکجا بازگردانده می‌شود.</p> }
				{ result.errors && result.errors.length > 0 && <ul className="pp-checks">{ result.errors.map( ( e, i ) => <li key={ i } className="pp-check pp-check--error">{ e }</li> ) }</ul> }
				{ ! source && <Button variant="link" onClick={ () => setResult( null ) }>ورود پاسخ دیگر</Button> }
			</div>
		);
	}

	return (
		<div className="pp-inline-import">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! preview && source ? (
				<div className="pp-ai-modal__row">
					{ busy ? <><Spinner /> <span className="pp-muted">در حال بررسی پاسخ…</span></> : <Button variant="secondary" onClick={ check }>بررسی دوباره</Button> }
				</div>
			) : ! preview ? (
				<>
					<TextareaControl __nextHasNoMarginBottom label="پاسخ هوش مصنوعی" hideLabelFromVision rows={ 4 } value={ text } onChange={ setText } placeholder="پاسخ کامل ChatGPT، Claude یا Gemini (بلوک JSON) را اینجا بچسبانید" className="pp-paste" dir="auto" />
					<Button variant="primary" onClick={ check } isBusy={ busy } disabled={ busy || ! text.trim() }>بررسی پاسخ</Button>
				</>
			) : (
				<>
					{ ( preview.warnings || [] ).map( ( w, i ) => <Notice key={ i } status="warning" isDismissible={ false }>{ w }</Notice> ) }
					{ SECTIONS.map( ( [ s, label ] ) => (
						<Fragment key={ s }>
							{ ( preview[ s ] || [] ).length > 5 && (
						<div className="pp-import-section">
							<strong>{ label }: { num( ( picked[ s ] || [] ).length ) } از { num( preview[ s ].length ) } انتخاب شده</strong>
							<Button variant="link" onClick={ () => setPicked( { ...picked, [ s ]: preview[ s ].filter( ( i ) => ! i.blocked ).map( ( i ) => i.index ) } ) }>همه</Button>
							<Button variant="link" onClick={ () => setPicked( { ...picked, [ s ]: [] } ) }>هیچ</Button>
							{ 'classifications' === s && <Button variant="link" onClick={ () => setPicked( { ...picked, [ s ]: preview[ s ].filter( ( i ) => ! i.blocked && ! i.noop ).map( ( i ) => i.index ) } ) }>فقط موارد دارای تغییر</Button> }
						</div>
					) }
							{ ( preview[ s ] || [] ).map( ( item ) => (
						<div key={ `${ s }-${ item.index }` } className={ `pp-import-item${ item.duplicate ? ' is-duplicate' : '' }` }>
							<CheckboxControl __nextHasNoMarginBottom label={ `${ label }: ${ itemTitle( s, item ) }` } checked={ ( picked[ s ] || [] ).includes( item.index ) } disabled={ item.blocked } onChange={ ( on ) => toggle( s, item.index, on ) } />
							{ 'articles' === s && item.words > 0 && <div className="pp-muted pp-import-item__meta">{ num( item.words ) } کلمه{ item.internal_links ? ` · ${ num( item.internal_links ) } لینک داخلی` : '' }{ item.faq && item.faq.length ? ` · ${ num( item.faq.length ) } پرسش متداول` : '' }{ item.inline_images && item.inline_images.length ? ` · ${ num( item.inline_images.length ) } پرامپت تصویر داخل متن` : '' }</div> }
							{ 'articles' === s && item.plan && ( picked.articles || [] ).includes( item.index ) && <RewritePlan item={ item } choice={ plans[ item.index ] } onChange={ ( c ) => setPlans( { ...plans, [ item.index ]: c } ) } /> }
							{ item.checks.length > 0 && <ul className="pp-checks">{ item.checks.map( ( c, i ) => <li key={ i } className={ `pp-check ${ LEVEL[ c.level ] || '' }` }>{ c.text }</li> ) }</ul> }
						</div>
							) ) }
						</Fragment>
					) ) }
					<div className="pp-ai-modal__row">
						<Button variant="primary" onClick={ commit } isBusy={ busy } disabled={ busy || 0 === total }>ورود { num( total ) } مورد</Button>
						{ source ? <Button variant="tertiary" isDestructive onClick={ discard } disabled={ busy }>کنار گذاشتن این پاسخ</Button> : <Button variant="tertiary" onClick={ () => setPreview( null ) } disabled={ busy }>انصراف</Button> }
						{ ( preview.articles || [] ).length > 0 && <span className="pp-muted">مقاله‌ها فقط پیش‌نویس می‌شوند.</span> }{ ( preview.image_alts || [] ).length > 0 && <span className="pp-muted">فقط تصاویری که متن جایگزین ندارند یا متنشان بی‌معناست تغییر می‌کنند؛ نسخه قبلی نوشته در «بازنگری‌ها» می‌ماند.</span> }
					</div>
				</>
			) }
		</div>
	);
}
