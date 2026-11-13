/**
 * Navigation: six sections. Each section opens its first page; its other pages are tabs at
 * the top of the page (SectionTabs). Every finding has one home page; other pages only link
 * to it, so nothing is listed twice.
 * `badge` names a count from window.paydarpress.badges.
 */
export const SECTIONS = [
	{
		id: 'today',
		label: 'امروز',
		icon: 'yes-alt',
		hint: 'کار بعدی شما و وضعیت کلی سایت',
		pages: [
			{ path: '/tasks', label: 'کارهای این هفته', badge: 'tasks', accent: true },
			{ path: '/', label: 'نمای کلی' },
		],
	},
	{
		id: 'content',
		label: 'محتوا',
		icon: 'media-document',
		hint: 'همه مطالب، مشکلات، بازنویسی، محتوای یتیم، تصاویر و دسته‌ها',
		pages: [
			{ path: '/inventory', label: 'همه مطالب' },
			{ path: '/audit', label: 'مشکلات', badge: 'audit' },
			{ path: '/refresh', label: 'نیازمند بازنویسی', badge: 'refresh' },
			{ path: '/orphans', label: 'محتوای یتیم', badge: 'orphans' },
			{ path: '/images', label: 'تصاویر' },
			{ path: '/taxonomies', label: 'دسته‌ها و برچسب‌ها' },
		],
	},
	{
		id: 'links',
		label: 'لینک‌ها',
		icon: 'admin-links',
		hint: 'صف تأیید لینک داخلی',
		pages: [ { path: '/links', label: 'صف لینک داخلی', badge: 'links', accent: true } ],
	},
	{
		id: 'create',
		label: 'تولید محتوا',
		icon: 'edit-page',
		hint: 'موضوعات، تولید خودکار با API و روش دستی با ContentMaster',
		pages: [
			{ path: '/topics', label: 'موضوعات', badge: 'topics' },
			{ path: '/auto', label: 'تولید خودکار' },
			{ path: '/cm-import', label: 'ورود پاسخ هوش مصنوعی' },
			{ path: '/export', label: 'خروجی سایت (JSON)' },
		],
	},
	{
		id: 'google',
		label: 'گوگل',
		icon: 'chart-line',
		hint: 'سرچ کنسول، نتیجه تغییرات، ریدایرکت‌ها و دسترسی خزنده‌ها',
		pages: [
			{ path: '/gsc', label: 'سرچ کنسول' },
			{ path: '/changes', label: 'نتیجه تغییرات' },
			{ path: '/redirects', label: 'ریدایرکت‌ها' },
			{ path: '/visibility', label: 'دسترسی خزنده‌ها' },
		],
	},
	{
		id: 'site',
		label: 'سایت من',
		icon: 'admin-home',
		hint: 'پروفایل، صفحات کلیدی، تنظیمات و گزارش فعالیت',
		pages: [
			{ path: '/profile', label: 'پروفایل و صفحات کلیدی' },
			{ path: '/settings', label: 'تنظیمات' },
			{ path: '/activity', label: 'گزارش فعالیت' },
		],
	},
];

/**
 * Section a path belongs to.
 *
 * @param {string} path Route.
 */
export function sectionOf( path ) {
	return SECTIONS.find( ( s ) => s.pages.some( ( p ) => p.path === path ) ) || null;
}

/**
 * Flat list of all routes (including hidden ones such as /welcome).
 */
export const ROUTES = [
	...SECTIONS.flatMap( ( s ) => s.pages ),
	{ path: '/welcome', label: 'خوش آمدید', hidden: true },
	{ path: '/wizard', label: 'راه‌اندازی پروفایل', hidden: true },
];
