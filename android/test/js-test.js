/*
   Testa, num Chromium sem tela, o JavaScript que conversa com o app Android:
     - app/assets/vn-app.js (imprimir e compartilhar) e
     - public_html/assets/js/vn-download.js (baixar arquivo: entrega ao app quando existe window.VNApp).
   O app de verdade (window.VNApp) é trocado por um "dublê" que anota as chamadas.
   Rodar:  node android/test/js-test.js        (precisa do pacote playwright)
*/
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const RAIZ = path.resolve(__dirname, '..', '..');
const VN_APP_JS = fs.readFileSync(path.join(RAIZ, 'android/app/assets/vn-app.js'), 'utf8');
const VN_DOWNLOAD_JS = fs.readFileSync(path.join(RAIZ, 'public_html/assets/js/vn-download.js'), 'utf8');
const PDF = Buffer.from('%PDF-1.4\n% arquivo de teste\n');

let falhou = false;
const ok = (c, m) => { console.log((c ? 'OK    ' : 'FALHA ') + m); if (!c) falhou = true; };

const PAGINA = `<!doctype html><meta charset="utf-8"><title>t</title><body>
<a id="baixar" href="/orc.pdf" data-baixar data-nome="orcamento-12.pdf">Baixar</a>
<a id="html" href="/login.html" data-baixar data-nome="x.pdf">HTML</a>
<a id="compartilhar" href="/orc.pdf" data-compartilhar data-nome="orcamento-12.pdf">Compartilhar</a>
<script src="/vn-download.js"></script>`;

const servidor = http.createServer((req, res) => {
  if (req.url === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(PAGINA); }
  else if (req.url === '/vn-download.js') { res.writeHead(200, { 'Content-Type': 'application/javascript' }); res.end(VN_DOWNLOAD_JS); }
  else if (req.url === '/vn-app.js') { res.writeHead(200, { 'Content-Type': 'application/javascript' }); res.end(VN_APP_JS); }
  else if (req.url === '/orc.pdf') { res.writeHead(200, { 'Content-Type': 'application/pdf' }); res.end(PDF); }
  else if (req.url === '/login.html') { res.writeHead(200, { 'Content-Type': 'text/html' }); res.end('<html>login</html>'); }
  else { res.writeHead(404); res.end('nao'); }
});

const DUBLE = `
  window.__chamadas = [];
  window.VNApp = new Proxy({}, { get: function (_, nome) {
    if (nome === 'versao') return function () { return '2.0.0-teste'; };
    return function () { window.__chamadas.push({ nome: String(nome), args: Array.prototype.slice.call(arguments) }); };
  } });
`;

(async () => {
  await new Promise(r => servidor.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + servidor.address().port;
  const b = await chromium.launch();

  /* ---------------- dentro do app ---------------- */
  {
    const c = await b.newContext({ acceptDownloads: true });
    const p = await c.newPage();
    const erros = []; p.on('pageerror', e => erros.push(e.message));
    const dialogos = []; p.on('dialog', d => { dialogos.push(d.message()); d.dismiss(); });
    await p.addInitScript(DUBLE);
    await p.goto(base + '/');
    await p.addScriptTag({ url: base + '/vn-app.js' });
    const chamadas = () => p.evaluate(() => window.__chamadas.slice());

    ok(await p.evaluate(() => window.VN && VN.app === true && VN.appVersao === '2.0.0-teste'), 'app: VN.app e a versão chegam ao site');

    await p.evaluate(() => window.print());
    ok((await chamadas()).some(x => x.nome === 'imprimir'), 'app: window.print() chama a impressão do app');

    await p.evaluate(() => navigator.share({ title: 'Orçamento', text: 'Segue o orçamento', url: 'https://vnsystemltda.com/x' }));
    const t = (await chamadas()).find(x => x.nome === 'compartilharTexto');
    ok(t && t.args[0] === 'Orçamento' && t.args[1] === 'Segue o orçamento' && t.args[2] === 'https://vnsystemltda.com/x', 'app: navigator.share de texto/link chama o compartilhar do app');

    await p.evaluate(() => navigator.share({ files: [new File(['abc'], 'a.pdf', { type: 'application/pdf' })], title: 'Meu PDF' }));
    const f = (await chamadas()).find(x => x.nome === 'compartilharArquivo');
    ok(f && f.args[0] === Buffer.from('abc').toString('base64') && f.args[1] === 'a.pdf' && f.args[2] === 'application/pdf' && f.args[3] === 'Meu PDF', 'app: navigator.share com arquivo manda base64, nome, tipo e título');
    ok(await p.evaluate(() => navigator.canShare({ files: [] }) === true), 'app: navigator.canShare responde sim');

    const antes = (await chamadas()).length;
    await p.addScriptTag({ url: base + '/vn-app.js' });          // injetar de novo não pode duplicar nada
    await p.evaluate(() => window.print());
    ok((await chamadas()).length === antes + 1, 'app: injetar o script duas vezes não duplica as chamadas');

    // baixar: o arquivo vai para o app com o nome certo
    await p.click('#baixar');
    await p.waitForFunction(() => window.__chamadas.some(x => x.nome === 'salvarBase64'), null, { timeout: 5000 });
    const s = (await chamadas()).find(x => x.nome === 'salvarBase64');
    ok(s && s.args[0] === PDF.toString('base64') && s.args[1] === 'orcamento-12.pdf' && s.args[2] === 'application/pdf', 'app: baixar entrega o PDF ao app (base64, "orcamento-12.pdf", application/pdf)');
    ok(await p.evaluate(() => !document.querySelector('a[download]')), 'app: não cria link de download do navegador');

    // compartilhar pelo site (vn-download.js) usa o compartilhar do app
    await p.click('#compartilhar');
    await p.waitForFunction(() => window.__chamadas.filter(x => x.nome === 'compartilharArquivo').length >= 2, null, { timeout: 5000 });
    const cs = (await chamadas()).filter(x => x.nome === 'compartilharArquivo').pop();
    ok(cs && cs.args[0] === PDF.toString('base64') && cs.args[1] === 'orcamento-12.pdf', 'app: "compartilhar" do site abre o compartilhar do app com o PDF');

    // sessão vencida: a página de login não pode virar "PDF"
    const n = (await chamadas()).filter(x => x.nome === 'salvarBase64').length;
    await p.click('#html');
    await p.waitForTimeout(600);
    ok((await chamadas()).filter(x => x.nome === 'salvarBase64').length === n && dialogos.some(m => /sess/i.test(m)), 'app: resposta HTML (sessão vencida) avisa e não salva nada: "' + (dialogos[dialogos.length - 1] || '') + '"');
    ok(erros.length === 0, 'app: sem erros de JavaScript' + (erros.length ? ': ' + erros.join(' | ') : ''));
    await c.close();
  }

  /* ---------------- no navegador comum (sem app) ---------------- */
  {
    const c = await b.newContext({ acceptDownloads: true });
    const p = await c.newPage();
    const erros = []; p.on('pageerror', e => erros.push(e.message));
    await p.goto(base + '/');
    const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 8000 }), p.click('#baixar')]);
    ok(dl.suggestedFilename() === 'orcamento-12.pdf', 'navegador: baixar continua baixando com o nome certo (' + dl.suggestedFilename() + ')');
    ok(erros.length === 0, 'navegador: sem erros de JavaScript');
    await c.close();
  }

  await b.close();
  servidor.close();
  console.log(falhou ? '\nRESULTADO: HOUVE FALHAS' : '\nRESULTADO: tudo certo');
  process.exit(falhou ? 1 : 0);
})().catch(e => { console.error('ERRO NO TESTE', e); process.exit(2); });
