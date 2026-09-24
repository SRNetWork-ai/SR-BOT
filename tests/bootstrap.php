<?php
declare(strict_types=1);

/**
 * بوت‌استرپ تست‌ها
 *
 * تست‌ها به دیتابیس، config.php یا اینترنت نیاز ندارند؛ فقط توابع کمکی خالص
 * (app/Helpers.php) بارگذاری می‌شوند تا روی هر سیستمی قابل اجرا باشند.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/app/Helpers.php';
