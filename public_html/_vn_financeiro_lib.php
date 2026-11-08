<?php
/*
    VN System - Números do Relatório Financeiro (orcamentos-dashboard.php).

    Junta num lugar só o que entra e o que sai:
      - A RECEBER: parcelas das cobranças + mensalidades dos clientes (mesma conta da central de Cobranças)
        e o material de saídas de estoque que ainda não virou cobrança ("a aprovar");
      - A PAGAR:   contas do A Pagar (contas-pagar.php).

    Precisa que _vn_orcamento_bootstrap.php e _vn_contas_pagar_lib.php já estejam carregados.
    Tabela que ainda não existe (cliente sem mensalidades, por exemplo) simplesmente conta como zero.
*/

if (is_file(__DIR__ . '/_vn_cobrancas_lib.php')) {
    require_once __DIR__ . '/_vn_cobrancas_lib.php';   // vn_mens_gerar_mes()
}

/* Primeiro e último dia de um mês 'AAAA-MM'. */
function fin_mes_limites(string $mes): array
{
    $ini = $mes . '-01';
    return [$ini, date('Y-m-t', strtotime($ini))];
}

/*
   O que há para receber hoje + o que entrou no mês escolhido.
   Mesma regra da central de Cobranças: parcelas em aberto + mensalidades em aberto (de qualquer mês até o atual,
   para não esconder atraso). Devolve valores em R$ e quantidades.
*/
function fin_receber_resumo(string $hoje, string $mes): array
{
    $r = [
        'a_receber' => 0.0, 'qtd_abertas' => 0, 'vencidas' => 0.0, 'qtd_vencidas' => 0,
        'recebido_mes' => 0.0, 'qtd_recebido_mes' => 0, 'material' => 0.0, 'qtd_material' => 0,
        'a_receber_parcelas' => 0.0, 'a_receber_mensalidades' => 0.0,
    ];
    [$ini, $fim] = fin_mes_limites($mes);
    $mesReal = substr($hoje, 0, 7);

    if (vn_table_exists('vn_cobranca_parcelas')) {
        $p = vn_fetch_one(
            'SELECT
                COALESCE(SUM(CASE WHEN pago = 0 THEN valor ELSE 0 END), 0) AS aberto,
                COALESCE(SUM(CASE WHEN pago = 0 THEN 1 ELSE 0 END), 0) AS q_aberto,
                COALESCE(SUM(CASE WHEN pago = 0 AND vencimento IS NOT NULL AND vencimento < ? THEN valor ELSE 0 END), 0) AS venc,
                COALESCE(SUM(CASE WHEN pago = 0 AND vencimento IS NOT NULL AND vencimento < ? THEN 1 ELSE 0 END), 0) AS q_venc,
                COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN valor ELSE 0 END), 0) AS rec,
                COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN 1 ELSE 0 END), 0) AS q_rec
             FROM vn_cobranca_parcelas', [$hoje, $hoje, $ini, $fim, $ini, $fim]) ?: [];
        $r['a_receber_parcelas'] = (float)($p['aberto'] ?? 0);
        $r['a_receber'] += (float)($p['aberto'] ?? 0);
        $r['qtd_abertas'] += (int)($p['q_aberto'] ?? 0);
        $r['vencidas'] += (float)($p['venc'] ?? 0);
        $r['qtd_vencidas'] += (int)($p['q_venc'] ?? 0);
        $r['recebido_mes'] += (float)($p['rec'] ?? 0);
        $r['qtd_recebido_mes'] += (int)($p['q_rec'] ?? 0);
    }

    if (vn_table_exists('vn_mensalidade_pagamentos')) {
        // a central de Cobranças cria a mensalidade do mês ao abrir; aqui também, para os números fecharem sem precisar abrir lá antes
        if (function_exists('vn_mens_gerar_mes') && vn_table_exists('vn_mensalidades')) {
            try { vn_mens_gerar_mes($mesReal); } catch (Throwable $e) { error_log('VN System [financeiro]: ' . $e->getMessage()); }
        }
        $m = vn_fetch_one(
            'SELECT
                COALESCE(SUM(CASE WHEN pago = 0 AND competencia <= ? THEN valor ELSE 0 END), 0) AS aberto,
                COALESCE(SUM(CASE WHEN pago = 0 AND competencia <= ? THEN 1 ELSE 0 END), 0) AS q_aberto,
                COALESCE(SUM(CASE WHEN pago = 0 AND competencia <= ? AND vencimento IS NOT NULL AND vencimento < ? THEN valor ELSE 0 END), 0) AS venc,
                COALESCE(SUM(CASE WHEN pago = 0 AND competencia <= ? AND vencimento IS NOT NULL AND vencimento < ? THEN 1 ELSE 0 END), 0) AS q_venc,
                COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN valor ELSE 0 END), 0) AS rec,
                COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN 1 ELSE 0 END), 0) AS q_rec
             FROM vn_mensalidade_pagamentos', [$mesReal, $mesReal, $mesReal, $hoje, $mesReal, $hoje, $ini, $fim, $ini, $fim]) ?: [];
        $r['a_receber_mensalidades'] = (float)($m['aberto'] ?? 0);
        $r['a_receber'] += (float)($m['aberto'] ?? 0);
        $r['qtd_abertas'] += (int)($m['q_aberto'] ?? 0);
        $r['vencidas'] += (float)($m['venc'] ?? 0);
        $r['qtd_vencidas'] += (int)($m['q_venc'] ?? 0);
        $r['recebido_mes'] += (float)($m['rec'] ?? 0);
        $r['qtd_recebido_mes'] += (int)($m['q_rec'] ?? 0);
    }

    if (vn_table_exists('vn_saida_cobrancas')) {
        $v = vn_fetch_one('SELECT COALESCE(SUM(valor_total), 0) AS t, COUNT(*) AS q FROM vn_saida_cobrancas WHERE cobranca_id IS NULL') ?: [];
        $r['material'] = (float)($v['t'] ?? 0);
        $r['qtd_material'] = (int)($v['q'] ?? 0);
    }
    return $r;
}

/* Recebido em cada mês entre os dois (inclusive), parcelas + mensalidades pela data do pagamento: ['AAAA-MM' => R$]. */
function fin_recebido_por_mes(string $mesIni, string $mesFim): array
{
    [$ini] = fin_mes_limites($mesIni);
    [, $fim] = fin_mes_limites($mesFim);
    $out = [];
    foreach (['vn_cobranca_parcelas', 'vn_mensalidade_pagamentos'] as $tab) {
        if (!vn_table_exists($tab)) continue;
        foreach (vn_fetch_all("SELECT DATE_FORMAT(pago_em, '%Y-%m') AS m, SUM(valor) AS t FROM $tab WHERE pago = 1 AND pago_em >= ? AND pago_em <= ? GROUP BY m", [$ini, $fim]) as $x) {
            $out[(string)$x['m']] = ($out[(string)$x['m']] ?? 0.0) + (float)$x['t'];
        }
    }
    return $out;
}

/*
   Tudo que o topo do relatório precisa, para o mês $mes ('AAAA-MM').
   Cada parte falha sozinha (o resto da página continua aparecendo).
*/
function fin_painel_dados(string $mes): array
{
    $hoje = function_exists('vn_today_sp') ? vn_today_sp() : date('Y-m-d');
    $d = ['hoje' => $hoje, 'mes' => $mes, 'erros' => []];

    try { $d['receber'] = fin_receber_resumo($hoje, $mes); }
    catch (Throwable $e) { error_log('VN System [financeiro receber]: ' . $e->getMessage()); $d['receber'] = null; $d['erros'][] = 'a receber'; }

    try {
        cp_ensure_tables();
        $res = cp_resumo($hoje);
        $d['pagar'] = [
            'a_pagar' => (float)$res['v_abertas'], 'qtd_abertas' => (int)$res['q_abertas'],
            'atrasado' => (float)$res['v_atrasadas'], 'qtd_atrasadas' => (int)$res['q_atrasadas'],
            'semana' => (float)$res['v_semana'], 'qtd_semana' => (int)$res['q_semana'],
            'pago_mes' => cp_rel_pago_no_mes($mes),
            'por_categoria' => cp_rel_pago_por_categoria($mes),
            'proximas' => cp_rel_proximas(8),
        ];
    } catch (Throwable $e) { error_log('VN System [financeiro pagar]: ' . $e->getMessage()); $d['pagar'] = null; $d['erros'][] = 'a pagar'; }

    // últimos 6 meses (terminando no escolhido): entradas x saídas
    $meses = [];
    $base = DateTime::createFromFormat('!Y-m-d', $mes . '-01') ?: new DateTime('first day of this month');
    for ($i = 5; $i >= 0; $i--) $meses[] = (clone $base)->modify("-$i months")->format('Y-m');
    $d['serie'] = [];
    try {
        $ent = fin_recebido_por_mes($meses[0], $meses[5]);
        $sai = cp_rel_pago_por_mes($meses[0], $meses[5]);
        foreach ($meses as $m) $d['serie'][$m] = ['entrada' => (float)($ent[$m] ?? 0), 'saida' => (float)($sai[$m] ?? 0)];
    } catch (Throwable $e) { error_log('VN System [financeiro serie]: ' . $e->getMessage()); $d['erros'][] = 'entradas e saídas por mês'; }
    return $d;
}
