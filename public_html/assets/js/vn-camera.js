/* =========================================================================
   VN System — Câmera de várias fotos (vn-camera.js) · v1

   Por quê: num <input type="file"> o celular abre o app da câmera e devolve UMA foto por vez
   (para tirar 5 fotos era preciso abrir, tirar, voltar... 5 vezes).
   Aqui a câmera fica aberta dentro da página: dispara quantas fotos quiser em sequência e,
   ao tocar em "Concluir", todas entram no mesmo campo de arquivos, como se tivessem sido
   escolhidas na galeria (o envio da tela continua igual).

   Uso:
     VNCamera.open(inputFile)          abre a câmera e entrega as fotos nesse campo
     VNCamera.attach(inputFile)        coloca o botão "Tirar várias fotos" depois do campo
     <input type="file" data-vn-camera ...>   ganha o botão sozinho quando a página carrega

   Sem câmera disponível (permissão negada, navegador antigo, página sem HTTPS) mostra o motivo e
   oferece a câmera comum do celular (1 foto por vez), então nunca fica pior do que antes.
   ========================================================================= */
(function () {
  'use strict';
  if (window.VNCamera) return;

  var MAX_FOTOS = 40;          // por abertura da câmera
  var LADO_MAX = 2048;         // px do lado maior (foto de 12 MP vira ~0,5 MB)
  var QUALIDADE = 0.88;

  var css = [
    '.vnc{position:fixed;inset:0;z-index:2147483600;background:#000;color:#fff;display:flex;flex-direction:column;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}',
    '.vnc[hidden]{display:none}',
    '.vnc *{box-sizing:border-box}',
    '.vnc-video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}',
    '.vnc-flash{position:absolute;inset:0;background:#fff;opacity:0;pointer-events:none}',
    '.vnc-flash.on{animation:vncflash .18s ease-out}',
    '@keyframes vncflash{from{opacity:.85}to{opacity:0}}',
    '.vnc-top,.vnc-base{position:relative;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px}',
    '.vnc-top{padding-top:max(12px,env(safe-area-inset-top,0px));background:linear-gradient(#000a,#0000)}',
    '.vnc-base{margin-top:auto;flex-direction:column;align-items:stretch;padding-bottom:max(14px,env(safe-area-inset-bottom,0px));background:linear-gradient(#0000,#000b)}',
    '.vnc-contador{font-weight:800;font-size:.95rem;text-shadow:0 1px 3px #000}',
    '.vnc-ib{width:48px;height:48px;border-radius:50%;border:1px solid #fff4;background:#0008;color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0}',
    '.vnc-ib svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.vnc-tira{display:flex;gap:8px;overflow-x:auto;padding:2px 2px 12px;min-height:0;scrollbar-width:none;-webkit-overflow-scrolling:touch}',
    '.vnc-tira::-webkit-scrollbar{display:none}',
    '.vnc-tira:empty{display:none}',
    '.vnc-mini{position:relative;flex:0 0 auto;width:58px;height:58px;border-radius:10px;overflow:hidden;border:2px solid #fff8;background:#222}',
    '.vnc-mini img{width:100%;height:100%;object-fit:cover;display:block}',
    '.vnc-mini button{position:absolute;top:2px;right:2px;width:24px;height:24px;border-radius:50%;border:0;background:#000c;color:#fff;font-size:15px;line-height:1;cursor:pointer;padding:0}',
    '.vnc-linha{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px}',
    '.vnc-disparo{width:76px;height:76px;border-radius:50%;border:5px solid #fff;background:#fff3;cursor:pointer;padding:0;position:relative}',
    '.vnc-disparo::after{content:"";position:absolute;inset:5px;border-radius:50%;background:#fff;transition:transform .08s}',
    '.vnc-disparo:active::after{transform:scale(.86)}',
    '.vnc-disparo:disabled{opacity:.4}',
    '.vnc-ok{justify-self:end;min-height:48px;padding:0 20px;white-space:nowrap;border-radius:999px;border:0;background:#2563eb;color:#fff;font:inherit;font-weight:800;font-size:1rem;cursor:pointer}',
    '.vnc-ok:disabled{background:#475569;color:#cbd5e1}',
    '.vnc-msg{position:absolute;z-index:3;left:16px;right:16px;top:50%;transform:translateY(-50%);padding:20px;border-radius:16px;background:#111827f2;border:1px solid #fff2;text-align:center;line-height:1.5}',
    '.vnc-msg[hidden]{display:none}',
    '.vnc-msg p{margin:0 0 14px}',
    '.vnc-msg button{min-height:46px;padding:0 18px;border-radius:12px;border:1px solid #fff4;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer;margin:4px}',
    '.vnc-msg button.sec{background:transparent}',
    '.vnc-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:10px 18px;margin-top:8px;border-radius:12px;border:1px solid #2563eb;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer}',
    '.vnc-btn svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.vnc-btn.bloco{display:flex;width:100%}'
  ].join('\n');

  var ICO_CAMERA = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.6"/></svg>';
  var ICO_VIRAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7h-9a5 5 0 0 0-5 5"/><path d="m17 4 3 3-3 3"/><path d="M4 17h9a5 5 0 0 0 5-5"/><path d="m7 20-3-3 3-3"/></svg>';
  var ICO_FECHAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  var ui = null;       // elementos da janela (criados na 1ª vez)
  var st = { input: null, stream: null, fotos: [], facing: 'environment', opener: null, seq: 0, htmlOverflow: '' };

  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  function estilo() {
    if (document.getElementById('vncStyle')) return;
    var s = document.createElement('style'); s.id = 'vncStyle'; s.textContent = css; document.head.appendChild(s);
  }

  function montar() {
    if (ui) return ui;
    estilo();

    var root = el('div', 'vnc'); root.hidden = true;
    root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', 'Câmera');
    var video = el('video', 'vnc-video'); video.setAttribute('autoplay', ''); video.setAttribute('playsinline', ''); video.setAttribute('muted', ''); video.muted = true;
    var flash = el('div', 'vnc-flash');
    var top = el('div', 'vnc-top');
    var fechar = el('button', 'vnc-ib', ICO_FECHAR); fechar.type = 'button'; fechar.setAttribute('aria-label', 'Fechar câmera');
    var contador = el('span', 'vnc-contador', 'Nenhuma foto'); contador.setAttribute('aria-live', 'polite');
    var virar = el('button', 'vnc-ib', ICO_VIRAR); virar.type = 'button'; virar.setAttribute('aria-label', 'Virar câmera (frente/trás)');
    top.appendChild(fechar); top.appendChild(contador); top.appendChild(virar);

    var msg = el('div', 'vnc-msg'); msg.hidden = true;
    var base = el('div', 'vnc-base');
    var tira = el('div', 'vnc-tira');
    var linha = el('div', 'vnc-linha');
    var vazio = el('span');
    var disparo = el('button', 'vnc-disparo'); disparo.type = 'button'; disparo.setAttribute('aria-label', 'Tirar foto');
    var ok = el('button', 'vnc-ok', 'Concluir'); ok.type = 'button'; ok.disabled = true;
    linha.appendChild(vazio); linha.appendChild(disparo); linha.appendChild(ok);
    base.appendChild(tira); base.appendChild(linha);

    root.appendChild(video); root.appendChild(flash); root.appendChild(top); root.appendChild(msg); root.appendChild(base);
    document.body.appendChild(root);

    ui = { root: root, video: video, flash: flash, contador: contador, msg: msg, tira: tira, disparo: disparo, ok: ok, virar: virar, fechar: fechar };

    disparo.addEventListener('click', tirar);
    ok.addEventListener('click', concluir);
    fechar.addEventListener('click', function () { fecharJanela(true); });
    virar.addEventListener('click', function () { st.facing = st.facing === 'environment' ? 'user' : 'environment'; ligar(); });
    document.addEventListener('keydown', function (e) {
      if (ui.root.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); fecharJanela(true); }
      else if ((e.key === ' ' || e.key === 'Enter') && document.activeElement === document.body) { e.preventDefault(); tirar(); }
    });
    // Celular que volta de outro app: a câmera costuma ser desligada pelo sistema; liga de novo.
    document.addEventListener('visibilitychange', function () {
      if (ui.root.hidden) return;
      if (document.hidden) parar(); else ligar();
    });
    window.addEventListener('pagehide', parar);
    return ui;
  }

  function parar() {
    if (st.stream) { st.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); st.stream = null; }
    if (ui) ui.video.srcObject = null;
  }

  function aviso(html, botoes) {
    ui.msg.innerHTML = '<p>' + html + '</p>';
    (botoes || []).forEach(function (b) {
      var bt = el('button', b.sec ? 'sec' : '', b.txt); bt.type = 'button'; bt.addEventListener('click', b.fn); ui.msg.appendChild(bt);
    });
    ui.msg.hidden = false;
  }

  function ligar() {
    ui.msg.hidden = true;
    parar();
    var md = navigator.mediaDevices;
    if (!md || !md.getUserMedia) { semCamera('Este navegador não deixa abrir a câmera aqui (ou a página não está em HTTPS).'); return; }
    ui.disparo.disabled = true;
    md.getUserMedia({ audio: false, video: { facingMode: { ideal: st.facing }, width: { ideal: 1920 }, height: { ideal: 1080 } } })
      .then(function (stream) {
        if (ui.root.hidden) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }   // fechou enquanto pedia permissão
        st.stream = stream;
        ui.video.srcObject = stream;
        var p = ui.video.play();
        if (p && p.catch) p.catch(function () {});
        ui.disparo.disabled = false;
      })
      .catch(function (err) {
        var nome = err && err.name;
        if (nome === 'NotAllowedError' || nome === 'SecurityError') semCamera('A câmera está bloqueada. Toque no cadeado ao lado do endereço do site, libere a <b>Câmera</b> e tente de novo.');
        else if (nome === 'NotFoundError' || nome === 'OverconstrainedError') semCamera('Não encontrei uma câmera neste aparelho.');
        else semCamera('Não consegui abrir a câmera (ela pode estar sendo usada por outro app).');
      });
  }

  /* Plano B: câmera comum do celular (uma foto por vez), para não deixar a pessoa sem opção. */
  function semCamera(motivo) {
    ui.disparo.disabled = true;
    aviso(motivo, [
      { txt: 'Tentar de novo', fn: function () { ligar(); } },
      { txt: 'Usar a câmera do celular (1 foto por vez)', sec: true, fn: planoB },
      { txt: 'Fechar', sec: true, fn: function () { fecharJanela(true); } }
    ]);
  }
  function planoB() {
    var f = document.createElement('input');
    f.type = 'file'; f.accept = 'image/*'; f.setAttribute('capture', 'environment');
    f.addEventListener('change', function () {
      if (f.files && f.files.length) {
        Array.prototype.forEach.call(f.files, function (file) { st.fotos.push({ file: file, url: URL.createObjectURL(file) }); });
        desenhar();
        ui.msg.hidden = true;
      }
    });
    f.click();
  }

  function desenhar() {
    ui.tira.innerHTML = '';
    st.fotos.forEach(function (f, i) {
      var m = el('div', 'vnc-mini');
      var img = el('img'); img.src = f.url; img.alt = 'Foto ' + (i + 1);
      var x = el('button', '', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Descartar foto ' + (i + 1));
      x.addEventListener('click', function () { URL.revokeObjectURL(f.url); st.fotos.splice(st.fotos.indexOf(f), 1); desenhar(); });
      m.appendChild(img); m.appendChild(x); ui.tira.appendChild(m);
    });
    var n = st.fotos.length;
    ui.contador.textContent = n === 0 ? 'Nenhuma foto' : (n === 1 ? '1 foto' : n + ' fotos');
    ui.ok.disabled = n === 0;
    ui.ok.textContent = n > 0 ? 'Concluir (' + n + ')' : 'Concluir';
    ui.tira.scrollLeft = ui.tira.scrollWidth;
  }

  function tirar() {
    var v = ui.video;
    if (!v.videoWidth || !st.stream) return;
    if (st.fotos.length >= MAX_FOTOS) { aviso('Limite de ' + MAX_FOTOS + ' fotos de uma vez. Toque em <b>Concluir</b> e abra a câmera de novo para tirar mais.', [{ txt: 'OK', fn: function () { ui.msg.hidden = true; } }]); return; }
    var w = v.videoWidth, h = v.videoHeight, e = Math.min(1, LADO_MAX / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w * e)); c.height = Math.max(1, Math.round(h * e));
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    ui.flash.classList.remove('on'); void ui.flash.offsetWidth; ui.flash.classList.add('on');
    try { if (navigator.vibrate) navigator.vibrate(25); } catch (er) {}
    c.toBlob(function (blob) {
      if (!blob) return;
      var d = new Date(), p2 = function (n) { return String(n).padStart(2, '0'); };
      var nome = 'foto-' + d.getFullYear() + p2(d.getMonth() + 1) + p2(d.getDate()) + '-' + p2(d.getHours()) + p2(d.getMinutes()) + p2(d.getSeconds()) + '-' + (++st.seq) + '.jpg';
      var file;
      try { file = new File([blob], nome, { type: 'image/jpeg', lastModified: Date.now() }); } catch (er) { file = blob; file.name = nome; }
      st.fotos.push({ file: file, url: URL.createObjectURL(blob) });
      desenhar();
    }, 'image/jpeg', QUALIDADE);
  }

  /* Põe as fotos no campo de arquivos (somando ao que já estava escolhido) e avisa a página. */
  function entregar(input, arquivos) {
    try {
      var dt = new DataTransfer();
      Array.prototype.forEach.call(input.files || [], function (f) { dt.items.add(f); });
      arquivos.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    } catch (e) { return false; }
  }

  function concluir() {
    if (!st.fotos.length) return;
    var arquivos = st.fotos.map(function (f) { return f.file; });
    if (!entregar(st.input, arquivos)) {
      aviso('Não consegui anexar as fotos neste navegador. Atualize o navegador ou use <b>Escolher da galeria</b>.', [{ txt: 'OK', fn: function () { ui.msg.hidden = true; } }]);
      return;
    }
    fecharJanela(false);
  }

  function fecharJanela(confirmar) {
    if (confirmar && st.fotos.length && !window.confirm('Descartar ' + (st.fotos.length === 1 ? 'a foto tirada' : 'as ' + st.fotos.length + ' fotos tiradas') + '?')) return;
    parar();
    st.fotos.forEach(function (f) { try { URL.revokeObjectURL(f.url); } catch (e) {} });
    st.fotos = [];
    ui.root.hidden = true;
    document.documentElement.style.overflow = st.htmlOverflow;
    if (st.opener && st.opener.focus) { try { st.opener.focus(); } catch (e) {} }
  }

  function abrir(input, opener) {
    if (!input) return;
    montar();
    st.input = input;
    st.opener = opener || document.activeElement;
    st.fotos = [];
    st.facing = 'environment';
    desenhar();
    st.htmlOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    ui.root.hidden = false;
    ligar();
    setTimeout(function () { try { ui.disparo.focus(); } catch (e) {} }, 50);
  }

  /* Botão "Tirar várias fotos" logo depois do campo (ou do <label> que o envolve). */
  function anexar(input, opts) {
    if (!input || input.__vncBtn) return input && input.__vncBtn;
    opts = opts || {};
    var btn = el('button', 'vnc-btn' + (opts.bloco ? ' bloco' : ''), ICO_CAMERA + '<span>Tirar várias fotos</span>');
    btn.type = 'button';
    btn.addEventListener('click', function () { abrir(input, btn); });
    var ref = input.closest('label') || input;
    ref.parentNode.insertBefore(btn, ref.nextSibling);
    estilo();
    input.__vncBtn = btn;
    return btn;
  }

  function auto() {
    Array.prototype.forEach.call(document.querySelectorAll('input[type="file"][data-vn-camera]'), function (i) {
      anexar(i, { bloco: i.hasAttribute('data-vn-camera-bloco') });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();

  window.VNCamera = { open: abrir, attach: anexar };
})();
