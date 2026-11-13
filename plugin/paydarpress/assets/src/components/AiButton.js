import { useState } from '@wordpress/element';
import { Button, Modal, Notice, Spinner, TextareaControl } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import InlineImport from './InlineImport';

const ASSISTANTS = [
	{ name: 'ChatGPT', url: 'https://chatgpt.com/' },
	{ name: 'Claude', url: 'https://claude.ai/new' },
	{ name: 'Gemini', url: 'https://gemini.google.com/app' },
];

/**
 * Copies text; falls back to a hidden textarea where the Clipboard API is unavailable
 * (admin over plain http).
 *
 * @param {string} text Text.
 * @return {Promise} Resolves when copied.
 */
export function copyText( text ) {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		return window.navigator.clipboard.writeText( text );
	}
	return new Promise( ( resolve, reject ) => {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			// eslint-disable-next-line no-restricted-syntax
			const ok = document.execCommand( 'copy' );
			document.body.removeChild( area );
			return ok ? resolve() : reject();
		} catch ( e ) {
			document.body.removeChild( area );
			return reject( e );
		}
	} );
}

/**
 * "Create with AI" button. Opens a dialog with:
 * - a complete prompt (rules + site profile + related content + task + JSON format) for any
 *   chat assistant, built by the server for exactly this item;
 * - the short command for users who keep a ContentMaster conversation.
 * The reply (a JSON block) goes to «ورود پاسخ هوش مصنوعی».
 *
 * @param {Object}  props
 * @param {Object}  props.command Action from lib/aiCommands.
 * @param {boolean} props.small   Small button.
 * @param {string}  props.variant Button variant.
 */
export default function AiButton( { command, small = true, variant = 'secondary' } ) {
	const canImport = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const [ open, setOpen ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( '' );
	const [ showPrompt, setShowPrompt ] = useState( false );

	if ( ! command ) {
		return null;
	}

	const start = () => {
		setOpen( true );
		setCopied( '' );
		if ( command.prompt && ! data ) {
			setError( '' );
			post( '/prompt', command.prompt )
				.then( setData )
				.catch( ( e ) => setError( errorMessage( e ) ) );
		}
	};

	const copy = ( key, text ) => {
		copyText( text )
			.then( () => {
				setCopied( key );
				setTimeout( () => setCopied( ( c ) => ( c === key ? '' : c ) ), 2500 );
			} )
			.catch( () => setError( 'کپی خودکار ممکن نشد؛ متن را از کادر انتخاب و کپی کنید.' ) );
	};

	const shortCommand = data ? data.command : command.command;

	return (
		<>
			<Button className="pp-ai-button" variant={ variant } size={ small ? 'small' : undefined } icon="admin-customizer" onClick={ start }>
				{ command.label }
			</Button>
			{ open && (
				<Modal title={ command.summary } onRequestClose={ () => setOpen( false ) } className="pp-modal pp-ai-modal" size="medium">
					{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
					{ data && data.policy && data.policy.over && (
						<Notice status="warning" isDismissible={ false }>
							سهمیه مقاله جدید این ماه پر شده است ({ num( data.policy.published ) } از { num( data.policy.budget ) }).
							{ data.policy.refresh_waiting > 0 && <> { num( data.policy.refresh_waiting ) } مقاله قدیمی منتظر بازنویسی است؛ <a href="#/refresh" onClick={ () => setOpen( false ) }>بازنویسی</a> معمولاً ارزش بیشتری دارد.</> }
						</Notice>
					) }

					{ command.prompt && (
						<section className="pp-ai-modal__block">
							<h3>روش ۱: با ChatGPT، Claude یا Gemini (بدون نیاز به چیز دیگر)</h3>
							<ol className="pp-ai-steps">
								<li>
									<strong>پرامپت کامل را کپی کنید.</strong> قوانین نوشتن، پروفایل سایت، صفحات کلیدی، مطالب مرتبط و قالب خروجی، مخصوص همین مورد، داخل آن است.
									<div className="pp-ai-modal__row">
										<Button variant="primary" onClick={ () => data && copy( 'full', data.prompt ) } disabled={ ! data } isBusy={ ! data && ! error }>
											{ 'full' === copied ? '✓ پرامپت کپی شد' : 'کپی پرامپت کامل' }
										</Button>
										{ ! data && ! error && <Spinner /> }
										{ data && <span className="pp-muted">{ num( data.chars ) } نویسه</span> }
										{ data && (
											<Button variant="link" onClick={ () => setShowPrompt( ! showPrompt ) }>{ showPrompt ? 'بستن متن' : 'دیدن متن' }</Button>
										) }
									</div>
									{ data && showPrompt && (
										<TextareaControl __nextHasNoMarginBottom label="پرامپت کامل" hideLabelFromVision readOnly rows={ 8 } value={ data.prompt } onChange={ () => {} } className="pp-ai-modal__prompt" />
									) }
								</li>
								<li>
									<strong>در یک گفتگوی تازه بچسبانید و بفرستید.</strong>
									<div className="pp-ai-modal__row">
										{ ASSISTANTS.map( ( a ) => (
											<Button key={ a.name } variant="secondary" href={ a.url } target="_blank" rel="noreferrer">باز کردن { a.name }</Button>
										) ) }
									</div>
								</li>
								<li>
									<strong>پاسخ (یک بلوک JSON) را کپی کنید و همین‌جا بچسبانید.</strong> پیش از ذخیره، بررسی‌ها را می‌بینید.
									{ canImport ? <InlineImport /> : <span className="pp-muted"> ورود فقط برای مدیر سایت ممکن است.</span> }
								</li>
							</ol>
						</section>
					) }

					<section className="pp-ai-modal__block">
						<h3>{ command.prompt ? 'روش ۲: در گفتگوی ContentMaster همین سایت' : 'در گفتگوی ContentMaster همین سایت' }</h3>
						<p className="pp-muted">اگر پروژه ContentMaster با فایل خروجی سایت دارید، فقط این فرمان کوتاه کافی است؛ بعد فرمان «خروجی پایدارپرس» را بزنید.</p>
						<TextareaControl __nextHasNoMarginBottom label="فرمان" hideLabelFromVision readOnly rows={ 3 } value={ shortCommand } onChange={ () => {} } />
						<Button variant="secondary" onClick={ () => copy( 'cmd', shortCommand ) }>{ 'cmd' === copied ? '✓ فرمان کپی شد' : 'کپی فرمان' }</Button>
						{ ! command.prompt && (
							<div className="pp-ai-modal__import">
								<p className="pp-muted">خروجی «خروجی پایدارپرس» را همین‌جا بچسبانید:</p>
								{ canImport ? <InlineImport /> : <span className="pp-muted"> ورود فقط برای مدیر سایت ممکن است.</span> }
							</div>
						) }
					</section>

					{ command.hint && <p className="pp-muted">{ command.hint }</p> }
					<p className="pp-ai-modal__phase">
						{ data && data.ai_ready
							? 'اتصال API تنظیم شده است؛ ساخت خودکار همین مورد با یک کلیک در به‌روزرسانی بعدی به این پنجره اضافه می‌شود.'
							: <>با تنظیم کلید API در <a href="#/settings?tab=ai" onClick={ () => setOpen( false ) }>تنظیمات ← هوش مصنوعی</a>، این کار بدون کپی و چسباندن انجام خواهد شد.</> }
					</p>
				</Modal>
			) }
		</>
	);
}
