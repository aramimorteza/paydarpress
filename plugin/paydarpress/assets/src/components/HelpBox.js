import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import helpContent from '../../../help/fa_IR/pages.json';

const config = window.paydarpress || {};

/**
 * Short help box shown at the top of each page (architecture section 12.7).
 * Dismissal is stored per user; the "؟" button in the page header reopens it.
 *
 * @param {Object}   props
 * @param {string}   props.pageKey Key in help/fa_IR/pages.json.
 * @param {boolean}  props.open    Whether the box is open.
 * @param {Function} props.onClose Called after the user dismisses the box.
 */
export default function HelpBox( { pageKey, open, onClose } ) {
	const [ saving, setSaving ] = useState( false );
	const help = helpContent[ pageKey ];

	if ( ! help || ! open ) {
		return null;
	}

	const dismiss = () => {
		setSaving( true );
		apiFetch( {
			path: `/${ config.restNamespace }/help/dismiss`,
			method: 'POST',
			data: { key: pageKey },
		} )
			.catch( () => {} )
			.finally( () => {
				setSaving( false );
				config.dismissedHelp = [ ...( config.dismissedHelp || [] ), pageKey ];
				onClose();
			} );
	};

	return (
		<div className="pp-help" role="note">
			<div className="pp-help__body">
				<strong className="pp-help__title">{ help.title }</strong>
				<p>{ help.body }</p>
				{ help.next && (
					<p className="pp-help__next">
						<strong>قدم بعدی: </strong>
						{ help.next }
					</p>
				) }
			</div>
			<Button variant="secondary" onClick={ dismiss } isBusy={ saving } disabled={ saving }>
				متوجه شدم
			</Button>
		</div>
	);
}
