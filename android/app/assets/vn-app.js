/* =========================================================================
   VN System — o que o site ganha dentro do aplicativo Android.
   O app injeta este script em cada página (window.VNApp é a ponte com o app).
     - window.print()      -> abre a impressão do Android (dá para salvar em PDF);
     - navigator.share()   -> folha de compartilhar do Android (texto, link e arquivo);
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
})();
