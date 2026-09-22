# SR-BOT — تصویر PHP-FPM برای سرویس‌های app و cron
# ساخت: docker compose build   |   اجرا: docker compose up -d
FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    APP_ROOT=/var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev unzip curl ca-certificates default-mysql-client tzdata \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl bcmath opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# تنطیمات PHP مناسب آپلود رسید، بکاپ و به‌روزرسانی
RUN { \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=180'; \
        echo 'expose_php=Off'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/zz-sr-bot.ini

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p storage/logs storage/backups storage/updates storage/receipts storage/tmp storage/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod 0755 docker/entrypoint.sh docker/cron.sh install.sh tools/sr-ui || true

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]
