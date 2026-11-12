/**
 * Internal navigation, grouped by the order of work: know the site → fix what exists →
 * connect it with links → see it through Google's eyes → produce new content.
 * `badge` names a count from window.paydarpress.badges.
 */
export const NAV = [
	{ path: '/', label: 'پیشخوان', icon: 'dashboard' },
	{
		id: 'know',
		group: 'شناخت سایت',
		hint: 'اول: سایت و کسب‌وکار را به پایدارپرس معرفی کنید.',
		items: [
			{ path: '/profile', label: 'پروفایل سایت', icon: 'id' },
			{ path: '/inventory', label: 'فهرست محتوا', icon: 'list-view' },
			{ path: '/taxonomies', label: 'دسته‌ها و برچسب‌ها', icon: 'category' },
		],
	},
	{
		id: 'improve',
		group: 'بهبود محتوای موجود',
		hint: 'مشکلات محتوای فعلی و نوشته‌هایی که باید بازنویسی شوند.',
		items: [
			{ path: '/audit', label: 'ممیزی و مشکلات', icon: 'shield', badge: 'audit', badgeTitle: 'مشکل مهم' },
			{ path: '/refresh', label: 'پیشنهاد بازنویسی', icon: 'update', badge: 'refresh', badgeTitle: 'نوشته نیازمند بازنویسی' },
			{ path: '/images', label: 'سلامت تصاویر', icon: 'format-image' },
		],
	},
	{
		id: 'links',
		group: 'لینک‌سازی داخلی',
		hint: 'نوشته‌های بی‌لینک را پیدا کنید و لینک‌ها را با تأیید شما درج کنید.',
		items: [
			{ path: '/orphans', label: 'محتوای یتیم', icon: 'admin-links', badge: 'orphans', badgeTitle: 'محتوای بدون لینک ورودی' },
			{ path: '/links', label: 'صف لینک داخلی', icon: 'randomize', badge: 'links', badgeTitle: 'لینک در انتظار تأیید', accent: true },
		],
	},
	{
		id: 'google',
		group: 'گوگل و هوش مصنوعی',
		hint: 'داده‌های سرچ کنسول و دسترسی خزنده‌ها.',
		items: [
			{ path: '/gsc', label: 'سرچ کنسول', icon: 'chart-line' },
			{ path: '/visibility', label: 'دسترسی خزنده‌ها', icon: 'visibility' },
		],
	},
	{
		id: 'create',
		group: 'تولید محتوا با ContentMaster',
		hint: 'خروجی بگیرید، در ContentMaster بنویسید و نتیجه را وارد کنید.',
		items: [
			{ path: '/export', label: 'خروجی سایت (JSON)', icon: 'download' },
			{ path: '/cm-import', label: 'ورود مقالات نوشته‌شده', icon: 'upload' },
			{ path: '/topics', label: 'موضوعات پیشنهادی', icon: 'lightbulb', badge: 'topics', badgeTitle: 'موضوع جدید' },
		],
	},
];

/** Utility links at the bottom of the sidebar. */
export const NAV_FOOTER = [
	{ path: '/activity', label: 'گزارش فعالیت', icon: 'backup' },
	{ path: '/settings', label: 'تنظیمات', icon: 'admin-generic' },
];

/**
 * Flat list of all routes (including hidden ones such as /welcome).
 */
export const ROUTES = [
	...NAV.flatMap( ( entry ) => ( entry.items ? entry.items : [ entry ] ) ),
	...NAV_FOOTER,
	{ path: '/welcome', label: 'خوش آمدید', hidden: true },
	{ path: '/wizard', label: 'راه‌اندازی پروفایل', hidden: true },
];
