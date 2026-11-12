import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import FindingsList from '../components/FindingsList';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const config = window.paydarpress || {};

/**
 * Bytes in Persian (KB/MB).
 *
 * @param {number} b Bytes.
 */
function size( b ) {
	if ( b >= 1048576 ) {
		return `${ num( Math.round( b / 104857.6 ) / 10 ) } مگابایت`;
	}
	return `${ num( Math.round( b / 1024 ) ) } کیلوبایت`;
}

export default function ImagesPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ confirm, setConfirm ] = useState( '' );
	const [ hashing, setHashing ] = useState( false );

	const load = () => get( '/images' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
	}, [] );

	// Refresh progress while background work runs.
	const running = !! data && ( !! data.bulk.state.running || ( hashing && data.hash.hashed < data.hash.images ) );
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		const t = setInterval( load, 5000 );
		return () => clearInterval( t );
	}, [ running ] );

	const act = ( name, path, body = {} ) => {
		setBusy( name );
		setError( '' );
		setConfirm( '' );
		post( path, body )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => {
				setBusy( '' );
				if ( 'hash' === name ) {
					setHashing( true );
				}
			} );
	};

	const w = data ? data.webp : null;
	const bulk = data ? data.bulk : null;
	const st = bulk ? bulk.state : {};

	return (
		<Page title="سلامت تصاویر" helpKey="images">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ data && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">
						تبدیل خودکار به WebP هنگام آپلود{ ' ' }
						{ ! data.supported ? <span className="pp-badge pp-badge--danger">سرور پشتیبانی نمی‌کند</span> : data.settings.convert_webp ? <span className="pp-badge pp-badge--success">روشن</span> : <span className="pp-badge">خاموش</span> }
					</h2>
					{ ! data.supported ? (
						<p>کتابخانه تصویر سرور (GD یا Imagick) ساخت WebP را ندارد. از پشتیبانی هاست بخواهید «GD با پشتیبانی WebP» را فعال کند؛ تا آن موقع تصاویر بدون تغییر آپلود می‌شوند.</p>
					) : (
						<p>
							هر تصویر PNG یا JPEG که آپلود می‌شود، به حداکثر { num( data.settings.max_dimension ) } پیکسل کوچک، با کیفیت { num( data.settings.webp_quality ) } به WebP تبدیل و اطلاعات اضافه‌اش حذف می‌شود
							{ data.settings.delete_original ? '؛ فایل اصلی پاک می‌شود.' : '؛ فایل اصلی نگه داشته می‌شود.' }{ ' ' }
							<a href="#/settings?tab=images">تنظیمات تصویر</a>
						</p>
					) }
					{ w.files > 0 && (
						<p className="pp-ok">
							تا امروز { num( w.files ) } تصویر تبدیل شد: { size( w.before ) } ← { size( w.after ) } (صرفه‌جویی { num( Math.round( ( 1 - w.after / Math.max( 1, w.before ) ) * 100 ) ) }٪).
						</p>
					) }
				</section>
			) }

			{ data && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">تصاویر مشابه در کتابخانه</h2>
					<p className="pp-muted">
						پایدارپرس برای هر تصویر یک «اثر انگشت» می‌سازد و تصاویر خیلی شبیه به هم را پیدا می‌کند (حتی با اندازه یا فرمت متفاوت). هنگام آپلود تصویر تکراری، در جزئیات همان تصویر هشدار نمایش داده می‌شود.
					</p>
					<p>
						{ num( data.hash.hashed ) } از { num( data.hash.images ) } تصویر بررسی شده است.{ ' ' }
						{ canManage && data.hash.hashed < data.hash.images && (
							<Button variant="secondary" size="small" onClick={ () => act( 'hash', '/images/hash-scan' ) } isBusy={ 'hash' === busy || hashing } disabled={ !! busy || hashing }>{ hashing ? 'در حال بررسی…' : 'بررسی تصاویر قبلی' }</Button>
						) }
					</p>
					{ data.groups.length > 0 ? (
						<div className="pp-dupes">
							{ data.groups.map( ( g, i ) => (
								<div key={ i } className="pp-dupes__group">
									{ g.map( ( img ) => (
										<a key={ img.id } href={ img.edit_url } title={ img.title } target="_blank" rel="noreferrer">
											<img src={ img.thumb } alt={ img.title } loading="lazy" />
										</a>
									) ) }
								</div>
							) ) }
							<p className="pp-muted">در هر گروه یکی را نگه دارید و در مطالب، تصاویر دیگر را با آن یا با تصویر تازه جایگزین کنید.</p>
						</div>
					) : (
						data.hash.hashed > 0 && <p className="pp-ok">تصویر تکراری پیدا نشد.</p>
					) }
				</section>
			) }

			{ data && canManage && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">تبدیل تصاویر قدیمی به WebP</h2>
					<p className="pp-muted">
						آرام و در پس‌زمینه انجام می‌شود. آدرس تصاویر در متن همه نوشته‌ها (و صفحه‌های المنتور) هم‌زمان عوض می‌شود. فایل‌های اصلی <strong>نگه داشته می‌شوند</strong> تا سایت را بررسی کنید؛ تا آن موقع «برگرداندن همه» همه‌چیز را به حالت قبل برمی‌گرداند. تصاویری که در تنظیمات قالب، ابزارک‌ها یا CSS آمده‌اند با آدرس قبلی می‌مانند و تا حذف فایل‌های اصلی کار می‌کنند.
					</p>
					{ st.running ? (
						<>
							<div className="pp-progress"><span style={ { width: `${ Math.round( ( ( st.done + st.skipped + st.failed ) / Math.max( 1, st.total ) ) * 100 ) }%` } } /></div>
							<p>{ num( st.done + st.skipped + st.failed ) } از { num( st.total ) } — { num( st.posts ) } نوشته به‌روز شد. <Button variant="link" onClick={ () => act( 'stop', '/images/bulk', { action: 'stop' } ) }>توقف</Button></p>
						</>
					) : (
						<>
							{ bulk.count > 0 ? (
								<p>
									{ num( bulk.count ) } تصویر PNG/JPEG با حجم { size( bulk.bytes ) }؛ پس از تبدیل حدود { size( bulk.estimate ) } کمتر.{ ' ' }
									{ 'start' === confirm ? (
										<>
											<strong>شروع شود؟</strong> <Button variant="primary" size="small" onClick={ () => act( 'start', '/images/bulk', { action: 'start' } ) } disabled={ !! busy }>بله، شروع کن</Button> <Button variant="tertiary" size="small" onClick={ () => setConfirm( '' ) }>انصراف</Button>
											<span className="pp-muted"> پیشنهاد: پیش از شروع از سایت پشتیبان بگیرید.</span>
										</>
									) : (
										<Button variant="secondary" size="small" onClick={ () => setConfirm( 'start' ) } disabled={ ! data.supported }>شروع تبدیل</Button>
									) }
								</p>
							) : (
								<p className="pp-ok">تصویر PNG یا JPEG برای تبدیل باقی نمانده است.</p>
							) }
							{ st.finished_at && (
								<p>
									آخرین اجرا: { num( st.done ) } تصویر تبدیل شد ({ size( st.before ) } ← { size( st.after ) })، { num( st.posts ) } نوشته به‌روز شد{ st.skipped ? `، ${ num( st.skipped ) } مورد نیازی نداشت` : '' }{ st.failed ? `، ${ num( st.failed ) } مورد ناموفق` : '' }.
								</p>
							) }
							{ ( st.errors || [] ).length > 0 && <ul className="pp-mini-list pp-muted">{ st.errors.map( ( e ) => <li key={ e }>{ e }</li> ) }</ul> }
						</>
					) }
					{ bulk.pending_cleanup > 0 && ! st.running && (
						<div className="pp-bulk-verify">
							<p><strong>{ num( bulk.pending_cleanup ) } تصویر تبدیل‌شده منتظر تأیید شماست.</strong> چند صفحه و مقاله سایت را باز کنید و مطمئن شوید تصاویر درست نمایش داده می‌شوند.</p>
							{ 'restore' === confirm && (
								<p>همه تبدیل‌ها و آدرس‌های داخل متن به حالت قبل برگردند؟ <Button variant="primary" isDestructive size="small" onClick={ () => act( 'restore', '/images/bulk', { action: 'restore' } ) }>بله، برگردان</Button> <Button variant="tertiary" size="small" onClick={ () => setConfirm( '' ) }>انصراف</Button></p>
							) }
							{ 'delete' === confirm && (
								<p>فایل‌های اصلی PNG/JPEG برای همیشه حذف شوند؟ پس از این، برگرداندن ممکن نیست. <Button variant="primary" isDestructive size="small" onClick={ () => act( 'delete', '/images/bulk', { action: 'delete_originals' } ) }>بله، حذف کن</Button> <Button variant="tertiary" size="small" onClick={ () => setConfirm( '' ) }>انصراف</Button></p>
							) }
							{ ! confirm && (
								<p>
									<Button variant="secondary" size="small" onClick={ () => setConfirm( 'delete' ) }>همه‌چیز درست است؛ فایل‌های اصلی حذف شوند</Button>{ ' ' }
									<Button variant="tertiary" isDestructive size="small" onClick={ () => setConfirm( 'restore' ) }>برگرداندن همه</Button>
								</p>
							) }
						</div>
					) }
				</section>
			) }

			<h2 className="pp-section-title">مشکلات تصاویر در مطالب</h2>
			<FindingsList fixedGroup="images" />
		</Page>
	);
}
