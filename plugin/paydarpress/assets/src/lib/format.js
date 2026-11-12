/**
 * Persian number formatting.
 *
 * @param {number} value Number.
 */
export function num( value ) {
	return Number( value || 0 ).toLocaleString( 'fa-IR' );
}

/**
 * Jalali date (e.g. ۱۴۰۵/۸/۲۱) from an ISO string.
 *
 * @param {string|null} iso ISO date.
 */
export function date( iso ) {
	if ( ! iso ) {
		return '—';
	}
	try {
		return new Intl.DateTimeFormat( 'fa-IR-u-ca-persian', { year: 'numeric', month: 'numeric', day: 'numeric' } ).format( new Date( iso ) );
	} catch ( e ) {
		return iso.slice( 0, 10 );
	}
}

/**
 * Relative time in Persian, e.g. «۵ دقیقه پیش».
 *
 * @param {string|null} iso ISO date.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const seconds = Math.max( 0, ( Date.now() - new Date( iso ).getTime() ) / 1000 );
	try {
		const rtf = new Intl.RelativeTimeFormat( 'fa', { numeric: 'auto' } );
		if ( seconds < 60 ) {
			return rtf.format( -Math.round( seconds ), 'second' );
		}
		if ( seconds < 3600 ) {
			return rtf.format( -Math.round( seconds / 60 ), 'minute' );
		}
		if ( seconds < 86400 ) {
			return rtf.format( -Math.round( seconds / 3600 ), 'hour' );
		}
		return rtf.format( -Math.round( seconds / 86400 ), 'day' );
	} catch ( e ) {
		return date( iso );
	}
}

export const TYPE_LABELS = {
	post: 'نوشته',
	page: 'برگه',
	product: 'محصول',
};

export const STATUS_LABELS = {
	publish: 'منتشرشده',
	draft: 'پیش‌نویس',
	pending: 'در انتظار بررسی',
	future: 'زمان‌بندی‌شده',
	private: 'خصوصی',
};

export const LINK_STATE_LABELS = {
	ok: 'سالم',
	missing: 'خراب (صفحه وجود ندارد)',
	unpublished: 'به محتوای منتشرنشده',
	redirect: 'ریدایرکت‌شده',
	unknown: '—',
	pending: 'در حال بررسی',
};
