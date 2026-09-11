/* ==========================================================================
   SR-BOT • Admin UI runtime  v5
   کشوی موبایل، نوار پایین، مودال، تب، جدول واکنش‌گرا، توست، تم‌روشن/تیره
   همه‌ی قلاب‌های data-* نسخه‌های پیشین دست‌نخورده پشتیبانی می‌شوند.
   ========================================================================== */
(function () {
  'use strict';

  var D = document;
  var H = D.documentElement;
  H.classList.add('js');

  function ready(fn) {
    if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  function $(s, r) { return (r || D).querySelector(s); }
  function $$(s, r) { return [].slice.call((r || D).querySelectorAll(s)); }
  function isDesktop() { return window.matchMedia('(min-width:1024px)').matches; }

  /* ======================================================================
     1) ارقام فارسی / انگلیسی
     ====================================================================== */
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var AR = '٠١٢٣٤٥٦٧٨٩';

  function en(s) {
    s = String(s == null ? '' : s);
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var c = s.charAt(i), k = FA.indexOf(c);
      if (k < 0) k = AR.indexOf(c);
      out += k > -1 ? String(k) : c;
    }
    return out.replace(/٬/g, ',').replace(/٫/g, '.');
  }
  function fa(s) {
    return String(s == null ? '' : s).replace(/[0-9]/g, function (n) { return FA.charAt(+n); });
  }
  window.enDigits = en;
  window.faDigits = fa;

  /* ======================================================================
     2) تم روشن / تیره
     ====================================================================== */
  var THEME_KEY = 'vs-theme';

  function applyTheme(t) {
    H.setAttribute('data-theme', t);
    var m = $('meta[name="theme-color"]');
    if (m) m.setAttribute('content', t === 'light' ? '#FFFFFF' : '#0B0E14');
    $$('[data-theme-icon]').forEach(function (el) { el.textContent = t === 'light' ? '🌙' : '☀️'; });
  }
  function savedTheme() {
    try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; }
  }
  (function initTheme() {
    var t = savedTheme();
    if (!t) t = window.matchMedia('(prefers-color-scheme:light)').matches ? 'light' : 'dark';
    applyTheme(t);
  })();

  window.vsToggleTheme = function () {
    var t = H.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(t);
    try { localStorage.setItem(THEME_KEY, t); } catch (e) {}
    toast(t === 'light' ? 'پوسته‌ی روشن فعال شد' : 'پوسته‌ی تیره فعال شد');
  };

  /* ======================================================================
     3) توست
     ====================================================================== */
  var toastEl = null, toastTmr = null;

  function toast(msg, kind) {
    if (!toastEl) {
      toastEl = D.createElement('div');
      toastEl.className = 'vs-toast';
      D.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.className = 'vs-toast on' + (kind ? ' ' + kind : '');
    clearTimeout(toastTmr);
    toastTmr = setTimeout(function () { toastEl.className = 'vs-toast'; }, 2600);
  }
  window.vsToast = toast;

  /* ======================================================================
     4) کشوی کناری (موبایل)
     ====================================================================== */
  var backdrop = null;

  function sideEl() { return $('.side'); }

  function openSide() {
    var s = sideEl();
    if (!s || isDesktop()) return;
    s.classList.add('open');
    if (!backdrop) {
      backdrop = D.createElement('div');
      backdrop.className = 'backdrop';
      backdrop.addEventListener('click', closeSide);
      D.body.appendChild(backdrop);
    }
    D.body.style.overflow = 'hidden';
    var first = s.querySelector('a, button');
    if (first) setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) {} }, 240);
  }
  function closeSide() {
    var s = sideEl();
    if (s) s.classList.remove('open');
    if (backdrop) { backdrop.remove(); backdrop = null; }
    if (!D.body.classList.contains('modal-open')) D.body.style.overflow = '';
  }
  function toggleSide() {
    var s = sideEl();
    if (s && s.classList.contains('open')) closeSide(); else openSide();
  }
  window.vsToggleSide = toggleSide;

  /* بستن خودکار در تغییر اندازه/جهت صفحه */
  var rzT = null;
  window.addEventListener('resize', function () {
    clearTimeout(rzT);
    rzT = setTimeout(function () { if (isDesktop()) closeSide(); }, 120);
  });
  window.addEventListener('orientationchange', function () { setTimeout(closeSide, 150); });

  /* کشیدن انگشت برای بستن کشو (RTL: به راست) */
  (function swipe() {
    var x0 = null, y0 = null, moved = 0;
    D.addEventListener('touchstart', function (e) {
      var s = sideEl();
      if (!s || !s.classList.contains('open') || e.touches.length !== 1) return;
      x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; moved = 0;
    }, { passive: true });
    D.addEventListener('touchmove', function (e) {
      if (x0 == null) return;
      var dx = e.touches[0].clientX - x0;
      var dy = e.touches[0].clientY - y0;
      if (Math.abs(dy) > Math.abs(dx)) { x0 = null; return; }
      moved = dx;
      var s = sideEl();
      if (s && dx > 0) s.style.transform = 'translateX(' + Math.min(dx, 400) + 'px)';
    }, { passive: true });
    D.addEventListener('touchend', function () {
      var s = sideEl();
      if (s) s.style.transform = '';
      if (x0 != null && moved > 70) closeSide();
      x0 = null; y0 = null; moved = 0;
    }, { passive: true });
  })();

  /* ======================================================================
     5) مودال
     ====================================================================== */
  function openModal(id) {
    var m = typeof id === 'string' ? D.getElementById(id) : id;
    if (!m) return;
    m.classList.add('on');
    D.body.classList.add('modal-open');
    D.body.style.overflow = 'hidden';
    var f = m.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
    if (f && isDesktop()) setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) {} }, 120);
  }
  function closeModal(m) {
    if (!m) m = $('.modal.on');
    if (!m) return;
    m.classList.remove('on');
    if (!$('.modal.on')) {
      D.body.classList.remove('modal-open');
      D.body.style.overflow = '';
    }
  }
  window.vsOpenModal = openModal;
  window.vsCloseModal = closeModal;

  /* ======================================================================
     6) تب‌ها
     ====================================================================== */
  function activateTab(group, key) {
    $$('[data-tab-group="' + group + '"]').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-tab') === key);
    });
    $$('[data-tab-panel-group="' + group + '"]').forEach(function (p) {
      p.classList.toggle('on', p.getAttribute('data-tab-panel') === key);
    });
    try { sessionStorage.setItem('vs-tab-' + group, key); } catch (e) {}
  }
  window.vsTab = activateTab;

  /* ======================================================================
     7) برچسب‌گذاری جدول برای موبایل
     ====================================================================== */
  function labelTables(root) {
    $$('table', root || D).forEach(function (t) {
      if (t.classList.contains('no-responsive')) return;
      var ths = $$('thead th', t);
      if (ths.length) t.classList.add('responsive');
      var labels = ths.map(function (th) {
        return (th.getAttribute('data-l') || th.textContent || '').replace(/\s+/g, ' ').trim();
      });
      $$('tbody tr', t).forEach(function (tr) {
        $$(':scope > td', tr).forEach(function (td, i) {
          if (!td.hasAttribute('data-l')) {
            var lb = labels[i] || '';
            if (td.classList.contains('acts') && !lb) lb = 'عملیات';
            td.setAttribute('data-l', lb);
          }
        });
      });
    });
  }
  window.vsLabelTables = labelTables;

  /* ======================================================================
     8) بهبود جدول: جستجو + مرتب‌سازی + صفحه‌بندی
     ====================================================================== */
  function enhanceTable(t) {
    if (t.getAttribute('data-enhanced') === '1') return;
    t.setAttribute('data-enhanced', '1');

    var rows = $$('tbody tr', t);
    var size = parseInt(t.getAttribute('data-page-size') || '20', 10) || 20;
    if (rows.length < 6) size = rows.length || 1;

    var wrap = t.closest('.table-wrap') || t.parentNode;
    var bar = D.createElement('div');
    bar.className = 'tbl-bar';
    bar.innerHTML =
      '<input type="search" class="tbl-q" placeholder="🔍 جستجو در جدول…">' +
      '<span class="tbl-count"></span>';
    if (rows.length > 10) {
      var sel = D.createElement('select');
      sel.className = 'tbl-size';
      [10, 20, 50, 100].forEach(function (n) {
        var o = D.createElement('option');
        o.value = String(n); o.textContent = fa(String(n)) + ' ردیف';
        if (n === size) o.selected = true;
        sel.appendChild(o);
      });
      bar.insertBefore(sel, bar.querySelector('.tbl-count'));
      sel.addEventListener('change', function () { size = parseInt(sel.value, 10) || 20; page = 1; render(); });
    }
    wrap.parentNode.insertBefore(bar, wrap);

    var pager = D.createElement('div');
    pager.className = 'tbl-pager';
    wrap.parentNode.insertBefore(pager, wrap.nextSibling);

    var q = bar.querySelector('.tbl-q');
    var cnt = bar.querySelector('.tbl-count');
    var page = 1, term = '';

    function matches(tr) {
      if (!term) return true;
      return en((tr.textContent || '').toLowerCase()).indexOf(term) > -1;
    }
    function render() {
      var vis = rows.filter(matches);
      var pages = Math.max(1, Math.ceil(vis.length / size));
      if (page > pages) page = pages;
      rows.forEach(function (tr) { tr.style.display = 'none'; });
      vis.slice((page - 1) * size, page * size).forEach(function (tr) { tr.style.display = ''; });
      cnt.textContent = fa(String(vis.length)) + ' از ' + fa(String(rows.length)) + ' ردیف';

      pager.innerHTML = '';
      if (pages < 2) return;
      var mk = function (label, p, on, dis) {
        var b = D.createElement('button');
        b.type = 'button'; b.textContent = label;
        if (on) b.className = 'on';
        if (dis) b.disabled = true;
        b.addEventListener('click', function () { page = p; render(); wrap.scrollIntoView({ block: 'nearest' }); });
        pager.appendChild(b);
      };
      mk('›', page - 1, false, page === 1);
      var from = Math.max(1, page - 2), to = Math.min(pages, from + 4);
      from = Math.max(1, to - 4);
      for (var i = from; i <= to; i++) mk(fa(String(i)), i, i === page, false);
      mk('‹', page + 1, false, page === pages);
    }

    q.addEventListener('input', function () {
      term = en(q.value.trim().toLowerCase());
      page = 1; render();
    });

    /* مرتب‌سازی ستونی */
    $$('thead th', t).forEach(function (th, idx) {
      if (th.hasAttribute('data-nosort') || th.classList.contains('acts')) return;
      th.classList.add('sortable');
      var dir = 0;
      th.addEventListener('click', function () {
        $$('thead th', t).forEach(function (o) { if (o !== th) o.classList.remove('sorted-a', 'sorted-d'); });
        dir = dir === 1 ? -1 : 1;
        th.classList.toggle('sorted-a', dir === 1);
        th.classList.toggle('sorted-d', dir === -1);
        var body = t.querySelector('tbody');
        rows.sort(function (a, b) {
          var av = cell(a, idx), bv = cell(b, idx);
          var an = parseFloat(av.replace(/[^0-9.\-]/g, '')), bn = parseFloat(bv.replace(/[^0-9.\-]/g, ''));
          var num = !isNaN(an) && !isNaN(bn) && /[0-9]/.test(av) && /[0-9]/.test(bv);
          if (num) return (an - bn) * dir;
          return av.localeCompare(bv, 'fa') * dir;
        });
        rows.forEach(function (tr) { body.appendChild(tr); });
        render();
      });
    });
    function cell(tr, i) {
      var td = tr.children[i];
      return en((td ? td.textContent : '') || '').replace(/\s+/g, ' ').trim();
    }

    render();
  }

  /* ======================================================================
     9) رویدادهای کلیک (تفویضی)
     ====================================================================== */
  D.addEventListener('click', function (e) {
    var t = e.target;

    /* -------- کشو -------- */
    var bg = t.closest('[data-burger]');
    if (bg) { e.preventDefault(); toggleSide(); return; }

    /* -------- تم -------- */
    var th = t.closest('[data-theme-toggle]');
    if (th) { e.preventDefault(); window.vsToggleTheme(); return; }

    /* -------- مودال -------- */
    var mo = t.closest('[data-modal]');
    if (mo && !mo.hasAttribute('data-modal-close')) {
      e.preventDefault(); openModal(mo.getAttribute('data-modal')); return;
    }
    var mc = t.closest('[data-modal-close]');
    if (mc) { e.preventDefault(); closeModal(mc.closest('.modal')); return; }
    if (t.classList && t.classList.contains('m-back')) { closeModal(t.closest('.modal')); return; }

    /* -------- تب -------- */
    var tb = t.closest('[data-tab-group]');
    if (tb && tb.hasAttribute('data-tab')) {
      e.preventDefault();
      activateTab(tb.getAttribute('data-tab-group'), tb.getAttribute('data-tab'));
      return;
    }

    /* -------- تاییدیه -------- */
    var cf = t.closest('[data-confirm]');
    if (cf) {
      if (!window.confirm(cf.getAttribute('data-confirm') || 'مطمئنید؟')) {
        e.preventDefault(); e.stopPropagation(); return;
      }
    }

    /* -------- کپی -------- */
    var cp = t.closest('[data-copy]');
    if (cp) {
      e.preventDefault();
      var txt = cp.getAttribute('data-copy') || cp.textContent || '';
      copy(txt.trim());
      return;
    }

    /* -------- چشم رمز -------- */
    var ey = t.closest('button[data-eye], .icon-btn[data-eye], [data-eye-btn]');
    if (ey) {
      e.preventDefault();
      var sel = ey.getAttribute('data-eye') || ey.getAttribute('data-eye-btn');
      var inp = sel ? $(sel) : null;
      if (inp) {
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ey.textContent = show ? '🙈' : '👁';
      }
      return;
    }

    /* -------- همه/هیچ در کارت دسترسی -------- */
    var pa = t.closest('[data-perm-all]');
    if (pa) {
      e.preventDefault();
      var card = pa.closest('.perm-card');
      if (card) {
        var boxes = $$('input[type=checkbox]', card);
        var allOn = boxes.every(function (c) { return c.checked; });
        boxes.forEach(function (c) { c.checked = !allOn; });
      }
      return;
    }

    /* -------- قالب دسترسی -------- */
    var ps = t.closest('[data-preset]');
    if (ps) {
      e.preventDefault();
      var keys = (ps.getAttribute('data-preset') || '').split(',').map(function (s) { return s.trim(); });
      var form = ps.closest('form');
      if (form) {
        $$('input[name="perms[]"]', form).forEach(function (c) {
          c.checked = keys.indexOf('*') > -1 || keys.indexOf(c.value) > -1;
        });
        var role = ps.getAttribute('data-preset-role');
        var rs = form.querySelector('select[name="role"]');
        if (role && rs) rs.value = role;
        var note = form.querySelector('[data-preset-note]');
        var nm = ps.querySelector('b');
        if (note) note.textContent = '✓ قالب «' + (nm ? nm.textContent : '') + '» اعمال شد';
      }
      return;
    }

    /* -------- بزرگ‌نمایی تصویر -------- */
    var im = t.closest('img[data-zoom], .msg .att img');
    if (im) {
      var lb = $('.lightbox');
      if (!lb) {
        lb = D.createElement('div');
        lb.className = 'lightbox';
        lb.innerHTML = '<img alt="">';
        lb.addEventListener('click', function () { lb.classList.remove('on'); });
        D.body.appendChild(lb);
      }
      lb.querySelector('img').src = im.getAttribute('data-zoom') || im.getAttribute('src') || '';
      lb.classList.add('on');
      return;
    }
  });

  /* ======================================================================
     10) کپی در کلیپ‌بورد
     ====================================================================== */
  function copy(text) {
    var done = function () { toast('کپی شد ✓', 'ok'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { legacy(text, done); });
    } else legacy(text, done);
  }
  function legacy(text, done) {
    var ta = D.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    D.body.appendChild(ta);
    ta.select();
    try { D.execCommand('copy'); done(); } catch (err) { toast('کپی ناموفق بود', 'err'); }
    ta.remove();
  }

  /* ======================================================================
     11) فیلتر زنده
     ====================================================================== */
  D.addEventListener('input', function (e) {
    var el = e.target;

    /* data-filter="#tableId" */
    var tSel = el.getAttribute && el.getAttribute('data-filter');
    if (tSel) {
      var tbl = $(tSel);
      if (tbl) {
        var q = en(el.value.trim().toLowerCase());
        $$('tbody tr', tbl).forEach(function (tr) {
          tr.style.display = !q || en((tr.textContent || '').toLowerCase()).indexOf(q) > -1 ? '' : 'none';
        });
      }
      return;
    }

    /* data-live-filter="#sel tbody tr" [data-live-count="#out"] */
    var lSel = el.getAttribute && el.getAttribute('data-live-filter');
    if (lSel) {
      var q2 = en(el.value.trim().toLowerCase());
      var n = 0;
      $$(lSel).forEach(function (row) {
        var ok = !q2 || en((row.textContent || '').toLowerCase()).indexOf(q2) > -1;
        row.style.display = ok ? '' : 'none';
        if (ok) n++;
      });
      var out = el.getAttribute('data-live-count');
      if (out) { var o = $(out); if (o) o.textContent = fa(String(n)); }
      return;
    }
  });

  /* ======================================================================
     12) چک‌باکس ← نمایش/پنهان بلوک
     ====================================================================== */
  function syncToggle(cb) {
    var box = $(cb.getAttribute('data-toggle-target'));
    if (box) box.style.display = cb.checked ? '' : 'none';
  }
  D.addEventListener('change', function (e) {
    var cb = e.target;
    if (cb.matches && cb.matches('[data-toggle-target]')) syncToggle(cb);
  });

  /* ======================================================================
     13) قفل دکمه‌ی ارسال فرم
     ====================================================================== */
  D.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.hasAttribute('data-no-lock')) return;
    var btn = f.querySelector('button[type=submit], input[type=submit]');
    if (!btn || btn.disabled) return;
    var old = btn.innerHTML;
    setTimeout(function () {
      btn.disabled = true;
      if (btn.tagName === 'BUTTON') btn.innerHTML = '⏳ در حال انجام…';
      setTimeout(function () {
        btn.disabled = false;
        if (btn.tagName === 'BUTTON') btn.innerHTML = old;
      }, 9000);
    }, 0);
  });

  /* ======================================================================
     14) کیبورد
     ====================================================================== */
  D.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if ($('.modal.on')) { closeModal(); return; }
      var s = sideEl();
      if (s && s.classList.contains('open')) { closeSide(); return; }
      var pl = $('.pg-list.on');
      if (pl) pl.classList.remove('on');
    }
    /* Ctrl/Cmd + K ← جستجوی صفحه‌ها */
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      var inp = $('.pg-search input');
      if (inp) { e.preventDefault(); inp.focus(); inp.select(); }
    }
    /* Space/Enter روی چیپ‌های انتخابی */
    if ((e.key === ' ' || e.key === 'Enter') && e.target.matches && e.target.matches('.pick input')) {
      e.target.checked = !e.target.checked;
      e.preventDefault();
    }
  });

  /* ======================================================================
     15) جستجوی سریع صفحه‌ها (window.VS_PAGES)
     ====================================================================== */
  function pageSearch() {
    var wrap = $('.pg-search');
    if (!wrap) return;
    var inp = wrap.querySelector('input');
    var list = wrap.querySelector('.pg-list');
    if (!inp || !list) return;

    var hi = -1;

    function paint() {
      var q = en((inp.value || '').trim().toLowerCase());
      var all = window.VS_PAGES || [];
      var rows = q
        ? all.filter(function (p) {
            return en(((p.label || '') + ' ' + (p.sub || '') + ' ' + (p.key || '')).toLowerCase()).indexOf(q) > -1;
          })
        : all.slice(0, 8);
      list.innerHTML = rows.length
        ? rows.slice(0, 12).map(function (p) {
            return '<a href="index.php?p=' + encodeURIComponent(p.key) + '">' +
              '<span>' + (p.emoji || '•') + '</span><span>' + esc(p.label || p.key) + '</span>' +
              (p.sub ? '<small>' + esc(p.sub) + '</small>' : '') + '</a>';
          }).join('')
        : '<a class="muted" href="javascript:void(0)">موردی پیدا نشد</a>';
      hi = -1;
      list.classList.add('on');
    }
    function esc(s) {
      return String(s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    inp.addEventListener('focus', paint);
    inp.addEventListener('input', paint);
    inp.addEventListener('keydown', function (e) {
      var items = $$('a', list);
      if (!items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        hi += e.key === 'ArrowDown' ? 1 : -1;
        if (hi < 0) hi = items.length - 1;
        if (hi >= items.length) hi = 0;
        items.forEach(function (a, i) { a.classList.toggle('hi', i === hi); });
      } else if (e.key === 'Enter' && hi > -1) {
        e.preventDefault();
        items[hi].click();
      }
    });
    D.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) list.classList.remove('on');
    });
  }

  /* ======================================================================
     16) مخفی‌سازی پیشرفته رمز روی خود فیلد
     ====================================================================== */
  function eyeInputs() {
    $$('input[data-eye]').forEach(function (inp) {
      if (inp.getAttribute('data-eye-done') === '1') return;
      inp.setAttribute('data-eye-done', '1');
      var box = D.createElement('div');
      box.className = 'row';
      box.style.cssText = 'gap:6px;flex-wrap:nowrap';
      inp.parentNode.insertBefore(box, inp);
      box.appendChild(inp);
      var b = D.createElement('button');
      b.type = 'button'; b.className = 'icon-btn'; b.textContent = '👁';
      b.setAttribute('data-eye-btn', inp.getAttribute('data-eye') || ('#' + inp.id));
      box.appendChild(b);
    });
  }

  /* ======================================================================
     17) سنجه‌ی قدرت رمز
     ====================================================================== */
  function pwMeters() {
    $$('.pw-meter').forEach(function (m) {
      var inp = m.previousElementSibling;
      while (inp && inp.tagName !== 'INPUT') inp = inp.previousElementSibling;
      if (!inp) return;
      var bar = m.querySelector('span') || m.appendChild(D.createElement('span'));
      inp.addEventListener('input', function () {
        var v = inp.value || '', s = 0;
        if (v.length >= 8) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/[0-9]/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        bar.style.width = (s * 25) + '%';
        bar.style.background = s <= 1 ? 'var(--red)' : (s === 2 ? 'var(--orange)' : (s === 3 ? 'var(--cyan)' : 'var(--green)'));
      });
    });
  }

  /* ======================================================================
     18) پیام همگانی – نوع محتوا
     ====================================================================== */
  function broadcastKinds() {
    var kinds = $$('[data-bc-kind]');
    if (!kinds.length) return;
    var sync = function () {
      var sel = $('[data-bc-kind]:checked');
      var kind = sel ? sel.getAttribute('data-bc-kind') : 'text';
      $$('[data-bc-need]').forEach(function (el) {
        var need = (el.getAttribute('data-bc-need') || '').split(',');
        el.style.display = need.indexOf(kind) > -1 ? '' : 'none';
      });
      var ta = $('textarea[name="text"]');
      if (ta) ta.placeholder = kind === 'text' ? 'متن پیام…' : 'توضیح (کپشن) – اختیاری';
    };
    kinds.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  }

  /* ======================================================================
     19) راه‌اندازی
     ====================================================================== */
  ready(function () {
    /* مودال خودکار */
    var auto = $('[data-modal-auto]');
    if (auto) openModal(auto.getAttribute('data-modal-auto'));

    /* تب پیش‌فرض هر گروه */
    var groups = {};
    $$('[data-tab-group]').forEach(function (b) { groups[b.getAttribute('data-tab-group')] = 1; });
    Object.keys(groups).forEach(function (g) {
      var saved = null;
      try { saved = sessionStorage.getItem('vs-tab-' + g); } catch (e) {}
      var btns = $$('[data-tab-group="' + g + '"]');
      var keys = btns.map(function (b) { return b.getAttribute('data-tab'); });
      var open = $('[data-tab-panel-group="' + g + '"].on');
      /* fixed80: #tab-<key> in URL opens that tab directly (e.g. index.php?p=settings#tab-adv) */
      var hk = (location.hash || '').indexOf('#tab-') === 0 ? location.hash.slice(5) : '';
      var key = hk && keys.indexOf(hk) > -1 ? hk
        : open ? open.getAttribute('data-tab-panel')
        : (saved && keys.indexOf(saved) > -1 ? saved : keys[0]);
      if (key) activateTab(g, key);
    });

    /* چک‌باکس‌های نمایشی */
    $$('[data-toggle-target]').forEach(syncToggle);

    /* جدول‌ها */
    labelTables(D);
    $$('table[data-enhance]').forEach(enhanceTable);

    eyeInputs();
    pwMeters();
    broadcastKinds();
    pageSearch();

    /* نوار بالا: سایه در اسکرول */
    var tp = $('.topbar');
    if (tp) {
      var onScroll = function () {
        tp.style.boxShadow = window.scrollY > 6 ? 'var(--shadow)' : '';
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }
  });
})();

/* ==========================================================================
   23) کشوی کناری: جستجوی بخش‌ها + جمع‌کردن گروه‌ها
   ========================================================================== */
(function () {
  var side = document.getElementById('vsSide');
  var nav  = document.getElementById('sdNav');
  if (!side || !nav) return;

  var q    = document.getElementById('sdQ');
  var none = document.getElementById('sdNone');
  var KEY  = 'vsNavCol';
  var firstHit = null;

  function secs() { return nav.querySelectorAll('.sd-sec'); }

  function colRead() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
    catch (e) { return {}; }
  }
  function colWrite(o) {
    try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {}
  }

  /* وضعیت باز/بستهٔ ذخیره‌شده را برگردان (گروه فعال همیشه باز) */
  var col = colRead();
  var list = secs();
  for (var i = 0; i < list.length; i++) {
    var sec = list[i];
    var nm  = sec.getAttribute('data-sec') || '';
    if (col[nm] && !sec.querySelector('.sd-i.on')) sec.classList.add('col');
  }

  function filter() {
    var t = (q && q.value ? q.value : '').trim().toLowerCase();
    var hits = 0;
    firstHit = null;
    var ss = secs();
    for (var a = 0; a < ss.length; a++) {
      var s = ss[a], seen = 0;
      var items = s.querySelectorAll('.sd-i');
      for (var b = 0; b < items.length; b++) {
        var it = items[b];
        var ok = !t || (it.getAttribute('data-q') || '').indexOf(t) !== -1;
        it.style.display = ok ? '' : 'none';
        if (ok) { seen++; if (!firstHit) firstHit = it; }
      }
      s.style.display = seen ? '' : 'none';
      if (t) s.classList.remove('col');
      hits += seen;
    }
    if (none) none.style.display = hits ? 'none' : '';
    side.classList.toggle('sd-searching', !!t);
  }

  if (q) {
    q.addEventListener('input', filter);
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { q.value = ''; filter(); q.blur(); }
      if (e.key === 'Enter' && firstHit) { e.preventDefault(); firstHit.click(); }
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;

    var g = e.target.closest('[data-sd-g]');
    if (g) {
      var sec2 = g.closest('.sd-sec');
      if (!sec2) return;
      sec2.classList.toggle('col');
      var st = colRead();
      st[sec2.getAttribute('data-sec') || ''] = sec2.classList.contains('col') ? 1 : 0;
      colWrite(st);
      return;
    }

    if (e.target.closest('#sdQx') && q) { q.value = ''; filter(); q.focus(); }
  });

  /* ردیف فعال را در دید قرار بده (فقط داخل خود کشو) */
  var on = side.querySelector('.sd-i.on');
  if (on && side.scrollHeight > side.clientHeight + 24) {
    var y = on.offsetTop - (side.clientHeight / 2) + (on.offsetHeight / 2);
    side.scrollTop = y > 0 ? y : 0;
  }
})();
