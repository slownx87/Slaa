/* =========================================================================
   VN System — o que o site ganha dentro do aplicativo Android.
   O app injeta este script em cada página (window.VNApp é a ponte com o app).
     - window.print()      -> abre a impressão do Android (dá para salvar em PDF);
     - navigator.share()   -> folha de compartilhar do Android (texto, link e arquivo);
     - <a data-vn-janela>  -> o link do próprio site abre numa janela por cima da página ("nova aba"), sem perder o que está aberto;
     - VN.app = true       -> o site sabe que está rodando no app.
   Baixar PDF/arquivos: o site chama VNApp.salvarBase64() (vn-download.js) ou o app trata sozinho (blob:).
   ========================================================================= */
(function () {
  'use strict';
  var A = window.VNApp;
  if (!A || window.__vnAppPronto) return;
  window.__vnAppPronto = true;

  var VN = window.VN = window.VN || {};
  VN.app = true;
  try { VN.appVersao = A.versao(); } catch (e) { VN.appVersao = ''; }

  function emBase64(blob) {
    return new Promise(function (ok, erro) {
      var r = new FileReader();
      r.onload = function () { var s = String(r.result || ''), i = s.indexOf(','); ok(i >= 0 ? s.slice(i + 1) : ''); };
      r.onerror = function () { erro(r.error || new Error('Não foi possível ler o arquivo.')); };
      r.readAsDataURL(blob);
    });
  }

  function definir(obj, nome, valor) {
    try { Object.defineProperty(obj, nome, { value: valor, configurable: true, writable: true }); }
    catch (e) { try { obj[nome] = valor; } catch (e2) { /* sem jeito */ } }
  }

  /* imprimir */
  window.print = function () { try { A.imprimir(); } catch (e) { /* nada */ } };

  /* compartilhar */
  var compartilhar = function (dados) {
    dados = dados || {};
    try {
      var arquivos = dados.files;
      if (arquivos && arquivos.length) {
        var f = arquivos[0];
        return emBase64(f).then(function (b64) {
          A.compartilharArquivo(b64, f.name || 'arquivo', f.type || 'application/octet-stream', dados.title || dados.text || '');
        });
      }
      A.compartilharTexto(dados.title || '', dados.text || '', dados.url || '');
      return Promise.resolve();
    } catch (e) {
      return Promise.reject(e);
    }
  };
  definir(window.navigator, 'share', compartilhar);
  definir(window.navigator, 'canShare', function (dados) { return !!dados; });

  /* Janela por cima ("nova aba" do navegador).
     No navegador, um link com target="_blank" abre outra aba e a página de origem continua lá. No app não existem abas:
     o link levaria a pessoa para outra tela e o que estava aberto (ex.: o orçamento sendo montado) se perderia.
     Links do próprio site marcados com data-vn-janela abrem numa janela por cima da página; "Fechar" ou o botão Voltar
     do app voltam para a página exatamente como estava. */
  var janela = null;

  function fecharJanela() {
    var j = janela;
    janela = null;
    if (!j) return;
    try { if (j.open) j.close(); } catch (e) { /* nada */ }
    try { if (j.parentNode) j.parentNode.removeChild(j); } catch (e) { /* nada */ }
  }

  function abrirJanela(url, titulo) {
    if (typeof HTMLDialogElement !== 'function' || !document.body) return false;   // WebView muito antigo: segue o link normal
    fecharJanela();
    var fonte = 'system-ui,-apple-system,Roboto,sans-serif';
    var d = document.createElement('dialog');
    d.className = 'vn-app-janela';
    d.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;max-width:none;max-height:none;margin:0;padding:0;border:0;background:#fff;overflow:hidden';
    var barra = document.createElement('div');
    barra.style.cssText = 'display:flex;align-items:center;gap:10px;box-sizing:border-box;height:48px;padding:0 12px;background:#0c1226;color:#fff;font:600 15px/1 ' + fonte;
    var t = document.createElement('span');
    t.style.cssText = 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
    t.textContent = titulo || 'VN System';
    var x = document.createElement('button');
    x.type = 'button';
    x.setAttribute('aria-label', 'Fechar janela');
    x.textContent = 'Fechar';
    x.style.cssText = 'flex:none;border:0;border-radius:8px;padding:10px 14px;background:#fff;color:#0c1226;font:600 14px/1 ' + fonte;
    x.addEventListener('click', fecharJanela);
    barra.appendChild(t);
    barra.appendChild(x);
    var f = document.createElement('iframe');
    f.setAttribute('title', titulo || 'VN System');
    f.style.cssText = 'display:block;border:0;width:100%;height:calc(100% - 48px);background:#fff';
    f.src = url;
    d.appendChild(barra);
    d.appendChild(f);
    d.addEventListener('close', function () { if (janela === d) fecharJanela(); });   // o Voltar do app fecha o dialog por aqui
    document.body.appendChild(d);
    try { d.showModal(); } catch (e) { if (d.parentNode) d.parentNode.removeChild(d); return false; }
    janela = d;
    return true;
  }

  VN.janela = abrirJanela;
  VN.fecharJanela = fecharJanela;

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || (e.button && e.button !== 0)) return;
    var alvo = e.target;
    var a = alvo && alvo.closest ? alvo.closest('a[data-vn-janela]') : null;
    if (!a || !a.getAttribute('href')) return;
    var u;
    try { u = new URL(a.href, location.href); } catch (er) { return; }
    if (u.origin !== location.origin) return;                       // só páginas do próprio site
    if (abrirJanela(u.href, a.getAttribute('data-vn-janela') || (a.textContent || '').trim())) e.preventDefault();
  }, true);
})();
