# designplugin

افزونه‌ی **میران (Miran)** `v1.0.0` برای صفحات فروشگاه، وبلاگ، محصول و مقاله (وردپرس + ووکامرس + WoodMart).
هسته‌ی کد از افزونه‌ی سرویا ۳.۲.۳ آمده و در نسخه **۱.۰.۰** با هویت برند میران (پالت قهوه‌ای/کرافت + متن‌ها و شماره‌های تماس) ری‌برند شده است. شناسه‌های فنی (شورت‌کد `[miran_*]`، REST `miran/v1`، پارامترهای `sb_*`) عمداً تغییر نکرده‌اند.

## ساختار مخزن

```text
miran/            کد افزونه (منبع حقیقت)
  miran.php       بوت‌استرپ + هدر افزونه
  modules/          shop.php · blog.php · product.php · post.php
  assets/css|js/    فایل‌های خوانا + build های *.min.*
  README-fa.md      راهنمای فارسی کاربر و تاریخچه نسخه‌ها
docs/
  PROJECT-MAP.md    نقشه کامل کد: هوک‌ها، شورت‌کدها، توکن‌های طراحی، بدهی فنی
  SPEC-AUDIT.md     ممیزی بند‌به‌بند کد در برابر هایپرپرامپت پروژه (با ارجاع فایل:خط)
  REBRAND.md        چک‌لیست اجرایی فاز ۰ تا ۵: بکاپ، WP-CLI، پاک‌سازی داده، QA عددی
  MIRAN-PLAN.md     برنامه و سؤالات باز پروژه جدید (کلون سرویا برای سایت جدید)
  BRAND.md          پالت میران، کنتراست‌ها، نگاشت توکن‌ها، دسته‌بندی‌ها و اسلاگ‌ها
  RELEASE.md        چک‌لیست انتشار نسخه و قالب لینک دانلود
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
npm run package      # ساخت release/miran-<version>.zip برای نصب در وردپرس

# ری‌برند (رنگ/متن) بدون تغییر شورت‌کد و REST:
cp rebrand.config.example.json rebrand.config.json   # مقادیر برند جدید را پر کنید
node tools/rebrand.mjs --dry-run                     # گزارش تغییرات
node tools/rebrand.mjs --write                       # بکاپ + اعمال + بازسازی min
```

- فایل‌های خوانا (`miran-*.css`, `miran-*.js`) را ویرایش کنید، نه `.min.*` را.
- نسخه را هم‌زمان در `miran/miran.php` (هدر + `MIRAN_VERSION`) و `package.json` به‌روز کنید.
- شورت‌کدها: `[miran_shop]` · `[miran_blog]` · `[miran_product]` · `[miran_post]`

## انتشار و دانلود

```bash
npm run build && npm run check && npm run package   # → release/miran-<version>.zip
```

- لینک دانلود هر نسخه: `https://github.com/itsrza/designplugin/raw/arena/8c639e3c-designplugin/release/miran-<version>.zip`
- قالب و چک‌لیست انتشار: [`docs/RELEASE.md`](docs/RELEASE.md)
- جدول نسخه‌ها: [`release/README.md`](release/README.md)

## مستندات

- راهنمای نصب و استفاده: [`miran/README-fa.md`](miran/README-fa.md)
- نقشه فنی کد: [`docs/PROJECT-MAP.md`](docs/PROJECT-MAP.md)
- نقشه راه پروژه جدید: [`docs/MIRAN-PLAN.md`](docs/MIRAN-PLAN.md)

> ⚠️ فایل `github-recovery-codes.txt` در ریشه‌ی مخزن، کدهای بازیابی ۲FA است و نباید در گیت باشد؛ آن را حذف و کدها را باطل کنید.

## برند میران (نسخه ۱.۰.۰)

| مورد | مقدار |
|---|---|
| برند | میران / Miran — `mirangiftshop.ir` (بسته‌بندی مقوایی، ظروف یک‌بارمصرف، چاپ اختصاصی) |
| Accent (کرافت) | `#D9A86C` · hover `#C8924F` |
| Dark | `#46270B` |
| Primary (متن/لینک) | `#7A4A16` |
| قیمت نهایی | `#A5321A` (۶٫۸۴:۱ روی سفید) |
| لیبل تخفیف | `#FF047E` |
| تماس پیش‌فرض | `09382420272` · واتس‌اپ `989382420272` |
| لوگو | `https://mirangiftshop.ir/wp-content/uploads/2026/10/logo-1-1.webp` |

پیش‌نمایش پالت با CSS واقعی افزونه: `preview/brand-preview.html` (سرو محلی: `python3 -m http.server 8080` → `/preview/brand-preview.html`).
