# SafeChat v0.1.0

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)
![Version](https://img.shields.io/badge/Version-0.1.0-blue)

> ⚠️ **Disclaimer:** This repository is an Experimental Sandbox for evaluating AI capabilities and is not intended as a functional tool or final product.

پیام‌رسان وب‌مبنا با رمزنگاری سرتاسر (E2E)، بدون ثبت‌نام، با پشتیبانی از پیام‌های خصوصی، گروهی و عمومی.

---

## ویژگی‌ها

| | ویژگی | توضیح |
|---|------|-------|
| 🔒 | رمزنگاری E2E | پیام‌های خصوصی با AES-256 رمزنگاری می‌شوند |
| 🆔 | بدون ثبت‌نام | شناسایی با Device ID منحصربه‌فرد |
| 💬 | پیام عمومی | پیام‌های عمومی قابل جستجو برای همه |
| 🔐 | پیام قفل‌دار | محافظت پیام با رمز عددی |
| 👥 | گروه چت | ایجاد گروه با کلید رمزنگاری مستقل |
| 🚫 | سیاه‌لیست | مسدودسازی کاربران |
| 📱 | PWA | قابل نصب به‌عنوان اپ موبایل |
| ⚡ | سبک و سریع | بدون وابستگی‌های غیرضروری |

---

## پیش‌نیازها

- PHP 8.3+ (extensions: `pdo_mysql`, `openssl`, `mbstring`, `sodium`, `json`)
- MySQL 8.0+ یا MariaDB 10.6+
- Composer 2.x
- Web server با پشتیبانی از `mod_rewrite` / `try_files`
- HTTPS برای محیط تولید توصیه می‌شود

---

## نصب سریع — Linux/VPS

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/Ho3einK84/SafeChat.git SafeChat
cd /var/www/SafeChat
sudo bash install.sh --help
```

نکته: اگر قصد استفاده از Nginx دارید، پروژه را داخل `/root` نصب نکنید (Nginx معمولاً به `/root` دسترسی ندارد). مسیر پیشنهادی: `/var/www/SafeChat`.

اسکریپت نصب برای Ubuntu 22.04+ و Debian 12+ طراحی شده و به‌صورت idempotent قابل اجرای مجدد است. اسکریپت به‌صورت خودکار:

- وابستگی‌های لازم را نصب می‌کند
- PHP 8.3/8.4 را انتخاب و نصب می‌کند
- Composer را با اعتبارسنجی امضا نصب می‌کند
- در صورت نیاز، MariaDB/MySQL را نصب و برای خطاهای شناخته‌شده startup تلاش به repair انجام می‌دهد
- فایل `.env` را آماده، `ENCRYPTION_KEY` را در صورت نیاز تولید، و دستورات bootstrap لاراول را اجرا می‌کند

برای اجرای نصب کامل نمونه (همراه با دریافت خودکار گواهینامه SSL رایگان):

```bash
sudo bash install.sh --with-nginx --with-db --db-engine mariadb --domain example.com --with-ssl --email admin@example.com
```

**نصب دستی:**

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit .env: fill DB_* and ENCRYPTION_KEY
php artisan key:generate
php artisan migrate --force
chmod -R 775 storage bootstrap/cache
```

---

## نصب CPanel

1. در CPanel → **Select PHP Version**، نسخه **8.3+** را انتخاب و extensionهای `pdo_mysql`، `openssl`، `mbstring`، `sodium` را فعال کنید.
2. در **MySQL Databases** یک دیتابیس و کاربر جدید بسازید و تمام دسترسی‌ها را بدهید.
3. فایل‌های پروژه را در `public_html` آپلود و Extract کنید.
4. Document Root دامنه را به `public_html/SafeChat/public` تغییر دهید.  
   اگر امکانپذیر نبود، فایل `.htaccess` زیر را در `public_html` قرار دهید:
   ```apacheconf
   Options -Indexes
   RewriteEngine On
   RewriteRule ^(.*)$ public/$1 [L]
   ```
5. فایل `.env` را از روی `.env.example` در ریشه پروژه بسازید و مقادیر `DB_*` را پر کنید.  
   برای `ENCRYPTION_KEY` از Terminal اجرا کنید:
   ```bash
   php -r "echo bin2hex(random_bytes(24));"
   ```
6. مجوز `755` یا `775` را روی `storage/` و `bootstrap/cache/` تنظیم کنید.
7. از Terminal یا `/install` مراحل راه‌اندازی را اجرا کنید:
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache
   ```
8. به آدرس `https://yourdomain.com/chat` بروید — اگر رابط SafeChat نمایش داده شد، نصب موفق بود.

**رفع مشکلات رایج CPanel:**

| مشکل | راه‌حل |
|------|---------|
| `500 Internal Server Error` | Permission `storage/` و `bootstrap/cache/` را `755` کنید؛ `APP_KEY` را بررسی کنید |
| `No input file specified` | Document Root را اصلاح کنید یا از `.htaccess` redirect استفاده کنید |
| `Connection refused` | `DB_HOST=localhost` (نه `127.0.0.1`)؛ پیشوند CPanel در نام دیتابیس را بررسی کنید |
| Session از بین می‌رود | Permission `storage/framework/sessions/` را `755` کنید |

---

## امنیت بعد از نصب

- [ ] مسیر `/install` به‌صورت پیش‌فرض در production غیرفعال است. اگر نیاز دارید، فقط به‌صورت موقت با `SAFECHAT_INSTALL_ENABLED=true` و یک `SAFECHAT_INSTALL_TOKEN` قوی فعال کنید
- [ ] مطمئن شوید `APP_DEBUG=false` و `APP_ENV=production` در `.env` است
- [ ] اگر HTTPS دارید، `SESSION_SECURE_COOKIE=true` را تنظیم کنید
- [ ] بعد از اولین ورود، Device ID خود را از پروفایل کپی و در `ADMIN_DEVICE_IDS` قرار دهید
- [ ] `ENCRYPTION_KEY` را در جایی امن پشتیبان بگیرید — بدون آن پیام‌های عمومی رمزگشایی نمی‌شوند

---

## متغیرهای محیطی

| متغیر | پیش‌فرض | توضیح |
|--------|---------|-------|
| `APP_URL` | `http://localhost` | آدرس کامل پروژه |
| `APP_ENV` | `local` | محیط اجرا (`production` برای سرور) |
| `APP_DEBUG` | `false` | حالت debug — در production باید `false` باشد |
| `DB_HOST` | `127.0.0.1` | میزبان دیتابیس |
| `DB_DATABASE` | `safechat` | نام دیتابیس |
| `DB_USERNAME` | `root` | کاربر دیتابیس |
| `DB_PASSWORD` | — | رمز دیتابیس |
| `ENCRYPTION_KEY` | — | کلید رمزنگاری پیام‌ها (حداقل ۳۲ کاراکتر) |
| `ADMIN_DEVICE_IDS` | — | Device IDهای ادمین، جداشده با کاما |
| `SESSION_SECURE_COOKIE` | `true` | فقط با HTTPS ارسال شود |
| `MSG_LIMIT` | `50` | تعداد پیام‌های بارگذاری در هر درخواست |
| `RATE_LIMIT_SEND` | `30` | حداکثر ارسال پیام (درخواست/دقیقه) |
| `SAFECHAT_INSTALL_ENABLED` | `false` | فعال‌سازی موقت صفحه نصب |
| `SAFECHAT_INSTALL_TOKEN` | — | توکن دسترسی به نصب (پیشنهاد: حداقل ۳۲ کاراکتر تصادفی) |
| `SAFECHAT_INSTALL_MARKER` | — | مسیر فایل marker برای غیر فعال‌کردن نصب پس از اولین اجرا |

---

## رفتار install.sh

- اگر `.env` وجود داشته باشد، تا حد امکان از مقادیر موجود استفاده می‌شود و فقط تنظیمات ضروری production همگام می‌شوند
- اگر `DB_PASSWORD` قبلا در `.env` یا `/root/safechat-db-pass.txt` وجود داشته باشد، همان مقدار reuse می‌شود
- اگر `--without-db` استفاده شود، تنظیمات دیتابیس موجود در `.env` حفظ می‌شوند مگر این‌که `--db-name` / `--db-user` / `--db-pass` را صراحتا بدهید
- اگر `--domain` مشخص نشود ولی Nginx فعال باشد، پیکربندی Nginx با `server_name _;` ساخته می‌شود
- اگر `--with-ssl` فعال باشد، وجود `--domain` و `--email` الزامی است
- اگر `mariadb.service` به‌دلیل خطای `ExecStartPost ... status=203/EXEC` روی Ubuntu 24.04 fail شود، اسکریپت override سازگار ایجاد و سرویس را دوباره راه‌اندازی می‌کند

---

## API Endpoints

| Method | Endpoint | توضیح |
|--------|----------|-------|
| `POST` | `/api/init` | ثبت یا claim دستگاه |
| `GET` | `/api/my-id` | دریافت Device ID جاری |
| `GET` | `/api/get-public` | دریافت پیام‌های عمومی |
| `POST` | `/api/send-public` | ارسال پیام عمومی |
| `GET` | `/api/get-private` | دریافت پیام‌های خصوصی |
| `POST` | `/api/send-private` | ارسال پیام خصوصی |
| `GET` | `/api/get-group-messages` | دریافت پیام‌های گروهی |
| `POST` | `/api/send-group-message` | ارسال پیام گروهی |
| `POST` | `/api/edit-message` | ویرایش پیام |
| `POST` | `/api/delete-message` | حذف پیام |
| `POST` | `/api/unlock-message` | باز کردن پیام قفل‌دار |
| `POST` | `/api/mark-seen` | علامت‌گذاری پیام به‌عنوان خوانده‌شده |
| `GET` | `/api/search-messages` | جستجوی پیام‌ها |
| `GET` | `/api/get-conversations` | فهرست مکالمات |
| `GET` | `/api/check-user` | بررسی وجود کاربر |
| `GET` | `/api/get-profile` | دریافت پروفایل |
| `POST` | `/api/update-profile` | بروزرسانی پروفایل |
| `GET` | `/api/get-pubkey` | دریافت کلید عمومی E2E |
| `POST` | `/api/store-pubkey` | ذخیره کلید عمومی E2E |
| `POST` | `/api/block-user` | مسدودسازی کاربر |
| `POST` | `/api/unblock-user` | رفع مسدودسازی |
| `GET` | `/api/get-blocked-users` | فهرست کاربران مسدود |
| `GET` | `/api/get-block-status` | وضعیت مسدودسازی با کاربر |
| `GET` | `/api/get-online-users` | کاربران آنلاین |
| `GET` | `/api/get-groups` | فهرست گروه‌ها |
| `POST` | `/api/create-group` | ایجاد گروه (فقط ادمین) |
| `POST` | `/api/add-member` | اضافه‌کردن عضو به گروه |
| `GET` | `/api/get-group-key` | دریافت کلید رمزنگاری گروه |
| `POST` | `/api/export-chat` | خروجی گرفتن از مکالمه |
| `POST` | `/api/reset-db` | ریست دیتابیس (فقط ادمین) |

---

## مجوز

این پروژه تحت مجوز [MIT](https://opensource.org/licenses/MIT) منتشر شده است.
