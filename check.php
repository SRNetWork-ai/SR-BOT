<?php
/**
 * بررسی سلامت فایل‌های PHP پروژه (برای عیب‌یابی خطای syntax)
 * بعد از رفع مشکل این فایل را حذف کنید.
 */
declare(strict_types=1);

/* ==========================================================
   محافظت دسترسی
   این صفحه ساختار کامل فایل‌ها و نسخه PHP را لو می‌دهد؛
   پس فقط برای مدیر کلِ واردشده (یا پیش از پایان نصب) باز می‌شود.
   ========================================================== */
require __DIR__ . '/app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$allowed = false;
if (app_installed()) {
    boot();
    try {
        if (!empty($_SESSION['vs_admin'])) {
            $adm = DB::one('SELECT * FROM {p}admins WHERE id = :id', [':id' => (int)$_SESSION['vs_admin']]);
            $allowed = $adm && (int)($adm['active'] ?? 1) === 1 && Perm::isSuper($adm);
        }
    } catch (Throwable $e) { $allowed = false; }
} else {
    // پیش از نصب، فقط تا وقتی قفل نصب ساخته نشده قابل استفاده است
    $allowed = !is_file(__DIR__ . '/storage/installed.lock');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (!$allowed) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><div dir="rtl" style="font-family:Tahoma,sans-serif;padding:48px;text-align:center;line-height:2">'
        . '<div style="font-size:40px">⛔</div><b>دسترسی مجاز نیست</b><br>'
        . '<span style="color:#667;font-size:13px">برای دیدن این صفحه ابتدا به عنوان «مدیر کل» وارد پنل شوید.</span><br>'
        . '<a href="admin/index.php" style="display:inline-block;margin-top:16px;padding:10px 18px;border-radius:10px;background:#3b82f6;color:#fff;text-decoration:none;font-size:13px">ورود به پنل</a>'
        . '</div>');
}

$root = __DIR__;
$bad  = [];
$ok   = 0;

$dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
foreach (new RecursiveIteratorIterator($dir) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $rel = trim(str_replace($root, '', $file->getPathname()), '/');
    $src = (string)file_get_contents($file->getPathname());
    try {
        token_get_all($src, TOKEN_PARSE);
        $ok++;
    } catch (Throwable $e) {
        $bad[] = ['file' => $rel, 'msg' => $e->getMessage(), 'line' => $e->getLine(), 'size' => strlen($src)];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>بررسی فایل‌ها</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f6f7fb;color:#1f2430;padding:24px;line-height:2}
.box{max-width:860px;margin:0 auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
h1{font-size:18px;margin:0 0 14px}
.ok{color:#0a7d38;font-weight:bold}
.err{color:#c62828;font-weight:bold}
table{width:100%;border-collapse:collapse;margin-top:12px}
td,th{border-bottom:1px solid #eee;padding:8px;text-align:right;font-size:13px;vertical-align:top}
code{background:#f2f3f7;padding:2px 6px;border-radius:6px;direction:ltr;display:inline-block}
.note{margin-top:14px;font-size:13px;color:#667}
</style>
</head>
<body>
<div class="box">
  <h1>📌 بررسی سلامت فایل‌های PHP</h1>
  <p>PHP: <code><?= PHP_VERSION ?></code> — سالم: <span class="ok"><?= $ok ?></span> — خراب: <span class="<?= $bad ? 'err' : 'ok' ?>"><?= count($bad) ?></span></p>
<?php if (!$bad): ?>
  <p class="ok">✅ همه فایل‌ها سالم هستند.</p>
  <p class="note">اگر نصب باز هم خطا داد، مشکل از کد نیست و باید دیتابیس یا دسترسی‌ها را بررسی کنید.</p>
<?php else: ?>
  <table>
    <tr><th>فایل</th><th>خط</th><th>پیام خطا</th><th>حجم</th></tr>
<?php foreach ($bad as $b): ?>
    <tr>
      <td><code><?= htmlspecialchars($b['file']) ?></code></td>
      <td><?= (int)$b['line'] ?></td>
      <td><?= htmlspecialchars($b['msg']) ?></td>
      <td><?= number_format($b['size']) ?> B</td>
    </tr>
<?php endforeach; ?>
  </table>
  <p class="note">فایل‌های بالا را با نسخه سالم جایگزین کنید.</p>
<?php endif; ?>
  <p class="note">🔒 بعد از رفع مشکل این فایل را حذف کنید.</p>
</div>
</body>
</html>
