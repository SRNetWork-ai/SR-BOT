<?php
/** مدیریت کاربران – پرونده‌ی کامل کاربر، فیلتر، خروجی CSV */
declare(strict_types=1);

if (!can('users.view')) { echo denyBox('بخش کاربران برای شما فعال نیست.'); return; }

/* ---------- خروجی CSV ---------- */
if (isset($_GET['export'])) {
    if (!can('users.export')) {
        flash('err', '⛔ دسترسی خروجی گرفتن را ندارید.');
        back('users');
    }
    $list = DB::all('SELECT id, tg_id, first_name, last_name, username, phone, email, balance, total_paid, is_banned, created_at FROM {p}users ORDER BY id ASC');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM برای نمایش صحیح فارسی در اکسل
    fputcsv($out, ['شناسه', 'آیدی تلگرام', 'نام', 'نام خانوادگی', 'یوزرنیم', 'موبایل', 'ایمیل', 'موجودی', 'جمع پرداختی', 'مسدود', 'تاریخ عضویت']);
    foreach ($list as $r) {
        fputcsv($out, [
            $r['id'], $r['tg_id'], $r['first_name'], $r['last_name'], $r['username'],
            $r['phone'], $r['email'], $r['balance'], $r['total_paid'],
            (int)$r['is_banned'] ? 'بله' : 'خیر', $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'create') {
        need('users.create', 'users');
        $tg = pint('tg_id');
        if ($tg <= 0) { flash('err', 'آیدی عددی تلگرام معتبر نیست.'); back('users'); }
        $exists = DB::one('SELECT * FROM {p}users WHERE tg_id = :t', [':t' => $tg]);
        if ($exists) { flash('warn', 'این کاربر قبلاً ثبت شده است.'); back('users', ['u' => (int)$exists['id']]); }
        $id = DB::insert('users', [
            'tg_id'      => $tg,
            'first_name' => ptxt('first_name', 'کاربر'),
            'username'   => ltrim(ptxt('username'), '@'),
            'phone'      => ptxt('phone'),
            'email'      => ptxt('email'),
            'balance'    => pint('balance'),
            'note'       => ptxt('note'),
            'created_at' => now(),
        ]);
        flash('ok', '✅ کاربر ساخته شد.');
        back('users', ['u' => $id]);
    }

    $uid = pint('user_id');
    $u   = $uid ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $uid]) : null;

    if ($u) {
        if ($act === 'balance') {
            need('users.balance', 'users');
            $amount = pint('amount');
            $note   = ptxt('note', 'تغییر دستی توسط مدیر');
            if ($amount === 0) { flash('err', 'مبلغ را وارد کنید.'); back('users', ['u' => $uid]); }
            if ($amount > 0) {
                Wallet::credit($uid, $amount, 'admin', 'admin', $note, (int)$ADMIN['id']);
                Tg::send((int)$u['tg_id'], "➕ <b>شارژ کیف پول</b>\n\n💰 مبلغ <b>" . money($amount) . "</b> به حساب شما افزوده شد.");
                flash('ok', '✅ موجودی افزوده شد.');
            } else {
                Wallet::debit($uid, abs($amount), $note, 'admin');
                flash('ok', '✅ موجودی کاهش یافت.');
            }
            back('users', ['u' => $uid]);
        }

        if ($act === 'save') {
            need('users.edit', 'users');
            DB::update('users', [
                'first_name' => ptxt('first_name'),
                'last_name'  => ptxt('last_name'),
                'username'   => ltrim(ptxt('username'), '@'),
                'phone'      => ptxt('phone'),
                'email'      => ptxt('email'),
                'note'       => ptxt('note'),
            ], 'id = :id', [':id' => $uid]);
            flash('ok', '✅ مشخصات کاربر ذخیره شد.');
            back('users', ['u' => $uid]);
        }

        if ($act === 'reseller') {
            need('resellers.level', 'users');
            if (!class_exists('Reseller')) {
                flash('err', 'ماژول نمایندگی در دسترس نیست.');
                back('users', ['u' => $uid]);
            }
            $lv = max(0, min(2, pint('level')));
            $r  = Reseller::setLevel($uid, $lv, [
                'credit'   => max(0, pint('credit')),
                'discount' => max(0, min(90, pint('discount'))),
            ]);
            if ($lv > 0) {
                Reseller::clearRequest($uid);
                Tg::send((int)$u['tg_id'], "🎉 <b>دسترسی نمایندگی فعال شد</b>\n\n🏷 سطح شما: "
                    . h(Reseller::levelLabel($lv)) . "\nاز منوی «🏷 نمایندگی» پنل خود را باز کنید.");
            }
            flash(!empty($r['ok']) ? 'ok' : 'err', (string)($r['message'] ?? '-'));
            back('users', ['u' => $uid]);
        }

        if ($act === 'reseller_reject') {
            need('resellers.level', 'users');
            if (class_exists('Reseller')) Reseller::clearRequest($uid);
            Tg::send((int)$u['tg_id'], "❌ <b>درخواست نمایندگی شما پذیرفته نشد</b>\n\nبرای اطلاعات بیشتر با پشتیبانی در تماس باشید.");
            flash('ok', 'درخواست نمایندگی رد شد.');
            back('users', ['u' => $uid]);
        }

        /* ---------- دسترسی مدیریت: ربات و پنل وب ---------- */
        if ($act === 'admin_bot') {
            need('admins.edit', 'users');
            $tg  = (int)$u['tg_id'];
            $raw = (string)DB::setting('extra_admins', '');
            $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($x) use ($tg) {
                return $x !== '' && $x !== (string)$tg;
            }));

            $on = pchk('bot_admin') === 1;
            if ($on) $ids[] = (string)$tg;
            DB::setSetting('extra_admins', implode(',', array_values(array_unique($ids))));

            if ($on) {
                Tg::send($tg, "🛡 <b>دسترسی مدیریت فعال شد</b>\n\n"
                    . "از این پس دکمهٔ «پنل مدیریت» در ربات برای شما فعال است.\n"
                    . "یک بار /start را بزنید تا منو تازه شود.");
            }
            flash('ok', $on
                ? '🛡 این کاربر در ربات مدیر شد.'
                : 'دسترسی مدیریت ربات برداشته شد.');
            back('users', ['u' => $uid]);
        }

        if ($act === 'admin_web') {
            need('admins.create', 'users');
            if (!class_exists('Perm')) { flash('err', 'ماژول دسترسی‌ها در دسترس نیست.'); back('users', ['u' => $uid]); }

            $tg    = (int)$u['tg_id'];
            $uname = strtolower(preg_replace('/[^a-zA-Z0-9_.\-]/', '', en_num(ptxt('au_username'))) ?? '');
            if ($uname === '') $uname = 'u' . $tg;

            $role    = ptxt('au_role', 'support');
            $typed   = ptxt('au_pass');
            $pass    = mb_strlen($typed) >= 6 ? $typed : (strtoupper(rnd(4)) . rnd(6));
            $presets = Perm::presets();
            $isSuper = $role === 'super';
            $perms   = (array)($presets[$role]['perms'] ?? []);

            $row = DB::one('SELECT * FROM {p}admins WHERE tg_id = :t', [':t' => $tg]);

            if (!$row) {
                if (DB::one('SELECT id FROM {p}admins WHERE username = :u', [':u' => $uname])) {
                    flash('err', 'این نام کاربری قبلاً برای مدیر دیگری ثبت شده است.');
                    back('users', ['u' => $uid]);
                }
                DB::insert('admins', [
                    'username'      => $uname,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'name'          => (string)($u['first_name'] ?: $uname),
                    'role'          => $isSuper ? 'super' : 'admin',
                    'perms'         => $isSuper ? jenc([Perm::ALL]) : Perm::encode($perms),
                    'tg_id'         => $tg,
                    'active'        => 1,
                    'note'          => 'ساخته‌شده از پروندهٔ کاربر',
                    'created_at'    => now(),
                ]);
                flash('ok', '✅ حساب مدیریت ساخته شد — نام کاربری: <b>' . h($uname)
                    . '</b> • رمز: <b class="mono ltr">' . h($pass) . '</b> (همین حالا یادداشت کنید)');
            } else {
                $up = [
                    'role'   => $isSuper ? 'super' : 'admin',
                    'perms'  => $isSuper ? jenc([Perm::ALL]) : Perm::encode($perms),
                    'active' => 1,
                ];
                if ($typed !== '') $up['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                DB::update('admins', $up, 'id = :id', [':id' => (int)$row['id']]);
                flash('ok', '✅ دسترسی پنل وب به‌روز شد.'
                    . ($typed !== '' ? ' رمز تازه: <b class="mono ltr">' . h($pass) . '</b>' : ''));
            }
            back('users', ['u' => $uid]);
        }

        if ($act === 'admin_off') {
            need('admins.edit', 'users');
            $tg  = (int)$u['tg_id'];
            $raw = (string)DB::setting('extra_admins', '');
            $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($x) use ($tg) {
                return $x !== '' && $x !== (string)$tg;
            }));
            DB::setSetting('extra_admins', implode(',', $ids));
            try { DB::q('UPDATE {p}admins SET active = 0, token = NULL WHERE tg_id = :t', [':t' => $tg]); } catch (Throwable $e) {}
            flash('ok', '⛔️ دسترسی‌های مدیریت این کاربر برداشته شد.');
            back('users', ['u' => $uid]);
        }

        if ($act === 'ban') {
            need('users.ban', 'users');
            $new = (int)$u['is_banned'] ? 0 : 1;
            DB::update('users', ['is_banned' => $new], 'id = :id', [':id' => $uid]);
            flash('ok', $new ? '🚫 کاربر مسدود شد.' : '✅ مسدودیت کاربر رفع شد.');
            back('users', ['u' => $uid]);
        }

        if ($act === 'msg') {
            need('users.message', 'users');
            $text = ptxt('text');
            if ($text !== '') {
                Tg::send((int)$u['tg_id'], "📩 <b>پیام از پشتیبانی</b>\n<code>─────────────────</code>\n" . h($text));
                flash('ok', '✅ پیام ارسال شد.');
            }
            back('users', ['u' => $uid]);
        }

        if ($act === 'reset_state') {
            need('users.edit', 'users');
            DB::update('users', ['state' => null, 'state_data' => null], 'id = :id', [':id' => $uid]);
            flash('ok', '🔄 وضعیت گفتگوی کاربر پاک شد.');
            back('users', ['u' => $uid]);
        }

        if ($act === 'del') {
            need('users.delete', 'users');
            DB::delete('users', 'id = :id', [':id' => $uid]);
            flash('ok', '🗑 کاربر حذف شد.');
            back('users');
        }
    }
}

$view = (int)($_GET['u'] ?? 0);
$U    = $view ? DB::one('SELECT * FROM {p}users WHERE id = :id', [':id' => $view]) : null;
$q    = trim((string)($_GET['q'] ?? ''));
$seg  = (string)($_GET['seg'] ?? '');

/* آمار کلی */
$tAll    = (int)DB::val('SELECT COUNT(*) FROM {p}users', [], 0);
$tBan    = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE is_banned = 1', [], 0);
$tBal    = (float)DB::val('SELECT COALESCE(SUM(balance),0) FROM {p}users', [], 0);
$tNew    = (int)DB::val('SELECT COUNT(*) FROM {p}users WHERE created_at >= :d', [':d' => date('Y-m-d 00:00:00')], 0);
$tBuyers = (int)DB::val('SELECT COUNT(DISTINCT user_id) FROM {p}services', [], 0);

if (!$U) {
    $params = [];
    $conds  = [];
    if ($q !== '') {
        $conds[] = '(tg_id LIKE :q OR username LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR phone LIKE :q OR email LIKE :q)';
        $params[':q'] = '%' . en_num($q) . '%';
    }
    if ($seg === 'banned')   $conds[] = 'is_banned = 1';
    if ($seg === 'balance')  $conds[] = 'balance > 0';
    if ($seg === 'today')    { $conds[] = 'created_at >= :today'; $params[':today'] = date('Y-m-d 00:00:00'); }
    if ($seg === 'buyers')   $conds[] = 'id IN (SELECT user_id FROM {p}services)';

    $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
    $users = DB::all('SELECT * FROM {p}users' . $where . ' ORDER BY id DESC LIMIT 300', $params);

    /* شمارش سرویس‌ها با یک کوئری (جای کوئری داخل حلقه) */
    $svcMap = [];
    try {
        $rowsC = DB::all("SELECT user_id, COUNT(*) c, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) a
                          FROM {p}services WHERE status <> 'deleted' GROUP BY user_id");
        foreach ((array)$rowsC as $rc) $svcMap[(int)$rc['user_id']] = ['c' => (int)$rc['c'], 'a' => (int)$rc['a']];
    } catch (Throwable $e) { $svcMap = []; }
}
?>

<style>
/* ===== پروندهٔ کاربر ===== */
.usr-hero{position:relative;overflow:hidden}
.usr-hero::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--uc),transparent)}
.usr-hero::after{content:"";position:absolute;inset-inline-end:-70px;top:-80px;width:210px;height:210px;border-radius:50%;
  background:radial-gradient(circle,color-mix(in srgb,var(--uc) 20%,transparent),transparent 70%);pointer-events:none}
.uh-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;position:relative;z-index:1}
.uh-ava{position:relative;width:62px;height:62px;flex:0 0 62px;border-radius:20px;display:grid;place-items:center;
  font-size:24px;font-weight:800;color:#fff;box-shadow:0 12px 30px -16px var(--uc);
  background:linear-gradient(140deg,var(--uc),color-mix(in srgb,var(--uc) 42%,#000))}
.uh-ava i{position:absolute;inset-block-end:-3px;inset-inline-end:-3px;width:15px;height:15px;border-radius:50%;
  background:var(--muted);border:3px solid var(--surface)}
.uh-ava i.on{background:var(--green)}
.uh-id{flex:1;min-width:210px}
.uh-id h2{margin:0;font-size:19px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.uh-tag{font-size:10.5px;font-weight:800;padding:4px 10px;border-radius:99px;color:var(--uc);
  background:color-mix(in srgb,var(--uc) 14%,transparent);border:1px solid color-mix(in srgb,var(--uc) 34%,transparent)}
.uh-id .chips{margin-top:9px}
.uh-acts{display:flex;gap:6px;flex-wrap:wrap;margin-inline-start:auto}
.uk{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:16px}
@media(min-width:720px){.uk{grid-template-columns:repeat(4,minmax(0,1fr))}}
.uk-i{position:relative;padding:12px 13px;border-radius:var(--r-lg);background:var(--surface-2);
  border:1px solid var(--border-soft);overflow:hidden;transition:.18s}
.uk-i::before{content:"";position:absolute;inset-block:0;inset-inline-start:0;width:3px;background:var(--k);opacity:.75}
.uk-i:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm);border-color:color-mix(in srgb,var(--k) 40%,var(--border))}
.uk-i .t{font-size:11px;color:var(--muted);font-weight:700;display:flex;align-items:center;gap:6px}
.uk-i .v{margin-top:7px;font-size:16.5px;font-weight:800;color:var(--k);font-family:var(--font-mono);direction:ltr;unicode-bidi:isolate}
.uk-i .s{margin-top:4px;font-size:10.5px;color:var(--text-dim)}
.uh-bar{margin-top:14px;padding:11px 12px;border-radius:var(--r-lg);background:var(--surface-2);border:1px solid var(--border-soft)}
.uh-bar .t{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--muted);font-weight:700}
.uh-bar .t b{font-family:var(--font-mono);color:var(--text);font-size:11.5px}
.uh-bar .p{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:9px}
.uh-bar .p i{display:block;height:100%;border-radius:99px;background:var(--green)}
.uh-bar.mid .p i{background:var(--accent)}.uh-bar.warn .p i{background:var(--orange)}.uh-bar.bad .p i{background:var(--red)}
.uh-chart{margin-top:14px;padding:12px 13px;border-radius:var(--r-lg);background:var(--surface-2);border:1px solid var(--border-soft)}
.uh-chart .hd{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;
  font-size:11.5px;color:var(--muted);font-weight:700;margin-bottom:10px}
.uh-chart .lg{display:flex;gap:10px;font-size:10.5px}
.uh-chart .lg span{display:flex;align-items:center;gap:5px}
.uh-chart .lg em{width:9px;height:9px;border-radius:3px;display:block;font-style:normal}
.uh-bars{display:flex;align-items:flex-end;gap:3px;height:66px}
.uh-bars .b{flex:1;min-width:0;height:100%;display:flex;flex-direction:column-reverse;justify-content:flex-start;gap:2px;
  border-radius:4px;transition:.15s}
.uh-bars .b:hover{background:color-mix(in srgb,var(--accent) 10%,transparent)}
.uh-bars .b i{display:block;width:100%;border-radius:3px}
.uh-bars .b .i-in{background:linear-gradient(180deg,var(--green),color-mix(in srgb,var(--green) 35%,transparent))}
.uh-bars .b .i-out{background:linear-gradient(180deg,var(--accent),color-mix(in srgb,var(--accent) 32%,transparent))}
/* تایم‌لاین */
.utl{position:relative;padding-inline-start:22px;margin-top:6px}
.utl::before{content:"";position:absolute;inset-block:10px;inset-inline-start:6px;width:2px;background:var(--border)}
.utl .it{position:relative;padding:9px 0;border-bottom:1px dashed var(--border-soft)}
.utl .it:last-child{border-bottom:0}
.utl .it::before{content:"";position:absolute;inset-inline-start:-21px;top:15px;width:10px;height:10px;border-radius:50%;
  background:var(--accent);box-shadow:0 0 0 3px var(--surface)}
.utl .it.tx::before{background:var(--green)}.utl .it.tk::before{background:var(--orange)}
.utl .it.new::before{background:var(--accent-2)}
.utl .tt{font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.utl .dt{font-size:11px;color:var(--muted);margin-top:3px;font-family:var(--font-mono)}
/* کارت بانکی */
.ucards{display:grid;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));gap:12px;margin-top:12px}
.ucard{position:relative;padding:14px;border-radius:16px;color:#fff;overflow:hidden;border:1px solid var(--border);
  background:linear-gradient(135deg,#26314b,#141a29)}
.ucard::after{content:"";position:absolute;inset-inline-end:-45px;top:-45px;width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.05)}
.ucard .bk{font-size:12.5px;font-weight:800}
.ucard .pan{margin-top:18px;font-family:var(--font-mono);font-size:15px;letter-spacing:2px;direction:ltr;text-align:left}
.ucard .ft{margin-top:12px;display:flex;justify-content:space-between;gap:8px;font-size:10.5px;opacity:.85}
.ucard .st{position:absolute;top:12px;inset-inline-end:12px;font-size:9.5px;padding:3px 8px;border-radius:99px;background:rgba(255,255,255,.14)}
/* سرویس‌ها */
.usg{min-width:110px}
.usg .p{height:6px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin-top:5px}
.usg .p i{display:block;height:100%;border-radius:99px;background:var(--green)}
.usg.mid .p i{background:var(--accent)}.usg.warn .p i{background:var(--orange)}.usg.bad .p i{background:var(--red)}
.usg .n{font-size:10.5px;color:var(--muted);font-family:var(--font-mono)}
/* لیست کاربران */
.ul-cell{display:flex;align-items:center;gap:9px;min-width:0}
.ul-ava{width:31px;height:31px;flex:0 0 31px;border-radius:11px;display:grid;place-items:center;font-size:13px;font-weight:800;
  color:#fff;background:linear-gradient(140deg,var(--accent),var(--accent-2))}
.ul-ava.rs{background:linear-gradient(140deg,var(--orange),#b45309)}
.ul-ava.ban{background:linear-gradient(140deg,var(--red),#7f1d1d)}
tr.u-row{transition:.15s}
tr.u-row:hover{background:var(--surface-2)}
.ul-tools{display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
.ul-tools input[type="search"]{min-width:150px}
/* ===== Users hero (list view) ===== */
.uh-hero{position:relative;overflow:hidden;padding:var(--s5) var(--s4);margin-top:var(--s3);
  border:1px solid var(--border);border-radius:var(--r-xl);
  background:linear-gradient(155deg,rgba(91,140,255,.14),rgba(139,92,246,.10) 45%,transparent 75%),var(--surface);
  box-shadow:var(--shadow)}
.uh-glow{position:absolute;border-radius:50%;filter:blur(60px);opacity:.5;pointer-events:none}
.uh-glow.g1{width:225px;height:225px;background:rgba(91,140,255,.32);inset-block-start:-92px;inset-inline-end:-58px}
.uh-glow.g2{width:185px;height:185px;background:rgba(139,92,246,.28);inset-block-end:-92px;inset-inline-start:-52px}
.uh-top{position:relative;display:flex;align-items:flex-start;gap:var(--s3);flex-wrap:wrap}
.uh-ic{width:52px;height:52px;flex:none;display:grid;place-items:center;font-size:26px;
  border-radius:var(--r-lg);background:var(--grad-soft,var(--accent-soft));border:1px solid var(--border);box-shadow:var(--glow)}
.uh-tt h2{margin:0;font-size:17px}
.uh-tt p{margin:4px 0 0;font-size:12px;color:var(--muted);line-height:1.8;max-width:58ch}
.uh-act{margin-inline-start:auto;display:flex;gap:6px;flex-wrap:wrap}
.uh-cells{position:relative;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:var(--s2);margin-top:var(--s4)}
.uh-cell{display:flex;flex-direction:column;align-items:center;gap:2px;padding:var(--s3) var(--s2);
  background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);transition:transform .18s,border-color .18s}
.uh-cell:hover{transform:translateY(-3px);border-color:var(--accent)}
.uh-cell .i{font-size:17px}
.uh-cell .v{font-family:var(--font-num);font-size:17px;font-weight:800;text-align:center}
.uh-cell .l{font-size:10.5px;color:var(--muted);text-align:center}
.uh-cell.g .v{color:var(--green)}.uh-cell.o .v{color:var(--orange)}
.uh-cell.r .v{color:var(--red)}.uh-cell.b .v{color:var(--accent)}
.uh-cell.c .v{color:var(--cyan)}.uh-cell.p .v{color:var(--accent-2)}
.uh-rate{position:relative;margin-top:var(--s4)}
.uh-rate-t{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px}
.uh-rate-t b{color:var(--accent);font-family:var(--font-num)}
.uh-rate-bar{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden}
.uh-rate-bar span{display:block;height:100%;border-radius:99px;background:var(--grad,var(--accent));transition:width .6s ease}
@media (max-width:1100px){.uh-cells{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:640px){.uh-cells{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<?php if ($U):
    $svcs   = Svc::forUser((int)$U['id'], false, true);
    $txs    = DB::all('SELECT * FROM {p}transactions WHERE user_id = :u ORDER BY id DESC LIMIT 15', [':u' => (int)$U['id']]);
    $tks    = DB::all('SELECT * FROM {p}tickets WHERE user_id = :u ORDER BY id DESC LIMIT 10', [':u' => (int)$U['id']]);
    $nSvcOk = 0;
    foreach ($svcs as $s) if ((string)$s['status'] === 'active') $nSvcOk++;
    $fullName = trim((string)$U['first_name'] . ' ' . (string)$U['last_name']) ?: 'کاربر';

    /* ---------- داده‌های تکمیلی پرونده ---------- */
    $uid = (int)$U['id'];
    $uTg = (int)$U['tg_id'];

    $nSvcEx = 0; $nSvcTs = 0; $uUsed = 0;
    foreach ($svcs as $s) {
        if ((string)$s['status'] === 'expired') $nSvcEx++;
        if ((int)($s['is_test'] ?? 0) === 1)    $nSvcTs++;
        $uUsed += (int)($s['used_bytes'] ?? 0);
    }

    $uTkOpen = 0;
    foreach ($tks as $t) if (!in_array((string)$t['status'], ['closed', 'resolved', 'done'], true)) $uTkOpen++;

    $uDep = 0.0; $uOut = 0.0; $uTxN = 0; $uTxP = 0;
    try {
        $uDep = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM {p}transactions WHERE user_id = :u AND amount > 0 AND status = 'approved'", [':u' => $uid], 0);
        $uOut = (float)DB::val("SELECT COALESCE(SUM(-amount),0) FROM {p}transactions WHERE user_id = :u AND amount < 0 AND status = 'approved'", [':u' => $uid], 0);
        $uTxN = (int)DB::val('SELECT COUNT(*) FROM {p}transactions WHERE user_id = :u', [':u' => $uid], 0);
        $uTxP = (int)DB::val("SELECT COUNT(*) FROM {p}transactions WHERE user_id = :u AND status = 'pending'", [':u' => $uid], 0);
    } catch (Throwable $e) { }

    $uRef    = class_exists('Referral') ? Referral::stats($U) : [];
    $uInv    = (class_exists('Referral') && $uTg > 0) ? Referral::invitees($uTg, 12) : [];
    $uCards  = class_exists('CardAuth') ? CardAuth::cards($uid) : [];
    $uCardOk = 0;
    foreach ($uCards as $c) if ((string)$c['status'] === 'approved') $uCardOk++;

    $uRsLv = class_exists('Reseller') ? (int)($U['reseller_level'] ?? 0) : 0;
    $uHl   = ($uRsLv > 0 && class_exists('Reseller') && method_exists('Reseller', 'health')) ? Reseller::health($U) : null;

    $uJoin = strtotime((string)$U['created_at']);
    if (!$uJoin) $uJoin = time();
    $uDays = max(0, (int)floor((time() - $uJoin) / 86400));

    $uSeenTs  = !empty($U['last_seen']) ? (int)strtotime((string)$U['last_seen']) : 0;
    $uSeenTxt = '—';
    if ($uSeenTs > 0) {
        $dlt = max(0, time() - $uSeenTs);
        if     ($dlt < 3600)    $uSeenTxt = fa_num((int)max(1, floor($dlt / 60)))  . ' دقیقه پیش';
        elseif ($dlt < 86400)   $uSeenTxt = fa_num((int)floor($dlt / 3600))        . ' ساعت پیش';
        elseif ($dlt < 2592000) $uSeenTxt = fa_num((int)floor($dlt / 86400))       . ' روز پیش';
        else                    $uSeenTxt = to_jalali((string)$U['last_seen']);
    }
    $uOnline = $uSeenTs > 0 && (time() - $uSeenTs) < 900;

    /* نمودار ۳۰ روز اخیر */
    $uDaily = [];
    for ($i = 29; $i >= 0; $i--) $uDaily[date('Y-m-d', strtotime('-' . $i . ' day'))] = ['in' => 0.0, 'out' => 0.0];
    try {
        $rowsD = DB::all(
            "SELECT DATE(created_at) d,
                    COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) i,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) o
             FROM {p}transactions
             WHERE user_id = :u AND status = 'approved' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)", [':u' => $uid]);
        foreach ((array)$rowsD as $rd) {
            $kd = (string)($rd['d'] ?? '');
            if (isset($uDaily[$kd])) $uDaily[$kd] = ['in' => (float)$rd['i'], 'out' => (float)$rd['o']];
        }
    } catch (Throwable $e) { }
    $uMax = 1.0;
    foreach ($uDaily as $vd) $uMax = max($uMax, (float)$vd['in'], (float)$vd['out']);

    /* تایم‌لاین فعالیت */
    $uTl = [];
    foreach (array_slice($svcs, 0, 8) as $s) {
        $nm = (string)($s['client_email'] ?? '');
        $uTl[] = ['t' => (string)($s['created_at'] ?? ''), 'ic' => '🔑', 'k' => 'svc',
                  'x' => 'ساخت سرویس ' . ($nm !== '' ? $nm : '#' . (int)$s['id'])];
    }
    foreach (array_slice($txs, 0, 8) as $t) {
        $am = (float)$t['amount'];
        $uTl[] = ['t' => (string)($t['created_at'] ?? ''), 'ic' => $am >= 0 ? '💚' : '🛒', 'k' => 'tx',
                  'x' => ($am >= 0 ? 'شارژ کیف پول ' : 'کسر از کیف پول ') . money(abs($am)) . ' · ' . (string)$t['method']];
    }
    foreach (array_slice($tks, 0, 6) as $t) {
        $uTl[] = ['t' => (string)($t['updated_at'] ?? ''), 'ic' => '🆘', 'k' => 'tk',
                  'x' => 'تیکت: ' . (string)$t['subject']];
    }
    $uTl[] = ['t' => (string)$U['created_at'], 'ic' => '🎉', 'k' => 'new', 'x' => 'عضویت در ربات'];
    usort($uTl, function ($a, $b) { return strcmp((string)$b['t'], (string)$a['t']); });
    $uTl = array_slice($uTl, 0, 14);

    $uColor = (int)$U['is_banned'] ? 'var(--red)' : ($uRsLv > 0 ? 'var(--orange)' : 'var(--accent)');
?>

  <div class="page-head">
    <div>
      <h2>👤 پروندهٔ کاربر</h2>
      <div class="sub">نمای کامل حساب، مالی، سرویس‌ها، معرفی‌ها و فعالیت‌های کاربر</div>
    </div>
    <div class="acts">
      <?php if ($U['username']): ?>
        <a class="btn btn-ghost" target="_blank" rel="noopener" href="https://t.me/<?= h((string)$U['username']) ?>">📨 گفتگو در تلگرام</a>
      <?php endif; ?>
      <a class="btn" href="index.php?p=users">← بازگشت به لیست</a>
    </div>
  </div>

  <div class="card mt3 usr-hero" style="--uc:<?= $uColor ?>">
    <div class="uh-top">
      <div class="uh-ava"><?= h(mb_substr($fullName, 0, 1)) ?><i class="<?= $uOnline ? 'on' : '' ?>"></i></div>
      <div class="uh-id">
        <h2><?= h($fullName) ?>
          <span class="uh-tag"><?= (int)$U['is_banned']
            ? '🚫 مسدود'
            : ($uRsLv > 0 ? h(Reseller::levelLabel($uRsLv)) : '✅ کاربر فعال') ?></span>
          <?php if (!empty($U['phone_verified']) || !empty($U['email_verified'])): ?>
            <span class="badge b-green">🔐 احراز شده</span>
          <?php endif; ?>
          <?php if ($uCardOk > 0): ?><span class="badge b-blue">💳 کارت تایید‌شده</span><?php endif; ?>
        </h2>
        <div class="chips">
          <span class="chip" data-copy="<?= (int)$U['tg_id'] ?>" title="کپی آیدی">🆔 <b class="mono"><?= fa_num((string)$U['tg_id']) ?></b></span>
          <?php if ($U['username']): ?><span class="chip">@<?= h((string)$U['username']) ?></span><?php endif; ?>
          <?php if (!empty($U['phone'])): ?><span class="chip">📱 <b class="mono"><?= h((string)$U['phone']) ?></b></span><?php endif; ?>
          <?php if (!empty($U['email'])): ?><span class="chip">✉️ <b class="mono ltr"><?= h((string)$U['email']) ?></b></span><?php endif; ?>
          <span class="chip">📅 عضویت <b><?= h(to_jalali((string)$U['created_at'])) ?></b> · <?= fa_num($uDays) ?> روز</span>
          <span class="chip"><?= $uOnline ? '🟢 آنلاین' : '🕓 آخرین بازدید' ?> <b><?= h($uSeenTxt) ?></b></span>
          <?php if ($U['state']): ?><span class="chip">⏳ در حال <b><?= h((string)$U['state']) ?></b></span><?php endif; ?>
          <?php if (!empty($U['miniapp_at'])): ?><span class="chip">📲 کاربر مینی‌اپ</span><?php endif; ?>
        </div>
      </div>
      <div class="uh-acts">
        <?php if (can('services.view')): ?>
          <a class="btn btn-sm btn-ghost" href="index.php?p=services&user=<?= $uid ?>">🔑 سرویس‌ها</a>
        <?php endif; ?>
        <?php if ($uRsLv > 0 && can('resellers.view')): ?>
          <a class="btn btn-sm btn-ghost" href="index.php?p=resellers&u=<?= $uid ?>">🏷 پنل نمایندگی</a>
        <?php endif; ?>
        <?php if ($uCards && can('cards.view')): ?>
          <a class="btn btn-sm btn-ghost" href="index.php?p=cards">💳 کارت‌ها</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="uk">
      <div class="uk-i" style="--k:<?= (float)$U['balance'] < 0 ? 'var(--red)' : 'var(--green)' ?>">
        <div class="t">💰 موجودی کیف پول</div>
        <div class="v"><?= money((float)$U['balance']) ?></div>
        <div class="s"><?= (float)$U['balance'] < 0 ? 'بدهکار' : 'قابل خرج کردن' ?></div>
      </div>
      <div class="uk-i" style="--k:var(--accent-text)">
        <div class="t">🧾 جمع پرداختی</div>
        <div class="v"><?= money((float)$U['total_paid']) ?></div>
        <div class="s"><?= fa_num($uTxN) ?> تراکنش · <?= fa_num($uTxP) ?> در انتظار</div>
      </div>
      <div class="uk-i" style="--k:var(--green)">
        <div class="t">💚 جمع شارژ تاییدشده</div>
        <div class="v"><?= money($uDep) ?></div>
        <div class="s">ورودی کیف پول</div>
      </div>
      <div class="uk-i" style="--k:var(--orange)">
        <div class="t">🛒 جمع خرید</div>
        <div class="v"><?= money($uOut) ?></div>
        <div class="s">کسر از کیف پول</div>
      </div>
      <div class="uk-i" style="--k:var(--accent)">
        <div class="t">🔑 سرویس‌ها</div>
        <div class="v"><?= fa_num(count($svcs)) ?></div>
        <div class="s">✅ <?= fa_num($nSvcOk) ?> فعال · ⌛️ <?= fa_num($nSvcEx) ?> منقضی</div>
      </div>
      <div class="uk-i" style="--k:var(--accent-2)">
        <div class="t">📊 ترافیک مصرفی</div>
        <div class="v ltr"><?= h(human_bytes($uUsed)) ?></div>
        <div class="s">مجموع همهٔ سرویس‌ها</div>
      </div>
      <div class="uk-i" style="--k:var(--orange)">
        <div class="t">🧪 اکانت تست</div>
        <div class="v"><?= fa_num((int)$U['test_count']) ?></div>
        <div class="s"><?= fa_num($nSvcTs) ?> سرویس تست موجود</div>
      </div>
      <div class="uk-i" style="--k:var(--cyan, var(--accent))">
        <div class="t">🎁 زیرمجموعه</div>
        <div class="v"><?= fa_num((int)($uRef['count'] ?? 0)) ?></div>
        <div class="s">درآمد معرفی: <?= h((string)($uRef['earned_txt'] ?? money(0))) ?></div>
      </div>
    </div>

    <?php if ($uHl && ((int)$uHl['credit'] > 0 || (int)$uHl['debt'] > 0)): ?>
      <div class="uh-bar <?= h((string)$uHl['key']) ?>">
        <div class="t">
          <span><?= h(trim((string)$uHl['icon'] . ' ' . (string)$uHl['label'])) ?> — مصرف <?= fa_num((int)$uHl['pct']) ?>٪ از سقف اعتبار نمایندگی</span>
          <b><?= money((int)$uHl['debt']) ?> / <?= money((int)$uHl['credit']) ?></b>
        </div>
        <div class="p"><i style="width:<?= max(2, min(100, (int)$uHl['pct'])) ?>%"></i></div>
      </div>
    <?php endif; ?>

    <div class="uh-chart">
      <div class="hd">
        <span>📈 گردش مالی ۳۰ روز اخیر</span>
        <span class="lg">
          <span><em style="background:var(--green)"></em> شارژ</span>
          <span><em style="background:var(--accent)"></em> خرید</span>
        </span>
      </div>
      <div class="uh-bars">
        <?php foreach ($uDaily as $dk => $dv):
          $hi = (float)$dv['in']  > 0 ? (int)max(4, round(((float)$dv['in']  / $uMax) * 100)) : 0;
          $ho = (float)$dv['out'] > 0 ? (int)max(4, round(((float)$dv['out'] / $uMax) * 100)) : 0;
        ?>
          <span class="b" title="<?= h(to_jalali($dk)) ?> — شارژ <?= money((float)$dv['in']) ?> · خرید <?= money((float)$dv['out']) ?>">
            <i class="i-in" style="height:<?= $hi ?>%"></i>
            <i class="i-out" style="height:<?= $ho ?>%"></i>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="tabbar mt4">
    <button type="button" data-tab-group="usr" data-tab="profile">🧾 مشخصات</button>
    <button type="button" data-tab-group="usr" data-tab="money">💰 مالی و پیام</button>
    <button type="button" data-tab-group="usr" data-tab="svc">🔑 سرویس‌ها <span class="n"><?= fa_num(count($svcs)) ?></span></button>
    <button type="button" data-tab-group="usr" data-tab="tx">🧾 تراکنش‌ها <span class="n"><?= fa_num(count($txs)) ?></span></button>
    <button type="button" data-tab-group="usr" data-tab="tk">🆘 تیکت‌ها <span class="n"><?= fa_num(count($tks)) ?></span></button>
    <button type="button" data-tab-group="usr" data-tab="ref">🎁 معرفی و کارت <span class="n"><?= fa_num((int)($uRef['count'] ?? 0) + count($uCards)) ?></span></button>
    <button type="button" data-tab-group="usr" data-tab="act">🕓 فعالیت</button>
    <button type="button" data-tab-group="usr" data-tab="danger">⚠️ عملیات حساس</button>
  </div>

  <!-- تب مشخصات -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="profile">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">👤 مشخصات کاربر</div>
          <div class="card-sub">این موارد را خود کاربر در بخش حساب کاربری پر می‌کند</div>
        </div>
      </div>
      <?php if (!can('users.edit')): ?>
        <div class="alert a-warn">شما فقط اجازه‌ی مشاهده دارید.</div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
        <div class="form-grid g2">
          <div class="field"><label>نام</label><input type="text" name="first_name" value="<?= h((string)$U['first_name']) ?>" <?= can('users.edit') ? '' : 'disabled' ?>></div>
          <div class="field"><label>نام خانوادگی</label><input type="text" name="last_name" value="<?= h((string)$U['last_name']) ?>" <?= can('users.edit') ? '' : 'disabled' ?>></div>
          <div class="field"><label>شماره تماس</label><input class="mono" type="text" name="phone" value="<?= h((string)$U['phone']) ?>" <?= can('users.edit') ? '' : 'disabled' ?>></div>
          <div class="field"><label>ایمیل</label><input class="mono" type="text" name="email" value="<?= h((string)$U['email']) ?>" <?= can('users.edit') ? '' : 'disabled' ?>></div>
          <div class="field"><label>یوزرنیم تلگرام</label><input class="mono" type="text" name="username" value="<?= h((string)$U['username']) ?>" <?= can('users.edit') ? '' : 'disabled' ?>></div>
          <div class="field"><label>آخرین بازدید</label><input type="text" value="<?= $U['last_seen'] ? h(to_jalali((string)$U['last_seen'], true)) : '—' ?>" disabled></div>
          <div class="field full"><label>یادداشت مدیر (خصوصی)</label><textarea name="note" rows="2" <?= can('users.edit') ? '' : 'disabled' ?>><?= h((string)$U['note']) ?></textarea></div>
        </div>
        <?php if (can('users.edit')): ?>
          <div class="sticky-acts"><button class="btn btn-primary">💾 ذخیره‌ی مشخصات</button></div>
        <?php endif; ?>
      </form>
    </div>

    <?php if (class_exists('Reseller')):
      $rsLv  = (int)($U['reseller_level'] ?? 0);
      $rsCr  = (int)($U['reseller_credit'] ?? 0);
      $rsDc  = (int)($U['reseller_discount'] ?? 0);
      $rsReq = (int)($U['reseller_req'] ?? 0) === 1;
    ?>
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">🏷 دسترسی نمایندگی</div>
            <div class="card-sub">سطح، سقف بدهی و تخفیف اختصاصی این کاربر</div>
          </div>
          <span class="badge <?= $rsLv > 0 ? 'b-green' : 'b-gray' ?>"><?= h(Reseller::levelLabel($rsLv)) ?></span>
        </div>

        <?php if ($rsReq): ?>
          <div class="alert a-warn">
            📝 <b>این کاربر درخواست نمایندگی ثبت کرده است.</b>
            <?php if (trim((string)($U['reseller_req_note'] ?? '')) !== ''): ?>
              <div class="mt3">توضیح کاربر: «<?= h((string)$U['reseller_req_note']) ?>»</div>
            <?php endif; ?>
            <?php if (!empty($U['reseller_req_at'])): ?>
              <div class="muted" style="font-size:12px">تاریخ درخواست: <?= h(to_jalali((string)$U['reseller_req_at'], true)) ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!can('resellers.level')): ?>
          <div class="alert a-warn">برای تغییر سطح نمایندگی دسترسی ندارید.</div>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="reseller">
            <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <div class="form-grid g2">
              <div class="field">
                <label>سطح دسترسی</label>
                <select name="level">
                  <option value="0" <?= $rsLv === 0 ? 'selected' : '' ?>>👤 کاربر عادی (بدون نمایندگی)</option>
                  <option value="1" <?= $rsLv === 1 ? 'selected' : '' ?>>🥈 سطح ۱ – فقط با موجودی کیف پول</option>
                  <option value="2" <?= $rsLv === 2 ? 'selected' : '' ?>>🥇 سطح ۲ – مجاز به بدهکار شدن</option>
                </select>
                <div class="hint">با انتخاب «کاربر عادی» اعتبار و تخفیف نمایندگی صفر می‌شود.</div>
              </div>
              <div class="field">
                <label>سقف بدهی مجاز (<?= h(currency()) ?>)</label>
                <input class="mono" type="number" name="credit" min="0" step="1000" value="<?= $rsCr ?>">
                <div class="hint">مخصوص سطح ۲. ۰ = استفاده از مقدار پیش‌فرض تعرفه.</div>
              </div>
              <div class="field">
                <label>تخفیف اختصاصی (٪)</label>
                <input class="mono" type="number" name="discount" min="0" max="90" value="<?= $rsDc ?>">
                <div class="hint">۰ = استفاده از تخفیف پیش‌فرض همان سطح.</div>
              </div>
            </div>
            <div class="btn-row mt3">
              <button class="btn btn-primary">💾 ذخیره‌ی نمایندگی</button>
              <a class="btn btn-ghost btn-sm" href="index.php?p=resellers&u=<?= (int)$U['id'] ?>">🏷 پرونده‌ی نمایندگی</a>
            </div>
          </form>

          <?php if ($rsReq): ?>
            <form method="post" class="mt3">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="reseller_reject">
              <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
              <button class="btn btn-ghost btn-sm" data-confirm="درخواست نمایندگی این کاربر رد شود؟">❌ رد درخواست نمایندگی</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (can('admins.edit') || can('admins.create')):
      $tgU        = (int)$U['tg_id'];
      $cfgAdmins  = array_map('strval', (array)cfg('bot.admins', []));
      $extraList  = array_values(array_filter(array_map('trim', explode(',', (string)DB::setting('extra_admins', '')))));
      $isCfgAdmin = in_array((string)$tgU, $cfgAdmins, true);
      $isBotAdmin = $isCfgAdmin || in_array((string)$tgU, $extraList, true);
      $webAdmin   = DB::one('SELECT * FROM {p}admins WHERE tg_id = :t', [':t' => $tgU]);
      $presets    = class_exists('Perm') ? Perm::presets() : [];
    ?>
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">🛡 دسترسی مدیریت</div>
            <div class="card-sub">این کاربر را مدیر ربات یا مدیر پنل تحت وب کنید</div>
          </div>
          <span class="badge <?= $isBotAdmin ? 'b-green' : 'b-gray' ?>"><?= $isBotAdmin ? 'مدیر ربات' : 'کاربر عادی' ?></span>
        </div>

        <div class="kv"><span class="k">آیدی عددی</span><span class="mono ltr"><?= (int)$tgU ?></span></div>
        <div class="kv"><span class="k">مدیر در ربات</span>
          <span><?= $isBotAdmin ? '✅ بله' : '—' ?><?= $isCfgAdmin ? ' (در فایل config.php)' : '' ?></span></div>
        <div class="kv"><span class="k">حساب پنل وب</span>
          <span><?= $webAdmin ? h((string)$webAdmin['username']) . (empty($webAdmin['active']) ? ' (غیرفعال)' : '') : '—' ?></span></div>

        <?php if ($isCfgAdmin): ?>
          <div class="alert a-info mt3">این آیدی در فایل <code>config.php</code> ثبت شده و از اینجا قابل حذف نیست.</div>
        <?php endif; ?>

        <?php if (can('admins.edit')): ?>
          <form method="post" class="mt3">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="admin_bot">
            <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <label class="check">
              <input type="checkbox" name="bot_admin" value="1" <?= $isBotAdmin ? 'checked' : '' ?>>
              مدیر ربات باشد (دکمهٔ پنل مدیریت برایش باز شود)
            </label>
            <div class="hint">بعد از ذخیره، کاربر یک بار /start را بزند.</div>
            <div class="btn-row mt3"><button class="btn btn-primary btn-sm">💾 ذخیرهٔ دسترسی ربات</button></div>
          </form>
        <?php endif; ?>

        <?php if (can('admins.create')): ?>
          <div class="section-title mt4">🌐 دسترسی پنل تحت وب</div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="admin_web">
            <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <div class="form-grid g2">
              <div class="field">
                <label>نام کاربری ورود</label>
                <input class="mono ltr" type="text" name="au_username"
                  value="<?= h((string)($webAdmin['username'] ?? ((string)$U['username'] !== '' ? (string)$U['username'] : 'u' . $tgU))) ?>"
                  <?= $webAdmin ? 'readonly' : '' ?>>
              </div>
              <div class="field">
                <label>نقش و سطح دسترسی</label>
                <select name="au_role">
                  <?php foreach ($presets as $pk => $pr): ?>
                    <option value="<?= h($pk) ?>" <?= (string)($webAdmin['role'] ?? '') === 'super' && $pk === 'super' ? 'selected' : '' ?>>
                      <?= h((string)$pr['title']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">برای دسترسی دقیق‌تر، بعد از ساخت به صفحهٔ مدیران بروید.</div>
              </div>
              <div class="field">
                <label>رمز عبور <?= $webAdmin ? '(خالی = بدون تغییر)' : '(خالی = خودکار)' ?></label>
                <input class="mono ltr" type="text" name="au_pass" autocomplete="new-password" placeholder="حداقل ۶ کاراکتر">
              </div>
            </div>
            <div class="btn-row mt3">
              <button class="btn btn-primary btn-sm"><?= $webAdmin ? '💾 به‌روزرسانی دسترسی' : '➕ ساخت حساب مدیریت' ?></button>
              <?php if ($webAdmin): ?>
                <a class="btn btn-ghost btn-sm" href="index.php?p=admins&e=<?= (int)$webAdmin['id'] ?>">🔐 دسترسی‌های دقیق</a>
              <?php endif; ?>
            </div>
          </form>
        <?php endif; ?>

        <?php if (($isBotAdmin && !$isCfgAdmin) || $webAdmin): ?>
          <?php if (can('admins.edit')): ?>
            <form method="post" class="mt3">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="admin_off">
              <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="همهٔ دسترسی‌های مدیریت این کاربر برداشته شود؟">⛔️ لغو کامل دسترسی مدیریت</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- تب مالی و پیام -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="money">
    <div class="grid g2">
      <div class="card">
        <div class="card-title">💰 تغییر موجودی کیف پول</div>
        <?php if (can('users.balance')): ?>
          <form method="post" class="mt3">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="balance">
            <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <div class="form-grid g2">
              <div class="field">
                <label>مبلغ (<?= h(currency()) ?>)</label>
                <input class="mono" type="text" name="amount" placeholder="مانند 50000 یا -20000">
                <div class="hint">عدد منفی = کاهش موجودی</div>
              </div>
              <div class="field">
                <label>توضیح</label>
                <input type="text" name="note" placeholder="هدیه / تسویه دستی">
              </div>
            </div>
            <button class="btn btn-green mt3">➕ اعمال تغییر موجودی</button>
          </form>
        <?php else: ?>
          <div class="alert a-warn mt3">دسترسی تغییر موجودی را ندارید.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-title">📩 ارسال پیام تلگرامی</div>
        <?php if (can('users.message')): ?>
          <form method="post" class="mt3">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="msg">
            <input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <div class="field"><label>متن پیام</label><textarea name="text" rows="4" placeholder="متن پیام به کاربر…" required></textarea></div>
            <button class="btn">📩 ارسال پیام</button>
          </form>
        <?php else: ?>
          <div class="alert a-warn mt3">دسترسی ارسال پیام را ندارید.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- تب سرویس‌ها -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="svc">
    <div class="card">
      <div class="card-head">
        <div><div class="card-title">🔑 سرویس‌های کاربر</div></div>
        <?php if (can('services.view')): ?>
          <a class="btn btn-sm" href="index.php?p=services&user=<?= (int)$U['id'] ?>">مدیریت سرویس‌ها</a>
        <?php endif; ?>
      </div>
      <?php if (!$svcs): ?>
        <div class="empty"><div class="ic">🔑</div>این کاربر سرویسی ندارد.</div>
      <?php else: ?>
        <div class="chips mb3">
          <span class="chip">📦 کل <b><?= fa_num(count($svcs)) ?></b></span>
          <span class="chip">✅ فعال <b><?= fa_num($nSvcOk) ?></b></span>
          <span class="chip">⌛️ منقضی <b><?= fa_num($nSvcEx) ?></b></span>
          <span class="chip">🧪 تست <b><?= fa_num($nSvcTs) ?></b></span>
          <span class="chip">📊 مصرف کل <b class="ltr"><?= h(human_bytes($uUsed)) ?></b></span>
        </div>
        <div class="table-wrap"><table class="responsive">
          <thead><tr><th>#</th><th>نام کاربری</th><th>حجم و مصرف</th><th>انقضا</th><th>نوع</th><th>وضعیت</th><th data-l=""></th></tr></thead>
          <tbody>
          <?php foreach ($svcs as $s):
            $vol  = (float)$s['volume_gb'];
            $used = (int)($s['used_bytes'] ?? 0);
            $pct  = $vol > 0 ? (int)min(100, round($used / max(1.0, $vol * 1073741824) * 100)) : 0;
            $ucl  = $pct >= 95 ? 'bad' : ($pct >= 75 ? 'warn' : ($pct >= 40 ? 'mid' : ''));
            $expT = !empty($s['expire_at']) ? (int)strtotime((string)$s['expire_at']) : 0;
            $left = $expT > 0 ? (int)ceil(($expT - time()) / 86400) : null;
          ?>
            <tr>
              <td data-l="#" class="num tight"><span class="numbox"><?= fa_num((int)$s['id']) ?></span></td>
              <td data-l="نام کاربری" class="grow-col">
                <div class="idcell">
                  <span class="nm mono"><?= h((string)$s['client_email']) ?></span>
                  <?php if (!empty($s['panel_name'])): ?><span class="sub">🖧 <?= h((string)$s['panel_name']) ?></span><?php endif; ?>
                </div>
              </td>
              <td data-l="حجم و مصرف">
                <div class="usg <?= $ucl ?>">
                  <span class="n ltr"><?= h(human_bytes($used)) ?> / <?= $vol > 0 ? fa_num((string)(float)$vol) . ' GB' : '∞' ?></span>
                  <?php if ($vol > 0): ?><div class="p"><i style="width:<?= max(2, $pct) ?>%"></i></div><?php endif; ?>
                </div>
              </td>
              <td data-l="انقضا">
                <?= $expT > 0 ? h(to_jalali((string)$s['expire_at'])) : 'بی‌نهایت' ?>
                <?php if ($left !== null): ?>
                  <div class="muted" style="font-size:11px"><?= $left >= 0
                    ? fa_num($left) . ' روز مانده'
                    : fa_num(abs($left)) . ' روز گذشته' ?></div>
                <?php endif; ?>
              </td>
              <td data-l="نوع"><?= (int)$s['is_test']
                ? '<span class="badge b-orange">🧪 تست</span>'
                : ((int)($s['is_reseller'] ?? 0) ? '<span class="badge b-purple">🏷 نمایندگی</span>' : '<span class="badge b-blue">📦 فروش</span>') ?></td>
              <td data-l="وضعیت"><?= badge((string)$s['status']) ?></td>
              <td class="acts" data-l="">
                <?php if (can('services.view')): ?>
                  <a class="btn btn-sm btn-ghost" href="index.php?p=services&q=<?= h(urlencode((string)$s['client_email'])) ?>">🔎 مدیریت</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تب تراکنش‌ها -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="tx">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">🧾 تراکنش‌های اخیر</div>
          <div class="card-sub">۱۵ تراکنش آخر از مجموع <?= fa_num($uTxN) ?> تراکنش</div>
        </div>
        <div class="chips">
          <span class="chip">💚 شارژ <b><?= money($uDep) ?></b></span>
          <span class="chip">🛒 خرید <b><?= money($uOut) ?></b></span>
          <?php if ($uTxP > 0): ?><span class="chip">⏳ در انتظار <b><?= fa_num($uTxP) ?></b></span><?php endif; ?>
        </div>
      </div>
      <?php if (!$txs): ?>
        <div class="empty"><div class="ic">💳</div>تراکنشی ثبت نشده است.</div>
      <?php else: ?>
        <div class="table-wrap"><table class="responsive">
          <thead><tr><th>#</th><th>نوع</th><th>روش</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
          <tbody>
          <?php foreach ($txs as $t): ?>
            <tr>
              <td class="mono"><?= fa_num((int)$t['id']) ?></td>
              <td><?= h((string)$t['type']) ?></td>
              <td><?= h((string)$t['method']) ?></td>
              <td data-l="مبلغ" class="num tight">
                <span class="numbox <?= (float)$t['amount'] < 0 ? 'r' : 'g' ?>"><?= money((float)$t['amount']) ?></span>
              </td>
              <td><?= badge((string)$t['status']) ?></td>
              <td class="muted"><?= h(to_jalali((string)$t['created_at'], true)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تب تیکت‌ها -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="tk">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">🆘 تیکت‌های کاربر</div>
          <div class="card-sub"><?= $uTkOpen > 0
            ? fa_num($uTkOpen) . ' تیکت باز دارد'
            : 'همهٔ تیکت‌ها بسته شده است' ?></div>
        </div>
        <?php if (can('tickets.view')): ?>
          <a class="btn btn-sm btn-ghost" href="index.php?p=tickets">🆘 مرکز پشتیبانی</a>
        <?php endif; ?>
      </div>
      <?php if (!$tks): ?>
        <div class="empty"><div class="ic">🆘</div>تیکتی ثبت نشده است.</div>
      <?php else: ?>
        <div class="table-wrap"><table class="responsive">
          <thead><tr><th>#</th><th>موضوع</th><th>وضعیت</th><th>آخرین فعالیت</th><th data-l=""></th></tr></thead>
          <tbody>
          <?php foreach ($tks as $t): ?>
            <tr>
              <td class="mono"><?= fa_num((int)$t['id']) ?></td>
              <td><?= h((string)$t['subject']) ?></td>
              <td><?= badge((string)$t['status']) ?></td>
              <td class="muted"><?= h(to_jalali((string)$t['updated_at'], true)) ?></td>
              <td class="acts"><?php if (can('tickets.view')): ?><a class="btn btn-sm" href="index.php?p=tickets&t=<?= (int)$t['id'] ?>">مشاهده</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تب معرفی و کارت‌ها -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="ref">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">🎁 معرفی و زیرمجموعه</div>
          <div class="card-sub">وضعیت دعوت‌ها و پاداش معرفی این کاربر</div>
        </div>
        <?php if (!empty($uRef['enabled'])): ?><span class="badge b-green">سیستم معرفی فعال</span>
        <?php else: ?><span class="badge b-gray">غیرفعال</span><?php endif; ?>
      </div>

      <?php if (!$uRef): ?>
        <div class="empty"><div class="ic">🎁</div>سیستم معرفی در دسترس نیست.</div>
      <?php else: ?>
        <div class="mini-stats mt3">
          <div class="mini"><div class="ic">👥</div><div><div class="t">زیرمجموعه مستقیم</div><div class="v"><?= fa_num((int)($uRef['count'] ?? 0)) ?></div></div></div>
          <div class="mini ok"><div class="ic">💳</div><div><div class="t">زیرمجموعه خریدار</div><div class="v"><?= fa_num((int)($uRef['active'] ?? 0)) ?></div></div></div>
          <div class="mini"><div class="ic">🔗</div><div><div class="t">سطح دوم</div><div class="v"><?= fa_num((int)($uRef['l2'] ?? 0)) ?></div></div></div>
          <div class="mini warn"><div class="ic">💰</div><div><div class="t">پاداش دریافتی</div><div class="v"><?= h((string)($uRef['earned_txt'] ?? money(0))) ?></div></div></div>
        </div>

        <div class="kv mt3"><span class="k">کد معرف</span><span class="v mono ltr"><?= h((string)($uRef['code'] ?? '—')) ?></span></div>
        <?php if (!empty($uRef['link'])): ?>
          <div class="copy-line mt3" data-copy="<?= h((string)$uRef['link']) ?>">
            <span class="mono ltr" style="word-break:break-all"><?= h((string)$uRef['link']) ?></span>
            <span class="badge b-blue">📋 کپی لینک</span>
          </div>
        <?php endif; ?>

        <?php if ($uInv): ?>
          <div class="table-wrap mt3"><table class="responsive">
            <thead><tr><th>#</th><th>کاربر</th><th>شارژ تاییدشده</th><th>تاریخ عضویت</th><th data-l=""></th></tr></thead>
            <tbody>
            <?php foreach ($uInv as $iv): ?>
              <tr>
                <td data-l="#" class="num tight"><span class="numbox"><?= fa_num((int)($iv['id'] ?? 0)) ?></span></td>
                <td data-l="کاربر" class="grow-col">
                  <div class="idcell">
                    <span class="nm"><?= h((string)($iv['name'] ?? 'کاربر')) ?></span>
                    <span class="sub mono"><?= !empty($iv['username']) ? '@' . h((string)$iv['username']) : fa_num((string)($iv['tg_id'] ?? '')) ?></span>
                  </div>
                </td>
                <td data-l="شارژ تاییدشده" class="num tight">
                  <span class="numbox <?= (float)($iv['paid'] ?? 0) > 0 ? 'g' : '' ?>"><?= money((float)($iv['paid'] ?? 0)) ?></span>
                </td>
                <td data-l="تاریخ عضویت" class="muted tight" style="font-size:12px"><?= h(to_jalali((string)($iv['created_at'] ?? ''))) ?></td>
                <td class="acts" data-l=""><a class="btn btn-sm btn-ghost" href="index.php?p=users&u=<?= (int)($iv['id'] ?? 0) ?>">👁 پرونده</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
          <div class="empty mt3"><div class="ic">👥</div>هنوز کسی را دعوت نکرده است.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card mt3">
      <div class="card-head">
        <div>
          <div class="card-title">💳 کارت‌های بانکی ثبت‌شده</div>
          <div class="card-sub">کارت‌هایی که کاربر برای پرداخت کارت به کارت ثبت کرده است</div>
        </div>
        <?php if (can('cards.view')): ?><a class="btn btn-sm btn-ghost" href="index.php?p=cards">⚙️ مدیریت احراز کارت</a><?php endif; ?>
      </div>

      <?php if (!$uCards): ?>
        <div class="empty"><div class="ic">💳</div>کارتی ثبت نشده است.</div>
      <?php else: ?>
        <div class="ucards">
          <?php foreach ($uCards as $c):
            $pan  = (string)($c['pan'] ?? '');
            $mask = trim((string)($c['pan_mask'] ?? ''));
            if ($mask === '') $mask = CardAuth::mask($pan);
            $bank = trim((string)($c['bank'] ?? ''));
            if ($bank === '') $bank = CardAuth::bankOf($pan);
            $stat = (string)($c['status'] ?? 'pending');
          ?>
            <div class="ucard">
              <span class="st"><?= h(CardAuth::label($stat)) ?></span>
              <div class="bk">🏦 <?= h($bank !== '' ? $bank : 'بانک نامشخص') ?></div>
              <div class="pan"><?= h($mask) ?></div>
              <div class="ft">
                <span><?= h((string)($c['holder'] ?? '—')) ?></span>
                <span><?= fa_num((int)($c['uses'] ?? 0)) ?> بار استفاده</span>
              </div>
              <?php if (!empty($c['sheba'])): ?>
                <div class="ft"><span class="mono ltr">IR<?= h(ltrim((string)$c['sheba'], 'IR')) ?></span></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تب فعالیت -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="act">
    <div class="grid g2">
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">🕓 خط زمانی فعالیت</div>
            <div class="card-sub">آخرین رویدادهای این کاربر در ربات</div>
          </div>
        </div>
        <?php if (!$uTl): ?>
          <div class="empty"><div class="ic">🕓</div>فعالیتی ثبت نشده است.</div>
        <?php else: ?>
          <div class="utl">
            <?php foreach ($uTl as $ev): ?>
              <div class="it <?= h((string)$ev['k']) ?>">
                <div class="tt"><span><?= $ev['ic'] ?></span><span><?= h((string)$ev['x']) ?></span></div>
                <div class="dt"><?= (string)$ev['t'] !== '' ? h(to_jalali((string)$ev['t'], true)) : '—' ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">📋 خلاصهٔ حساب</div>
            <div class="card-sub">یک نگاه کلی به وضعیت کاربر</div>
          </div>
        </div>
        <div class="kv"><span class="k">مدت عضویت</span><span class="v"><?= fa_num($uDays) ?> روز</span></div>
        <div class="kv"><span class="k">آخرین بازدید</span><span class="v"><?= h($uSeenTxt) ?></span></div>
        <div class="kv"><span class="k">میانگین خرید ماهانه</span><span class="v"><?= money($uDays > 0 ? ($uOut / max(1.0, $uDays / 30)) : $uOut) ?></span></div>
        <div class="kv"><span class="k">ماندهٔ قابل خرج</span><span class="v"><?= money((float)$U['balance']) ?></span></div>
        <div class="kv"><span class="k">سرویس فعال</span><span class="v"><?= fa_num($nSvcOk) ?> از <?= fa_num(count($svcs)) ?></span></div>
        <div class="kv"><span class="k">تیکت باز</span><span class="v"><?= fa_num($uTkOpen) ?></span></div>
        <div class="kv"><span class="k">کارت تاییدشده</span><span class="v"><?= fa_num($uCardOk) ?> از <?= fa_num(count($uCards)) ?></span></div>
        <div class="kv"><span class="k">وضعیت گفتگو در ربات</span><span class="v"><?= $U['state'] ? h((string)$U['state']) : 'بدون وضعیت' ?></span></div>
        <?php if (trim((string)$U['note']) !== ''): ?>
          <div class="alert a-info mt3">📝 <?= h((string)$U['note']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- تب عملیات حساس -->
  <div class="tab-panel" data-tab-panel-group="usr" data-tab-panel="danger">
    <div class="card">
      <div class="card-title">⚠️ عملیات حساس</div>
      <div class="card-sub">این عملیات‌ها فوراً اعمال می‌شوند.</div>
      <div class="row mt3" style="flex-wrap:wrap">
        <?php if (can('users.ban')): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="ban"><input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <button class="btn <?= (int)$U['is_banned'] ? 'btn-green' : 'btn-red' ?>">
              <?= (int)$U['is_banned'] ? '✅ رفع مسدودیت' : '🚫 مسدود کردن' ?></button></form>
        <?php endif; ?>
        <?php if (can('users.edit')): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="reset_state"><input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <button class="btn">🔄 ریست وضعیت گفتگو</button></form>
        <?php endif; ?>
        <?php if (can('users.delete')): ?>
          <form method="post" style="display:inline" data-confirm="کاربر حذف شود؟ سرویس‌ها حذف نمی‌شوند."><?= csrf_field() ?>
            <input type="hidden" name="act" value="del"><input type="hidden" name="user_id" value="<?= (int)$U['id'] ?>">
            <button class="btn btn-red">🗑 حذف کاربر</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php else: ?>

  <div class="uh-hero">
    <span class="uh-glow g1"></span><span class="uh-glow g2"></span>

    <div class="uh-top">
      <div class="uh-ic">👥</div>
      <div class="uh-tt">
        <h2>مدیریت کاربران</h2>
        <p>جستجو، فیلتر و مدیریت کامل پروندهٔ کاربران ربات — موجودی کیف پول، سرویس‌ها، سطح نمایندگی، تیکت‌ها و دسترسی‌ها.</p>
      </div>
      <div class="uh-act">
<?php if (can('users.export')): ?>
        <a class="btn btn-sm" href="index.php?p=users&amp;export=1">⬇️ خروجی CSV</a>
<?php endif; ?>
<?php if (can('users.create')): ?>
        <button class="btn btn-primary btn-sm" data-modal="mUserNew">➕ افزودن کاربر</button>
<?php endif; ?>
      </div>
    </div>

    <div class="uh-cells">
      <div class="uh-cell b"><span class="i">👥</span><span class="v"><?= fa_num($tAll) ?></span><span class="l">کل کاربران</span></div>
      <div class="uh-cell g"><span class="i">🆕</span><span class="v"><?= fa_num($tNew) ?></span><span class="l">عضو امروز</span></div>
      <div class="uh-cell c"><span class="i">🛒</span><span class="v"><?= fa_num($tBuyers) ?></span><span class="l">خریدار</span></div>
      <div class="uh-cell <?= $tBan > 0 ? 'r' : 'g' ?>"><span class="i">🚫</span><span class="v"><?= fa_num($tBan) ?></span><span class="l">مسدود</span></div>
      <div class="uh-cell o"><span class="i">💰</span><span class="v"><?= money($tBal) ?></span><span class="l">جمع کیف پول‌ها</span></div>
      <div class="uh-cell p"><span class="i">📈</span><span class="v"><?= fa_num($tAll > 0 ? (int)round($tBuyers * 100 / $tAll) : 0) ?>٪</span><span class="l">نرخ تبدیل به خریدار</span></div>
    </div>

    <div class="uh-rate">
      <div class="uh-rate-t">
        <span>نسبت کاربران خریدار به کل</span>
        <b><?= fa_num($tBuyers) ?> از <?= fa_num($tAll) ?></b>
      </div>
      <div class="uh-rate-bar"><span style="width:<?= $tAll > 0 ? (int)round($tBuyers * 100 / $tAll) : 0 ?>%"></span></div>
    </div>
  </div>

  <div class="tabbar mt4">
    <a class="<?= $seg === '' ? 'on' : '' ?>" href="index.php?p=users">📂 همه</a>
    <a class="<?= $seg === 'today' ? 'on' : '' ?>" href="index.php?p=users&seg=today">🆕 عضو امروز</a>
    <a class="<?= $seg === 'buyers' ? 'on' : '' ?>" href="index.php?p=users&seg=buyers">🛒 خریداران</a>
    <a class="<?= $seg === 'balance' ? 'on' : '' ?>" href="index.php?p=users&seg=balance">💰 دارای موجودی</a>
    <a class="<?= $seg === 'banned' ? 'on' : '' ?>" href="index.php?p=users&seg=banned">🚫 مسدودها</a>
  </div>

  <div class="card mt3">
    <div class="card-head">
      <div>
        <div class="card-title">📋 لیست کاربران</div>
        <div class="card-sub">نمایش <span id="usrCount"><?= fa_num(count($users)) ?></span> کاربر (حداکثر ۳۰۰ مورد)</div>
      </div>
      <div class="ul-tools">
        <input id="usrFilter" class="mono" type="search" placeholder="⚡️ فیلتر لحظه‌ای همین صفحه">
        <form method="get" class="row" style="gap:6px">
          <input type="hidden" name="p" value="users">
          <?php if ($seg !== ''): ?><input type="hidden" name="seg" value="<?= h($seg) ?>"><?php endif; ?>
          <input class="mono" type="search" name="q" value="<?= h($q) ?>" placeholder="🔎 جستجوی کامل در دیتابیس">
          <button class="btn btn-sm">جستجو</button>
          <?php if ($q !== '' || $seg !== ''): ?>
            <a class="btn btn-sm btn-ghost" href="index.php?p=users">✖️ پاک کردن</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <?php if (!$users): ?>
      <div class="empty"><div class="ic">👤</div>کاربری یافت نشد.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table id="usrTable" class="responsive">
        <thead><tr><th>#</th><th>کاربر</th><th>آیدی عددی</th><th>تماس</th><th>موجودی</th><th>سرویس</th><th>وضعیت</th><th>عضویت</th><th data-l="">عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($users as $x):
            $xid  = (int)$x['id'];
            $cnt  = (int)($svcMap[$xid]['c'] ?? 0);
            $cntA = (int)($svcMap[$xid]['a'] ?? 0);
            $xnm  = trim((string)$x['first_name'] . ' ' . (string)$x['last_name']);
            if ($xnm === '') $xnm = 'کاربر';
            $xlv  = (int)($x['reseller_level'] ?? 0);
            $xav  = (int)$x['is_banned'] ? 'ban' : ($xlv > 0 ? 'rs' : '');
            $xq   = mb_strtolower(trim($xnm . ' ' . (string)$x['username'] . ' ' . (string)$x['tg_id']
                    . ' ' . (string)$x['phone'] . ' ' . (string)$x['email']));
        ?>
          <tr class="u-row" data-q="<?= h($xq) ?>">
            <td data-l="#" class="num tight"><span class="numbox"><?= fa_num($xid) ?></span></td>
            <td data-l="کاربر" class="grow-col">
              <div class="ul-cell">
                <span class="ul-ava <?= $xav ?>"><?= h(mb_substr($xnm, 0, 1)) ?></span>
                <div class="idcell">
                  <span class="nm"><?= h($xnm) ?>
                    <?php if ($xlv > 0): ?><span class="badge b-orange"><?= $xlv === 2 ? '🥇 نماینده ۲' : '🥈 نماینده ۱' ?></span><?php endif; ?>
                    <?php if (!empty($x['phone_verified']) || !empty($x['email_verified'])): ?><span class="badge b-green">🔐</span><?php endif; ?>
                  </span>
                  <?php if ($x['username']): ?><span class="sub mono">@<?= h((string)$x['username']) ?></span><?php endif; ?>
                </div>
              </div>
            </td>
            <td data-l="آیدی عددی" class="num tight">
              <span class="numbox mono" data-copy="<?= (int)$x['tg_id'] ?>" title="کپی"><?= fa_num((string)$x['tg_id']) ?></span>
            </td>
            <td data-l="تماس" class="num tight">
              <?php if (!empty($x['phone'])): ?><span class="numbox mono"><?= h((string)$x['phone']) ?></span>
              <?php elseif (!empty($x['email'])): ?><span class="muted mono ltr" style="font-size:11px"><?= h((string)$x['email']) ?></span>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td data-l="موجودی" class="num tight">
              <span class="numbox <?= (float)$x['balance'] < 0 ? 'r' : ((float)$x['balance'] > 0 ? 'g' : '') ?>"><?= money((float)$x['balance']) ?></span>
            </td>
            <td data-l="سرویس" class="num tight">
              <span class="numbox <?= $cntA > 0 ? 'a' : '' ?>"><?= fa_num($cnt) ?></span>
              <?php if ($cnt > 0): ?><div class="muted" style="font-size:10.5px">✅ <?= fa_num($cntA) ?> فعال</div><?php endif; ?>
            </td>
            <td data-l="وضعیت"><?= (int)$x['is_banned']
              ? '<span class="badge b-red">🚫 مسدود</span>'
              : '<span class="badge b-green">✅ فعال</span>' ?></td>
            <td data-l="عضویت" class="muted tight" style="font-size:12px"><?= h(to_jalali((string)$x['created_at'])) ?></td>
            <td class="acts" data-l="">
              <a class="btn btn-sm btn-primary" href="index.php?p=users&u=<?= $xid ?>">👁 پرونده</a>
              <?php if ($cnt > 0 && can('services.view')): ?>
                <a class="btn btn-sm btn-ghost" href="index.php?p=services&user=<?= $xid ?>">🔑</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <script>
  (function () {
    var f = document.getElementById('usrFilter');
    var t = document.getElementById('usrTable');
    var c = document.getElementById('usrCount');
    if (!f || !t) return;
    var rows = Array.prototype.slice.call(t.querySelectorAll('tbody tr'));
    var fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function en(v) { fa.forEach(function (d, i) { v = v.split(d).join(String(i)); }); return v; }
    function run() {
      var v = en((f.value || '').trim().toLowerCase()), n = 0;
      rows.forEach(function (r) {
        var ok = !v || (r.getAttribute('data-q') || '').indexOf(v) > -1;
        r.style.display = ok ? '' : 'none';
        if (ok) n++;
      });
      if (c) c.textContent = n;
    }
    f.addEventListener('input', run);
  })();
  </script>

  <?php if (can('users.create')): ?>
  <div class="modal" id="mUserNew">
    <div class="m-back"></div>
    <div class="m-box">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="create">
        <div class="m-head">
          <span style="font-size:18px">➕</span>
          <h3>افزودن دستی کاربر</h3>
          <button type="button" class="x" data-modal-close>✕</button>
        </div>
        <div class="m-body">
          <div class="alert a-info">کاربران معمولاً خودکار با زدن <span class="mono">/start</span> در ربات ساخته می‌شوند.</div>
          <div class="form-grid">
            <div class="field"><label>آیدی عددی تلگرام <span style="color:var(--red)">*</span></label><input class="mono" type="text" name="tg_id" required></div>
            <div class="field"><label>نام</label><input type="text" name="first_name" placeholder="کاربر"></div>
            <div class="field"><label>یوزرنیم</label><input class="mono" type="text" name="username" placeholder="@user"></div>
            <div class="field"><label>موبایل</label><input class="mono" type="text" name="phone"></div>
            <div class="field"><label>ایمیل</label><input class="mono" type="text" name="email"></div>
            <div class="field"><label>موجودی اولیه</label><input class="mono" type="text" name="balance" value="0"></div>
            <div class="field full"><label>یادداشت</label><input type="text" name="note"></div>
          </div>
        </div>
        <div class="m-foot">
          <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
          <button class="btn btn-primary">💾 ذخیره‌ی کاربر</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
