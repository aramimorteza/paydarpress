import { Button, Notice } from '@wordpress/components';
import { num, ago } from '../lib/format';

/**
 * Scan status, progress bar and actions.
 *
 * @param {Object} props
 * @param {Object} props.state     Result of useScan().
 * @param {string} props.doneLink  Where to go after completion.
 * @param {string} props.doneLabel Label of the completion link.
 */
export default function ScanPanel( { state, doneLink = '#/inventory', doneLabel = 'مشاهده فهرست محتوا' } ) {
	const { scan, running, error, starting, start, cancel, canManage } = state;

	return (
		<div className="pp-scan">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ running && (
				<div className="pp-scan__progress" aria-live="polite">
					<div className="pp-scan__label">
						{ 'links' === scan.stage
							? 'در حال بررسی لینک‌های داخلی…'
							: `در حال اسکن: ${ num( scan.processed ) } از ${ num( scan.total ) }` }
					</div>
					<div className="pp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow={ scan.percent }>
						<span style={ { width: `${ Math.max( 3, scan.percent ) }%` } } />
					</div>
					<p className="pp-muted">
						اسکن در پس‌زمینه انجام می‌شود و سایت را کند نمی‌کند. اگر این صفحه باز بماند، سریع‌تر تمام می‌شود.
					</p>
					{ canManage && (
						<Button variant="link" isDestructive onClick={ cancel }>
							لغو اسکن
						</Button>
					) }
				</div>
			) }

			{ ! running && scan && 'completed' === scan.status && (
				<div className="pp-scan__done">
					<span className="pp-badge pp-badge--success">انجام شد</span>
					<span>
						آخرین اسکن { ago( scan.finished_at ) } — { num( scan.total ) } مورد بررسی شد.
					</span>
					<a className="button" href={ doneLink }>
						{ doneLabel }
					</a>
				</div>
			) }

			{ ! running && scan && 'failed' === scan.status && (
				<Notice status="error" isDismissible={ false }>
					اسکن با خطا متوقف شد: { scan.error }
				</Notice>
			) }

			{ ! running && canManage && (
				<Button variant={ scan && 'completed' === scan.status ? 'secondary' : 'primary' } onClick={ start } isBusy={ starting } disabled={ starting } __next40pxDefaultSize>
					{ scan && 'completed' === scan.status ? 'اسکن مجدد' : 'شروع اسکن' }
				</Button>
			) }

			{ ! canManage && ! running && ! scan && <p className="pp-muted">برای شروع اسکن به دسترسی مدیر نیاز است.</p> }
		</div>
	);
}
