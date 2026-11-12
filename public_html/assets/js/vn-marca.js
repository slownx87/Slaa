/* =========================================================================
   VN System — Marca dos PDFs: a prévia do cabeçalho acompanha o que se digita e as cores que se escolhem.
   ========================================================================= */
(function (w, d) {
  'use strict';
  var prev = d.getElementById('pmPrev');
  if (!prev) return;
  var $ = function (id) { return d.getElementById(id); };

  /* Mesma regra do PDF: texto branco precisa de contraste; cor clara demais é escurecida. */
  function rgb(hex) { var m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return [0, 0, 0]; var n = parseInt(m[1], 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function lum(c) { var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); }
  function contrasteBranco(c) { return 1.05 / (lum(c) + 0.05); }
  function hex(c) { return '#' + c.map(function (v) { v = Math.max(0, Math.min(255, Math.round(v))); return (v < 16 ? '0' : '') + v.toString(16); }).join(''); }
  function escurecer(c) { for (var i = 0; i < 40 && contrasteBranco(c) < 4.5; i++) c = c.map(function (v) { return v * 0.93; }); return c; }

  /* Luminância média dos pixels visíveis da logo (sem transparência nem branco puro): a mesma conta do PDF. */
  var lumCache = {};
  function lumLogo(img) {
    var chave = img.currentSrc || img.src;
    if (chave in lumCache) return lumCache[chave];
    var r = null;
    try {
      if (img.naturalWidth > 0) {
        var s = 64, k = Math.min(1, s / Math.max(img.naturalWidth, img.naturalHeight));
        var cw = Math.max(1, Math.round(img.naturalWidth * k)), ch = Math.max(1, Math.round(img.naturalHeight * k));
        var cv = d.createElement('canvas'); cv.width = cw; cv.height = ch;
        var cx = cv.getContext('2d'); cx.drawImage(img, 0, 0, cw, ch);
        var px = cx.getImageData(0, 0, cw, ch).data, soma = 0, n = 0;
        for (var i = 0; i < px.length; i += 4) {
          if (px[i + 3] < 114) continue;                                      // transparente
          if (px[i] > 240 && px[i + 1] > 240 && px[i + 2] > 240) continue;    // branco
          soma += lum([px[i], px[i + 1], px[i + 2]]); n++;
        }
        r = n >= 12 ? soma / n : null;
      }
    } catch (e) { r = null; }
    lumCache[chave] = r;
    return r;
  }
  /* Logo clara sobre faixa escura fica melhor direto na faixa (no quadrado branco ela quase some). */
  function modo() {
    var img = $('pmPrevImg'), direto = false;
    if (!img.hidden && img.getAttribute('src')) {
      var l = lumLogo(img), f = lum(escurecer(rgb($('pmCor1').value)));
      if (l !== null) direto = (Math.max(l, f) + 0.05) / (Math.min(l, f) + 0.05) > (1.05 / (l + 0.05)) * 1.15;
    }
    prev.classList.toggle('pm-direto', direto);
    prev.classList.toggle('pm-logonome', direto && $('pmLogoNome').checked);
  }
  function cores() {
    var c1 = escurecer(rgb($('pmCor1').value)), c2 = rgb($('pmCor2').value);
    prev.style.setProperty('--pm-c1', hex(c1));
    prev.style.setProperty('--pm-c2', hex(c2));
    prev.style.setProperty('--pm-on2', contrasteBranco(c2) >= 3.2 ? '#ffffff' : '#111827');
    modo();
  }
  function iniciais(nome) {
    var p = String(nome || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return (p.map(function (x) { return x.charAt(0); }).join('') || '?').toUpperCase();
  }
  function textos() {
    var nome = $('pmNome').value.trim();
    $('pmPrevNome').textContent = (nome || 'Nome da empresa').toUpperCase();
    $('pmPrevIni').textContent = iniciais(nome);
    $('pmPrevSlogan').textContent = $('pmSlogan').value.trim();
    var contato = [];
    if ($('pmCnpj').value.trim()) contato.push('CNPJ ' + $('pmCnpj').value.trim());
    if ($('pmTel').value.trim()) contato.push($('pmTel').value.trim());
    $('pmPrevContato').textContent = contato.join('  ·  ');
  }
  function logo(url) {
    var tem = !!url;
    [['pmPrevImg', 'pmPrevIni'], ['pmLogoImg', 'pmLogoVazio']].forEach(function (par) {
      var img = $(par[0]), alt = $(par[1]);
      if (tem) img.src = url;
      img.hidden = !tem;
      alt.hidden = tem;
    });
    modo();
  }
  $('pmPrevImg').addEventListener('load', modo);
  $('pmLogoNome').addEventListener('change', modo);

  ['pmNome', 'pmSlogan', 'pmCnpj', 'pmTel'].forEach(function (id) { $(id).addEventListener('input', textos); });
  ['pmCor1', 'pmCor2'].forEach(function (id) {
    $(id).addEventListener('input', function () { $('pmCoresLogo').checked = false; cores(); });   // escolheu à mão: não sobrescreve ao salvar
  });

  var arq = $('pmLogo'), blob = null;
  arq.addEventListener('change', function () {
    if (blob) { w.URL.revokeObjectURL(blob); blob = null; }
    var f = arq.files && arq.files[0];
    if (!f) return;
    blob = w.URL.createObjectURL(f);
    logo(blob);
    $('pmCoresLogo').checked = true;                    // logo nova: as cores saem dela
    var rm = $('pmRemoverLogo'); if (rm) rm.checked = false;
  });
  var rm = $('pmRemoverLogo'), logoOriginal = $('pmLogoImg').getAttribute('src') || '';
  if (rm) rm.addEventListener('change', function () {
    if (rm.checked) { arq.value = ''; logo(''); } else { logo(logoOriginal); }
  });

  /* Salvando: evita toque duplo enviar duas vezes. */
  var form = $('pmForm');
  form.addEventListener('submit', function () {
    var b = form.querySelector('button[type="submit"]');
    if (b) { b.disabled = true; b.style.opacity = '.6'; }
  });

  cores(); textos();
})(window, document);
