# راهنمای اجرای SR-BOT با داکر

اجرای داکری **اختیاری** است. اگر فقط یک سرور ساده دارید، نصب تک‌خطی (`install.sh` و دستور `sr-ui`) ساده‌تر است — [docs/INSTALL-SR-UI.md](INSTALL-SR-UI.md).
داکر وقتی مفید است که می‌خواهید محیط جدا و تکرارپذیر داشته باشید، چند سرویس روی یک سرور اجرا کنید، یا روی سیستم شخصی تست بگیرید.

---

## پیش‌نیاز

```bash
# نصب داکر و افزونهٔ compose (اوبونتو/دبیان/سنت‌او‌اس)
bash <(curl -fsSL https://get.docker.com)
docker compose version
```

---

## راه‌اندازی در چهار گام

```bash
git clone https://github.com/SRNetWork-ai/SR-BOT.git /opt/sr-bot
cd /opt/sr-bot

cp .env.docker.example .env
nano .env            # حداقل DB_PASS و DB_ROOT_PASS را عوض کنید

docker compose up -d --build
docker compose ps
```

سپس آدرس `http://SERVER_IP:8080/install/` را باز کنید و در نصاب وب:

| فیلد | مقدار |
|---|---|
| هاست دیتابیس | `db` (نه localhost) |
| پورت | `3306` |
| نام دیتابیس / کاربر / رمز | همان `DB_NAME` / `DB_USER` / `DB_PASS` در `.env` |
| پیشوند جدول | `vs_` |

بعد از پایان نصاب، فایل `config.php` کنار سورس روی هاست ساخته می‌شود و با ریستارت کانتینرها پاک نمی‌شود.

---

## سرویس‌ها

| سرویس | تصویر | کار |
|---|---|---|
| `db` | `mariadb:11` | دیتابیس؛ داده‌ها در ولوم `dbdata` می‌ماند |
| `app` | ساخت از `Dockerfile` | PHP-FPM 8.3 با `pdo_mysql, zip, gd, intl, bcmath, opcache` |
| `web` | `nginx:alpine` | وب‌سرور روی پورت `WEB_PORT` با وی‌هاست امن `docker/nginx.conf` |
| `cron` | همان تصویر app | اجرای `cron/tasks.php` هر `CRON_INTERVAL` ثانیه (لاگ: `storage/logs/cron-docker.log`) |

پوشهٔ پروژه به صورت bind-mount داخل کانتینرها سوار می‌شود؛ یعنی `config.php`، `storage/` و به‌روزرسانی از داخل پنل همه روی هاست ذخیره می‌شوند.

---

## متغیرهای `.env`

| متغیر | پیش‌فرض | توضیح |
|---|---|---|
| `WEB_PORT` | `8080` | پورتی که روی سرور باز می‌شود |
| `TZ` | `Asia/Tehran` | منطقهٔ زمانی همهٔ کانتینرها |
| `CRON_INTERVAL` | `300` | فاصلهٔ اجرای وظایف دوره‌ای (ثانیه) |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `srbot` / `srbot` / — | دیتابیس برنامه |
| `DB_ROOT_PASS` | — | رمز root دیتابیس |

> تغییر `DB_PASS` **بعد** از اولین اجرا روی دیتابیس موجود اثر ندارد؛ باید داخل خود MariaDB عوض شود.

---

## دستورهای روزمره

```bash
docker compose ps                     # وضعیت سرویس‌ها
docker compose logs -f web app        # لاگ زندهٔ وب و PHP
docker compose logs -f cron           # لاگ وظایف دوره‌ای
docker compose restart app web        # ریستارت
docker compose down                   # خاموش کردن (داده‌ها می‌مانند)
```

اجرای خط فرمان خود برنامه (همان `cli.php`):

```bash
docker compose exec -u www-data app php cli.php version
docker compose exec -u www-data app php cli.php check
docker compose exec -u www-data app php cli.php migrate
docker compose exec -u www-data app php cli.php hook set
docker compose exec -u www-data app php cli.php backup db
docker compose exec -u www-data app php cli.php list
```

---

## بکاپ و بازگردانی

```bash
# بکاپ از داخل برنامه (در storage/backups ذخیره می‌شود)
docker compose exec -u www-data app php cli.php backup full

# بکاپ مستقیم از دیتابیس
docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > srbot-$(date +%F).sql

# بازگردانی
cat srbot-2026-09-22.sql | docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
```

---

## به‌روزرسانی

دو راه دارید:

1. **از داخل پنل وب** (بخش به‌روزرسانی) — چون سورس bind-mount است، همان مسیر روی هاست به‌روز می‌شود. بعد از آن:
   ```bash
   docker compose exec -u www-data app php cli.php migrate
   docker compose restart app
   ```
2. **با گیت**:
   ```bash
   cd /opt/sr-bot
   git pull
   docker compose build app
   docker compose up -d
   docker compose exec -u www-data app php cli.php migrate
   ```

---

## دامنه و SSL

کانتینر `web` فقط HTTP روی `WEB_PORT` می‌دهد. وب‌هوک تلگرام فقط روی HTTPS و پورت‌های 443/80/88/8443 کار می‌کند؛ پس یکی از این دو را انتخاب کنید:

- **پراکسی روی هاست**: یک nginx/caddy روی خود سرور با گواهی Let’s Encrypt که به `127.0.0.1:8080` پراکسی می‌کند (حتماً هدرهای `X-Forwarded-Proto: https` و `X-Forwarded-For` را بفرستید).
- **Cloudflare**: دامنه را به سرور وصل کنید، حالت SSL را Full بگذارید و `WEB_PORT=8080` را پشت پراکسی ببرید.

بعد از آماده شدن HTTPS، آدرس سایت را در تنطیمات برنامه به‌روز کنید و وب‌هوک را دوباره تنطیم کنید:

```bash
docker compose exec -u www-data app php cli.php hook set
docker compose exec -u www-data app php cli.php hook info
```

---

## عیب‌یابی

| نشانه | علت و درمان |
|---|---|
| صفحهٔ `502 Bad Gateway` | کانتینر `app` بالا نیامده: `docker compose logs app` |
| در نصاب، اتصال دیتابیس رد می‌شود | هاست را `db` بنویسید، نه `localhost` |
| `403/404` روی فایل‌های داخلی | طبیعی است؛ `app/`، `cron/`، `tools/`، `docker/`، `storage/` عمداً بسته‌اند |
| خطای دسترسی در آپلود/بکاپ | `docker compose exec app chown -R www-data:www-data storage` |
| وظایف دوره‌ای اجرا نمی‌شوند | `docker compose logs cron` و بررسی وجود `config.php` |
| تغییر پورت | `WEB_PORT` در `.env` را عوض کنید و `docker compose up -d` |

حذف کامل همراه داده‌ها (خطرناک):

```bash
docker compose down -v
```
