<?php
/** مدیریت سرورها و پنل‌ها (VPN-UI / سنایی / مرزبان) – لیست‌محور با فرم مودال */
declare(strict_types=1);

if (!can('panels.view')) { echo denyBox('بخش پنل‌ها برای شما فعال نیست.'); return; }

$act = (string)($_POST['act'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {

    if ($act === 'save') {
        $id = pint('id');
        need($id ? 'panels.edit' : 'panels.create', 'panels');

        /* fixed77: پاسارگارد — در فیلد گروه‌ها می‌توان «نام» گروه (a-z و 0-9) یا شناسهٔ عددی نوشت؛ سایر پنل‌ها فقط عدد. جداکنندهٔ فارسی «،» هم پذیرفته می‌شود */
        $pnIds = static function (string $k): string {
            $raw = preg_replace('/[\s،;]+/u', ',', en_num(ptxt($k)));
            if (Xui::normType(ptxt('type', 'vpn-ui')) === 'pasarguard') {
                $out = [];
                foreach (explode(',', strtolower($raw)) as $t) {
                    $t = preg_replace('/[^a-z0-9_\-]/', '', trim($t));
                    if ($t !== '') $out[] = $t;
                }
                return implode(',', array_values(array_unique($out)));
            }
            return trim(preg_replace('/[^\d,]/', '', $raw), ',');
        };

        $data = [
            'name'           => ptxt('name'),
            'type'           => Xui::typeAllowed(ptxt('type', 'vpn-ui')) ? Xui::normType(ptxt('type', 'vpn-ui')) : 'vpn-ui',
            'scheme'         => ptxt('scheme', 'https') === 'http' ? 'http' : 'https',
            'host'           => preg_replace('#^https?://#', '', rtrim(ptxt('host'), '/')),
            'port'           => pint('port', 54321),
            'web_path'       => trim(ptxt('web_path'), '/'),
            'username'       => ptxt('username'),
            'password'       => ptxt('password'),
            'ssl_verify'     => pchk('ssl_verify'),
            'sub_base'       => rtrim(ptxt('sub_base'), '/'),
            'node_host'      => ptxt('node_host'),
            'inbound_ids'    => $pnIds('inbound_ids'),
            'remark_prefix'  => ptxt('remark_prefix'),
            'username_mode'  => array_key_exists(ptxt('username_mode'), Svc::USERNAME_MODES) ? ptxt('username_mode') : 'random',
            'renew_mode'     => array_key_exists(ptxt('renew_mode'), Svc::RENEW_MODES) ? ptxt('renew_mode') : 'reset_extend',
            'test_enabled'   => pchk('test_enabled'),
            'test_inbound_ids'   => $pnIds('test_inbound_ids'),
            'test_single_inbound'=> pchk('test_single_inbound'),
            'test_volume_gb' => pflt('test_volume_gb', 1),
            'test_volume_mb' => max(0, pint('test_volume_mb')), /* fixed79: حجم تست به مگابایت (اولویت بر گیگ) */
            'test_days'      => pint('test_days'),
            'test_hours'     => pint('test_hours'),
            'test_limit'     => pint('test_limit', 1),
            'user_limit'     => pint('user_limit', -1),
            'active'         => pchk('active'),
            'sort'           => pint('sort'),
        ];

        /* پنل تک‌اینباند (سنایی قدیم): فقط یک کد اینباند و الزامی */
        if (Xui::isSingleType($data['type'])) {
            $one = Svc::parseIds($data['inbound_ids']);
            $data['inbound_ids'] = $one ? (string)$one[0] : '';
            if ($data['inbound_ids'] === '') {
                flash('err', '⛔️ در «سنایی قدیم» کانفیگ فقط روی یک اینباند ساخته می‌شود؛ لطفاً کد همان اینباند را وارد کنید.');
                back('panels', $id ? ['edit' => $id] : []);
            }
        }

        /* پاسارگارد: با «کلید API» نیازی به یوزر/پسورد ادمین نیست (کلید تازه یا کلید ذخیره‌شدهٔ قبلی) */
        /* fixed79: ستون test_volume_mb — نصب‌های قدیمی: خودکار اضافه می‌شود؛ اگر نشد، از داده حذف شود تا INSERT خطا ندهد */
        if (class_exists('Migrate') && !Migrate::hasColumn('panels', 'test_volume_mb')) {
            try { DB::q('ALTER TABLE {p}panels ADD COLUMN `test_volume_mb` INT NOT NULL DEFAULT 0'); } catch (Throwable $ex) { app_log('db', 'add panels.test_volume_mb failed: ' . $ex->getMessage()); }
        }
        if (!class_exists('Migrate') || !Migrate::hasColumn('panels', 'test_volume_mb')) unset($data['test_volume_mb']);
        $isPg    = ($data['type'] === 'pasarguard');
        $pgKeyed = false;
        if ($isPg) {
            $pgKeyed = trim(ptxt('api_token')) !== '' && !pchk('api_token_clear');
            if (!$pgKeyed && $id && !pchk('api_token_clear')) {
                try { $pgKeyed = trim((string)DB::val('SELECT api_token FROM {p}panels WHERE id = :id', [':id' => $id])) !== ''; } catch (Throwable $ex) { $pgKeyed = false; }
            }
            if ($data['username'] === '' && $pgKeyed) $data['username'] = 'apikey';
        }

        if ($data['name'] === '' || $data['host'] === '' || $data['username'] === '') {
            flash('err', $isPg
                ? 'نام پنل و آدرس الزامی است؛ برای پاسارگارد یا یوزر/پسورد ادمین را بدهید یا «کلید API» (pg_key_…) را در فیلد توکن وارد کنید.'
                : 'نام پنل، آدرس و نام کاربری الزامی است.');
            back('panels', $id ? ['edit' => $id] : []);
        }

        /* توکن API نسل جدید 3x-ui — خالی = بدون تغییر؛ تیک حذف = پاک شود */
        $apiTok = trim(ptxt('api_token'));
        $tokCol = class_exists('Migrate') && Migrate::hasColumn('panels', 'api_token');
        if (!$tokCol && class_exists('Migrate')) {
            /* نصب‌های قدیمی: ستون همین‌جا بی‌خطر اضافه می‌شود */
            try {
                DB::q('ALTER TABLE {p}panels ADD COLUMN `api_token` VARCHAR(500) NULL');
                $tokCol = Migrate::hasColumn('panels', 'api_token');
            } catch (Throwable $ex) {
                app_log('db', 'add panels.api_token failed: ' . $ex->getMessage());
            }
        }
        if ($tokCol) {
            if (pchk('api_token_clear'))  $data['api_token'] = null;
            elseif ($apiTok !== '')       $data['api_token'] = app_encrypt($apiTok);
        } elseif ($apiTok !== '') {
            flash('err', '⚠️ ستون «توکن API» به دیتابیس اضافه نشد و توکن ذخیره نشد؛ از صفحهٔ «بروزرسانی»، تکمیل دیتابیس را اجرا کنید.');
        }

        if ($id) {
            // رمز خالی یعنی «بدون تغییر»؛ در غیر این صورت رمزنگاری‌شده ذخیره می‌شود
            if ($data['password'] === '') unset($data['password']);
            else                          $data['password'] = app_encrypt($data['password']);
            $data['session'] = null;
            DB::update('panels', $data, 'id = :id', [':id' => $id]);
            flash('ok', '✅ پنل <b>' . h($data['name']) . '</b> به‌روز شد.');
        } else {
            $data['password']   = app_encrypt($data['password']);
            $data['created_at'] = now();
            $id = DB::insert('panels', $data);
            flash('ok', '✅ پنل جدید افزوده شد. دکمه‌ی «تست اتصال» را بزنید.');
        }
        back('panels', ['edit' => $id]);
    }

    if ($act === 'del') {
        need('panels.delete', 'panels');
        $id = pint('id');
        DB::delete('panels', 'id = :id', [':id' => $id]);
        flash('ok', '🗑 پنل حذف شد. (سرویس‌های قبلی در دیتابیس باقی ماندند)');
        back('panels');
    }

    if ($act === 'toggle') {
        need('panels.edit', 'panels');
        $id = pint('id');
        $p  = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $id]);
        if ($p) DB::update('panels', ['active' => (int)$p['active'] ? 0 : 1], 'id = :id', [':id' => $id]);
        back('panels');
    }

    if ($act === 'setinb') { /* fixed79: ثبت گروه‌ها/اینباندهای تیک‌خورده */
        need('panels.edit', 'panels');
        $id  = pint('id');
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,،;]+/u', en_num((string)($_POST['ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []), static function ($x) { return $x > 0; })));
        if (!$ids) { flash('err', 'هیچ موردی تیک نخورده است.'); back('panels', ['inb' => $id]); }
        if (!DB::one('SELECT id FROM {p}panels WHERE id = :id', [':id' => $id])) { flash('err', 'پنل پیدا نشد.'); back('panels'); }
        DB::update('panels', ['inbound_ids' => implode(',', $ids)], 'id = :id', [':id' => $id]);
        if (class_exists('Audit')) { try { Audit::log('panels.setinb', ['id' => $id, 'ids' => implode(',', $ids)]); } catch (Throwable $e) { } }
        flash('ok', '✅ ' . fa_num((string)count($ids)) . ' مورد به‌عنوان گروه/اینباند پنل ثبت شد: <code>' . h(implode(',', $ids)) . '</code>');
        back('panels', ['inb' => $id]);
    }

    if ($act === 'health') {
        need('panels.health', 'panels');
        $id = pint('id');
        $x  = Xui::forPanel($id);
        if (!$x) {
            flash('err', 'پنل پیدا نشد.');
        } else {
            $r = $x->healthCheck();
            if (!empty($r['ok'])) {
                $cnt = isset($r['inbounds']) ? (is_array($r['inbounds']) ? count($r['inbounds']) : (int)$r['inbounds']) : null;
                flash('ok', '✅ اتصال به پنل موفق بود.' . ($cnt !== null ? ' تعداد اینباند: ' . fa_num($cnt) : ''));
            } else {
                flash('err', '❌ اتصال ناموفق: ' . h((string)($r['error'] ?? $r['message'] ?? 'نامشخص')));
            }
        }
        back('panels', ['inb' => $id]);
    }
}

$panels = DB::all('SELECT * FROM {p}panels ORDER BY sort ASC, id ASC');
$editId = (int)($_GET['edit'] ?? 0);
$e      = $editId ? DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $editId]) : null;
$inbId  = (int)($_GET['inb'] ?? 0);
$v      = static function (string $k, $d = '') use ($e) { return $e[$k] ?? $d; };

/* آمار خلاصه */
$cntOn = 0; $cntTest = 0; $svcTotal = 0;
foreach ($panels as $p) {
    if ((int)$p['active']) $cntOn++;
    if ((int)$p['test_enabled']) $cntTest++;
    $svcTotal += Svc::panelUsage((int)$p['id']);
}

$canWrite = can('panels.create') || can('panels.edit');
?>

<div class="page-head">
  <div>
    <h2>🖧 سرورها و پنل‌ها</h2>
    <div class="sub">پنل‌های پشتیبانی‌شده: <b>VPN-UI</b> ، <b>سنایی جدید (مولتی‌اینباند)</b> ، <b>سنایی قدیم (تک‌اینباند)</b> و <b>مرزبان</b></div>
  </div>
  <div class="acts">
    <?php if (can('panels.create')): ?>
      <button class="btn btn-primary" data-modal="mPanel">➕ افزودن پنل</button>
    <?php endif; ?>
  </div>
</div>

<style>
/* ===== Panels Studio v3 ===== */
.pn-hero{position:relative;overflow:hidden;border-radius:18px;padding:18px 20px;margin:14px 0 0;
  background:linear-gradient(135deg,rgba(91,140,255,.18),rgba(139,92,246,.10) 55%,transparent),var(--surface);
  border:1px solid var(--border)}
.pn-hero::after{content:"";position:absolute;inset-inline-end:-80px;top:-90px;width:250px;height:250px;border-radius:50%;
  background:radial-gradient(circle,rgba(91,140,255,.22),transparent 70%);pointer-events:none}
.pn-hero h3{margin:0 0 5px;font-size:16.5px;display:flex;align-items:center;gap:9px}
.pn-hero .s{color:var(--muted);font-size:12.5px;line-height:1.95}
.pn-cells{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:15px;position:relative;z-index:1}
.pn-cell{background:var(--surface-2);border:1px solid var(--border);border-radius:13px;padding:11px 9px;text-align:center}
.pn-cell .v{font-size:19px;font-weight:700;line-height:1.25}
.pn-cell .l{font-size:10.5px;color:var(--muted);margin-top:4px}
.pn-cell.g .v{color:var(--green)}.pn-cell.o .v{color:var(--orange)}
.pn-cell.b .v{color:var(--accent)}.pn-cell.r .v{color:var(--red)}

.pn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;margin-top:14px}
.pn-card{position:relative;border:1px solid var(--border);border-radius:17px;background:var(--surface-2);
  padding:14px 15px;display:flex;flex-direction:column;gap:10px;transition:.18s}
.pn-card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.22)}
.pn-card::before{content:"";position:absolute;inset-inline:15px;top:0;height:2px;border-radius:2px;
  background:linear-gradient(90deg,var(--accent),var(--accent-2));opacity:.8}
.pn-card.off{opacity:.56}
.pn-card.off::before{background:var(--muted)}
.pn-top{display:flex;align-items:flex-start;gap:10px;min-width:0}
.pn-ic{width:42px;height:42px;flex:0 0 auto;border-radius:12px;display:grid;place-items:center;font-size:19px;
  background:var(--surface-3);border:1px solid var(--border)}
.pn-nm{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pn-ty{font-size:11px;color:var(--muted);margin-top:3px}
.pn-dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto;margin-top:6px}
.pn-dot.on{background:var(--green);box-shadow:0 0 0 4px rgba(47,212,143,.16)}
.pn-dot.no{background:var(--muted)}
.pn-url{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;direction:ltr;text-align:left;
  background:var(--surface-3);border:1px solid var(--border);border-radius:10px;padding:7px 9px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.pn-tags{display:flex;flex-wrap:wrap;gap:5px}
.pn-cap{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--muted)}
.pn-bar{flex:1;height:7px;border-radius:99px;background:var(--surface-3);overflow:hidden;border:1px solid var(--border)}
.pn-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
.pn-bar.hot i{background:linear-gradient(90deg,var(--orange),var(--red))}
.pn-err{font-size:11px;color:var(--red);background:rgba(255,107,107,.09);border:1px solid rgba(255,107,107,.25);
  border-radius:9px;padding:6px 9px;line-height:1.75}
.pn-acts{display:flex;gap:5px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border)}
.pn-acts form{display:inline;margin:0}
.pn-add{display:grid;place-items:center;gap:9px;border:2px dashed var(--border);border-radius:17px;
  background:transparent;padding:26px 16px;text-align:center;cursor:pointer;transition:.18s;color:var(--muted);
  font-family:inherit;font-size:13px;width:100%}
.pn-add:hover{border-color:var(--accent);color:var(--text);background:var(--surface-2)}
.pn-add .p{font-size:30px;line-height:1}

.pn-prev{margin:10px 0 2px;padding:10px 12px;border-radius:12px;border:1px dashed var(--border);
  background:var(--surface-3);font-size:12px;color:var(--muted)}
.pn-prev b{display:block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;
  color:var(--accent);direction:ltr;text-align:left;margin-top:5px;word-break:break-all}
@media (max-width:900px){.pn-cells{grid-template-columns:repeat(3,1fr)}}
@media (max-width:560px){.pn-cells{grid-template-columns:repeat(2,1fr)}.pn-grid{grid-template-columns:1fr}}

/* ===== مودال افزودن پنل — نسخهٔ گسترده ===== */
/* نوار گام‌ها — تنها مسیر جابه‌جایی بین بخش‌های مودال */
.pnm-steps{display:flex;align-items:stretch;gap:5px;margin:0 0 14px;padding:6px;border-radius:17px;
  background:linear-gradient(180deg,var(--surface-3),var(--surface-2));border:1px solid var(--border);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);overflow-x:auto;scrollbar-width:none}
.pnm-steps::-webkit-scrollbar{display:none}
.pnm-st{position:relative;flex:1 1 0;min-width:126px;display:flex;align-items:center;gap:9px;
  padding:10px 12px;border-radius:13px;background:transparent;border:1px solid transparent;
  font-family:inherit;font-size:11.5px;font-weight:600;color:var(--muted);text-align:start;
  white-space:nowrap;cursor:pointer;transition:.18s ease;user-select:none}
.pnm-st::before{content:"";position:absolute;inset-inline-start:-5px;top:50%;width:5px;height:2px;
  margin-top:-1px;border-radius:99px;background:var(--border)}
.pnm-st:first-child::before{display:none}
.pnm-st:hover{background:var(--surface-3);color:var(--text)}
.pnm-st .n{width:25px;height:25px;flex:0 0 25px;border-radius:50%;display:grid;place-items:center;
  background:var(--surface-3);border:1px solid var(--border);font-size:11px;font-weight:800;transition:.18s ease}
.pnm-st .t{overflow:hidden;text-overflow:ellipsis}
.pnm-st.ok{color:var(--text)}
.pnm-st.ok .n{background:var(--green-soft);border-color:transparent;color:var(--green);font-size:0}
.pnm-st.ok .n::after{content:"✓";font-size:13px;line-height:1;font-weight:800}
.pnm-st.on{background:var(--grad);border-color:transparent;color:#fff;box-shadow:var(--glow);transform:translateY(-1px)}
.pnm-st.on .n{background:rgba(255,255,255,.22);border-color:transparent;color:#fff;font-size:11px}
.pnm-st.on .n::after{content:none}
.pnm-st .dot{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:var(--red);
  margin-inline-start:auto;box-shadow:0 0 0 3px rgba(255,107,107,.16)}
.pnm-st.on .dot{background:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.22)}
@media (max-width:640px){.pnm-st{flex:0 0 auto;min-width:auto;padding:9px 11px}}

.pnm-quick{position:relative;overflow:hidden;border-radius:15px;padding:14px 15px;margin-bottom:14px;
  border:1px solid var(--accent);
  background:linear-gradient(135deg,var(--accent-soft),transparent 70%),var(--surface-2)}
.pnm-quick h4{margin:0 0 3px;font-size:13.5px;display:flex;align-items:center;gap:7px}
.pnm-quick p{margin:0 0 10px;font-size:11.5px;color:var(--muted);line-height:1.85}
.pnm-quick .qr{display:flex;gap:7px;align-items:stretch}
.pnm-quick input{flex:1;min-width:0}
.pnm-quick input.err{border-color:var(--red)}

.pnm-sum{border:1px solid var(--border);border-radius:15px;overflow:hidden;background:var(--surface-2)}
.pnm-sum .hd{padding:11px 14px;background:var(--surface-3);border-bottom:1px solid var(--border);
  font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.pnm-sum .rw{display:flex;align-items:center;gap:10px;padding:10px 14px;
  border-bottom:1px solid var(--border);font-size:12.5px}
.pnm-sum .rw:last-child{border-bottom:0}
.pnm-sum .rw .k{color:var(--muted);flex:0 0 42%}
.pnm-sum .rw .v{flex:1;min-width:0;font-weight:600;overflow-wrap:anywhere}
.pnm-sum .rw .v.mono{font-family:ui-monospace,Menlo,Consolas,monospace;direction:ltr;text-align:left;font-size:11.5px}
.pnm-sum .rw .v.em{color:var(--muted);font-weight:400}

.pnm-chk{display:flex;flex-direction:column;gap:7px;margin-top:12px}
.pnm-ck{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:11px;
  border:1px solid var(--border);background:var(--surface-2);font-size:12.5px}
.pnm-ck .i{width:20px;height:20px;flex:0 0 20px;border-radius:50%;display:grid;place-items:center;
  font-size:11px;font-weight:700}
.pnm-ck.ok .i{background:var(--green-soft);color:var(--green)}
.pnm-ck.no{border-color:var(--red)}
.pnm-ck.no .i{background:var(--red-soft);color:var(--red)}

.pnm-tip{display:flex;gap:9px;padding:10px 12px;border-radius:12px;background:var(--surface-3);
  border:1px dashed var(--border);font-size:11.5px;color:var(--muted);line-height:1.9;margin-top:12px}
.pnm-tip .e{font-size:15px;line-height:1.4}
@media (max-width:560px){
  .pnm-sum .rw{flex-direction:column;align-items:flex-start;gap:3px}
  .pnm-sum .rw .k{flex:none}
}
/* راهنمای نوع پنل (سنایی قدیم/جدید) */
.pn-tyh{display:none;margin-top:8px;padding:10px 12px;border-radius:14px;font-size:12px;line-height:2;
  background:linear-gradient(160deg,rgba(91,140,255,.14),rgba(139,92,246,.08));border:1px solid var(--border)}
.pn-tyh.on{display:block}
.pn-tyh .r{color:var(--muted)}
.pn-tyh .r b{color:var(--accent-text)}
.pn-tyh .gh{display:inline-block;margin-top:6px;font-size:11.5px;color:var(--cyan);text-decoration:none;
  padding:5px 10px;border-radius:999px;background:rgba(38,211,232,.10)}
.pn-ibw{display:none;margin-top:7px;padding:8px 11px;border-radius:12px;font-size:11.5px;line-height:1.9;
  color:#FFD9A0;background:rgba(255,169,46,.12);border:1px solid rgba(255,169,46,.30)}
/* fixed76: موبایل — کارت‌ها و خانه‌های آمار از کادر بیرون نزنند */
@media (max-width:640px){
  .pn-cells{grid-template-columns:repeat(2,minmax(0,1fr))}
  .pn-grid{grid-template-columns:minmax(0,1fr)}
  .pn-card{min-width:0;max-width:100%;overflow:hidden}
  .pn-hero{padding:14px}
  .pn-url{max-width:100%}
  .pn-acts{flex-wrap:wrap}
  .pn-acts .btn,.pn-acts a{flex:1 1 auto;min-width:0}
  .pnm-steps{overflow-x:auto;-webkit-overflow-scrolling:touch}
}
@media (max-width:400px){
  .pn-cells{grid-template-columns:minmax(0,1fr)}
}
</style>

<?php
$pnErrs = 0;
foreach ($panels as $p) { if (!empty($p['last_error']) && (int)$p['active']) $pnErrs++; }
?>

<div class="pn-hero">
  <h3>🖧 مرکز فرمان سرورها</h3>
  <div class="s">
    افزودن، ویرایش، تست اتصال و پایش ظرفیت تمام پنل‌ها در یک صفحه.<br>
    پشتیبانی: <b>VPN-UI</b> ، <b>سنایی جدید</b> ، <b>سنایی قدیم</b> ، <b>مرزبان</b> — و تحویل <b>WireGuard</b> / <b>OpenVPN</b> از طریق انبار ملی
  </div>

  <div class="pn-cells">
    <div class="pn-cell b"><div class="v"><?= fa_num(count($panels)) ?></div><div class="l">کل پنل‌ها</div></div>
    <div class="pn-cell g"><div class="v"><?= fa_num($cntOn) ?></div><div class="l">فعال</div></div>
    <div class="pn-cell o"><div class="v"><?= fa_num($cntTest) ?></div><div class="l">دارای تست</div></div>
    <div class="pn-cell"><div class="v"><?= fa_num($svcTotal) ?></div><div class="l">سرویس ساخته‌شده</div></div>
    <div class="pn-cell <?= $pnErrs ? 'r' : 'g' ?>"><div class="v"><?= fa_num($pnErrs) ?></div><div class="l">خطای اخیر</div></div>
  </div>
</div>

<div class="pn-grid">
  <?php foreach ($panels as $p):
      $used = Svc::panelUsage((int)$p['id']);
      $lim  = (int)$p['user_limit'];
      $pct  = $lim > 0 ? min(100, (int)round($used / max(1, $lim) * 100)) : min(100, (int)round($used / 200 * 100));
      $on   = (int)$p['active'];
      $url  = (string)$p['scheme'] . '://' . (string)$p['host'] . ':' . (int)$p['port']
            . ($p['web_path'] ? '/' . (string)$p['web_path'] : '');
  ?>
    <div class="pn-card <?= $on ? '' : 'off' ?>">
      <div class="pn-top">
        <div class="pn-ic">🖥</div>
        <div style="min-width:0;flex:1">
          <div class="pn-nm"><?= h((string)$p['name']) ?></div>
          <div class="pn-ty"><?= h(Xui::typeLabel((string)$p['type'])) ?></div>
        </div>
        <span class="pn-dot <?= $on ? 'on' : 'no' ?>" title="<?= $on ? 'فعال' : 'غیرفعال' ?>"></span>
      </div>

      <div class="pn-url" data-copy="<?= h($url) ?>" title="کپی آدرس"><?= h($url) ?></div>

      <div class="pn-tags">
        <span class="badge b-blue"><?= Xui::normType((string)($p['type'] ?? '')) === 'pasarguard' ? '🗂 گروه' : '📡 اینباند' ?>: <?= h((string)($p['inbound_ids'] ?: 'همه')) ?></span>
        <?php if ((int)$p['test_enabled']): ?>
          <span class="badge b-orange">🧪 تست <?= h(Svc::testVolumeLabel($p)) ?></span>
        <?php endif; ?>
        <?php if (!empty($p['node_host'])): ?><span class="badge b-gray">🔀 Node</span><?php endif; ?>
        <?php if (!$on): ?><span class="badge b-gray">غیرفعال</span><?php endif; ?>
      </div>

      <div class="pn-cap">
        <span>👥 <?= fa_num($used) ?></span>
        <span class="pn-bar <?= $pct >= 85 ? 'hot' : '' ?>"><i style="width:<?= max(3, $pct) ?>%"></i></span>
        <span><?= $lim < 0 ? '∞' : fa_num($lim) ?></span>
      </div>

      <?php if ($on && !empty($p['last_error'])): ?>
        <div class="pn-err">⚠️ <?= h(mb_substr((string)$p['last_error'], 0, 120)) ?></div>
      <?php endif; ?>

      <div class="pn-acts">
        <?php if (can('panels.edit')): ?>
          <a class="btn btn-sm" href="index.php?p=panels&edit=<?= (int)$p['id'] ?>">✏️ ویرایش</a>
        <?php endif; ?>
        <?php if (can('panels.health')): ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="health">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm">🔍 تست اتصال</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-sm" href="index.php?p=panels&inb=<?= (int)$p['id'] ?>">📡 اینباندها</a>
        <?php if (can('panels.edit')): ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="act" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm"><?= $on ? '⏸' : '▶️' ?></button>
          </form>
        <?php endif; ?>
        <?php if (can('panels.delete')): ?>
          <form method="post" data-confirm="پنل «<?= h((string)$p['name']) ?>» حذف شود؟"><?= csrf_field() ?>
            <input type="hidden" name="act" value="del">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-red">🗑</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (can('panels.create')): ?>
    <button type="button" class="pn-add" data-modal="mPanel">
      <span class="p">➕</span>
      <span><b>افزودن پنل جدید</b></span>
      <span>اتصال به سرور در ۵ گام ساده</span>
    </button>
  <?php endif; ?>
</div>

<div class="card mt4">
  <div class="card-head">
    <div>
      <div class="card-title">📋 پنل‌های ثبت‌شده</div>
      <div class="card-sub">جمعاً <?= fa_num(count($panels)) ?> پنل</div>
    </div>
    <div class="row">
      <input type="search" style="max-width:190px" placeholder="🔎 جستجوی پنل" data-live-filter="#pnlTable tbody tr">
    </div>
  </div>

  <?php if (!$panels): ?>
    <div class="empty">
      <div class="ic">🖧</div>
      هنوز پنلی ثبت نشده است.<br>
      <span class="muted">برای شروع روی دکمه‌ی «افزودن پنل» بزنید.</span>
      <?php if (can('panels.create')): ?>
        <div class="mt3"><button class="btn btn-primary" data-modal="mPanel">➕ افزودن پنل</button></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($inbId && ($ix = Xui::forPanel($inbId))): $list = $ix->inbounds(); ?>
<?php /* fixed79: انتخاب تیک‌زدنی گروه/اینباند */
  $inbPanel = DB::one('SELECT * FROM {p}panels WHERE id = :id', [':id' => $inbId]) ?: [];
  $inbIsPg  = Xui::normType((string)($inbPanel['type'] ?? '')) === 'pasarguard';
  $curTok   = array_map('strtolower', preg_split('/[\s,،;]+/u', en_num((string)($inbPanel['inbound_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
?>
  <div class="card mt4">
    <div class="card-head">
      <div>
        <div class="card-title">📡 اینباندهای پنل</div>
        <div class="card-sub">کدهای زیر را در فیلد «کد اینباندها» یا در محصول وارد کنید</div>
      </div>
      <a class="btn btn-sm" href="index.php?p=panels">✖ بستن</a>
    </div>
    <?php if (!$list): ?>
      <div class="empty"><div class="ic">📡</div>اینباندی دریافت نشد. اطلاعات ورود و مسیر پنل را بررسی کنید.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th style="width:34px"></th><th>کد</th><th>نام (Remark)</th><th>پروتکل</th><th>پورت</th><th>تعداد کاربر</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($list as $ib):
            $clients = [];
            $st = jdec((string)($ib['settings'] ?? '{}'));
            if (is_array($st) && !empty($st['clients'])) $clients = $st['clients'];
        ?>
          <tr>
            <td><input type="checkbox" class="inbPick" value="<?= (int)($ib['id'] ?? 0) ?>" <?= (in_array((string)(int)($ib['id'] ?? 0), $curTok, true) || in_array(strtolower(trim((string)($ib['remark'] ?? ''))), $curTok, true)) ? 'checked' : '' ?>></td>
            <td class="mono"><b><?= fa_num((string)($ib['id'] ?? '-')) ?></b></td>
            <td><?= h((string)($ib['remark'] ?? '-')) ?></td>
            <td class="mono"><?= h((string)($ib['protocol'] ?? '-')) ?></td>
            <td class="mono"><?= fa_num((string)($ib['port'] ?? '-')) ?></td>
            <td><?= fa_num(count($clients)) ?></td>
            <td><?= !empty($ib['enable']) ? '<span class="badge b-green">فعال</span>' : '<span class="badge b-gray">غیرفعال</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($canWrite): ?>
      <form method="post" class="mt3" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="setinb">
        <input type="hidden" name="id" value="<?= (int)$inbId ?>">
        <input type="hidden" name="ids" id="inbPickIds" value="">
        <button class="btn btn-primary" type="submit" onclick="document.getElementById('inbPickIds').value=[].map.call(document.querySelectorAll('.inbPick:checked'),function(c){return c.value}).join(',');">✅ ثبت موارد تیک‌خورده به‌عنوان «<?= $inbIsPg ? 'گروه‌ها' : 'کد اینباندها' ?>» این پنل</button>
        <span class="hint">فعلی: <code class="ltr"><?= h((string)($inbPanel['inbound_ids'] ?? '')) !== '' ? h((string)$inbPanel['inbound_ids']) : '—' ?></code></span>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canWrite): ?>
<!-- ==================== مودال افزودن / ویرایش پنل ==================== -->
<div class="modal wide" id="mPanel" <?= $e ? 'data-modal-auto="mPanel"' : '' ?>>
  <div class="m-back"></div>
  <div class="m-box">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)$editId ?>">

      <div class="m-head">
        <span style="font-size:18px"><?= $e ? '✏️' : '➕' ?></span>
        <h3><?= $e ? 'ویرایش پنل: ' . h((string)$e['name']) : 'افزودن پنل جدید' ?></h3>
        <?php if ($e): ?>
          <a class="x" href="index.php?p=panels" style="display:grid;place-items:center;text-decoration:none">✕</a>
        <?php else: ?>
          <button type="button" class="x" data-modal-close>✕</button>
        <?php endif; ?>
      </div>

      <div class="m-body">
        <div class="pnm-steps" id="pnmSteps">
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="conn"  data-st="conn"><span class="n">۱</span><span class="t">اتصال به سرور</span></button>
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="sub"   data-st="sub"><span class="n">۲</span><span class="t">لینک و اینباند</span></button>
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="acct"  data-st="acct"><span class="n">۳</span><span class="t">نام کاربری و تمدید</span></button>
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="test"  data-st="test"><span class="n">۴</span><span class="t">اکانت تست</span></button>
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="limit" data-st="limit"><span class="n">۵</span><span class="t">محدودیت</span></button>
          <button type="button" class="pnm-st" data-tab-group="pnl" data-tab="rev"   data-st="rev"><span class="n">۶</span><span class="t">بازبینی</span></button>
        </div>

        <!-- تب اتصال -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="conn">

          <div class="pnm-quick">
            <h4>⚡ پرکردن خودکار با آدرس پنل</h4>
            <p>آدرس کامل پنل را از نوار مرورگر کپی و اینجا بچسبانید تا
              پروتکل، دامنه، پورت و مسیر وب خودکار جدا و پر شوند.</p>
            <div class="qr">
              <input class="mono ltr" type="text" id="pnmPaste" autocomplete="off"
                     placeholder="https://panel.example.com:54321/mypath/">
              <button type="button" class="btn btn-primary" id="pnmPasteGo">تجزیه</button>
            </div>
          </div>

          <div class="fieldset">
            <div class="lg"><span class="n">🏷</span> شناسهٔ پنل</div>
            <div class="fs-hint">این نام فقط در پنل مدیریت و پیام‌های ربات دیده می‌شود.</div>
            <div class="form-grid g2">
              <div class="field">
                <label>نام نمایشی پنل <span style="color:var(--red)">*</span></label>
                <input type="text" name="name" value="<?= h((string)$v('name')) ?>" placeholder="مثلاً سرور آلمان ۱" required>
              </div>
              <div class="field">
                <label>نوع پنل</label>
                <select name="type" id="pnmType">
                  <?php foreach (Xui::TYPES as $tKey => $tLabel): ?>
                    <option value="<?= h((string)$tKey) ?>"
                      data-single="<?= Xui::isSingleType((string)$tKey) ? '1' : '0' ?>"
                      data-note="<?= h(Xui::typeNote((string)$tKey)) ?>"
                      <?= Xui::normType((string)$v('type', 'vpn-ui')) === (string)$tKey ? 'selected' : '' ?>><?= h((string)$tLabel) ?></option>
                  <?php endforeach; ?>
                  <optgroup label="🔒 به‌زودی – Coming Soon">
                    <?php foreach (Xui::SOON as $tKey => $tLabel): ?>
                      <option value="<?= h((string)$tKey) ?>" disabled><?= h((string)$tLabel) ?> — Coming Soon 🔒</option>
                    <?php endforeach; ?>
                  </optgroup>
                </select>
                <div class="hint" id="pnmTypeNote">نوع پنل را انتخاب کنید تا راهنمای اینباند همان پنل نمایش داده شود.</div>
                <div class="pn-tyh" id="pnmTypeHelp">
                  <div class="r"><b>سنایی جدید</b> — مولتی‌اینباند؛ چند کد اینباند می‌دهید و روی همه کانفیگ ساخته می‌شود.</div>
                  <div class="r"><b>توکن API (اختیاری)</b> — اگر پنل سنایی شما نسل جدید است، از «تنظیمات → امنیت → API Token» پنل یک توکن admin بسازید و در فیلد «توکن API» وارد کنید تا ربات از API جدید کلاینت‌محور استفاده کند؛ بدون توکن همه‌چیز مثل قبل کار می‌کند.</div>
                  <div class="r"><b>سنایی قدیم</b> — تک‌اینباند؛ آدرس و یوزر/پس می‌دهید و فقط کد یک اینباند.</div>
                  <a class="gh" href="https://github.com/MHSanaei/3x-ui" target="_blank" rel="noopener">📦 مخزن رسمی سنایی جدید (MHSanaei/3x-ui)</a>
                </div>
                <div class="pn-tyh" id="pnmPgHelp">
                  <div class="r"><b>ورود</b> — یا یوزر/پسورد ادمین پنل، یا فقط «کلید API»: در پنل پاسارگارد ← <span class="mono">API Keys</span> یک کلید با دسترسی کامل (sudo) بسازید و در فیلد «کلید API» وارد کنید. با کلید، یوزر/پسورد لازم نیست.</div>
                  <div class="r"><b>گروه‌ها</b> — در پاسارگارد کاربر باید عضو حداقل یک گروه (Group) باشد؛ اینباندها روی گروه تعریف می‌شوند. شناسهٔ گروه‌ها را در تب «لینک و اینباند» بنویسید یا خالی بگذارید (= همهٔ گروه‌های فعال). با دکمهٔ 🔍 تست، لیست گروه‌ها و شناسهٔ آن‌ها نمایش داده می‌شود.</div>
                  <div class="r"><b>لینک اشتراک</b> — را خود پنل می‌سازد؛ اگر دامنهٔ ساب جدا دارید آن را در «آدرس پایهٔ ساب» بنویسید.</div>
                  <a class="gh" href="https://docs.pasarguard.org/en/introduction/" target="_blank" rel="noopener">📘 مستندات رسمی پاسارگارد (docs.pasarguard.org)</a>
                </div>
              </div>
            </div>
          </div>

          <div class="fieldset accent">
            <div class="lg"><span class="n">🌐</span> آدرس و پورت پنل</div>
            <div class="fs-hint">دقیقاً همان آدرسی که برای ورود به پنل در مرورگر باز می‌کنید.</div>
            <div class="form-grid g2">
              <div class="field">
                <label>پروتکل</label>
                <select name="scheme">
                  <option value="https" <?= $v('scheme', 'https') === 'https' ? 'selected' : '' ?>>https (دامنه + SSL)</option>
                  <option value="http" <?= $v('scheme') === 'http' ? 'selected' : '' ?>>http (بدون SSL)</option>
                </select>
              </div>
              <div class="field">
                <label>آدرس دامنه یا آیپی <span style="color:var(--red)">*</span></label>
                <input class="mono ltr" type="text" name="host" value="<?= h((string)$v('host')) ?>" placeholder="panel.example.com" required>
              </div>
              <div class="field">
                <label>پورت پنل</label>
                <input class="mono ltr" type="number" name="port" value="<?= h((string)$v('port', 54321)) ?>">
              </div>
              <div class="field">
                <label>مسیر وب (Path)</label>
                <input class="mono ltr" type="text" name="web_path" value="<?= h((string)$v('web_path')) ?>" placeholder="در صورت نداشتن خالی بماند">
                <div class="hint">اگر پنل شما مسیر دارد (مانند <span class="mono">/mypath/</span>) فقط همان را بنویسید.</div>
              </div>
            </div>
            <div class="url-preview">آدرس نهایی ورود: <b id="pnlUrlOut">—</b></div>
          </div>

          <div class="fieldset warn">
            <div class="lg"><span class="n">🔐</span> اطلاعات ورود</div>
            <div class="fs-hint">همان نام کاربری و رمزی که در صفحهٔ ورود پنل وارد می‌کنید.</div>
            <div class="form-grid g2">
              <div class="field">
                <label>نام کاربری پنل <span style="color:var(--red)">*</span></label>
                <input class="mono ltr" type="text" name="username" value="<?= h((string)$v('username')) ?>" required autocomplete="off">
              </div>
              <div class="field">
                <label>رمز عبور پنل <?= $e ? '(خالی = بدون تغییر)' : '<span style="color:var(--red)">*</span>' ?></label>
                <div class="row" style="gap:6px">
                  <input class="grow mono ltr" type="password" name="password" id="pnp" <?= $e ? '' : 'required' ?> autocomplete="new-password">
                  <button type="button" class="icon-btn" data-eye="#pnp">👁</button>
                </div>
                <div class="hint">رمز به صورت رمزنگاری‌شده (AES-256-GCM) در دیتابیس ذخیره می‌شود.</div>
              </div>
              <div class="field" id="pnmTokWrap">
                <label id="pnmTokLab">توکن API — نسل جدید 3x-ui (اختیاری)</label>
                <div class="row" style="gap:6px">
                  <input class="grow mono ltr" type="password" name="api_token" id="pntok" autocomplete="new-password"
                         placeholder="<?= ($e && !empty($e['api_token'])) ? 'توکن ذخیره شده ✓ — خالی = بدون تغییر' : 'اگر پنل شما API Token دارد اینجا وارد کنید' ?>">
                  <button type="button" class="icon-btn" data-eye="#pntok">👁</button>
                </div>
                <div class="hint" id="pnmTokHint">از «تنظیمات → امنیت → API Token» خودِ پنل یک توکن با سطح admin بسازید. با ثبت توکن، ربات به‌جای ورود با یوزر/پسورد از API نسل جدید (کلاینت‌محور) استفاده می‌کند؛ خالی بماند = مثل قبل. توکن هم رمزنگاری‌شده ذخیره می‌شود.</div>
                <?php if ($e && !empty($e['api_token'])): ?>
                  <label class="check" style="margin-top:4px">
                    <input type="checkbox" name="api_token_clear" value="1">
                    <span>حذف توکن ذخیره‌شده (بازگشت به ورود با یوزر/پسورد)</span>
                  </label>
                <?php endif; ?>
              </div>
            </div>
            <div class="field">
              <label class="check" style="margin-top:4px">
                <input type="checkbox" name="ssl_verify" value="1" <?= (int)$v('ssl_verify', 0) ? 'checked' : '' ?>>
                <span>بررسی گواهی SSL این پنل</span>
              </label>
              <div class="hint">اگر پنل شما گواهی معتبر (مانند Let’s Encrypt) دارد این گزینه را روشن کنید تا ارتباط در برابر شنود و حملهٔ میانی محافظت شود. برای پنل‌های با گواهی self-signed خاموش بماند.</div>
            </div>
          </div>
        </div>

        <!-- تب لینک و اینباند -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="sub">

          <div class="fieldset accent">
            <div class="lg"><span class="n">🔗</span> لینک اتصال کاربر</div>
            <div class="fs-hint">این مقادیر در لینکی که به خریدار داده می‌شود استفاده می‌شوند.</div>
            <div class="form-grid g2">
              <div class="field">
                <label>لینک ساب‌سکریپشن (Subscription)</label>
                <input class="mono ltr" type="text" name="sub_base" value="<?= h((string)$v('sub_base')) ?>" placeholder="https://sub.example.com:2096/sub/">
                <div class="hint">همان آدرسی که در پنل بخش Subscription تنظیم کرده‌اید. خالی = فقط کانفیگ تکی.</div>
                <div class="hint">مرزبان: خالی بگذارید؛ لینک ساب و کانفیگ‌ها مستقیماً از خود پنل گرفته می‌شود.</div>
              </div>
              <div class="field">
                <label>آدرس اتصال کانفیگ (اختیاری)</label>
                <input class="mono ltr" type="text" name="node_host" value="<?= h((string)$v('node_host')) ?>" placeholder="خالی = همان آدرس پنل">
                <div class="hint">اگر کاربران باید به دامنه/آیپی دیگری (CDN یا تانل) وصل شوند.</div>
              </div>
            </div>
          </div>

          <div class="fieldset">
            <div class="lg"><span class="n">🧩</span> اینباند و نام کانفیگ</div>
            <div class="fs-hint">کانفیگ خریداران روی همین اینباندها ساخته می‌شود. در مرزبان این بخش اختیاری است.</div>
            <div class="form-grid g2">
              <div class="field">
                <label id="pnmIbLab">کد اینباندها (Inbound IDs)</label>
                <input class="mono ltr" type="text" name="inbound_ids" id="pnmIb" value="<?= h((string)$v('inbound_ids')) ?>" placeholder="1,2,5">
                <div class="hint" id="pnmIbH1">با کاما جدا کنید. خالی = همهٔ اینباندهای پنل مجاز هستند.</div>
                <div class="hint" id="pnmIbH2">مرزبان: خالی بگذارید تا همهٔ اینباندها خودکار شناسایی و اضافه شوند؛ یا نام تگ اینباندها را بنویسید (مثلاً VLESS_TCP).</div>
                <div class="hint" id="pnmIbH3">پاسارگارد: نام گروه‌ها (مثلاً premium,vip) یا شناسهٔ عددی آن‌ها (مثلاً 1,2) را با کاما جدا بنویسید. خالی = کاربر در همهٔ گروه‌های فعال پنل ساخته می‌شود. فهرست گروه‌ها با «تست اتصال» نمایش داده می‌شود. اگر ادمین پنل sudo نیست باید مجوز groups.read داشته باشد؛ در غیر این صورت فقط شناسهٔ عددی کار می‌کند (ربات شناسه‌ها را از کاربران موجودِ همان ادمین استنتاج می‌کند).</div>
                <div class="pn-ibw" id="pnmIbWarn">⚠️ سنایی قدیم تک‌اینباند است؛ اگر چند عدد بنویسید تنها عدد اول ذخیره می‌شود.</div>
              </div>
              <div class="field">
                <label>پیشوند نام کانفیگ (Remark)</label>
                <input class="mono ltr" type="text" name="remark_prefix" value="<?= h((string)$v('remark_prefix')) ?>" placeholder="shop">
              </div>
            </div>
            <?php if ($e && can('panels.health')): ?>
              <div class="alert a-info mt3">برای دیدن لیست اینباندها، ابتدا پنل را ذخیره کنید و سپس از لیست، دکمه‌ی 🔍 تست را بزنید.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- تب نام کاربری -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="acct">
          <div class="form-grid g2">
            <div class="field">
              <label>نحوه انتخاب نام کاربری</label>
              <select name="username_mode">
                <?php foreach (Svc::USERNAME_MODES as $k => $lbl): ?>
                  <option value="<?= h($k) ?>" <?= $v('username_mode', 'random') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">در حالت «انتخاب توسط کاربر»، ربات قبل از ساخت از کاربر نام دلخواه می‌پرسد.</div>
            </div>
            <div class="field">
              <label>نحوه تمدید سرویس</label>
              <select name="renew_mode" id="renewMode">
                <?php foreach (Svc::RENEW_MODES as $k => $lbl): ?>
                  <option value="<?= h($k) ?>"
                          data-desc="<?= h((string)(Svc::RENEW_MODE_HINTS[$k] ?? '')) ?>"
                          <?= $v('renew_mode', 'reset_extend') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint" id="renewHint">—</div>
            </div>
          </div>
        </div>

        <!-- تب اکانت تست -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="test">
          <label class="check">
            <input type="checkbox" name="test_enabled" value="1" data-toggle-target="#testbox" <?= (int)$v('test_enabled', 1) ? 'checked' : '' ?>>
            <span>ایجاد اکانت تست از این پنل فعال باشد</span>
          </label>
          <div id="testbox" class="mt3">
            <div class="form-grid">
              <div class="field">
                <label>حجم اکانت تست (گیگ)</label>
                <input class="mono" type="text" name="test_volume_gb" value="<?= h((string)$v('test_volume_gb', '1')) ?>">
                <div class="hint">۰ = نامحدود</div>
              </div>
              <div class="field">
                <label>حجم اکانت تست (مگابایت)</label>
                <input class="mono" type="number" min="0" name="test_volume_mb" value="<?= (int)$v('test_volume_mb', 0) ?>">
                <div class="hint">اگر بزرگ‌تر از ۰ باشد جای «گیگ» را می‌گیرد؛ مثلاً ۵۰۰ = نیم گیگ</div>
              </div>
              <div class="field">
                <label>مدت تست – روز</label>
                <input class="mono" type="number" name="test_days" value="<?= h((string)$v('test_days', 1)) ?>">
              </div>
              <div class="field">
                <label>مدت تست – ساعت</label>
                <input class="mono" type="number" name="test_hours" value="<?= h((string)$v('test_hours', 0)) ?>">
                <div class="hint">مدت زمان = روز + ساعت</div>
              </div>
              <div class="field">
                <label>محدودیت ساخت تست برای هر کاربر</label>
                <input class="mono" type="number" name="test_limit" value="<?= h((string)$v('test_limit', 1)) ?>">
                <div class="hint">تعداد دفعات مجاز در کل عمر حساب</div>
              </div>
              <div class="field">
                <label>اینباندهای مخصوص تست</label>
                <input class="mono" type="text" name="test_inbound_ids" value="<?= h((string)$v('test_inbound_ids', '')) ?>" placeholder="1,2,5">
                <div class="hint">خالی = همان اینباندهای بالا ؛ چند کد را با «،» جدا کنید (دقیقاً مثل محصولات)</div>
              </div>
              <div class="field">
                <label>حالت ساخت اکانت تست</label>
                <label class="check" style="margin-top:8px">
                  <input type="checkbox" name="test_single_inbound" value="1" <?= (int)$v('test_single_inbound', 0) ? 'checked' : '' ?>>
                  <span>فقط روی اولین اینباند ساخته شود</span>
                </label>
                <div class="hint">پیش‌فرض: روی همهٔ اینباندهای انتخاب‌شده ساخته می‌شود</div>
              </div>
            </div>
          </div>
        </div>

        <!-- تب محدودیت -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="limit">
          <div class="form-grid">
            <div class="field">
              <label>محدودیت کل ساخت کاربر روی این پنل</label>
              <input class="mono" type="number" name="user_limit" value="<?= h((string)$v('user_limit', -1)) ?>">
              <div class="hint">۱− = نامحدود ؛ عدد دلخواه = تا همان تعداد اکانت</div>
            </div>
            <div class="field">
              <label>ترتیب نمایش</label>
              <input class="mono" type="number" name="sort" value="<?= h((string)$v('sort', 0)) ?>">
            </div>
            <div class="field">
              <label>وضعیت</label>
              <label class="check" style="margin-top:8px">
                <input type="checkbox" name="active" value="1" <?= (int)$v('active', 1) ? 'checked' : '' ?>>
                <span>پنل فعال باشد</span>
              </label>
            </div>
          </div>
          <?php if ($e): ?>
            <div class="kv mt3"><span class="k">تاریخ ساخت</span><span><?= h(to_jalali((string)$e['created_at'])) ?></span></div>
          <?php endif; ?>
        </div>

        <!-- تب بازبینی -->
        <div class="tab-panel" data-tab-panel-group="pnl" data-tab-panel="rev">

          <div class="pnm-sum">
            <div class="hd">🧾 خلاصهٔ تنظیمات این پ����ل</div>
            <div class="rw"><span class="k">نام نمایشی</span><span class="v" data-sum="name">—</span></div>
            <div class="rw"><span class="k">نوع پنل</span><span class="v" data-sum="type">—</span></div>
            <div class="rw"><span class="k">آدرس ورود</span><span class="v mono" data-sum="url">—</span></div>
            <div class="rw"><span class="k">نام کاربری</span><span class="v mono" data-sum="username">—</span></div>
            <div class="rw"><span class="k">بررسی گواهی SSL</span><span class="v" data-sum="ssl">—</span></div>
            <div class="rw"><span class="k">لینک ساب‌سکریپشن</span><span class="v mono" data-sum="sub_base">—</span></div>
            <div class="rw"><span class="k">آدرس اتصال کانفیگ</span><span class="v mono" data-sum="node_host">—</span></div>
            <div class="rw"><span class="k">کد اینباندها</span><span class="v mono" data-sum="inbound_ids">—</span></div>
            <div class="rw"><span class="k">پیشوند نام کانفیگ</span><span class="v mono" data-sum="remark_prefix">—</span></div>
            <div class="rw"><span class="k">نحوهٔ نام کاربری</span><span class="v" data-sum="username_mode">—</span></div>
            <div class="rw"><span class="k">نحوهٔ تمدید</span><span class="v" data-sum="renew_mode">—</span></div>
            <div class="rw"><span class="k">اکانت تست</span><span class="v" data-sum="test">—</span></div>
            <div class="rw"><span class="k">سقف ساخت روی این پنل</span><span class="v" data-sum="user_limit">—</span></div>
            <div class="rw"><span class="k">وضعیت</span><span class="v" data-sum="active">—</span></div>
          </div>

          <div class="pnm-chk" id="pnmChk"></div>

          <div class="pnm-tip">
            <span class="e">💡</span>
            <span>پس از ذخیره، حتماً دکمهٔ <b>🔍 تست اتصال</b> روی کارت پنل را بزنید.
              اگر اتصال موفق باشد، تعداد اینباندهای پنل نمایش داده می‌شود و
              می‌توانید کدهای آن‌ها را در فیلد «کد اینباندها» یا در محصولات وارد کنید.</span>
          </div>
        </div>
      </div>

      <div class="m-foot">
        <?php if ($e): ?>
          <a class="btn btn-ghost" href="index.php?p=panels">انصراف</a>
        <?php else: ?>
          <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
        <?php endif; ?>
        <button class="btn btn-primary">💾 <?= $e ? 'ذخیره‌ی تغییرات' : 'افزودن پنل' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
/* ===== Panels Studio v3 — پرکردن سریع، پیش‌نمایش زنده و بازبینی ===== */
(function () {
  var box = document.getElementById('mPanel');
  if (!box) return;

  var f   = function (n) { return box.querySelector('[name="' + n + '"]'); };
  var val = function (n) { var el = f(n); return el ? String(el.value || '').trim() : ''; };
  var chk = function (n) { var el = f(n); return !!(el && el.checked); };
  var sel = function (n) {
    var el = f(n);
    if (!el || el.selectedIndex < 0) return '';
    var op = el.options[el.selectedIndex];
    return op ? op.text : '';
  };

  var out    = box.querySelector('#pnlUrlOut');
  var idEl   = box.querySelector('input[name="id"]');
  var isEdit = !!(idEl && Number(idEl.value) > 0);

  function buildUrl() {
    var h = val('host').replace(/^https?:\/\//i, '').replace(/\/+$/, '');
    if (!h) return '';
    var s = val('scheme') || 'https';
    var p = val('port');
    var w = val('web_path').replace(/^\/+/, '').replace(/\/+$/, '');
    var u = s + '://' + h;
    if (p) u += ':' + p;
    if (w) u += '/' + w;
    return u + '/';
  }

  /* ---------- پرکردن خودکار از روی آدرس ---------- */
  function quickFill() {
    var inp = box.querySelector('#pnmPaste');
    if (!inp) return;

    var raw = String(inp.value || '').trim();
    if (!raw) return;
    if (!/^https?:\/\//i.test(raw)) raw = 'https://' + raw;

    var u = null;
    try { u = new URL(raw); } catch (e) { u = null; }
    if (!u || !u.hostname) { inp.classList.add('err'); return; }
    inp.classList.remove('err');

    var sc = f('scheme'), ho = f('host'), po = f('port'), wp = f('web_path');
    if (sc) sc.value = (u.protocol === 'http:') ? 'http' : 'https';
    if (ho) ho.value = u.hostname;
    if (po && u.port) po.value = u.port;
    if (wp) wp.value = u.pathname.replace(/^\/+/, '').replace(/\/+$/, '');

    inp.value = '';
    paint();
  }

  var pgo = box.querySelector('#pnmPasteGo');
  if (pgo) pgo.addEventListener('click', quickFill);

  var pin = box.querySelector('#pnmPaste');
  if (pin) {
    pin.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); quickFill(); }
    });
    pin.addEventListener('paste', function () { setTimeout(quickFill, 40); });
  }

  /* ---------- خلاصهٔ بازبینی ---------- */
  /* ---------- نوع پنل: تک‌اینباند / مولتی‌اینباند ---------- */
  var pnmReady = false;
  var tySel = box.querySelector('#pnmType');
  var ibInp = box.querySelector('#pnmIb');
  /* پاسارگارد: متن پیش‌فرض فیلد توکن و وضعیت required یوزر/پسورد را نگه می‌داریم تا با تغییر نوع برگردانده شوند */
  var tokLab  = box.querySelector('#pnmTokLab');
  var tokHint = box.querySelector('#pnmTokHint');
  var tokInp  = box.querySelector('#pntok');
  var unInp   = box.querySelector('input[name="username"]');
  var pwInp   = box.querySelector('#pnp');
  var tokLabDef  = tokLab ? tokLab.textContent : '';
  var tokHintDef = tokHint ? tokHint.textContent : '';
  var tokPhDef   = tokInp ? tokInp.getAttribute('placeholder') : '';
  var unReqDef   = !!(unInp && unInp.hasAttribute('required'));
  var pwReqDef   = !!(pwInp && pwInp.hasAttribute('required'));

  function pnmSingle() {
    if (!tySel) return false;
    var o = tySel.options[tySel.selectedIndex];
    return !!(o && o.getAttribute('data-single') === '1');
  }

  function pnmOneId(v) {
    var m = String(v || '').match(/[0-9]+/);
    return m ? m[0] : '';
  }

  function pnmSyncType() {
    if (!tySel) return;
    var o      = tySel.options[tySel.selectedIndex];
    var single = pnmSingle();
    var note   = (o && o.getAttribute('data-note')) || '';

    var nEl = box.querySelector('#pnmTypeNote');
    if (nEl) nEl.textContent = note || '—';

    var isPg = (tySel.value === 'pasarguard');

    var lab = box.querySelector('#pnmIbLab');
    if (lab) lab.innerHTML = single
      ? 'کد اینباند (فقط یک عدد) <span style="color:var(--red)">*</span>'
      : (isPg ? 'گروه‌ها (نام یا شناسه) — پاسارگارد' : 'کد اینباندها (Inbound IDs)');

    var h1 = box.querySelector('#pnmIbH1');
    if (h1) h1.textContent = single
      ? 'در سنایی قدیم همهٔ کانفیگ‌ها روی همین یک اینباند ساخته می‌شوند. مانند: 1'
      : 'با کاما جدا کنید. خالی = همهٔ اینباندهای پنل مجاز هستند.';
    if (h1) h1.style.display = isPg ? 'none' : '';

    var h2 = box.querySelector('#pnmIbH2');
    if (h2) h2.style.display = (tySel.value === 'marzban') ? '' : 'none';

    var h3 = box.querySelector('#pnmIbH3');
    if (h3) h3.style.display = isPg ? '' : 'none';

    var wn = box.querySelector('#pnmIbWarn');
    if (wn) wn.style.display = single ? '' : 'none';

    if (ibInp) {
      ibInp.placeholder = single ? '1' : '1,2,5';
      if (single) ibInp.value = pnmOneId(ibInp.value);
    }

    var hp = box.querySelector('#pnmTypeHelp');
    if (hp) hp.classList.toggle('on', String(tySel.value).indexOf('sanaei') === 0);
    var pgh = box.querySelector('#pnmPgHelp');
    if (pgh) pgh.classList.toggle('on', isPg);

    /* فیلد توکن API: برای «سنایی جدید» (توکن اختیاری) و «پاسارگارد» (کلید API به‌جای یوزر/پسورد) */
    var tw = box.querySelector('#pnmTokWrap');
    if (tw) tw.style.display = (tySel.value === 'sanaei' || isPg) ? '' : 'none';
    if (tokLab)  tokLab.textContent  = isPg ? 'کلید API پاسارگارد (pg_key_…) — به‌جای یوزر/پسورد' : tokLabDef;
    if (tokHint) tokHint.textContent = isPg
      ? 'در پنل پاسارگارد ← API Keys یک کلید با دسترسی کامل (sudo) بسازید و اینجا وارد کنید. با کلید، یوزر/پسورد ادمین لازم نیست و می‌توانند خالی بمانند؛ بدون کلید، یوزر/پسورد الزامی است. کلید رمزنگاری‌شده ذخیره می‌شود.'
      : tokHintDef;
    if (tokInp && (!tokInp.getAttribute('placeholder') || tokInp.getAttribute('placeholder').indexOf('✓') < 0)) {
      tokInp.setAttribute('placeholder', isPg ? 'pg_key_…' : tokPhDef);
    }
    /* با پاسارگارد یوزر/پسورد اجباری نیست (سرور بررسی می‌کند که یا کلید یا یوزر/پسورد داده شده باشد) */
    if (unInp) { if (isPg) unInp.removeAttribute('required'); else if (unReqDef) unInp.setAttribute('required', 'required'); }
    if (pwInp) { if (isPg) pwInp.removeAttribute('required'); else if (pwReqDef) pwInp.setAttribute('required', 'required'); }

    if (pnmReady && typeof paintSum === 'function') paintSum();
  }

  if (tySel) tySel.addEventListener('change', pnmSyncType);
  if (ibInp) ibInp.addEventListener('input', function () {
    if (pnmSingle()) this.value = pnmOneId(this.value);
  });
  pnmSyncType();
  pnmReady = true;

  function setSum(k, text, muted) {
    var el = box.querySelector('[data-sum="' + k + '"]');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('em', !!muted);
  }

  function paintSum() {
    var nm = val('name');
    setSum('name', nm || 'وارد نشده', !nm);
    setSum('type', sel('type') || '—', false);

    var u = buildUrl();
    setSum('url', u || 'وارد نشده', !u);

    var un = val('username');
    setSum('username', un || 'وارد نشده', !un);

    var ssl = chk('ssl_verify');
    setSum('ssl', ssl ? 'روشن' : 'خاموش', !ssl);

    var sb = val('sub_base');
    setSum('sub_base', sb || 'تنظیم نشده — فقط کانفیگ تکی', !sb);

    var nh = val('node_host');
    setSum('node_host', nh || 'همان آدرس پنل', !nh);

    var ib = val('inbound_ids');
    setSum('inbound_ids', ib || (pnmSingle() ? 'وارد نشده — الزامی' : 'همهٔ اینباندها'), !ib);

    var rp = val('remark_prefix');
    setSum('remark_prefix', rp || 'بدون پیشوند', !rp);

    setSum('username_mode', sel('username_mode') || '—', false);
    setSum('renew_mode', sel('renew_mode') || '—', false);

    if (chk('test_enabled')) {
      var g = val('test_volume_gb') || '0';
      var gmb = parseInt(val('test_volume_mb') || '0', 10) || 0; /* fixed79 */
      var d = val('test_days') || '0';
      var hr = val('test_hours') || '0';
      setSum('test', 'فعال — ' + (gmb > 0 ? gmb + ' مگابایت ، ' : g + ' گیگ ، ') + d + ' روز و ' + hr + ' ساعت', false);
    } else {
      setSum('test', 'غیرفعال', true);
    }

    var ul     = val('user_limit');
    var ulTxt  = 'نامحدود';
    var ulNum  = Number(ul);
    if (ul !== '' && !isNaN(ulNum) && ulNum >= 0) ulTxt = ul + ' اکانت';
    setSum('user_limit', ulTxt, false);

    var ac = chk('active');
    setSum('active', ac ? 'فعال' : 'غیرفعال', !ac);
  }

  /* ---------- چک‌لیست ورودی‌های الزامی ---------- */
  var CHECKS = [
    { k: 'name',     t: 'نام نمایشی پنل وارد شده',     tab: 'conn' },
    { k: 'host',     t: 'آدرس دامنه یا آیپی وارد شده', tab: 'conn' },
    { k: 'username', t: 'نام کاربری پنل وارد شده',     tab: 'conn' },
    { k: 'password', t: 'رمز عبور پنل وارد شده',       tab: 'conn' }
  ];

  function okOf(c) {
    if (c.k === 'password' && isEdit) return true;
    return val(c.k) !== '';
  }

  function paintChk() {
    var wrap = box.querySelector('#pnmChk');
    if (!wrap) return;

    var html = '';
    for (var i = 0; i < CHECKS.length; i++) {
      var ok  = okOf(CHECKS[i]);
      var cls = ok ? 'ok' : 'no';
      var ico = ok ? '✓' : '!';
      html += '<div class="pnm-ck ' + cls + '"><span class="i">' + ico + '</span>' +
              '<span>' + CHECKS[i].t + '</span></div>';
    }
    if (isEdit) {
      html += '<div class="pnm-ck ok"><span class="i">✓</span>' +
              '<span>رمز فعلی حفظ می‌شود مگر آن‌که مقدار جدیدی بنویسید</span></div>';
    }
    wrap.innerHTML = html;
  }

  /* ---------- نوار گام‌ها و نشانگر تب ناقص ---------- */
  function paintSteps() {
    var need = {}, has = {};
    for (var i = 0; i < CHECKS.length; i++) {
      has[CHECKS[i].tab] = true;
      if (!okOf(CHECKS[i])) need[CHECKS[i].tab] = true;
    }

    var sts = box.querySelectorAll('.pnm-st');
    for (var j = 0; j < sts.length; j++) {
      var st  = sts[j];
      var key = st.getAttribute('data-st');
      var bad = !!need[key];

      /* تیک سبز فقط برای گامی که ورودی اجباری دارد و کامل شده است */
      st.classList.toggle('ok', !!has[key] && !bad && !st.classList.contains('on'));

      var dot = st.querySelector('.dot');
      if (bad && !dot) {
        var d = document.createElement('span');
        d.className = 'dot';
        st.appendChild(d);
      } else if (!bad && dot && dot.parentNode) {
        dot.parentNode.removeChild(dot);
      }
    }
  }

  /* ---------- توضیح روش تمدید انتخاب‌شده ---------- */
  function paintRenew() {
    var s  = f('renew_mode');
    var el = box.querySelector('#renewHint');
    if (!s || !el) return;
    var op = s.options[s.selectedIndex];
    el.textContent = (op && op.getAttribute('data-desc')) || '—';
  }

  function paint() {
    if (out) out.textContent = buildUrl() || '—';
    paintSum();
    paintChk();
    paintRenew();
    paintSteps();
  }

  box.addEventListener('input', paint);
  box.addEventListener('change', paint);

  /* جابه‌جایی بین بخش‌ها از روی نوار گام‌ها */
  box.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('.pnm-st')) setTimeout(paintSteps, 20);
  });

  paint();
  setTimeout(paintSteps, 80);
})();
</script>
