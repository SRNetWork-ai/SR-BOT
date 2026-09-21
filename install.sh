#!/usr/bin/env bash
# SR-BOT — نصاب هوشمند (install.sh) + نصب مدیر خط فرمان sr-ui
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/main/install.sh)
#
# متغیرهای اختیاری برای نصب بی‌سکوت:
#   SRB_NONINTERACTIVE=1 SRB_DOMAIN=shop.example.com SRB_PORT=80 SRB_SSL=1 bash install.sh
set -euo pipefail

REPO="${SRB_REPO:-SRNetWork-ai/SR-BOT}"
BRANCH="${SRB_BRANCH:-main}"
ROOT_DIR="${SRB_ROOT:-/var/www/sr-bot}"
CONF_DIR="/etc/sr-bot"
CONF_FILE="$CONF_DIR/sr-ui.conf"
CRED_FILE="/root/sr-bot-install.txt"
DOMAIN="${SRB_DOMAIN:-}"
PORT="${SRB_PORT:-}"
WANT_SSL="${SRB_SSL:-0}"
NONINT="${SRB_NONINTERACTIVE:-0}"
DB_NAME="${SRB_DB_NAME:-srbot}"
DB_USER="${SRB_DB_USER:-srbot}"
DB_PASS="${SRB_DB_PASS:-}"
DB_PREFIX="${SRB_DB_PREFIX:-vs_}"
PHPBIN="php"
WEBUSER="www-data"
FPM_SERVICE="php-fpm"
FPM_PASS="127.0.0.1:9000"
VHOST=""
PKG=""
ANS=""

C0=$'\033[0m'; CB=$'\033[1;36m'; CG=$'\033[1;32m'; CY=$'\033[1;33m'; CR=$'\033[1;31m'
say(){ printf '%s %s\n' "${CB}==>${C0}" "$*"; }
ok(){ printf '%s %s\n' "${CG}[ok]${C0}" "$*"; }
warn(){ printf '%s %s\n' "${CY}[!]${C0}" "$*"; }
die(){ printf '%s %s\n' "${CR}[x]${C0}" "$*" >&2; exit 1; }

ask(){
  local __var="$1" __msg="$2" __def="${3:-}" __ans=""
  if [ "$NONINT" = "1" ]; then printf -v "$__var" '%s' "$__def"; return 0; fi
  if [ -n "$__def" ]; then read -r -p "$__msg [$__def]: " __ans || true
  else read -r -p "$__msg: " __ans || true; fi
  if [ -z "$__ans" ]; then __ans="$__def"; fi
  printf -v "$__var" '%s' "$__ans"
}

rnd(){
  local n="${1:-16}"
  if command -v openssl >/dev/null 2>&1; then openssl rand -hex "$n"
  else date +%s%N | sha256sum | cut -c "1-$((n * 2))"; fi
}

banner(){
  printf '%s\n' "${CB}=================================================${C0}"
  printf '%s\n' "   SR-BOT — نصاب هوشمند | مدیریت با دستور sr-ui"
  printf '%s\n' "${CB}=================================================${C0}"
}

need_root(){ if [ "$(id -u)" != "0" ]; then die "لطفاً با کاربر root اجرا کنید."; fi; }

detect_os(){
  [ -r /etc/os-release ] || die "فایل /etc/os-release پیدا نشد؛ این توزیع پشتیبانی نمی‌شود."
  # shellcheck disable=SC1091
  . /etc/os-release
  case "${ID:-linux}" in
    ubuntu|debian|linuxmint|pop) PKG="apt"; WEBUSER="www-data" ;;
    centos|rhel|almalinux|rocky|fedora|ol)
      WEBUSER="nginx"
      if command -v dnf >/dev/null 2>&1; then PKG="dnf"; else PKG="yum"; fi ;;
    *)
      if command -v apt-get >/dev/null 2>&1; then PKG="apt"; WEBUSER="www-data"
      elif command -v dnf >/dev/null 2>&1; then PKG="dnf"; WEBUSER="nginx"
      else die "فقط توزیع‌های مبتنی بر apt یا dnf پشتیبانی می‌شوند."; fi ;;
  esac
  ok "سیستم‌عامل: ${PRETTY_NAME:-linux} — مدیر بسته: $PKG"
}

pkg_update(){
  if [ "$PKG" = "apt" ]; then DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null 2>&1 || true
  else $PKG makecache -y >/dev/null 2>&1 || true; fi
}

pkg_add(){
  if [ "$PKG" = "apt" ]; then DEBIAN_FRONTEND=noninteractive apt-get install -y "$@" >/dev/null 2>&1
  else $PKG install -y "$@" >/dev/null 2>&1; fi
}

install_deps(){
  say "نصب پیش‌نیازها (چند دقیقه طول می‌کشد)…"
  pkg_update
  if [ "$PKG" = "apt" ]; then
    pkg_add curl tar unzip ca-certificates openssl cron nginx mariadb-server || die "نصب بسته‌های پایه ناموفق بود."
    pkg_add php-fpm php-cli php-mysql php-mbstring php-curl php-zip php-gd php-xml php-bcmath php-intl || warn "بعضی افزونه‌های PHP نصب نشدند."
  else
    pkg_add curl tar unzip ca-certificates openssl cronie nginx mariadb-server || die "نصب بسته‌های پایه ناموفق بود."
    pkg_add php-fpm php-cli php-mysqlnd php-mbstring php-gd php-xml php-bcmath php-intl || warn "بعضی افزونه‌های PHP نصب نشدند."
    pkg_add php-pecl-zip || true
  fi
  command -v php >/dev/null 2>&1 || die "PHP نصب نشد."
  ok "پیش‌نیازها نصب شد."
}

check_php(){
  local v
  v="$(php -r 'echo PHP_VERSION;')"
  if [ "$(printf '%s\n8.1.0\n' "$v" | sort -V | head -n1)" != "8.1.0" ]; then
    warn "نسخهٔ PHP شما $v است؛ حداقل نسخهٔ پیشنهادی 8.1 است."
  else
    ok "PHP نسخهٔ $v"
  fi
}

detect_fpm(){
  local s sock
  s="$(systemctl list-unit-files --type=service 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-?fpm\.service$' | head -n1 || true)"
  if [ -n "$s" ]; then FPM_SERVICE="${s%.service}"; fi
  sock="$(ls /run/php/php*-fpm.sock /run/php-fpm/www.sock /var/run/php-fpm/www.sock 2>/dev/null | head -n1 || true)"
  if [ -n "$sock" ]; then FPM_PASS="unix:$sock"; fi
}

svc_up(){
  systemctl enable --now nginx >/dev/null 2>&1 || warn "nginx راه‌اندازی نشد."
  systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql >/dev/null 2>&1 || warn "سرویس دیتابیس راه‌اندازی نشد."
  systemctl enable --now "$FPM_SERVICE" >/dev/null 2>&1 || warn "php-fpm راه‌اندازی نشد."
  if [ "$PKG" = "apt" ]; then systemctl enable --now cron >/dev/null 2>&1 || true
  else systemctl enable --now crond >/dev/null 2>&1 || true; fi
}

setup_db(){
  if [ -z "$DB_PASS" ]; then DB_PASS="$(rnd 12)"; fi
  local -a M=(-uroot)
  if ! mysql "${M[@]}" -e "SELECT 1" >/dev/null 2>&1; then
    local rp=""
    ask rp "رمز کاربر root دیتابیس" ""
    M=(-uroot "-p$rp")
    mysql "${M[@]}" -e "SELECT 1" >/dev/null 2>&1 || die "اتصال به MySQL/MariaDB با کاربر root ناموفق بود."
  fi
  mysql "${M[@]}" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql "${M[@]}" -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  mysql "${M[@]}" -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  mysql "${M[@]}" -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
  mysql "${M[@]}" -e "FLUSH PRIVILEGES;"
  ok "دیتابیس آماده شد: $DB_NAME (کاربر: $DB_USER)"
}

fetch_src(){
  local tmp src
  tmp="$(mktemp -d)"
  say "دریافت سورس از GitHub ($REPO@$BRANCH)…"
  curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" -o "$tmp/src.tgz" || die "دانلود سورس ناموفق بود."
  mkdir -p "$tmp/x"
  tar -xzf "$tmp/src.tgz" -C "$tmp/x"
  src="$(find "$tmp/x" -mindepth 1 -maxdepth 1 -type d | head -n1)"
  [ -n "$src" ] || die "محتوای بستهٔ دانلودشده معتبر نیست."
  if [ -f "$ROOT_DIR/config.php" ]; then
    cp -a "$ROOT_DIR/config.php" "$tmp/config.php.bak"
    say "نصب قبلی پیدا شد؛ فقط فایل‌های برنامه به‌روزرسانی می‌شوند."
  fi
  mkdir -p "$ROOT_DIR"
  cp -a "$src/." "$ROOT_DIR/"
  if [ -f "$tmp/config.php.bak" ]; then cp -a "$tmp/config.php.bak" "$ROOT_DIR/config.php"; fi
  mkdir -p "$ROOT_DIR/storage/logs" "$ROOT_DIR/storage/backups" "$ROOT_DIR/storage/tmp" "$ROOT_DIR/storage/uploads"
  rm -rf "$tmp"
  ok "فایل‌ها در $ROOT_DIR نصب شدند."
}

setup_perms(){
  id -u "$WEBUSER" >/dev/null 2>&1 || WEBUSER="nobody"
  chown -R "$WEBUSER":"$WEBUSER" "$ROOT_DIR"
  find "$ROOT_DIR" -type d -exec chmod 755 {} +
  find "$ROOT_DIR" -type f -exec chmod 644 {} +
  chmod -R 775 "$ROOT_DIR/storage"
  if [ -f "$ROOT_DIR/config.php" ]; then chmod 640 "$ROOT_DIR/config.php"; fi
  if [ -f "$ROOT_DIR/tools/lint.sh" ]; then chmod 755 "$ROOT_DIR/tools/lint.sh"; fi
  ok "مجوز فایل‌ها تنظیم شد (کاربر وب: $WEBUSER)"
}

setup_nginx(){
  local avail="/etc/nginx/conf.d"
  if [ -d /etc/nginx/sites-available ]; then avail="/etc/nginx/sites-available"; fi
  VHOST="$avail/sr-bot.conf"
  cat >"$VHOST" <<'NGX'
server {
    listen __PORT__;
    server_name __DOMAIN__;
    root __ROOT__;
    index index.php;
    charset utf-8;
    client_max_body_size 32m;

    access_log /var/log/nginx/sr-bot.access.log;
    error_log  /var/log/nginx/sr-bot.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_pass __FPM__;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 180;
    }

    location ~* ^/(app|database|cron|tools)/ { deny all; return 404; }
    location ~* ^/(config\.php|config\.sample\.php|composer\.json|phpstan\.neon\.dist)$ { deny all; return 404; }
    location ~* ^/storage/(logs|backups|tmp)/ { deny all; return 404; }
    location ~ /\.(?!well-known) { deny all; return 404; }
}
NGX
  sed -i "s|__PORT__|$PORT|g" "$VHOST"
  sed -i "s|__DOMAIN__|${DOMAIN:-_}|g" "$VHOST"
  sed -i "s|__ROOT__|$ROOT_DIR|g" "$VHOST"
  sed -i "s|__FPM__|$FPM_PASS|g" "$VHOST"
  if [ -d /etc/nginx/sites-enabled ]; then
    ln -sf "$VHOST" /etc/nginx/sites-enabled/sr-bot.conf
    rm -f /etc/nginx/sites-enabled/default
  fi
  nginx -t >/dev/null 2>&1 || die "پیکربندی nginx معتبر نیست؛ خروجی «nginx -t» را ببینید."
  systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true
  ok "وب‌سرور روی پورت $PORT تنظیم شد."
}

setup_cron(){
  printf '%s\n' "*/5 * * * * $WEBUSER $PHPBIN $ROOT_DIR/cron/tasks.php >/dev/null 2>&1" >/etc/cron.d/sr-bot
  chmod 644 /etc/cron.d/sr-bot
  ok "کران هر ۵ دقیقه فعال شد."
}

open_port(){
  if command -v ufw >/dev/null 2>&1; then
    ufw allow "$PORT/tcp" >/dev/null 2>&1 || true
    ufw allow 443/tcp >/dev/null 2>&1 || true
  fi
  if command -v firewall-cmd >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port="$PORT/tcp" >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-port=443/tcp >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
  fi
}

selinux_fix(){
  if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce 2>/dev/null || echo Disabled)" = "Enforcing" ]; then
    setsebool -P httpd_can_network_connect 1 >/dev/null 2>&1 || true
    if command -v semanage >/dev/null 2>&1; then semanage fcontext -a -t httpd_sys_rw_content_t "$ROOT_DIR/storage(/.*)?" >/dev/null 2>&1 || true; fi
    if command -v restorecon >/dev/null 2>&1; then restorecon -R "$ROOT_DIR" >/dev/null 2>&1 || true; fi
  fi
}

install_srui(){
  if [ -f "$ROOT_DIR/tools/sr-ui" ]; then
    install -m 0755 "$ROOT_DIR/tools/sr-ui" /usr/local/bin/sr-ui
    ln -sf /usr/local/bin/sr-ui /usr/local/bin/srui
    ok "دستور sr-ui نصب شد."
  else
    warn "فایل tools/sr-ui در سورس نبود؛ دستور sr-ui نصب نشد."
  fi
}

save_conf(){
  mkdir -p "$CONF_DIR"
  chmod 750 "$CONF_DIR"
  cat >"$CONF_FILE" <<EOF
ROOT_DIR="$ROOT_DIR"
DOMAIN="$DOMAIN"
PORT="$PORT"
WEBUSER="$WEBUSER"
FPM_SERVICE="$FPM_SERVICE"
PHPBIN="$PHPBIN"
DB_NAME="$DB_NAME"
DB_USER="$DB_USER"
DB_PASS="$DB_PASS"
DB_PREFIX="$DB_PREFIX"
REPO="$REPO"
BRANCH="$BRANCH"
VHOST="$VHOST"
PKG="$PKG"
EOF
  chmod 600 "$CONF_FILE"
  cat >"$CRED_FILE" <<EOF
SR-BOT — اطلاعات نصب
مسیر نصب : $ROOT_DIR
دیتابیس  : $DB_NAME
کاربر DB : $DB_USER
رمز DB   : $DB_PASS
پیشوند   : $DB_PREFIX
مدیریت   : دستور sr-ui
EOF
  chmod 600 "$CRED_FILE"
}

setup_ssl(){
  if [ -z "$DOMAIN" ]; then warn "بدون دامنه SSL نصب نمی‌شود."; return 0; fi
  say "نصب گواهی SSL برای $DOMAIN …"
  pkg_add certbot python3-certbot-nginx || true
  if command -v certbot >/dev/null 2>&1; then
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect >/dev/null 2>&1; then
      ok "SSL فعال شد: https://$DOMAIN"
    else
      warn "نصب SSL ناموفق بود؛ بعداً با «sr-ui ssl» دوباره تلاش کنید."
    fi
  else
    warn "certbot نصب نشد."
  fi
}

summary(){
  local host="$DOMAIN"
  if [ -z "$host" ]; then host="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"; fi
  local url="http://$host"
  if [ "$PORT" != "80" ]; then url="$url:$PORT"; fi
  printf '\n'
  printf '%s\n' "${CG}=================================================${C0}"
  printf '%s\n' " نصب پایه با موفقیت انجام شد 🎉"
  printf '%s\n' "${CG}=================================================${C0}"
  printf '%s\n' " ۱) نصاب وب را باز کنید: $url/install/"
  printf '%s\n' " ۲) در نصاب، این مقادیر دیتابیس را وارد کنید:"
  printf '%s\n' "      هاست  : localhost"
  printf '%s\n' "      نام   : $DB_NAME"
  printf '%s\n' "      کاربر : $DB_USER"
  printf '%s\n' "      رمز   : $DB_PASS"
  printf '%s\n' "      پیشوند: $DB_PREFIX"
  printf '%s\n' " ۳) توکن ربات و آیدی مدیر را وارد کنید تا وبهوک ست شود."
  printf '%s\n' " • این اطلاعات در $CRED_FILE ذخیره شد."
  printf '%s\n' " • مدیریت کامل سرور با دستور: ${CB}sr-ui${C0}"
  printf '\n'
}

main(){
  banner
  need_root
  detect_os
  install_deps
  check_php
  detect_fpm
  svc_up
  detect_fpm
  ask DOMAIN "دامنهٔ پنل (اگر ندارید خالی بگذارید)" "$DOMAIN"
  ask PORT "پورت وب‌سرور" "${PORT:-80}"
  setup_db
  fetch_src
  setup_perms
  setup_nginx
  setup_cron
  open_port
  selinux_fix
  install_srui
  save_conf
  if [ -n "$DOMAIN" ]; then
    if [ "$NONINT" = "1" ]; then
      if [ "$WANT_SSL" = "1" ]; then setup_ssl; fi
    else
      ask ANS "گواهی SSL رایگان نصب شود؟ (y/n)" "y"
      if [ "$ANS" = "y" ] || [ "$ANS" = "Y" ]; then setup_ssl; fi
    fi
  fi
  summary
}

main "$@"
