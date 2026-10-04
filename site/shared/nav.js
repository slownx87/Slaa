/*
 * Navegação do site, igual em todas as páginas. Cada página só chama
 * Nav.render("chaveDaPagina") dentro de um elemento com id="siteNav".
 *
 * No computador (telas >=760px) as 8 opções ficam numa coluna fixa à
 * esquerda, sempre visíveis — nenhum clique. No celular, a mesma lista
 * vira uma gaveta em tela cheia, atrás de um botão "Menu" bem visível
 * (nunca só um ícone). Quem está em qual página é mostrado de duas
 * formas independentes: destaque na lista + a linha "Você está em" que
 * fica sempre visível no cabeçalho do celular, mesmo com o menu fechado.
 */
(function (global) {
  "use strict";
  var PAGINAS = [
    { chave: "home", href: "index.html", label: "Início" },
    { chave: "custos", href: "custos.html", label: "Custos" },
    { chave: "materiais", href: "materiais.html", label: "Materiais" },
    { chave: "produtos", href: "produtos.html", label: "Produtos" },
    { chave: "lote", href: "lote.html", label: "Tabela em Lote" },
    { chave: "limites", href: "limites.html", label: "Limite dos Indicadores" },
    { chave: "servicos", href: "servicos.html", label: "Serviços" },
    { chave: "cursos", href: "cursos.html", label: "Cursos Online" }
  ];

  function ehDesktop() {
    return !!(global.matchMedia && global.matchMedia("(min-width: 760px)").matches);
  }

  function render(paginaAtual) {
    var el = document.getElementById("siteNav");
    if (!el) return;
    el.setAttribute("role", "navigation");
    el.setAttribute("aria-label", "Seções do aplicativo");

    var atual = null;
    for (var i = 0; i < PAGINAS.length; i++) { if (PAGINAS[i].chave === paginaAtual) { atual = PAGINAS[i]; break; } }
    var nomeAtual = atual ? atual.label : "";

    var itensHtml = PAGINAS.map(function (p) {
      var ehAtual = p.chave === paginaAtual;
      var marca = ehAtual ? '<span class="nav-check" aria-hidden="true">✓</span> ' : "";
      var rotuloExtra = ehAtual ? ' <span class="nav-atual-tag">(página atual)</span>' : "";
      return '<li><a href="' + p.href + '" class="nav-link' + (ehAtual ? " atual" : "") + '"' + (ehAtual ? ' aria-current="page"' : "") + ">" + marca + p.label + rotuloExtra + "</a></li>";
    }).join("");

    el.innerHTML =
      '<button type="button" id="navToggle" aria-expanded="false" aria-controls="navList">' +
        '<span aria-hidden="true">☰</span> Menu' +
      "</button>" +
      '<p id="navStatus" class="nav-status">Você está em: <b>' + nomeAtual + "</b></p>" +
      '<div id="navOverlay"></div>' +
      '<div id="navList">' +
        '<div class="nav-list-head">' +
          '<span class="nav-list-title">Menu — escolha uma página</span>' +
          '<button type="button" id="navClose">✕ Fechar</button>' +
        "</div>" +
        "<ul>" + itensHtml + "</ul>" +
      "</div>";

    ligarInteracao(el);
  }

  function ligarInteracao(el) {
    var toggle = el.querySelector("#navToggle");
    var fecharBtn = el.querySelector("#navClose");
    var lista = el.querySelector("#navList");
    var overlay = el.querySelector("#navOverlay");
    var aoTeclarRef = null;

    function aberta() { return lista.classList.contains("aberta"); }

    function abrir() {
      if (ehDesktop() || aberta()) return;
      lista.classList.add("aberta");
      overlay.classList.add("aberta");
      lista.setAttribute("role", "dialog");
      lista.setAttribute("aria-modal", "true");
      lista.setAttribute("aria-label", "Menu de navegação");
      toggle.setAttribute("aria-expanded", "true");
      document.body.style.overflow = "hidden";
      fecharBtn.focus();
      aoTeclarRef = function (e) { aoTeclar(e); };
      document.addEventListener("keydown", aoTeclarRef);
    }

    function fechar() {
      if (!aberta()) return;
      lista.classList.remove("aberta");
      overlay.classList.remove("aberta");
      lista.removeAttribute("role");
      lista.removeAttribute("aria-modal");
      lista.removeAttribute("aria-label");
      toggle.setAttribute("aria-expanded", "false");
      document.body.style.overflow = "";
      if (aoTeclarRef) { document.removeEventListener("keydown", aoTeclarRef); aoTeclarRef = null; }
      toggle.focus();
    }

    function aoTeclar(e) {
      if (e.key === "Escape") { fechar(); return; }
      if (e.key === "Tab") {
        var focaveis = lista.querySelectorAll("button, a[href]");
        if (!focaveis.length) return;
        var primeiro = focaveis[0], ultimo = focaveis[focaveis.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) { ultimo.focus(); e.preventDefault(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { primeiro.focus(); e.preventDefault(); }
      }
    }

    toggle.addEventListener("click", function () { aberta() ? fechar() : abrir(); });
    fecharBtn.addEventListener("click", fechar);
    overlay.addEventListener("click", fechar);
    lista.querySelectorAll("a.nav-link").forEach(function (a) {
      a.addEventListener("click", function () { if (!ehDesktop()) fechar(); });
    });
    global.addEventListener("resize", function () {
      if (ehDesktop() && aberta()) fechar();
    });
  }

  global.Nav = { render: render, PAGINAS: PAGINAS };
})(window);
