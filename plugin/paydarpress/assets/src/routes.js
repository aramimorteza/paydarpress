/**
 * Internal navigation. Order follows the user's workflow (architecture section 12.2).
 * Only Phase 1 sections are listed; later-phase sections are added when they ship.
 */
export const NAV = [
	{ path: '/', label: 'پیشخوان', icon: 'dashboard' },
	{
		group: 'شناخت سایت',
		items: [
			{ path: '/profile', label: 'پروفایل سایت', icon: 'id' },
			{ path: '/inventory', label: 'فهرست محتوا', icon: 'list-view' },
			{ path: '/taxonomies', label: 'دسته‌بندی‌ها و برچسب‌ها', icon: 'category' },
		],
	},
	{
		group: 'سلامت محتوا',
		items: [
			{ path: '/audit', label: 'ممیزی', icon: 'shield' },
			{ path: '/orphans', label: 'محتوای یتیم و لینک‌ها', icon: 'admin-links' },
			{ path: '/images', label: 'سلامت تصاویر', icon: 'format-image' },
			{ path: '/refresh', label: 'پیشنهاد بازنویسی', icon: 'update' },
			{ path: '/visibility', label: 'دیده‌شدن در گوگل و هوش مصنوعی', icon: 'visibility' },
		],
	},
	{
		group: 'تولید محتوا',
		items: [
			{ path: '/topics', label: 'موضوعات پیشنهادی', icon: 'lightbulb' },
			{ path: '/cm-import', label: 'ورود از ContentMaster', icon: 'upload' },
			{ path: '/links', label: 'صف لینک داخلی', icon: 'admin-links' },
		],
	},
	{
		group: 'ورود و خروجی',
		items: [
			{ path: '/gsc', label: 'سرچ کنسول', icon: 'chart-line' },
			{ path: '/export', label: 'خروجی JSON', icon: 'download' },
		],
	},
	{ path: '/activity', label: 'گزارش فعالیت', icon: 'backup' },
	{ path: '/settings', label: 'تنظیمات', icon: 'admin-generic' },
];

/**
 * Flat list of all routes (including hidden ones such as /welcome).
 */
export const ROUTES = [
	...NAV.flatMap( ( entry ) => ( entry.items ? entry.items : [ entry ] ) ),
	{ path: '/welcome', label: 'خوش آمدید', hidden: true },
	{ path: '/wizard', label: 'راه‌اندازی پروفایل', hidden: true },
];
