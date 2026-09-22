# -*- coding: utf-8 -*-
# fixed122 - mini-app: "my devices" (HWID) + links/Happ screens inside the service sheet
import io, os, re, sys, json, tempfile, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed122').strip() or 'fixed122'

CACHE = {}
NEW = {}
ERRORS = []
WARN = []

EM = {
    'DEV': chr(0x1F4F1),
    'LNK': chr(0x1F517),
    'TRA': chr(0x1F5D1),
    'BRM': chr(0x1F9F9),
    'ROK': chr(0x1F680),
}


def em(s):
    for k in EM:
        s = s.replace('{' + k + '}', EM[k])
    return s


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def dump_find(tag, path, pattern, before=2, after=25, limit=1):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('find %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    hits = [i for i, l in enumerate(lines, 1) if rx.search(l)]
    print('---- find %s : %s -> %d hits %s ----' % (tag, pattern, len(hits), hits[:30]))
    for h in hits[:limit]:
        s = max(1, h - before)
        e = min(len(lines), h + after)
        print('  -- around line %d --' % h)
        i = s
        while i <= e:
            print('%5d %s' % (i, lines[i - 1]))
            i += 1
    print('---- end find %s ----' % tag)


def grep_lines(tag, path, pattern, limit=40):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('grep %s: cannot read %s (%s)' % (tag, path, e))
        return
    rx = re.compile(pattern)
    out = [(i, l) for i, l in enumerate(lines, 1) if rx.search(l)]
    print('---- grep %s : %s -> %d hits ----' % (tag, pattern, len(out)))
    for i, l in out[:limit]:
        print('%5d %s' % (i, l[:220]))
    print('---- end grep %s ----' % tag)


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        ERRORS.append('%s: cannot read (%s)' % (path, e))
        return
    if marker and marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker or pattern[:40], n, expect)
        if optional:
            WARN.append(msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = rx.sub(fn, src, count=expect)
    NEW[path] = True
    print('patched %s (%s)' % (path, marker or 'ok'))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    lint = 0
    for path in sorted(NEW):
        data = CACHE[path]
        if path.endswith('.php'):
            tmp = os.path.join(tempfile.gettempdir(), 'syntax-check.php')
            with io.open(tmp, 'w', encoding='utf-8') as fh:
                fh.write(data)
            r = subprocess.run(['php', '-l', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print('php lint FAILED for ' + path)
                print(r.stdout + r.stderr)
                sys.exit(1)
            lint += 1
        with io.open(os.path.join(ROOT, path), 'w', encoding='utf-8') as fh:
            fh.write(data)
        print('wrote ' + path)
    print('php lint: %s' % ('on' if lint else 'n/a'))
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


# ------------------------------------------------ recon #3: service payload in api.php
dump_find('api_service_payload', 'miniapp/api.php', r"'can_renew'", 34, 14, 1)
grep_lines('api_can_flags', 'miniapp/api.php', r"'can_(renew|del|sync|tut)'", 20)

# ------------------------------------------------ 1) buttons inside the service sheet
BTNS = em(
    "        (s.can_devs ? '<button type=\"button\" class=\"btn gh b3d\" data-devs=\"' + s.id + '\">{DEV} \u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627\u06cc \u0645\u0646</button>' : '') +\n"
    "        (s.can_links ? '<button type=\"button\" class=\"btn gh b3d\" data-links=\"' + s.id + '\">{LNK} \u0644\u06cc\u0646\u06a9\u200c\u0647\u0627 \u0648 Happ</button>' : '') +\n"
)

rep_rx(
    'miniapp/index.php',
    r"(\(s\.can_tut  !== false \? '<button type=\"button\" class=\"btn gh b3d\" data-go=\"tut\">[^<]*</button>' : ''\) \+\n)",
    lambda m: m.group(1) + BTNS,
    marker='data-devs=\\"',
)

# ------------------------------------------------ 2) new screens
JS = em(r'''  /* ============ دستگاه‌ها و لینک‌های تکمیلی (0.0.2 #ma-dev-ui) ============ */
  function maLoading(title) {
    sheet(title, '<div class="card tight g3"><div class="row"><div class="ri">⏳</div>' +
      '<div class="rt"><b>در حال دریافت اطلاعات…</b><span>چند لحظه صبر کنید</span></div></div></div>');
  }

  function devicesSheet(id) {
    maLoading('{DEV} دستگاه‌های من');
    api('svc_devices', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message || 'دریافت فهرست دستگاه‌ها ناموفق بود.', 'err'); closeSheet(); return; }
      paintDevices(id, r);
    });
  }

  function paintDevices(id, r) {
    if (!r.supported) {
      sheet('{DEV} دستگاه‌های من',
        '<div class="alert e">سرور این سرویس از مدیریت دستگاه‌ها پشتیبانی نمی‌کند.</div>');
      return;
    }
    var items = r.items || [];
    var lim   = +(r.limit || 0);
    var h = '<div class="card tight g3">' +
      row('{DEV}', 'دستگاه‌های ثبت‌شده', '', fa(items.length) + (lim > 0 ? ' / ' + fa(lim) : '')) +
      (lim > 0 ? row('⚖', 'ظرفیت آزاد', '', fa(Math.max(0, lim - items.length))) : '') +
      '</div>';
    if (!items.length) {
      h += '<div class="hint3d">هنوز دستگاهی روی این سرویس ثبت نشده است؛ با اولین اتصال ثبت می‌شود.</div>';
    } else {
      h += '<div class="sec-t"><span>{DEV} فهرست دستگاه‌ها</span></div><div class="card tight g3">';
      items.forEach(function (d) {
        h += '<div class="row"><div class="ri">{DEV}</div><div class="rt"><b>' + esc(d.title || ('#' + d.id)) + '</b>' +
          (d.seen_txt ? '<span>' + esc(d.seen_txt) + '</span>' : '') + '</div>' +
          '<div class="rv"><button type="button" class="btn gh b3d" data-devdel="' + id + ':' + d.id + '">{TRA}</button></div></div>';
      });
      h += '</div><button type="button" class="btn gh b3d" style="width:100%;margin-top:10px" data-devclr="' + id + '">{BRM} حذف همهٔ دستگاه‌ها</button>';
    }
    h += '<div style="font-size:11px;color:var(--mut);margin-top:10px">با حذف هر دستگاه، یک ظرفیت آزاد می‌شود و می‌توانید روی دستگاه تازه وصل شوید.</div>';
    sheet('{DEV} دستگاه‌های من', h);
  }

  function doDevDel(id, dev, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_device_del', { id: id, device: dev }).then(function (r) {
      if (!r.ok) {
        toast(r.message || 'حذف دستگاه انجام نشد.', 'err');
        if (btn) { btn.disabled = false; btn.textContent = '{TRA}'; }
        return;
      }
      toast(r.message || 'دستگاه حذف شد.', 'ok');
      paintDevices(id, { supported: true, items: r.items || [], limit: r.limit || 0 });
    });
  }

  function doDevClear(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    api('svc_devices_clear', { id: id }).then(function (r) {
      if (!r.ok) {
        toast(r.message || 'پاک‌سازی دستگاه‌ها انجام نشد.', 'err');
        if (btn) { btn.disabled = false; btn.textContent = '{BRM} حذف همهٔ دستگاه‌ها'; }
        return;
      }
      toast(r.message || 'دستگاه‌ها پاک شدند.', 'ok');
      paintDevices(id, { supported: true, items: r.items || [], limit: r.limit || 0 });
    });
  }

  function linksSheet(id) {
    maLoading('{LNK} لینک‌ها و Happ');
    api('svc_links', { id: id }).then(function (r) {
      if (!r.ok) { toast(r.message || 'دریافت لینک‌ها ناموفق بود.', 'err'); closeSheet(); return; }
      if (!r.supported) {
        sheet('{LNK} لینک‌ها و Happ',
          '<div class="alert e">برای این سرویس لینک تکمیلی در دسترس نیست.</div>');
        return;
      }
      var h = '';
      if (r.happ) {
        h += '<div class="sec-t"><span>{ROK} ایمپورت یک‌ضربه‌ای در Happ</span></div>' +
          '<div class="hint3d">این لینک را کپی کنید و در اپلیکیشن Happ باز کنید تا اشتراک خودکار اضافه شود.</div>' +
          copyBox(String(r.happ));
      }
      var items = r.items || [];
      if (items.length) {
        h += '<div class="sec-t"><span>{LNK} لینک‌های دیگر</span></div>';
        items.forEach(function (it) {
          var u = String(it.link || it.url || '');
          if (!u) return;
          h += '<div class="cfg-h">' + esc(it.title || it.name || 'لینک') + '</div>' + copyBox(u);
        });
      }
      if (!h) h = '<div class="alert e">لینکی برای این سرویس ثبت نشده است.</div>';
      sheet('{LNK} لینک‌ها و Happ', h);
    });
  }

''')

rep_rx(
    'miniapp/index.php',
    r"^  /\* =+ \u062a\u0645\u062f\u06cc\u062f \u0648 \u062d\u0630\u0641 \u0633\u0631\u0648\u06cc\u0633 =+ \*/\n",
    lambda m: JS + m.group(0),
    marker='#ma-dev-ui',
)

# ------------------------------------------------ 3) click delegates
DELEG = (
    "    if ((el = t.closest('[data-devs]'))) { haptic(); devicesSheet(+el.getAttribute('data-devs')); return; }\n"
    "    if ((el = t.closest('[data-links]'))) { haptic(); linksSheet(+el.getAttribute('data-links')); return; }\n"
    "    if ((el = t.closest('[data-devdel]'))) { haptic(); var dv = (el.getAttribute('data-devdel') || '').split(':'); doDevDel(+dv[0], +dv[1], el); return; }\n"
    "    if ((el = t.closest('[data-devclr]'))) { haptic(); doDevClear(+el.getAttribute('data-devclr'), el); return; }\n"
)

rep_rx(
    'miniapp/index.php',
    r"^    if \(\(el = t\.closest\('\[data-renew\]'\)\)\) \{ haptic\(\); renewSheet\(\+el\.getAttribute\('data-renew'\)\); return; \}\n",
    lambda m: m.group(0) + DELEG,
    marker='[data-devclr]',
)

write_all()

# ------------------------------------------------ sanity
SANITY = [
    ('miniapp/index.php', 'data-devs=\\"'),
    ('miniapp/index.php', 'data-links=\\"'),
    ('miniapp/index.php', '#ma-dev-ui'),
    ('miniapp/index.php', "function devicesSheet(id)"),
    ('miniapp/index.php', "function paintDevices(id, r)"),
    ('miniapp/index.php', "function linksSheet(id)"),
    ('miniapp/index.php', "api('svc_devices'"),
    ('miniapp/index.php', "api('svc_device_del'"),
    ('miniapp/index.php', "api('svc_devices_clear'"),
    ('miniapp/index.php', "api('svc_links'"),
    ('miniapp/index.php', "t.closest('[data-devclr]')"),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

# ------------------------------------------------ version + changelog
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
ENTRY = '\u0645\u06cc\u0646\u06cc\u200c\u0627\u067e: \u0635\u0641\u062d\u0647\u0654 \u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627\u06cc \u0645\u0646 (HWID) \u0648 \u0635\u0641\u062d\u0647\u0654 \u0644\u06cc\u0646\u06a9\u200c\u0647\u0627 \u0648 Happ \u062f\u0631 \u062c\u0632\u0626\u06cc\u0627\u062a \u0633\u0631\u0648\u06cc\u0633'
cl = vj.get('changelog')
if isinstance(cl, list) and (len(cl) == 0 or isinstance(cl[0], str)):
    if ENTRY in cl:
        print('changelog: already present')
    else:
        cl.insert(0, ENTRY)
        print('changelog: entry added')
    vj['changelog'] = cl
else:
    print('changelog: skipped (shape=%s)' % type(cl).__name__)
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
