import { useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import PostPicker from './PostPicker';
import AiButton from './AiButton';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

/**
 * Merge an article into another one: (1) optional AI rewrite of the article that stays,
 * (2) retire this one with a 301 redirect and fix internal links.
 *
 * @param {Object} props
 * @param {number} props.id    Article being retired.
 * @param {string} props.title Its title.
 * @param {Object} props.into  Optional article that stays: { id, title } (preselected).
 * @param {string} props.label Optional button label.
 * @param {Function} props.onDone Called when the dialog closes after a successful merge.
 */
export default function MergeButton( { id, title, into: preset = null, label = 'ادغام در مقاله دیگر', onDone } ) {
	const [ open, setOpen ] = useState( false );
	const [ into, setInto ] = useState( preset );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );

	const merge = () => {
		setBusy( true );
		setError( '' );
		post( '/merge', { from: id, into: into.id } )
			.then( setResult )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<Button variant="tertiary" size="small" onClick={ () => setOpen( true ) }>{ label }</Button>
			{ open && (
				<Modal title={ `ادغام «${ title }»` } onRequestClose={ () => {
					setOpen( false );
					if ( result && onDone ) {
						onDone( result );
					}
				} } className="pp-modal pp-ai-modal" size="medium">
					{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
					{ result ? (
						<Notice status="success" isDismissible={ false }>
							ادغام انجام شد: «{ title }» پیش‌نویس شد و لینک‌های { num( result.links ) } نوشته به «{ into.title }» اصلاح شد. { result.message } نتیجه در «گوگل ← نتیجه تغییرات» سنجیده می‌شود.
						</Notice>
					) : (
						<>
							<p className="pp-muted">وقتی دو مقاله یک نیت جست‌وجو را پوشش می‌دهند، با هم رقابت می‌کنند. یکی را نگه دارید (معمولاً آن که در گوگل بهتر است) و دیگری را در آن ادغام کنید.</p>
							{ ! into ? (
								<PostPicker exclude={ id } onPick={ setInto } label="مقاله‌ای که می‌ماند را جست‌وجو کنید" />
							) : (
								<>
									<p>می‌ماند: <strong>{ into.title }</strong> <Button variant="link" onClick={ () => setInto( null ) }>تغییر</Button></p>
									<section className="pp-ai-modal__block">
										<h3>۱. محتوای ارزشمند را منتقل کنید (اختیاری، پیشنهادی)</h3>
										<p className="pp-muted">هوش مصنوعی نسخه کامل مقاله‌ای که می‌ماند را با بخش‌های غیرتکراری «{ title }» می‌نویسد؛ بازنویسی پیش‌نویس جدا می‌شود و با «جایگزینی نوشته اصلی» اعمال می‌شود.</p>
										<AiButton command={ { label: 'ادغام محتوا با هوش مصنوعی', summary: `ادغام «${ title }» در «${ into.title }»`, command: `ادغام: «${ title }» در «${ into.title }»`, prompt: { task: 'merge', args: { from: id, into: into.id } }, hint: 'پس از جایگزینی متن، مرحله ۲ را انجام دهید.' } } />
									</section>
									<section className="pp-ai-modal__block">
										<h3>۲. بازنشسته کردن این مقاله</h3>
										<p className="pp-muted">«{ title }» پیش‌نویس می‌شود، آدرسش با ریدایرکت ۳۰۱ به «{ into.title }» می‌رود (اعتبارش منتقل می‌شود) و لینک‌های داخلی سایت به آن، به مقاله مقصد اصلاح می‌شوند.</p>
										{ confirm ? (
											<p><strong>انجام شود؟</strong> <Button variant="primary" isDestructive onClick={ merge } isBusy={ busy } disabled={ busy }>بله، ادغام کن</Button> <Button variant="tertiary" onClick={ () => setConfirm( false ) }>انصراف</Button></p>
										) : (
											<Button variant="secondary" onClick={ () => setConfirm( true ) }>ادغام و ریدایرکت ۳۰۱</Button>
										) }
									</section>
								</>
							) }
						</>
					) }
				</Modal>
			) }
		</>
	);
}
