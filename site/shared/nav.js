/*
 * Barra de navegação, igual em todas as páginas. Cada página só chama
 * Nav.render("chaveDaPagina") dentro de um elemento com id="siteNav".
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

  function render(paginaAtual) {
    var el = document.getElementById("siteNav");
    if (!el) return;
    el.setAttribute("role", "navigation");
    el.setAttribute("aria-label", "Seções do aplicativo");
    el.innerHTML = PAGINAS.map(function (p) {
      var atual = p.chave === paginaAtual;
      return '<a href="' + p.href + '" class="nav-link' + (atual ? " atual" : "") + '"' + (atual ? ' aria-current="page"' : "") + ">" + p.label + "</a>";
    }).join("");
  }

  global.Nav = { render: render, PAGINAS: PAGINAS };
})(window);
