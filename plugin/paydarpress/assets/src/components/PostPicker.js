import { useEffect, useState } from '@wordpress/element';
import { SearchControl, Spinner } from '@wordpress/components';
import { get } from '../lib/api';

/**
 * Search a published post or page by title and pick it.
 *
 * @param {Object}   props
 * @param {Function} props.onPick  Called with { id, title, url }.
 * @param {number}   props.exclude Id to leave out.
 * @param {string}   props.label   Label.
 */
export default function PostPicker( { onPick, exclude = 0, label = 'جست‌وجوی مقاله مقصد' } ) {
	const [ q, setQ ] = useState( '' );
	const [ items, setItems ] = useState( null );

	useEffect( () => {
		if ( q.trim().length < 2 ) {
			setItems( null );
			return undefined;
		}
		const t = setTimeout( () => {
			get( '/inventory', { search: q, per_page: 8, status: 'publish' } )
				.then( ( r ) => setItems( ( r.items || [] ).filter( ( i ) => i.id !== exclude ) ) )
				.catch( () => setItems( [] ) );
		}, 300 );
		return () => clearTimeout( t );
	}, [ q ] );

	return (
		<div className="pp-picker">
			<SearchControl __nextHasNoMarginBottom label={ label } placeholder={ label } value={ q } onChange={ setQ } />
			{ null === items && q.trim().length >= 2 && <Spinner /> }
			{ items && 0 === items.length && <p className="pp-muted">موردی پیدا نشد.</p> }
			{ items && items.length > 0 && (
				<ul className="pp-picker__list">
					{ items.map( ( i ) => (
						<li key={ i.id }>
							<button type="button" onClick={ () => onPick( { id: i.id, title: i.title, url: i.url } ) }>{ i.title }</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
