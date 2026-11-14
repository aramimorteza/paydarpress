import { useState } from '@wordpress/element';
import AiButton from './AiButton';
import { commandForFinding } from '../lib/aiCommands';
import { Button } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';

/** Typed «view» links (same wording as the task cards). */
const VIEW_LABELS = {
	post: 'مشاهده مقاله',
	page: 'مشاهده صفحه',
	product: 'مشاهده محصول',
	category: 'مشاهده آرشیو دسته',
	post_tag: 'مشاهده آرشیو برچسب',
	product_cat: 'مشاهده آرشیو دسته محصول',
	product_tag: 'مشاهده آرشیو برچسب محصول',
};

export const SEVERITY_LABELS = { high: 'بالا', medium: 'متوسط', low: 'کم' };
const SEVERITY_CLASS = { high: 'danger', medium: 'warning', low: 'neutral' };
const STATE_LABELS = { open: 'باز', reviewed: 'بررسی‌شده', ignored: 'نادیده گرفته‌شده', resolved: 'رفع‌شده' };

/**
 * Wraps URLs in LTR isolates so they are not reordered inside Persian text.
 *
 * @param {string} text Line of text.
 */
function withLtrUrls( text ) {
	return String( text )
		.split( /(https?:\/\/\S+)/ )
		.map( ( part, i ) =>
			/^https?:\/\//.test( part ) ? (
				<bdi key={ i } dir="ltr" className="pp-url">
					{ part }
				</bdi>
			) : (
				part
			)
		);
}

/**
 * Severity badge.
 *
 * @param {Object} props
 * @param {string} props.severity high|medium|low.
 */
export function SeverityBadge( { severity } ) {
	return <span className={ `pp-badge pp-badge--${ SEVERITY_CLASS[ severity ] || 'neutral' }` }>شدت { SEVERITY_LABELS[ severity ] || severity }</span>;
}

/**
 * One finding: problem, why it matters, what to do, details and state actions.
 *
 * @param {Object}   props
 * @param {Object}   props.finding  Finding from the API.
 * @param {Function} props.onChange Called with new stats after a state change.
 * @param {*}        props.children Optional extra content (e.g. link suggestions).
 */
export default function FindingCard( { finding, onChange, children } ) {
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const [ state, setState ] = useState( finding.state );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const object = finding.object || {};

	const change = ( next ) => {
		setBusy( true );
		setError( '' );
		post( `/findings/${ finding.id }/state`, { state: next } )
			.then( ( data ) => {
				setState( next );
				if ( onChange ) {
					onChange( data.stats );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<article className={ `pp-finding pp-finding--${ finding.severity }${ 'open' !== state ? ' is-muted' : '' }` }>
			<header className="pp-finding__head">
				<h3 className="pp-finding__title">{ finding.title }</h3>
				<SeverityBadge severity={ finding.severity } />
				{ 'open' !== state && <span className="pp-badge">{ STATE_LABELS[ state ] }</span> }
			</header>

			<p className="pp-finding__object">
				{ object.url ? (
					<a href={ object.url } target="_blank" rel="noreferrer">
						{ object.title || '(بدون عنوان)' }
					</a>
				) : (
					object.title
				) }
				{ object.edit_url && (
					<a className="pp-finding__edit" href={ object.edit_url }>
						ویرایش
					</a>
				) }
				{ object.url && VIEW_LABELS[ object.type ] && (
					<a className="pp-task__view" href={ object.url } target="_blank" rel="noreferrer" title={ decodeURI( object.url ) }>
						{ VIEW_LABELS[ object.type ] } ↗
					</a>
				) }
			</p>

			<dl className="pp-finding__explain">
				<div>
					<dt>چرا مهم است؟</dt>
					<dd>{ finding.why }</dd>
				</div>
				<div>
					<dt>چه کار کنم؟</dt>
					<dd>{ finding.fix }</dd>
				</div>
			</dl>

			{ finding.details && finding.details.length > 0 && (
				<ul className="pp-finding__details">
					{ finding.details.map( ( line, i ) => (
						<li key={ i }>{ withLtrUrls( line ) }</li>
					) ) }
				</ul>
			) }

			{ children }

			{ canManage && (
				<footer className="pp-finding__actions">
					{ 'open' === state ? (
						<>
							<Button variant="secondary" size="small" onClick={ () => change( 'reviewed' ) } disabled={ busy }>
								بررسی کردم
							</Button>
							<Button variant="tertiary" size="small" onClick={ () => change( 'ignored' ) } disabled={ busy }>
								نادیده بگیر
							</Button>
						</>
					) : (
						<Button variant="tertiary" size="small" onClick={ () => change( 'open' ) } disabled={ busy }>
							بازگرداندن به فهرست باز
						</Button>
					) }
					<AiButton command={ commandForFinding( finding ) } />
					{ error && <span className="pp-missing">{ error }</span> }
				</footer>
			) }
		</article>
	);
}
