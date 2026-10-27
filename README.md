# designplugin

افزونه‌ی **میران (Miran)** برای صفحات فروشگاه، وبلاگ، محصول و مقاله (وردپرس + ووکامرس + WoodMart).
هسته‌ی کد از افزونه‌ی سرویا ۳.۲.۳ آمده و در نسخه **۳.۳.۰** با هویت برند میران (پالت قهوه‌ای/کرافت + متن‌ها و شماره‌های تماس) ری‌برند شده است. شناسه‌های فنی (شورت‌کد `[serviya_*]`، REST `serviya/v1`، پارامترهای `sb_*`) عمداً تغییر نکرده‌اند.

## ساختار مخزن

```text
serviya/            کد افزونه (منبع حقیقت)
  serviya.php       بوت‌استرپ + هدر افزونه
  modules/          shop.php · blog.php · product.php · post.php
  assets/css|js/    فایل‌های خوانا + build های *.min.*
  README-fa.md      راهنمای فارسی کاربر و تاریخچه نسخه‌ها
docs/
  PROJECT-MAP.md    نقشه کامل کد: هوک‌ها، شورت‌کدها، توکن‌های طراحی، بدهی فنی
  SPEC-AUDIT.md     ممیزی بند‌به‌بند کد در برابر هایپرپرامپت پروژه (با ارجاع فایل:خط)
  REBRAND.md        چک‌لیست اجرایی فاز ۰ تا ۵: بکاپ، WP-CLI، پاک‌سازی داده، QA عددی
  MIRAN-PLAN.md     برنامه و سؤالات باز پروژه جدید (کلون سرویا برای سایت جدید)
  BRAND.md          پالت میران، کنتراست‌ها، نگاشت توکن‌ها، دسته‌بندی‌ها و اسلاگ‌ها
tools/
  build.mjs         مینیفای CSS/JS
  lint-php.mjs      بررسی سینتکس PHP بدون باینری php
  package.mjs       ساخت ZIP نصب‌شدنی
  rebrand.mjs       جایگزینی رنگ/متن برند بدون دست‌زدن به شناسه‌های فنی
rebrand.config.example.json   نمونه‌ی نگاشت رنگ/متن برای ری‌برند
```

## کارهای رایج

```bash
npm install          # نصب ابزارهای بیلد (clean-css + terser + php-parser)
npm run build        # بازسازی همه‌ی فایل‌های *.min.css و *.min.js
npm run build:check  # فقط بررسی به‌روز بودن فایل‌های min
npm run lint:php     # بررسی سینتکس PHP بدون نیاز به باینری php
npm run check        # lint:php + build:check (قبل از کامیت)
npm run package      # ساخت release/serviya-<version>.zip برای نصب در وردپرس

# ری‌برند (رنگ/متن) بدون تغییر شورت‌کد و REST:
cp rebrand.config.example.json rebrand.config.json   # مقادیر برند جدید را پر کنید
node tools/rebrand.mjs --dry-run                     # گزارش تغییرات
node tools/rebrand.mjs --write                       # بکاپ + اعمال + بازسازی min
```

- فایل‌های خوانا (`serviya-*.css`, `serviya-*.js`) را ویرایش کنید، نه `.min.*` را.
- نسخه را هم‌زمان در `serviya/serviya.php` (هدر + `SERVIYA_VERSION`) و `package.json` به‌روز کنید.
- شورت‌کدها: `[serviya_shop]` · `[serviya_blog]` · `[serviya_product]` · `[serviya_post]`

## مستندات

- راهنمای نصب و استفاده: [`serviya/README-fa.md`](serviya/README-fa.md)
- نقشه فنی کد: [`docs/PROJECT-MAP.md`](docs/PROJECT-MAP.md)
- نقشه راه پروژه جدید: [`docs/MIRAN-PLAN.md`](docs/MIRAN-PLAN.md)

> ⚠️ فایل `github-recovery-codes.txt` در ریشه‌ی مخزن، کدهای بازیابی ۲FA است و نباید در گیت باشد؛ آن را حذف و کدها را باطل کنید.

## برند میران (نسخه ۳.۳.۱)

| مورد | مقدار |
|---|---|
| برند | میران / Miran — `mirangiftshop.ir` |
| Accent (کرافت) | `#D9A86C` · hover `#C8924F` |
| Dark | `#46270B` |
| Primary (متن/لینک) | `#7A4A16` |
| قیمت نهایی | `#A5321A` (۶٫۸۴:۱ روی سفید) |
| لیبل تخفیف | `#FF047E` |
| تماس پیش‌فرض | `09382420272` · واتس‌اپ `989382420272` |
| لوگو | `https://mirangiftshop.ir/wp-content/uploads/2026/10/logo-1-1.webp` |

پیش‌نمایش پالت با CSS واقعی افزونه: `preview/brand-preview.html` (سرو محلی: `python3 -m http.server 8080` → `/preview/brand-preview.html`).
