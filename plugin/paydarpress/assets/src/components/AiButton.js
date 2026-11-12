import { useState } from '@wordpress/element';
import { Button, Modal, TextareaControl } from '@wordpress/components';

/**
 * "Create with AI" button. Before the AI API is connected it opens a dialog with a ready
 * ContentMaster command to copy; in phase 2 it will create the draft directly.
 *
 * @param {Object}  props
 * @param {Object}  props.command Command from lib/aiCommands (label, summary, command, hint).
 * @param {boolean} props.small   Small button.
 */
export default function AiButton( { command, small = true } ) {
	const [ open, setOpen ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	if ( ! command ) {
		return null;
	}

	const copy = () => {
		const done = () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		};
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( command.command ).then( done ).catch( () => {} );
		}
	};

	return (
		<>
			<Button className="pp-ai-button" variant="secondary" size={ small ? 'small' : undefined } icon="admin-customizer" onClick={ () => setOpen( true ) }>
				{ command.label }
			</Button>
			{ open && (
				<Modal title={ command.summary } onRequestClose={ () => setOpen( false ) } className="pp-modal pp-ai-modal">
					<p>فرمان زیر آماده است. آن را در گفتگوی <strong>ContentMaster</strong> همین سایت بچسبانید:</p>
					<TextareaControl __nextHasNoMarginBottom label="فرمان" hideLabelFromVision readOnly rows={ 4 } value={ command.command } onChange={ () => {} } />
					<div className="pp-form__actions pp-form__actions--start">
						<Button variant="primary" onClick={ copy }>{ copied ? 'کپی شد' : 'کپی فرمان' }</Button>
						<Button variant="secondary" href="https://claude.ai/" target="_blank" rel="noreferrer">باز کردن Claude</Button>
					</div>
					<p className="pp-muted">{ command.hint }</p>
					<p className="pp-ai-modal__phase">پس از اتصال مستقیم هوش مصنوعی (فاز ۲)، همین دکمه بدون کپی و چسباندن، پیش‌نویس را در سایت می‌سازد.</p>
				</Modal>
			) }
		</>
	);
}
