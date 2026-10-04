/*
 * Guarda o estado do app no navegador (localStorage). Usa a MESMA chave
 * da primeira versão do app (app/index.html) para não perder dados de
 * quem já cadastrou custos/materiais/produtos ali.
 */
(function (global) {
  "use strict";
  var STORAGE_KEY = "calculadora_preco_v1";

  function estadoInicial() {
    return {
      config: { faturamentoMensal: 0, custosFixos: [], custosVariaveis: [] },
      materiais: [],
      produtos: [],
      itensLote: [],
      servico: { salarioDesejado: 0, horasMes: 0, custosFixosReais: 0, custoVariavelPct: 0, margemFinalPct: 0 },
      cursoCPL: { custoPorClique: 0, conversaoCampanha: 0, taxaPctVenda: 0, taxaFixaVenda: 0, margemDesejada: 0 },
      cursoAud: { resultadoEsperado: 0, tamanhoAudiencia: 0, conversaoCampanha: 0, taxaPctVenda: 0, taxaFixaVenda: 0, custosFixos: 0 },
      limites: { precoVendaLimite: 0, precoCusto: 0, modo: "referencia", custoFixoPctManual: 0, custoVariavelPctManual: 0, margemPct: 0 }
    };
  }

  function carregar() {
    var state = estadoInicial();
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        // mescla "raso" por seção, pra não perder campos novos quando o
        // dado salvo é de uma versão anterior do app (só tinha config/materiais/produtos)
        Object.keys(state).forEach(function (k) {
          if (parsed[k] && typeof parsed[k] === "object" && !Array.isArray(parsed[k]) && !Array.isArray(state[k])) {
            state[k] = Object.assign({}, state[k], parsed[k]);
          } else if (parsed[k] !== undefined) {
            state[k] = parsed[k];
          }
        });
      }
    } catch (e) { /* localStorage indisponível (aba anônima, etc.) — segue em memória */ }
    return state;
  }
  function salvar(state) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) { /* ignora falha de gravação */ }
  }

  global.Store = { carregar: carregar, salvar: salvar, estadoInicial: estadoInicial };
})(window);
