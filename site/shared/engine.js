/*
 * Motor de Precificação — funções puras de cálculo, sem nada de tela.
 * Todas as fórmulas foram conferidas, número por número, contra as
 * planilhas originais (pacote "Preço de Venda Completo").
 * Nenhuma função aqui toca o DOM ou o localStorage — são só contas.
 */
(function (global) {
  "use strict";

  /* ---------- formatação ---------- */
  function uid() { return Math.random().toString(36).slice(2, 10) + Date.now().toString(36); }
  function brl(n) { return "R$ " + (Number(n) || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function pct(n) { return ((Number(n) || 0) * 100).toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + "%"; }

  /* ---------- custos de base (alimentam todo o resto) ---------- */
  function calcularCustoFixo(config) {
    var total = (config.custosFixos || []).reduce(function (s, c) { return s + (Number(c.valor) || 0); }, 0);
    var faturamento = Number(config.faturamentoMensal) || 0;
    return { total: total, percentual: faturamento > 0 ? total / faturamento : 0 };
  }
  function calcularCustoVariavel(config) {
    var total = (config.custosVariaveis || []).reduce(function (s, c) { return s + ((Number(c.valor) || 0) / 100); }, 0);
    return { percentual: total };
  }

  /* ---------- materiais e fichas técnicas ---------- */
  function custoUnitarioMaterial(material) {
    var qtd = Number(material.qtd) || 0;
    return qtd > 0 ? (Number(material.valor) || 0) / qtd : 0;
  }
  function calcularFichaTecnica(ingredientes, materiais, unidadesPorLote) {
    var custoTotal = (ingredientes || []).reduce(function (s, ing) {
      var mat = materiais.find(function (m) { return m.id === ing.materialId; });
      if (!mat) return s;
      return s + (Number(ing.qtd) || 0) * custoUnitarioMaterial(mat);
    }, 0);
    var unidades = Number(unidadesPorLote) || 0;
    return { custoTotalReceita: custoTotal, custoUnitario: unidades > 0 ? custoTotal / unidades : 0 };
  }

  /* ---------- motor central: a MESMA fórmula usada por toda tela de precificação ---------- */
  // preco = custo * 1 / (1 - (custoFixoPct + custoVariavelPct + margemPct [+ taxas do módulo]))
  function motorPrecificacao(custo, custoFixoPct, custoVariavelPct, margemPct) {
    var soma = custoFixoPct + custoVariavelPct + margemPct;
    if (soma >= 1 || !(custo >= 0)) {
      return { erro: "A soma dos custos e da margem passa de 100%. Reduza a margem ou revise os custos." };
    }
    var markup = 1 / (1 - soma);
    return { markup: markup, precoVenda: custo * markup };
  }

  function montarDRE(precoVenda, custoFixoPct, custoVariavelPct, custoProduto, custosVariaveisDetalhados) {
    var itensVariaveis = (custosVariaveisDetalhados || []).map(function (c) {
      return { nome: c.nome, valor: ((Number(c.valor) || 0) / 100) * precoVenda };
    });
    var totalCustosVariaveis = custoVariavelPct * precoVenda;
    var totalCustosFixos = custoFixoPct * precoVenda;
    var totalCustos = totalCustosVariaveis + totalCustosFixos + custoProduto;
    var margemLucro = precoVenda - totalCustos;
    return {
      itensVariaveis: itensVariaveis, totalCustosVariaveis: totalCustosVariaveis,
      totalCustosFixos: totalCustosFixos, custoProduto: custoProduto,
      totalCustos: totalCustos, margemLucro: margemLucro
    };
  }

  // Tela "Produtos": preço a partir de uma ficha técnica (receita) OU de um
  // custo informado direto — as duas pontas da mesma fórmula central.
  function precificarProduto(produto, state) {
    var custoUnitario;
    if (produto.modoCusto === "manual") {
      custoUnitario = Number(produto.custoManual) || 0;
    } else {
      custoUnitario = calcularFichaTecnica(produto.ingredientes, state.materiais, produto.unidades).custoUnitario;
    }
    var cf = calcularCustoFixo(state.config);
    var cv = calcularCustoVariavel(state.config);
    var margem = (Number(produto.margem) || 0) / 100;
    var r = motorPrecificacao(custoUnitario, cf.percentual, cv.percentual, margem);
    if (r.erro) return { erro: r.erro, custoUnitario: custoUnitario };
    var dre = montarDRE(r.precoVenda, cf.percentual, cv.percentual, custoUnitario, state.config.custosVariaveis);
    return { custoUnitario: custoUnitario, markup: r.markup, precoVenda: r.precoVenda, dre: dre };
  }

  /* ---------- Tabela de Precificação em Lote ---------- */
  // modo "referencia": usa os % globais de Custos Fixos/Variáveis.
  // modo "aberto": cada linha define seu próprio % de custo fixo/variável.
  function precificarItemLote(item, state) {
    var custoFixoPct, custoVariavelPct;
    if (item.modo === "aberto") {
      custoFixoPct = (Number(item.custoFixoPct) || 0) / 100;
      custoVariavelPct = (Number(item.custoVariavelPct) || 0) / 100;
    } else {
      custoFixoPct = calcularCustoFixo(state.config).percentual;
      custoVariavelPct = calcularCustoVariavel(state.config).percentual;
    }
    var margemPct = (Number(item.margemPct) || 0) / 100;
    var valorCusto = Number(item.valorCusto) || 0;
    var r = motorPrecificacao(valorCusto, custoFixoPct, custoVariavelPct, margemPct);
    if (r.erro) return { erro: r.erro, custoFixoPct: custoFixoPct, custoVariavelPct: custoVariavelPct };
    return {
      custoFixoPct: custoFixoPct, custoVariavelPct: custoVariavelPct, markup: r.markup, valorVenda: r.precoVenda,
      ctFixoReais: custoFixoPct * r.precoVenda, ctVarReais: custoVariavelPct * r.precoVenda, margemReais: margemPct * r.precoVenda
    };
  }

  /* ---------- Limite dos Indicadores ---------- */
  // Dado um preço-limite, mostra até onde cada indicador pode ir SOZINHO
  // (mantendo os outros três como estão) sem passar do limite.
  // Convenção de toda a API: percentuais entram como o usuário digita
  // (ex.: 10 para 10%) e saem como fração (0.10) — use Engine.pct() pra exibir.
  function simularLimites(precoVendaLimiteR$, precoCustoR$, custoFixoPctDigitado, custoVariavelPctDigitado, margemPctDigitado) {
    var precoVendaLimite = Number(precoVendaLimiteR$) || 0;
    var precoCusto = Number(precoCustoR$) || 0;
    var custoFixoPct = (Number(custoFixoPctDigitado) || 0) / 100;
    var custoVariavelPct = (Number(custoVariavelPctDigitado) || 0) / 100;
    var margemPct = (Number(margemPctDigitado) || 0) / 100;
    var soma = custoFixoPct + custoVariavelPct + margemPct;
    var markupAtual = soma < 1 ? 1 / (1 - soma) : null;
    var precoCalculadoAtual = markupAtual != null ? precoCusto * markupAtual : null;
    if (!(precoVendaLimite > 0)) {
      return { markupAtual: markupAtual, precoCalculadoAtual: precoCalculadoAtual, maxPrecoCusto: null, maxCustoFixoPct: null, maxCustoVariavelPct: null, maxMargemPct: null };
    }
    var ratio = precoCusto / precoVendaLimite;
    function livre(a, b) { var v = 1 - ratio - a - b; return v < 0 ? null : v; }
    return {
      markupAtual: markupAtual,
      precoCalculadoAtual: precoCalculadoAtual,
      maxPrecoCusto: markupAtual != null ? precoVendaLimite / markupAtual : null,
      maxCustoFixoPct: livre(custoVariavelPct, margemPct),
      maxCustoVariavelPct: livre(custoFixoPct, margemPct),
      maxMargemPct: livre(custoFixoPct, custoVariavelPct)
    };
  }

  /* ---------- Precificação de Serviços (preço da hora) ---------- */
  function precificarServico(p) {
    var salario = Number(p.salarioDesejado) || 0;
    var horas = Number(p.horasMes) || 0;
    var custosFixos = Number(p.custosFixosReais) || 0;
    var cv = (Number(p.custoVariavelPct) || 0) / 100;
    var margem = (Number(p.margemFinalPct) || 0) / 100;
    var denom = 1 - cv - margem;
    if (!(horas > 0) || denom <= 0) return { erro: "Revise as horas do mês e a soma de custo variável + margem (tem que ser menor que 100%)." };
    var valorHora = (salario + custosFixos) / denom / horas;
    var custoVariavelH = cv * valorHora;
    var custoFixoH = custosFixos / horas;
    var totalCustosH = custoVariavelH + custoFixoH;
    var margemLucroH = valorHora - totalCustosH;
    var fator = horas;
    return {
      valorHora: valorHora,
      dreHora: { custoVariavel: custoVariavelH, custoFixo: custoFixoH, totalCustos: totalCustosH, margemLucro: margemLucroH },
      dreMes: { custoVariavel: custoVariavelH * fator, custoFixo: custoFixoH * fator, totalCustos: totalCustosH * fator, margemLucro: margemLucroH * fator, precoVendaMes: valorHora * fator }
    };
  }

  /* ---------- Curso Online — Custo por Lead/Clique ---------- */
  function precificarCursoCPL(p) {
    var custoClique = Number(p.custoPorClique) || 0;
    var conversao = (Number(p.conversaoCampanha) || 0) / 100;
    var taxaPct = (Number(p.taxaPctVenda) || 0) / 100;
    var taxaFixa = Number(p.taxaFixaVenda) || 0;
    var margem = (Number(p.margemDesejada) || 0) / 100;
    if (!(conversao > 0)) return { erro: "Informe a conversão da campanha (maior que zero)." };
    var custo100Cliques = custoClique * 100;
    var vendasEm100Cliques = 100 * conversao;
    var custoPorVenda = custo100Cliques / vendasEm100Cliques;
    var custoFinal = custoPorVenda + taxaFixa;
    var denom = 1 - taxaPct - margem;
    if (denom <= 0) return { erro: "A taxa da plataforma + a margem desejada passam de 100%." };
    var precoVenda = custoFinal / denom;
    var totalCustosVariaveis = taxaPct * precoVenda;
    var totalTaxaFixaVenda = taxaFixa;
    var investimentoTrafego = custoPorVenda;
    var totalCustos = totalCustosVariaveis + totalTaxaFixaVenda + investimentoTrafego;
    var margemLucro = precoVenda - totalCustos;
    return { custoPorVenda: custoPorVenda, precoVenda: precoVenda, dre: { totalCustosVariaveis: totalCustosVariaveis, totalTaxaFixaVenda: totalTaxaFixaVenda, investimentoTrafego: investimentoTrafego, totalCustos: totalCustos, margemLucro: margemLucro } };
  }

  /* ---------- Curso Online — Audiência ---------- */
  function precificarCursoAudiencia(p) {
    var resultadoEsperado = Number(p.resultadoEsperado) || 0;
    var tamanhoAudiencia = Number(p.tamanhoAudiencia) || 0;
    var conversao = (Number(p.conversaoCampanha) || 0) / 100;
    var taxaPct = (Number(p.taxaPctVenda) || 0) / 100;
    var taxaFixa = Number(p.taxaFixaVenda) || 0;
    var custosFixos = Number(p.custosFixos) || 0;
    var qtdVendas = tamanhoAudiencia * conversao;
    if (!(qtdVendas > 0)) return { erro: "Informe o tamanho da audiência e a conversão esperada (maiores que zero)." };
    if (taxaPct >= 1) return { erro: "A taxa % na venda não pode ser 100% ou mais." };
    var lucroPorVenda = resultadoEsperado / qtdVendas;
    var custoFixoPorVenda = custosFixos / qtdVendas;
    var custoTotalPorVenda = lucroPorVenda + custoFixoPorVenda + taxaFixa;
    var precoVendaPorVenda = custoTotalPorVenda / (1 - taxaPct);
    var dreVenda = {
      totalCustosVariaveis: taxaPct * precoVendaPorVenda,
      totalTaxaFixaVenda: taxaFixa,
      totalCustoFixo: custoFixoPorVenda,
      margemLucro: lucroPorVenda
    };
    dreVenda.totalCustos = dreVenda.totalCustosVariaveis + dreVenda.totalTaxaFixaVenda + dreVenda.totalCustoFixo;
    var precoVendaProjeto = precoVendaPorVenda * qtdVendas;
    var dreProjeto = {
      totalCustosVariaveis: dreVenda.totalCustosVariaveis * qtdVendas,
      totalTaxaFixaVenda: dreVenda.totalTaxaFixaVenda * qtdVendas,
      totalCustoFixo: custosFixos,
      margemLucro: resultadoEsperado
    };
    dreProjeto.totalCustos = dreProjeto.totalCustosVariaveis + dreProjeto.totalTaxaFixaVenda + dreProjeto.totalCustoFixo;
    return { qtdVendas: qtdVendas, precoVendaPorVenda: precoVendaPorVenda, dreVenda: dreVenda, precoVendaProjeto: precoVendaProjeto, dreProjeto: dreProjeto };
  }

  global.Engine = {
    uid: uid, brl: brl, pct: pct,
    calcularCustoFixo: calcularCustoFixo, calcularCustoVariavel: calcularCustoVariavel,
    custoUnitarioMaterial: custoUnitarioMaterial, calcularFichaTecnica: calcularFichaTecnica,
    motorPrecificacao: motorPrecificacao, montarDRE: montarDRE, precificarProduto: precificarProduto,
    precificarItemLote: precificarItemLote, simularLimites: simularLimites,
    precificarServico: precificarServico, precificarCursoCPL: precificarCursoCPL, precificarCursoAudiencia: precificarCursoAudiencia
  };
})(window);
