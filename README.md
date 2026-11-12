# PaydarPress | پایدارپرس

افزونه وردپرس برای شناخت، ممیزی، برنامه‌ریزی و تولید هوشمند محتوا — محصولی از **PaydarWP (پایدار وردپرس)**.

## وضعیت

**فاز 1 (0.6.0) و فاز 1.5 (0.9.0) تکمیل شدند:** ورود سرچ کنسول، ورود موضوعات و پیش‌نویس‌ها از ContentMaster و صف تأیید لینک داخلی. Core، Site Intelligence، Health Audit، پروفایل سایت و خروجی ContentMaster — بدون نیاز به API هوش مصنوعی. گزارش آزمون: [docs/phase1-test-report.md](docs/phase1-test-report.md)

## مستندات

| فایل | توضیح |
|---|---|
| [docs/architecture.md](docs/architecture.md) | سند معماری کامل محصول (چشم‌انداز همه فازها) |
| [docs/phase1-spec.md](docs/phase1-spec.md) | مشخصات فنی فاز 1 (مبنای پیاده‌سازی فعلی) |
| [docs/phase15-spec.md](docs/phase15-spec.md) | مشخصات فاز 1.5: ورود سرچ کنسول، ورود از ContentMaster، صف تأیید لینک |
| [docs/phase1-test-report.md](docs/phase1-test-report.md) | گزارش آزمون عملکرد، هاست اشتراکی و امنیت فاز 1 |
| [docs/contentmaster.md](docs/contentmaster.md) | مسترپرامپت ContentMaster |
| [docs/site-inventory.example.json](docs/site-inventory.example.json) | نمونه خروجی JSON |
| [docs/content-inventory-template.csv](docs/content-inventory-template.csv) | قالب فهرست محتوا برای استفاده دستی با ContentMaster |

## نیازمندی‌ها

- WordPress 6.5 یا بالاتر
- PHP 8.0 یا بالاتر

## ساختار مخزن

```text
docs/               مستندات
plugin/paydarpress/ کد افزونه (این پوشه به‌صورت zip نصب می‌شود)
```

## توسعه

```bash
cd plugin/paydarpress
composer dump-autoload -o   # Autoloader (Action Scheduler در lib/ قرار دارد)
npm install && npm run build   # رابط کاربری مدیریت
```
