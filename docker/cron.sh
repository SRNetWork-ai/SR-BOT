#!/bin/sh
# حلقهٔ اجرای وظایف دوره‌ای (جایگزین cron داخل کانتینر)
# پیش‌فرض: هر ۳۰۰ ثانیه یک‌بار — با CRON_INTERVAL قابل تغییر است
set -u

APP_ROOT="${APP_ROOT:-/var/www/html}"
INTERVAL="${CRON_INTERVAL:-300}"
LOG="$APP_ROOT/storage/logs/cron-docker.log"

cd "$APP_ROOT" || exit 1
mkdir -p "$APP_ROOT/storage/logs" 2>/dev/null || true
echo "sr-bot cron loop started (every ${INTERVAL}s)"

while true; do
    if [ -f "$APP_ROOT/config.php" ]; then
        if ! php "$APP_ROOT/cron/tasks.php" >> "$LOG" 2>&1; then
            echo "cron run failed at $(date -u '+%Y-%m-%d %H:%M:%S') UTC" >> "$LOG"
        fi
    else
        echo "waiting for config.php (run the web installer first)"
    fi
    sleep "$INTERVAL"
done
