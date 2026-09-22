#!/bin/sh
# اینتری‌پوینت کانتینر app: ساخت پوشه‌های نوشتنی، اصلاح دسترسی، انتظار برای دیتابیس
set -e

APP_ROOT="${APP_ROOT:-/var/www/html}"
cd "$APP_ROOT" || exit 1

mkdir -p storage/logs storage/backups storage/updates storage/receipts storage/tmp storage/uploads 2>/dev/null || true
chown -R www-data:www-data storage 2>/dev/null || true
chmod -R 0775 storage 2>/dev/null || true
if [ -f config.php ]; then
    chown www-data:www-data config.php 2>/dev/null || true
    chmod 0640 config.php 2>/dev/null || true
fi

# انتظار برای بالا آمدن دیتابیس
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-3306}"
if [ -n "$DB_HOST" ]; then
    i=0
    while [ "$i" -lt 60 ]; do
        if php -r '$h=getenv("DB_HOST"); $p=(int)(getenv("DB_PORT") ?: 3306); $s=@fsockopen($h,$p,$e,$m,2); if($s){fclose($s); exit(0);} exit(1);' 2>/dev/null; then
            echo "database ${DB_HOST}:${DB_PORT} is up"
            break
        fi
        i=$((i + 1))
        echo "waiting for database ${DB_HOST}:${DB_PORT} ... (${i})"
        sleep 2
    done
    if [ "$i" -ge 60 ]; then
        echo "WARNING: database ${DB_HOST}:${DB_PORT} not reachable, continuing anyway"
    fi
fi

if [ ! -f "$APP_ROOT/config.php" ]; then
    echo "--------------------------------------------------------------"
    echo " config.php not found — open the web installer to finish setup:"
    echo "   http://<server-ip>:${SRB_PORT:-8080}/install/"
    echo "   database host: db   |   port: 3306"
    echo "--------------------------------------------------------------"
fi

exec "$@"
