import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import HelpBox from './HelpBox';

const config = window.paydarpress || {};

/**
 * Standard page frame: title, "؟" help toggle, help box and content.
 *
 * @param {Object} props
 * @param {string} props.title    Page title.
 * @param {string} props.helpKey  Help content key.
 * @param {*}      props.actions  Optional header actions.
 * @param {*}      props.children Page content.
 */
export default function Page( { title, helpKey, actions, children } ) {
	const dismissed = ( config.dismissedHelp || [] ).includes( helpKey );
	const [ helpOpen, setHelpOpen ] = useState( ! dismissed );

	return (
		<div className="pp-page">
			<header className="pp-page__header">
				<h1 className="pp-page__title">{ title }</h1>
				{ helpKey && ! helpOpen && (
					<Button
						className="pp-page__help-toggle"
						variant="tertiary"
						onClick={ () => setHelpOpen( true ) }
						label="نمایش راهنما"
						showTooltip
					>
						؟
					</Button>
				) }
				{ actions && <div className="pp-page__actions">{ actions }</div> }
			</header>
			{ helpKey && <HelpBox pageKey={ helpKey } open={ helpOpen } onClose={ () => setHelpOpen( false ) } /> }
			<div className="pp-page__content">{ children }</div>
		</div>
	);
}
