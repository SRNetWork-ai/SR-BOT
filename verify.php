<?php

/**
 * صفحهٔ تایید حساب با لینک
 * کاربر روی لینک ارسال‌شده (ایمیل / پیامک / ربات) می‌زند، کد امنیتی را وارد می‌کند
 * و پس از آن حسابش تایید می‌شود. تا وقتی کپچا درست وارد نشود، توکن مصرف نمی‌شود.
 */

require __DIR__ . '/app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------------- کد امنیتی (کپچا) ---------------- */

function vs_cap_chars(): string
{
    return 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
}

function vs_cap_new(): string
{
    $set = vs_cap_chars();
    $n   = strlen($set);
    $s   = '';
    for ($i = 0; $i < 5; $i++) $s .= $set[random_int(0, $n - 1)];
    $_SESSION['vs_cap']    = $s;
    $_SESSION['vs_cap_at'] = time();
    return $s;
}

function vs_cap_ok(string $in): bool
{
    $want = (string)($_SESSION['vs_cap'] ?? '');
    $at   = (int)($_SESSION['vs_cap_at'] ?? 0);
    if ($want === '' || $at === 0) return false;
    if (time() - $at > 600) return false;
    $in = strtoupper(trim(en_num($in)));
    $in = preg_replace('/[^A-Z0-9]/', '', $in) ?? '';
    return $in !== '' && hash_equals($want, $in);
}

/** کپچای SVG – بدون نیاز به افزونهٔ GD */
function vs_cap_svg(string $code, string $accent): string
{
    $w = 220;
    $h = 76;
    $o = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" role="img" aria-label="کد امنیتی">';
    $o .= '<rect width="' . $w . '" height="' . $h . '" rx="13" fill="#0f1115"/>';
    for ($i = 0; $i < 7; $i++) {
        $o .= '<path d="M' . random_int(0, $w) . ' ' . random_int(0, $h)
            . ' Q' . random_int(0, $w) . ' ' . random_int(0, $h)
            . ' ' . random_int(0, $w) . ' ' . random_int(0, $h) . '" fill="none" stroke="' . $accent
            . '" stroke-opacity="' . (random_int(12, 32) / 100) . '" stroke-width="' . (random_int(8, 18) / 10) . '"/>';
    }
    for ($i = 0; $i < 42; $i++) {
        $o .= '<circle cx="' . random_int(0, $w) . '" cy="' . random_int(0, $h) . '" r="' . (random_int(4, 11) / 10)
            . '" fill="#7f8aa0" fill-opacity="' . (random_int(20, 60) / 100) . '"/>';
    }
    $len  = strlen($code);
    $step = (int)floor(($w - 40) / max(1, $len));
    for ($i = 0; $i < $len; $i++) {
        $x = 26 + $i * $step + random_int(-3, 3);
        $y = 52 + random_int(-7, 7);
        $o .= '<text x="' . $x . '" y="' . $y . '" fill="' . ($i % 2 === 0 ? '#eaf0fa' : $accent)
            . '" font-family="monospace" font-size="' . random_int(29, 37) . '" font-weight="700"'
            . ' transform="rotate(' . random_int(-27, 27) . ' ' . $x . ' ' . $y . ')">'
            . htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8') . '</text>';
    }
    return $o . '</svg>';
}

/* ---------------- منطق صفحه ---------------- */

$ok      = false;
$stage   = 'error';   // error | captcha | done
$msg     = '';
$err     = '';
$accent  = '#6C8CFF';
$botUser = '';
$token   = '';
$kindTxt = '';
$name    = '';

if (!app_installed()) {
    $msg = 'ربات هنوز نصب نشده است.';
} else {
    try {
        boot();
        $accent  = (string)DB::setting('miniapp_accent', '#6C8CFF');
        $botUser = trim((string)cfg('bot.username', ''));

        $isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
        $token  = trim((string)($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? '')));

        if ($token === '') {
            $msg = 'لینک تایید نامعتبر است.';
        } elseif (!class_exists('Security')) {
            $msg = 'ماژول امنیت در دسترس نیست.';
        } else {
            $peek = Security::peekToken($token);

            if (empty($peek['ok'])) {
                $msg = (string)($peek['message'] ?? 'لینک تایید نامعتبر است.');
            } else {
                $stage   = 'captcha';
                $kindTxt = (string)($peek['kind_txt'] ?? '');
                $name    = (string)($peek['name'] ?? '');

                if ($isPost) {
                    $tries = (int)($_SESSION['vs_cap_try'] ?? 0);

                    if (trim((string)($_POST['website'] ?? '')) !== '') {
                        $err = 'درخواست نامعتبر بود.';
                    } elseif ($tries >= 6) {
                        $err = 'تعداد تلاش بیش از حد مجاز بود. چند دقیقه بعد دوباره امتحان کنید.';
                    } elseif (!vs_cap_ok((string)($_POST['cap'] ?? ''))) {
                        $_SESSION['vs_cap_try'] = $tries + 1;
                        $err = 'کد امنیتی اشتباه است. کد تازه را وارد کنید.';
                    } else {
                        $res = Security::checkToken($token);
                        $ok  = !empty($res['ok']);
                        $msg = (string)($res['message'] ?? '');
                        unset($_SESSION['vs_cap'], $_SESSION['vs_cap_at'], $_SESSION['vs_cap_try']);
                        $stage = $ok ? 'done' : 'error';

                        if ($ok && !empty($res['user']['tg_id'])) {
                            try {
                                Tg::send(
                                    (int)$res['user']['tg_id'],
                                    "✅ <b>حساب شما تایید شد</b>\n\n"
                                    . 'اکنون می‌توانید از تمام امکانات ربات استفاده کنید.'
                                );
                            } catch (Throwable $e) {
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $msg   = 'خطای غیرمنتظره‌ای رخ داد.';
        $stage = 'error';
    }
}

if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $accent)) $accent = '#6C8CFF';
if ($stage === 'done' && $msg === '') $msg = 'حساب شما تایید شد.';
if ($stage === 'error' && $msg === '') $msg = 'تایید حساب انجام نشد.';

$capSvg = $stage === 'captcha' ? vs_cap_svg(vs_cap_new(), $accent) : '';

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="<?= h($accent) ?>">
<title>تایید حساب</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{ --acc: <?= h($accent) ?>; }
*{box-sizing:border-box}
html,body{margin:0;padding:0;min-height:100%}
body{
  background:radial-gradient(1200px 600px at 50% -10%, #1b2340 0%, #0f1115 60%);
  color:#eaf0fa;
  font-family:"Vazirmatn","IRANSans",system-ui,-apple-system,"Segoe UI",sans-serif;
  display:grid;place-items:center;
  padding:24px calc(20px + env(safe-area-inset-right,0px)) calc(24px + env(safe-area-inset-bottom,0px)) calc(20px + env(safe-area-inset-left,0px));
  line-height:1.85;
}
.box{
  width:100%;max-width:430px;background:#171b24;border:1px solid #2a3140;
  border-radius:22px;padding:30px 24px;text-align:center;
  box-shadow:0 20px 60px rgba(0,0,0,.45);
}
.ic{width:78px;height:78px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;font-size:38px}
.ic.ok{background:rgba(52,211,153,.16);color:#34d399}
.ic.no{background:rgba(248,113,113,.16);color:#f87171}
.ic.cap{background:rgba(108,140,255,.16);color:var(--acc);font-size:34px}
h1{font-size:19px;margin:0 0 10px;font-weight:700}
p{margin:0;color:#b9c3d4;font-size:14px}
.who{
  margin-top:14px;font-size:12.5px;color:#7f8aa0;background:#1d222d;
  border:1px solid #2a3140;border-radius:12px;padding:9px 12px;
}
.cap-wrap{margin-top:20px;background:#1d222d;border:1px solid #2a3140;border-radius:16px;padding:12px}
.cap-img{border-radius:12px;overflow:hidden;line-height:0;user-select:none}
.cap-row{display:flex;gap:8px;margin-top:10px}
.cap-row input{
  flex:1 1 auto;min-width:0;padding:13px 14px;border-radius:12px;border:1px solid #2a3140;
  background:#0f1115;color:#eaf0fa;font-family:ui-monospace,Menlo,Consolas,monospace;
  font-size:19px;font-weight:700;letter-spacing:6px;text-align:center;direction:ltr;
  text-transform:uppercase;
}
.cap-row input:focus{outline:0;border-color:var(--acc);box-shadow:0 0 0 3px rgba(108,140,255,.18)}
.cap-row button.rf{
  flex:0 0 auto;width:52px;border-radius:12px;border:1px solid #2a3140;background:#171b24;
  color:#b9c3d4;font-size:19px;cursor:pointer;
}
.cap-row button.rf:hover{color:var(--acc);border-color:var(--acc)}
.hint{margin-top:9px;font-size:11.5px;color:#7f8aa0}
.err{
  margin-top:14px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.4);
  color:#f87171;border-radius:12px;padding:10px 12px;font-size:12.5px;
}
.btn{
  display:block;width:100%;margin-top:16px;padding:14px 18px;border-radius:14px;border:0;
  background:var(--acc);color:#fff;text-decoration:none;font-weight:700;font-size:14px;
  font-family:inherit;cursor:pointer;
}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.gh{background:#1d222d;color:#eaf0fa;border:1px solid #2a3140;margin-top:10px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
.foot{margin-top:20px;font-size:11.5px;color:#7f8aa0}
</style>
</head>
<body>
  <div class="box">

  <?php if ($stage === 'captcha'): ?>

    <div class="ic cap">&#128274;</div>
    <h1>تایید حساب کاربری</h1>
    <p>برای اطمینان از اینکه ربات نیستید، کد امنیتی زیر را وارد کنید.</p>

    <?php if ($name !== '' || $kindTxt !== ''): ?>
      <div class="who">
        <?php if ($name !== ''): ?>👤 <?= h($name) ?><?php endif; ?>
        <?php if ($name !== '' && $kindTxt !== ''): ?> • <?php endif; ?>
        <?php if ($kindTxt !== ''): ?>روش تایید: <?= h($kindTxt) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
      <div class="err">&#9888; <?= h($err) ?></div>
    <?php endif; ?>

    <form method="post" id="f" autocomplete="off">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <input class="hp" type="text" name="website" tabindex="-1" aria-hidden="true">

      <div class="cap-wrap">
        <div class="cap-img"><?= $capSvg ?></div>
        <div class="cap-row">
          <input type="text" name="cap" id="cap" inputmode="latin" maxlength="5"
                 autocomplete="one-time-code" placeholder="— — — — —" required>
          <button type="button" class="rf" id="rf" title="کد تازه">&#8635;</button>
        </div>
        <div class="hint">حروف بزرگ و کوچک تفاوتی ندارد • برای کد تازه دکمهٔ چرخش را بزنید</div>
      </div>

      <button class="btn" type="submit" id="sb">&#10003; تایید و فعال‌سازی حساب</button>
    </form>

  <?php elseif ($stage === 'done'): ?>

    <div class="ic ok">&#10003;</div>
    <h1>حساب شما تایید شد</h1>
    <p><?= h($msg) ?></p>
    <?php if ($botUser !== ''): ?>
      <a class="btn" href="https://t.me/<?= h(ltrim($botUser, '@')) ?>">بازگشت به ربات</a>
    <?php endif; ?>

  <?php else: ?>

    <div class="ic no">&#10007;</div>
    <h1>تایید انجام نشد</h1>
    <p><?= h($msg) ?></p>
    <?php if ($botUser !== ''): ?>
      <a class="btn" href="https://t.me/<?= h(ltrim($botUser, '@')) ?>">دریافت لینک تازه از ربات</a>
    <?php endif; ?>

  <?php endif; ?>

    <div class="foot">این صفحه را می‌توانید ببندید.</div>
  </div>

<?php if ($stage === 'captcha'): ?>
<script>
(function () {
  var c = document.getElementById('cap');
  var f = document.getElementById('f');
  var b = document.getElementById('sb');
  var r = document.getElementById('rf');
  if (c) { c.focus(); c.addEventListener('input', function () { c.value = c.value.toUpperCase(); }); }
  if (r) { r.addEventListener('click', function () { location.reload(); }); }
  if (f && b) {
    f.addEventListener('submit', function () {
      b.disabled = true;
      b.innerHTML = 'در حال بررسی…';
    });
  }
})();
</script>
<?php endif; ?>
</body>
</html>
