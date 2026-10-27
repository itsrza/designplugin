# designplugin

افزونه‌ی طراحی سرویا برای صفحات فروشگاه، وبلاگ، محصول و مقاله (وردپرس + ووکامرس + WoodMart).

## ساختار مخزن

```text
serviya/            کد افزونه (منبع حقیقت)
  serviya.php       بوت‌استرپ + هدر افزونه
  modules/          shop.php · blog.php · product.php · post.php
  assets/css|js/    فایل‌های خوانا + build های *.min.*
  README-fa.md      راهنمای فارسی کاربر و تاریخچه نسخه‌ها
docs/
  PROJECT-MAP.md    نقشه کامل کد: هوک‌ها، شورت‌کدها، توکن‌های طراحی، بدهی فنی
  MIRAN-PLAN.md     برنامه و چک‌لیست پروژه جدید «میران»
tools/              اسکریپت‌های بیلد و بسته‌بندی
```

## کارهای رایج

```bash
npm install          # نصب ابزارهای بیلد (clean-css + terser + php-parser)
npm run build        # بازسازی همه‌ی فایل‌های *.min.css و *.min.js
npm run build:check  # فقط بررسی به‌روز بودن فایل‌های min
npm run lint:php     # بررسی سینتکس PHP بدون نیاز به باینری php
npm run check        # lint:php + build:check (قبل از کامیت)
npm run package      # ساخت release/serviya-<version>.zip برای نصب در وردپرس
```

- فایل‌های خوانا (`serviya-*.css`, `serviya-*.js`) را ویرایش کنید، نه `.min.*` را.
- نسخه را هم‌زمان در `serviya/serviya.php` (هدر + `SERVIYA_VERSION`) و `package.json` به‌روز کنید.
- شورت‌کدها: `[serviya_shop]` · `[serviya_blog]` · `[serviya_product]` · `[serviya_post]`

## مستندات

- راهنمای نصب و استفاده: [`serviya/README-fa.md`](serviya/README-fa.md)
- نقشه فنی کد: [`docs/PROJECT-MAP.md`](docs/PROJECT-MAP.md)
- نقشه راه پروژه جدید: [`docs/MIRAN-PLAN.md`](docs/MIRAN-PLAN.md)

> ⚠️ فایل `github-recovery-codes.txt` در ریشه‌ی مخزن، کدهای بازیابی ۲FA است و نباید در گیت باشد؛ آن را حذف و کدها را باطل کنید.
