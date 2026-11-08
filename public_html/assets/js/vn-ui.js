/* =========================================================================
   VN System — vn-ui.js · v1
   - Tema claro/escuro único (html[data-theme], chave 'vn-theme' no localStorage)
   - Drawer do menu (celular) com foco e Esc
   - VN.confirm() / VN.toast()  +  [data-confirm] em formulários e links
   - Acessibilidade: liga <label> ao campo (for/id), nome para campos sem rótulo,
     role="dialog" e Esc nos modais antigos
   ========================================================================= */
(function () {
  'use strict';

  var KEY = 'vn-theme';
  var root = document.documentElement;
  var VN = window.VN = window.VN || {};

  /* ------------------------------------------------------------------ tema */
  function stored() {
    try { var t = localStorage.getItem(KEY); return (t === 'dark' || t === 'light') ? t : null; } catch (e) { return null; }
  }
  function save(t) { try { localStorage.setItem(KEY, t); } catch (e) {} }
  function current() { return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

  function applyTheme(t, persist) {
    var dark = t === 'dark';
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    if (document.body) {
      document.body.classList.toggle('dark-theme', dark);
      document.body.classList.toggle('light-theme', !dark);
      document.body.setAttribute('data-vn-theme', dark ? 'system-dark' : 'system-light');
    }
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#0f172a' : '#2563eb');
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.setAttribute('aria-pressed', dark ? 'true' : 'false');
      b.setAttribute('title', dark ? 'Usar tema claro' : 'Usar tema escuro');
      if (b.hasAttribute('data-theme-icon')) b.textContent = dark ? '☀️' : '🌙';
    });
    if (persist) save(t);
    try { window.dispatchEvent(new CustomEvent('vn:themechange', { detail: { theme: t } })); } catch (e) {}
  }
  VN.theme = { get: current, set: function (t) { applyTheme(t, true); }, apply: function (t) { applyTheme(t, false); }, toggle: function () { applyTheme(current() === 'dark' ? 'light' : 'dark', true); } };

  /* ---------------------------------------------------------------- toasts */
  var toastHost;
  VN.toast = function (msg, type, ms) {
    if (!toastHost) {
      toastHost = document.createElement('div');
      toastHost.className = 'vn-toast-host';
      toastHost.setAttribute('role', 'status');
      toastHost.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastHost);
    }
    var el = document.createElement('div');
    el.className = 'vn-toast' + (type ? ' ' + type : '');
    el.textContent = String(msg == null ? '' : msg);
    toastHost.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .25s';
      el.style.opacity = '0';
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 260);
    }, ms || 3600);
    return el;
  };

  /* ------------------------------------------------------------ confirmação */
  VN.confirm = function (message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (typeof HTMLDialogElement === 'undefined') { resolve(window.confirm(message)); return; }
      var d = document.createElement('dialog');
      d.className = 'vn-dialog';
      d.setAttribute('aria-labelledby', 'vnDlgTitle');
      var head = document.createElement('div'); head.className = 'vn-dialog-head';
      var h = document.createElement('h4'); h.id = 'vnDlgTitle'; h.textContent = opts.title || 'Confirmar';
      head.appendChild(h);
      var body = document.createElement('div'); body.className = 'vn-dialog-body'; body.textContent = String(message || '');
      var foot = document.createElement('div'); foot.className = 'vn-dialog-foot';
      var no = document.createElement('button'); no.type = 'button'; no.className = 'vn-btn'; no.textContent = opts.cancelText || 'Cancelar';
      var guess = 'Confirmar';
      if (opts.danger) { var w = String(message || '').trim().split(/\s+/)[0].toLowerCase(); guess = /^(excluir|remover|apagar|desfazer|cancelar)$/.test(w) ? w.charAt(0).toUpperCase() + w.slice(1) : 'Confirmar'; }
      var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'vn-btn ' + (opts.danger ? 'vn-btn-danger' : 'vn-btn-primary'); yes.textContent = opts.okText || guess;
      foot.appendChild(no); foot.appendChild(yes);
      d.appendChild(head); d.appendChild(body); d.appendChild(foot);
      document.body.appendChild(d);
      var done = function (v) { try { d.close(); } catch (e) {} if (d.parentNode) d.parentNode.removeChild(d); resolve(v); };
      no.addEventListener('click', function () { done(false); });
      yes.addEventListener('click', function () { done(true); });
      d.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });
      d.showModal();
      (opts.danger ? no : yes).focus();
    });
  };

  /* [data-confirm="texto"] em <form>, <button>, <input type=submit> e <a> */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    var sub = e.submitter || null;
    var msg = (sub && sub.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (!msg || f.__vnOk) { f.__vnOk = false; return; }
    e.preventDefault();
    var danger = (sub && sub.hasAttribute('data-danger')) || f.hasAttribute('data-danger');
    VN.confirm(msg, { danger: danger, okText: (sub && sub.getAttribute('data-confirm-ok')) || undefined }).then(function (ok) {
      if (!ok) return;
      f.__vnOk = true;
      if (f.requestSubmit) { try { f.requestSubmit(sub || undefined); return; } catch (err) {} }
      f.submit();
    });
  }, true);
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-confirm]');
    if (!a || a.__vnOk) { if (a) a.__vnOk = false; return; }
    e.preventDefault();
    VN.confirm(a.getAttribute('data-confirm'), { danger: a.hasAttribute('data-danger') }).then(function (ok) {
      if (ok) { a.__vnOk = true; a.click(); }
    });
  }, true);

  /* [data-copy="texto"]: copia para a área de transferência e avisa */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) return;
    e.preventDefault();
    var txt = b.getAttribute('data-copy') || '';
    var done = function () { VN.toast(b.getAttribute('data-copy-ok') || 'Link copiado.', 'ok'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(done, function () { window.prompt('Copie o link:', txt); });
    } else {
      var t = document.createElement('textarea'); t.value = txt; t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Copie o link:', txt); }
      document.body.removeChild(t);
    }
  });

  /* ---------------------------------------------------------------- drawer */
  var drawer, backdrop, lastOpener;
  function focusables(root) {
    return Array.prototype.slice.call(root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'))
      .filter(function (el) { return el.offsetParent !== null; });
  }
  function openDrawer(opener) {
    if (!drawer) return;
    lastOpener = opener || document.activeElement;
    drawer.classList.add('open');
    root.classList.add('vn-drawer-open');   // trava a rolagem da pagina de tras (ver vn-ui.css)
    if (backdrop) backdrop.classList.add('open');
    document.querySelectorAll('[data-vn-drawer]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    var f = focusables(drawer);
    if (f.length) setTimeout(function () { f[0].focus(); }, 30);
  }
  function closeDrawer() {
    if (!drawer || !drawer.classList.contains('open')) return;
    drawer.classList.remove('open');
    root.classList.remove('vn-drawer-open');
    if (backdrop) backdrop.classList.remove('open');
    document.querySelectorAll('[data-vn-drawer]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    if (lastOpener && lastOpener.focus) lastOpener.focus();
  }
  VN.drawer = { open: openDrawer, close: closeDrawer };
  // Voltando pelo botao 'voltar' (cache do navegador) o menu nao pode reaparecer aberto, travando a pagina.
  window.addEventListener('pageshow', function (e) { if (e.persisted) closeDrawer(); });

  /* ---------------------------------------------------- acessibilidade */
  var uid = 0;
  function needId(el) { if (!el.id) el.id = 'vn-f-' + (++uid); return el.id; }
  function realControls(scope) {
    return Array.prototype.slice.call(scope.querySelectorAll('input,select,textarea'))
      .filter(function (c) { return c.type !== 'hidden' && c.type !== 'checkbox' && c.type !== 'radio' && c.type !== 'submit' && c.type !== 'button'; });
  }
  function fixLabels() {
    document.querySelectorAll('label:not([for])').forEach(function (l) {
      if (l.querySelector('input,select,textarea')) return;
      var c = null, n = l.nextElementSibling;
      if (n && /^(INPUT|SELECT|TEXTAREA)$/.test(n.tagName)) c = n;
      else if (n && n.tagName !== 'LABEL') { var inN = realControls(n); if (inN.length === 1) c = inN[0]; }
      if (!c && l.parentElement) { var inP = realControls(l.parentElement); if (inP.length === 1) c = inP[0]; }
      if (c && !c.hasAttribute('aria-labelledby') && !(c.labels && c.labels.length)) l.setAttribute('for', needId(c));
    });
    document.querySelectorAll('input,select,textarea').forEach(function (c) {
      if (c.type === 'hidden' || c.type === 'submit' || c.type === 'button') return;
      if ((c.labels && c.labels.length) || c.getAttribute('aria-label') || c.getAttribute('aria-labelledby') || c.getAttribute('title')) return;
      var t = c.getAttribute('placeholder') || c.getAttribute('name');
      if (t) c.setAttribute('aria-label', t.replace(/[_-]+/g, ' '));
    });
    document.querySelectorAll('button:not([aria-label])').forEach(function (b) {
      if (b.textContent.trim() === '' && b.getAttribute('title')) b.setAttribute('aria-label', b.getAttribute('title'));
    });
  }
  function fixModals() {
    document.querySelectorAll('.modal, .modal-card, .modal-box, .modal-content').forEach(function (m) {
      if (!m.getAttribute('role')) { m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true'); }
    });
  }
  function visible(el) { return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function escapeLegacyModal() {
    var open = Array.prototype.slice.call(document.querySelectorAll('.modal-backdrop, .modal-overlay, .modal, .overlay-modal')).filter(visible);
    if (!open.length) return false;
    var top = open[open.length - 1];
    var btn = top.querySelector('.modal-close, [data-close], .btn-close, .close, button[aria-label="Fechar"]') ||
              (top.parentElement && top.parentElement.querySelector('.modal-close, [data-close], .btn-close'));
    if (btn) { btn.click(); return true; }
    return false;
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (drawer && drawer.classList.contains('open')) { closeDrawer(); return; }
      escapeLegacyModal();
    }
    if (e.key === 'Tab' && drawer && drawer.classList.contains('open')) {
      var f = focusables(drawer); if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-theme-toggle]');
    if (t) { e.preventDefault(); VN.theme.toggle(); return; }
    var o = e.target.closest && e.target.closest('[data-vn-drawer]');
    if (o) { e.preventDefault(); (drawer && drawer.classList.contains('open')) ? closeDrawer() : openDrawer(o); return; }
    if (backdrop && e.target === backdrop) closeDrawer();
  });
  window.addEventListener('storage', function (e) {
    if (e.key === KEY && (e.newValue === 'dark' || e.newValue === 'light')) applyTheme(e.newValue, false);
  });

  function init() {
    drawer = document.getElementById('vnDrawer');
    backdrop = document.getElementById('vnDrawerBackdrop');
    document.querySelectorAll('[data-vn-drawer]').forEach(function (b) {
      b.setAttribute('aria-controls', 'vnDrawer'); b.setAttribute('aria-expanded', 'false');
    });
    applyTheme(stored() || current(), false);
    fixLabels();
    fixModals();
    try {
      new MutationObserver(function () { clearTimeout(init._t); init._t = setTimeout(function () { fixLabels(); fixModals(); }, 120); })
        .observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  /* App instalável + tela offline (service worker conservador: só guarda CSS/JS/imagens). */
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
  }
})();
