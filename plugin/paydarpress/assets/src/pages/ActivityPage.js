import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { get, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

const GROUPS = [
	{ key: '', label: 'همه' },
	{ key: 'scan', label: 'اسکن' },
	{ key: 'finding', label: 'یافته‌ها' },
	{ key: 'profile', label: 'پروفایل' },
	{ key: 'export', label: 'خروجی' },
	{ key: 'settings', label: 'تنظیمات' },
];

const GROUP_LABELS = Object.fromEntries( GROUPS.filter( ( g ) => g.key ).map( ( g ) => [ g.key, g.label ] ) );

/** Badge style per event (failures stand out). */
function badgeClass( event ) {
	if ( /failed$/.test( event ) ) {
		return 'pp-badge pp-badge--danger';
	}
	if ( /completed$|created$|wizard$/.test( event ) ) {
		return 'pp-badge pp-badge--success';
	}
	return 'pp-badge';
}

function fullDate( iso ) {
	try {
		return new Intl.DateTimeFormat( 'fa-IR-u-ca-persian', { dateStyle: 'medium', timeStyle: 'short' } ).format( new Date( iso ) );
	} catch ( e ) {
		return iso;
	}
}

export default function ActivityPage() {
	const [ group, setGroup ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setData( null );
		get( '/activity', { group, page, per_page: 30 } )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ group, page ] );

	return (
		<Page title="گزارش فعالیت" helpKey="activity">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			<div className="pp-tabs" role="tablist" aria-label="نوع فعالیت">
				{ GROUPS.map( ( g ) => (
					<button
						key={ g.key || 'all' }
						type="button"
						role="tab"
						aria-selected={ group === g.key }
						className={ `pp-tab${ group === g.key ? ' is-active' : '' }` }
						onClick={ () => {
							setGroup( g.key );
							setPage( 1 );
						} }
					>
						{ g.label }
					</button>
				) ) }
			</div>

			{ ! data && ! error && <Spinner /> }

			{ data && 0 === data.items.length && (
				<div className="pp-card">
					<EmptyState
						title="فعالیتی ثبت نشده است"
						text={ group ? 'در این دسته هنوز فعالیتی نیست.' : 'با اولین اسکن سایت، فعالیت‌ها اینجا ثبت می‌شوند.' }
					/>
				</div>
			) }

			{ data && data.items.length > 0 && (
				<>
					<div className="pp-card pp-card--flush">
						<table className="pp-table">
							<thead>
								<tr>
									<th scope="col">زمان</th>
									<th scope="col">نوع</th>
									<th scope="col">شرح</th>
									<th scope="col">انجام‌دهنده</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( item ) => (
									<tr key={ item.id }>
										<td className="pp-nowrap" title={ fullDate( item.created_at ) }>{ ago( item.created_at ) }</td>
										<td><span className={ badgeClass( item.event ) }>{ GROUP_LABELS[ item.group ] || item.group }</span></td>
										<td>
											{ item.message }
											{ item.link && <> <a href={ item.link }>ویرایش</a></> }
										</td>
										<td className="pp-nowrap">{ item.user }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<p className="pp-muted pp-table-note">
						{ num( data.total ) } فعالیت. گزارش‌های قدیمی‌تر از { num( data.retention_days ) } روز خودکار پاک می‌شوند.
					</p>
					{ data.pages > 1 && (
						<div className="pp-pagination">
							<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
							<span>صفحه { num( page ) } از { num( data.pages ) }</span>
							<Button variant="secondary" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
						</div>
					) }
				</>
			) }
		</Page>
	);
}
