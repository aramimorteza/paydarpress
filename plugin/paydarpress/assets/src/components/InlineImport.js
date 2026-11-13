import { useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, TextareaControl } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const SECTIONS = [
	[ 'articles', 'مقاله' ],
	[ 'topics', 'موضوع' ],
	[ 'term_descriptions', 'متن دسته' ],
	[ 'seo_updates', 'عنوان و متا' ],
	[ 'link_suggestions', 'پیشنهاد لینک' ],
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
	return item.title;
}

/**
 * Paste the assistant's JSON reply and import it without leaving the dialog.
 * Same preview, checks and commit as the import page.
 */
export default function InlineImport() {
	const [ text, setText ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const check = () => {
		setBusy( true );
		setError( '' );
		post( '/cm/preview', { text } )
			.then( ( p ) => {
				setPreview( p );
				const sel = {};
				SECTIONS.forEach( ( [ s ] ) => {
					sel[ s ] = ( p[ s ] || [] ).filter( ( i ) => ! i.duplicate && ! i.blocked ).map( ( i ) => i.index );
				} );
				setPicked( sel );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const commit = () => {
		setBusy( true );
		setError( '' );
		post( '/cm/commit', { token: preview.token, ...picked } )
			.then( ( r ) => {
				setResult( r );
				setPreview( null );
				setText( '' );
				// Lists behind the dialog (topics, tasks…) reload themselves.
				window.dispatchEvent( new window.CustomEvent( 'paydarpress:imported', { detail: r } ) );
			} )
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
					].filter( Boolean ).join( '، ' ) || 'موردی' }.
				</Notice>
				{ result.articles.length > 0 && (
					<ul className="pp-mini-list">
						{ result.articles.map( ( a ) => <li key={ a.id }><a href={ a.edit_url } target="_blank" rel="noreferrer">{ a.title }</a> <span className="pp-muted">{ a.rewrite ? '(بازنویسی — در ویرایشگر «جایگزینی نوشته اصلی»)' : '(پیش‌نویس)' }</span></li> ) }
					</ul>
				) }
				{ result.errors && result.errors.length > 0 && <ul className="pp-checks">{ result.errors.map( ( e, i ) => <li key={ i } className="pp-check pp-check--error">{ e }</li> ) }</ul> }
				<Button variant="link" onClick={ () => setResult( null ) }>ورود پاسخ دیگر</Button>
			</div>
		);
	}

	return (
		<div className="pp-inline-import">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! preview ? (
				<>
					<TextareaControl __nextHasNoMarginBottom label="پاسخ هوش مصنوعی" hideLabelFromVision rows={ 4 } value={ text } onChange={ setText } placeholder="پاسخ کامل ChatGPT، Claude یا Gemini (بلوک JSON) را اینجا بچسبانید" className="pp-paste" dir="auto" />
					<Button variant="primary" onClick={ check } isBusy={ busy } disabled={ busy || ! text.trim() }>بررسی پاسخ</Button>
				</>
			) : (
				<>
					{ ( preview.warnings || [] ).map( ( w, i ) => <Notice key={ i } status="warning" isDismissible={ false }>{ w }</Notice> ) }
					{ SECTIONS.map( ( [ s, label ] ) => ( preview[ s ] || [] ).map( ( item ) => (
						<div key={ `${ s }-${ item.index }` } className={ `pp-import-item${ item.duplicate ? ' is-duplicate' : '' }` }>
							<CheckboxControl __nextHasNoMarginBottom label={ `${ label }: ${ itemTitle( s, item ) }` } checked={ ( picked[ s ] || [] ).includes( item.index ) } disabled={ item.blocked } onChange={ ( on ) => toggle( s, item.index, on ) } />
							{ item.checks.length > 0 && <ul className="pp-checks">{ item.checks.map( ( c, i ) => <li key={ i } className={ `pp-check ${ LEVEL[ c.level ] || '' }` }>{ c.text }</li> ) }</ul> }
						</div>
					) ) ) }
					<div className="pp-ai-modal__row">
						<Button variant="primary" onClick={ commit } isBusy={ busy } disabled={ busy || 0 === total }>ورود { num( total ) } مورد</Button>
						<Button variant="tertiary" onClick={ () => setPreview( null ) } disabled={ busy }>انصراف</Button>
						<span className="pp-muted">مقاله‌ها فقط پیش‌نویس می‌شوند.</span>
					</div>
				</>
			) }
		</div>
	);
}
