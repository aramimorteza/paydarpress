# PaydarPress | پایدارپرس

افزونه وردپرس برای شناخت، ممیزی، برنامه‌ریزی و تولید هوشمند محتوا — محصولی از **PaydarWP (پایدار وردپرس)**.

## وضعیت

در حال توسعه — **فاز 1: Core، Site Intelligence و Health Audit** (بدون نیاز به API هوش مصنوعی).

## مستندات

| فایل | توضیح |
|---|---|
| [docs/architecture.md](docs/architecture.md) | سند معماری کامل محصول (چشم‌انداز همه فازها) |
| [docs/phase1-spec.md](docs/phase1-spec.md) | مشخصات فنی فاز 1 (مبنای پیاده‌سازی فعلی) |
| [docs/contentmaster.md](docs/contentmaster.md) | مسترپرامپت ContentMaster |
| [docs/site-inventory.example.json](docs/site-inventory.example.json) | نمونه خروجی JSON |
| [docs/content-inventory-template.csv](docs/content-inventory-template.csv) | قالب فهرست محتوا برای استفاده دستی با ContentMaster |

## نیازمندی‌ها

- WordPress 6.4 یا بالاتر
- PHP 8.0 یا بالاتر

## ساختار مخزن

```text
docs/               مستندات
plugin/paydarpress/ کد افزونه (این پوشه به‌صورت zip نصب می‌شود)
```

## توسعه

```bash
cd plugin/paydarpress
composer install      # وابستگی‌ها (Action Scheduler)
npm install && npm run build   # رابط کاربری مدیریت
```
