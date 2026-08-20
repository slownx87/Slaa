/**
 * VN System - coletor de cadastros do portal Wi-Fi
 *
 * Servidorzinho que roda no SEU computador e recebe os cadastros direto do
 * portal, sem passar por Google nem por nuvem nenhuma. Grava em CSV (abre no
 * Excel) e em JSONL (uma linha por cadastro, bom para reprocessar depois).
 *
 * Nao usa nenhuma biblioteca externa -- so precisa do Node.js instalado.
 *
 *   node servidor.js
 *
 * Depois abra http://localhost:3000 para ver os cadastros.
 */

var http = require("http");
var fs   = require("fs");
var path = require("path");

/* ===================== CONFIGURACAO ===================== */
var PORTA  = 3000;
var PASTA  = __dirname;                              // onde salvar os arquivos
var CSV    = path.join(PASTA, "cadastros.csv");
var JSONL  = path.join(PASTA, "cadastros.jsonl");
var TOKEN  = "";        // opcional: exija ?token=xxx no POST. "" = sem token
/* ======================================================== */

var COLUNAS = [
  ["data",     "Data/hora"],
  ["nome",     "Nome completo"],
  ["email",    "E-mail"],
  ["bloco",    "Bloco"],
  ["ap",       "Apartamento"],
  ["fone",     "Celular"],
  ["local",    "AP / Local"],
  ["confere",  "Confere?"],
  ["mac",      "MAC"],
  ["ip",       "IP"],
  ["servidor", "Servidor"],
  ["roteador", "Roteador"]
];

/* ---------------- formatacao ---------------- */

function String(v){
  v = String(v || "").replace(/\D+/g, "");
  if (v.length !== 11) return v;
  return v.slice(0, 3) + "." + v.slice(3, 6) + "." + v.slice(6, 9) + "-" + v.slice(9);
}

function fmtFone(v){
  v = String(v || "").replace(/\D+/g, "");
  if (v.length === 11) return "(" + v.slice(0, 2) + ") " + v.slice(2, 7) + "-" + v.slice(7);
  if (v.length === 10) return "(" + v.slice(0, 2) + ") " + v.slice(2, 6) + "-" + v.slice(6);
  return v;
}

function fmtData(v){
  var d = v ? new Date(v) : new Date();
  if (isNaN(d.getTime())) d = new Date();
  function p(n){ return (n < 10 ? "0" : "") + n; }
  return p(d.getDate()) + "/" + p(d.getMonth() + 1) + "/" + d.getFullYear() +
         " " + p(d.getHours()) + ":" + p(d.getMinutes()) + ":" + p(d.getSeconds());
}

function valor(d, chave){
  if (chave === "data") return fmtData(d.data);
  if (chave === "email") return String(d.email || "");
  if (chave === "fone") return fmtFone(d.fone);
  if (chave === "confere") return d.confere ? d.confere : "ok";
  return String(d[chave] === undefined || d[chave] === null ? "" : d[chave]);
}

/* CSV com ; e BOM -- e o que o Excel em portugues abre certo com 2 cliques */
function celulaCsv(v){
  v = String(v === undefined || v === null ? "" : v);
  return '"' + v.replace(/"/g, '""') + '"';
}

function linhaCsv(d){
  return COLUNAS.map(function(c){ return celulaCsv(valor(d, c[0])); }).join(";") + "\r\n";
}

function garanteCabecalho(){
  if (fs.existsSync(CSV) && fs.statSync(CSV).size > 0) return;
  var cab = COLUNAS.map(function(c){ return celulaCsv(c[1]); }).join(";") + "\r\n";
  fs.writeFileSync(CSV, "﻿" + cab, "utf8");
}

function grava(d){
  garanteCabecalho();
  fs.appendFileSync(CSV, linhaCsv(d), "utf8");
  fs.appendFileSync(JSONL, JSON.stringify(d) + "\n", "utf8");
}

function leTudo(){
  if (!fs.existsSync(JSONL)) return [];
  return fs.readFileSync(JSONL, "utf8")
    .split("\n")
    .filter(function(l){ return l.trim().length > 0; })
    .map(function(l){ try { return JSON.parse(l); } catch (e) { return null; } })
    .filter(Boolean);
}

/* ---------------- painel ---------------- */

function escapaHtml(v){
  return String(v === undefined || v === null ? "" : v)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function painel(){
  var todos = leTudo().reverse();
  var hoje  = new Date().toDateString();
  var deHoje = todos.filter(function(d){
    return d.data && new Date(d.data).toDateString() === hoje;
  }).length;

  var chaves = {};
  todos.forEach(function(d){ if (d.email || d.nome) chaves[d.email || d.nome] = 1; });
  var pessoas = Object.keys(chaves).length;

  var linhas = todos.slice(0, 300).map(function(d){
    var alerta = d.confere && d.confere.length > 0;
    return "<tr>" + COLUNAS.map(function(c){
      var v = escapaHtml(valor(d, c[0]));
      if (c[0] === "confere"){
        v = alerta ? '<span class="tag ruim">' + v + "</span>"
                   : '<span class="tag boa">ok</span>';
      }
      return '<td data-label="' + escapaHtml(c[1]) + '">' + v + "</td>";
    }).join("") + "</tr>";
  }).join("");

  return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    "<title>VN System &mdash; Cadastros do Wi-Fi</title><style>" +
    ":root{--bg:#050814;--card:#101a2f;--line:#26334d;--text:#e8eefc;--muted:#aab5ca;--blue:#3b82f6;--blue2:#60a5fa}" +
    "*{box-sizing:border-box}body{margin:0;padding:26px 18px;background:var(--bg);color:var(--text);" +
    "font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.6}" +
    ".wrap{width:min(1180px,100%);margin:auto}" +
    "h1{margin:0 0 4px;font-size:24px;letter-spacing:-.03em}h1 b{color:var(--blue2)}" +
    "p.sub{margin:0 0 22px;color:var(--muted);font-size:14px}" +
    ".cards{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:20px}" +
    ".dash-card{background:var(--card);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:16px}" +
    ".dash-card span{color:var(--muted);font-size:13px}" +
    ".dash-card strong{display:block;font-size:30px;color:#fff;line-height:1.1}" +
    ".bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}" +
    "input#q{flex:1;min-width:200px;background:#060b18;border:1px solid var(--line);border-radius:12px;color:#fff;padding:12px 13px;font:inherit;outline:none}" +
    "input#q:focus{border-color:var(--blue2)}" +
    "a.btn{display:inline-flex;align-items:center;padding:12px 16px;border-radius:12px;font-weight:800;" +
    "font-size:14px;text-decoration:none;background:linear-gradient(180deg,var(--blue2),var(--blue));color:#fff}" +
    ".table-card{background:rgba(16,26,47,.86);border:1px solid rgba(255,255,255,.08);border-radius:20px;overflow:hidden}" +
    ".table-responsive{overflow-x:auto}table{width:100%;border-collapse:collapse}" +
    "th,td{text-align:left;padding:13px;border-bottom:1px solid rgba(255,255,255,.07);white-space:nowrap}" +
    "th{font-size:11px;color:#93a4c0;text-transform:uppercase;letter-spacing:.08em;background:rgba(255,255,255,.025)}" +
    "td{color:#e7eefc;font-size:13.5px}tr:hover td{background:rgba(255,255,255,.02)}" +
    ".tag{padding:4px 9px;border-radius:999px;font-size:11px;font-weight:900}" +
    ".tag.boa{background:rgba(34,197,94,.16);color:#bbf7d0}" +
    ".tag.ruim{background:rgba(245,158,11,.16);color:#fde68a}" +
    "td{vertical-align:middle}" +
    ".vazio{padding:44px;text-align:center;color:var(--muted)}" +
    "@media(max-width:720px){.cards{grid-template-columns:1fr}th,td{white-space:normal}}" +
    "</style></head><body><div class=wrap>" +
    "<h1><b>VN System</b> &mdash; cadastros do Wi-Fi</h1>" +
    "<p class=sub>Salvo em <code>" + escapaHtml(CSV) + "</code></p>" +
    '<div class=cards>' +
    "<div class=dash-card><span>Cadastros no total</span><strong>" + todos.length + "</strong></div>" +
    "<div class=dash-card><span>Hoje</span><strong>" + deHoje + "</strong></div>" +
    "<div class=dash-card><span>Pessoas diferentes</span><strong>" + pessoas + "</strong></div>" +
    "</div>" +
    '<div class=bar><input id=q placeholder="Filtrar por nome, e-mail, bloco, apartamento...">' +
    '<a class=btn href="/cadastros.csv">Baixar CSV</a></div>' +
    '<div class=table-card><div class=table-responsive><table><thead><tr>' +
    COLUNAS.map(function(c){ return "<th>" + escapaHtml(c[1]) + "</th>"; }).join("") +
    "</tr></thead><tbody id=tb>" + linhas + "</tbody></table></div>" +
    (todos.length === 0 ? '<div class=vazio>Nenhum cadastro ainda. Conecte um celular no Wi-Fi para testar.</div>' : "") +
    "</div></div><script>" +
    "document.getElementById('q').addEventListener('input',function(){" +
    "var t=this.value.toLowerCase();" +
    "var l=document.querySelectorAll('#tb tr');" +
    "for(var i=0;i<l.length;i++){l[i].style.display=l[i].textContent.toLowerCase().indexOf(t)===-1?'none':'';}" +
    "});setTimeout(function(){location.reload();},30000);" +
    "</script></body></html>";
}

/* ---------------- servidor ---------------- */

function cors(res){
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "POST, GET, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");
}

var servidor = http.createServer(function(req, res){
  cors(res);
  var url = req.url.split("?")[0];
  var query = req.url.indexOf("?") > -1 ? req.url.split("?")[1] : "";

  if (req.method === "OPTIONS"){ res.writeHead(204); res.end(); return; }

  if (req.method === "GET" && (url === "/" || url === "/index.html")){
    res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
    res.end(painel());
    return;
  }

  if (req.method === "GET" && url === "/cadastros.csv"){
    garanteCabecalho();
    res.writeHead(200, {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": 'attachment; filename="cadastros.csv"'
    });
    fs.createReadStream(CSV).pipe(res);
    return;
  }

  if (req.method === "POST"){
    if (TOKEN && query.indexOf("token=" + TOKEN) === -1){
      res.writeHead(403, { "Content-Type": "application/json" });
      res.end('{"ok":false,"erro":"token invalido"}');
      return;
    }

    var corpo = "";
    req.on("data", function(p){
      corpo += p;
      if (corpo.length > 20000) req.destroy();   // ninguem manda cadastro de 20KB
    });
    req.on("end", function(){
      var d;
      try { d = JSON.parse(corpo); }
      catch (e){
        res.writeHead(400, { "Content-Type": "application/json" });
        res.end('{"ok":false,"erro":"json invalido"}');
        return;
      }
      if (!d.data) d.data = new Date().toISOString();
      if (!d.ip) d.ip = (req.socket.remoteAddress || "").replace("::ffff:", "");

      try { grava(d); }
      catch (e){
        console.error("  !! erro ao gravar:", e.message);
        res.writeHead(500, { "Content-Type": "application/json" });
        res.end('{"ok":false}');
        return;
      }

      console.log("  + " + fmtData(d.data) + "  " + (d.nome || "?") +
                  "  |  Bloco " + (d.bloco || "?") + " ap " + (d.ap || "?") +
                  (d.confere ? "  [" + d.confere + "]" : ""));

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end('{"ok":true}');
    });
    return;
  }

  res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
  res.end("nao encontrado");
});

servidor.listen(PORTA, "0.0.0.0", function(){
  garanteCabecalho();
  console.log("");
  console.log("  VN System - coletor de cadastros do Wi-Fi");
  console.log("  ----------------------------------------");
  console.log("  Painel:   http://localhost:" + PORTA);
  console.log("  Recebe:   POST http://<ip-deste-pc>:" + PORTA + "/");
  console.log("  CSV:      " + CSV);
  console.log("");
  console.log("  Deixe esta janela aberta. Ctrl+C encerra.");
  console.log("");
});
