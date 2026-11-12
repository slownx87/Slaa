<?php
require_once __DIR__ . '/_vn_orcamento_layout.php';
require_once __DIR__ . '/_vn_contas_pagar_lib.php';
require_once __DIR__ . '/_vn_financeiro_lib.php';
require_once __DIR__ . '/_vn_financeiro_view.php';
$mes = vn_mes_atual();
$erro = ''; $ok = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acaoRel = (string)($_POST['acao'] ?? '');
        if ($acaoRel === 'excluir_saida') {
            $ok = vn_excluir_saida_estoque_v1((int)($_POST['saida_id'] ?? 0));
        } elseif ($acaoRel === 'editar_saida') {
            $ok = vn_editar_saida_estoque_v1($_POST);
        }
    }
} catch (Throwable $e) { $erro = $e->getMessage(); }
$responsaveis = [];
try { $responsaveis = vn_responsaveis_lista_v1(); } catch (Throwable $e) { $responsaveis = []; }
try { $orc = vn_orcamento_stats($mes); $lista = vn_orcamentos_listagem_v4($mes); }
catch (Throwable $e) { if(!$erro) $erro = $e->getMessage(); $orc = []; $lista = []; }
$estoqueValor = ['total_custo'=>0.0,'total_venda'=>0.0,'lucro_potencial'=>0.0,'total_qtd'=>0.0,'itens_distintos'=>0,'itens_com_estoque'=>0,'itens'=>[]];
try { $estoqueValor = vn_estoque_valor_total_v1(); } catch (Throwable $e) {}
$cobr = ['a_receber'=>0.0,'recebido'=>0.0,'vencidas'=>0.0,'qtd_vencidas'=>0,'qtd_abertas'=>0,'qtd_cobrancas'=>0];
try { $cobr = vn_cobrancas_resumo_v1(); } catch (Throwable $e) {}
/* Resumo geral do topo (a receber, a pagar, saldo, entradas x saídas). Cada parte falha sozinha. */
$fin = [];
try { $fin = fin_painel_dados($mes); } catch (Throwable $e) { $fin = ['erros' => ['resumo geral']]; }
if (!empty($fin['receber'])) {
    /* "Cobranças a receber" passa a incluir as mensalidades, como na central de Cobranças. */
    $cobr['a_receber'] = (float)$fin['receber']['a_receber'];
    $cobr['qtd_abertas'] = (int)$fin['receber']['qtd_abertas'];
    $cobr['vencidas'] = (float)$fin['receber']['vencidas'];
    $cobr['qtd_vencidas'] = (int)$fin['receber']['qtd_vencidas'];
}
$saidasResumo = ['lista'=>[], 'registros'=>0, 'total_qtd'=>0.0, 'por_item'=>[]];
try { $saidasResumo = vn_estoque_saidas_resumo_mes_v1($mes, 'material'); } catch (Throwable $e) {}
$saidaTop = !empty($saidasResumo['por_item']) ? array_key_first($saidasResumo['por_item']) : null;
$ferrResumo = ['lista'=>[], 'registros'=>0, 'total_qtd'=>0.0, 'por_item'=>[]];
try { $ferrResumo = vn_estoque_saidas_resumo_mes_v1($mes, 'ferramenta'); } catch (Throwable $e) {}
$ferrTop = !empty($ferrResumo['por_item']) ? array_key_first($ferrResumo['por_item']) : null;
/* Gráfico do topo (estilo do Resumo das Cobranças): orçado x lucro nos últimos 6 meses. */
$finChart = [];
try {
    $baseMes = DateTime::createFromFormat('Y-m-d', $mes . '-01') ?: new DateTime('first day of this month');
    for ($i = 5; $i >= 0; $i--) {
        $m = (clone $baseMes)->modify("-$i months")->format('Y-m');
        $s = ($m === $mes && isset($orc['valor_total'])) ? $orc : vn_orcamento_stats($m);
        $finChart[$m] = ['orcado' => (float)($s['valor_total'] ?? 0), 'lucro' => (float)($s['lucro_total'] ?? 0)];
    }
} catch (Throwable $e) { $finChart = []; }
$finChartMax = 0.0;
foreach ($finChart as $d) $finChartMax = max($finChartMax, $d['orcado'], $d['lucro']);

vn_admin_start('Relatórios Financeiros', 'relatorios');
vn_alerts($ok, $erro);
$ap = (int)($orc['aprovados'] ?? 0); $pe = (int)($orc['pendentes'] ?? 0); $re = (int)($orc['recusados'] ?? 0); $totalStatus = max(1, $ap + $pe + $re);
$chartCores = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#e11d48', '#0891b2'];
?>
<style>
    /* ---- Mesmo visual da aba Resumo da central de Cobranças ---- */
    .vn-mchart-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px}
    .vn-mchart{display:flex;gap:14px;align-items:flex-end;padding:10px 4px 0;min-height:190px}
    .vn-mchart-mes{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:76px}
    .vn-mchart-cols{display:flex;gap:5px;align-items:flex-end;height:140px;width:100%;justify-content:center}
    .vn-mchart-cols .col{width:26px;max-width:40%;border-radius:6px 6px 0 0;min-height:3px;position:relative}
    .vn-mchart-cols .col.orcado{background:#2563eb}
    .vn-mchart-cols .col.lucro{background:#16a34a}
    .vn-mchart-mes small{font-size:.72rem;font-weight:700;color:var(--muted,#667);white-space:nowrap}
    .vn-mchart-mes .vals{font-size:.66rem;color:var(--muted,#667);text-align:center;line-height:1.5;white-space:nowrap}
    .vn-mchart-legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:.78rem;color:var(--text-soft,#556);font-weight:700}
    .vn-mchart-legend i{display:inline-block;width:12px;height:12px;border-radius:4px;margin-right:5px;vertical-align:-1px}
    @media(max-width:600px){
        .vn-mchart{gap:10px;min-height:170px}
        .vn-mchart-cols{height:110px}
        .vn-mchart-cols .col{width:20px}
    }
    .vn-chart{display:flex;flex-direction:column;gap:10px;margin:6px 0 2px}
    .vn-chart-row{display:grid;grid-template-columns:minmax(120px,220px) 1fr auto;gap:10px;align-items:center}
    .vn-chart-label{font-size:.85rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .vn-chart-label small{display:block;font-weight:600;color:var(--muted,#667);font-size:.72rem}
    .vn-chart-bar{height:14px;border-radius:999px;background:var(--surface-3,#eef1f6);overflow:hidden}
    .vn-chart-bar i{display:block;height:100%;border-radius:999px}
    .vn-chart-val{font-size:.82rem;font-weight:800;white-space:nowrap}
    @media(max-width:600px){
        .vn-chart-row{grid-template-columns:1fr auto;grid-template-rows:auto auto}
        .vn-chart-bar{grid-column:1/-1;grid-row:2}
    }
</style>

<section class="vn-page-head"><div><span class="vn-kicker">Financeiro</span><h2>Relatórios financeiros do mês.</h2><p>Gráficos e valores no mesmo estilo do resumo de Cobranças: orçado × lucro, status dos pedidos, estoque parado, o que há para receber e as contas a pagar.</p></div></section>
<form class="vn-filter" method="get"><?php echo vn_mes_picker_html($mes); ?></form>

<?php fin_render_topo($fin, $mes); ?>

<section class="vn-panel">
    <div class="vn-panel-head"><div><h3>Orçado × lucro por mês</h3><small>Cada mês mostra o total orçado (azul) e o lucro previsto (verde) dos últimos 6 meses.</small></div></div>
    <?php if (empty($finChart) || $finChartMax <= 0): ?>
        <div class="vn-empty">Sem valores nos últimos meses para desenhar o gráfico.</div>
    <?php else: ?>
        <div class="vn-mchart-wrap">
            <div class="vn-mchart">
                <?php foreach ($finChart as $comp => $d):
                    $hOrc = $finChartMax > 0 ? max(2, round($d['orcado'] / $finChartMax * 100)) : 2;
                    $hLuc = $finChartMax > 0 ? max(2, round($d['lucro'] / $finChartMax * 100)) : 2;
                ?>
                <div class="vn-mchart-mes">
                    <div class="vn-mchart-cols">
                        <div class="col orcado" style="height:<?php echo $hOrc; ?>%" title="Orçado: <?php echo vn_h(vn_money($d['orcado'])); ?>"></div>
                        <div class="col lucro" style="height:<?php echo $hLuc; ?>%" title="Lucro: <?php echo vn_h(vn_money($d['lucro'])); ?>"></div>
                    </div>
                    <small><?php echo vn_h(substr($comp, 5, 2) . '/' . substr($comp, 2, 2)); ?></small>
                    <span class="vals"><?php echo vn_money($d['orcado']); ?><br><?php echo vn_money($d['lucro']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="vn-mchart-legend">
            <span><i style="background:#2563eb"></i>Total orçado</span>
            <span><i style="background:#16a34a"></i>Lucro previsto</span>
        </div>
    <?php endif; ?>
</section>

<section class="vn-grid vn-grid-4" style="margin-bottom:18px">
    <article class="vn-card"><div class="label"><span>Total orçado</span><span>Σ</span></div><div class="value money-big"><?php echo vn_money($orc['valor_total'] ?? 0); ?></div><div class="sub">Custo: <?php echo vn_money($orc['custo_total'] ?? 0); ?></div></article>
    <article class="vn-card"><div class="label"><span>Lucro previsto</span><span>R$</span></div><div class="value money-big"><?php echo vn_money($orc['lucro_total'] ?? 0); ?></div><div class="sub">Aprovado: <?php echo vn_money($orc['lucro_aprovado'] ?? 0); ?></div></article>
    <article class="vn-card"><div class="label"><span>Aprovados</span><span class="material-symbols-outlined" style="font-size:1.1rem">check_circle</span></div><div class="value"><?php echo $ap; ?></div><div class="sub"><?php echo vn_money($orc['valor_aprovado'] ?? 0); ?></div></article>
    <article class="vn-card"><div class="label"><span>Pendentes</span><span class="material-symbols-outlined" style="font-size:1.1rem">hourglass_top</span></div><div class="value"><?php echo $pe; ?></div><div class="sub"><?php echo vn_money($orc['valor_pendente'] ?? 0); ?></div></article>
</section>

<section class="vn-grid vn-grid-2">
    <article class="vn-panel">
        <div class="vn-panel-head"><div><h3>Status dos pedidos do mês</h3><small>Quantos aprovaram, quantos estão pendentes e quantos recusaram.</small></div></div>
        <div class="vn-chart">
            <?php foreach ([
                ['Aprovados', $ap, (float)($orc['valor_aprovado'] ?? 0), '#16a34a'],
                ['Pendentes', $pe, (float)($orc['valor_pendente'] ?? 0), '#d97706'],
                ['Recusados', $re, (float)($orc['valor_recusado'] ?? 0), '#e11d48'],
            ] as $b): $pct = max(3, round(($b[1] / $totalStatus) * 100)); ?>
            <div class="vn-chart-row">
                <span class="vn-chart-label"><?php echo vn_h($b[0]); ?><small><?php echo (int)$b[1]; ?> pedido(s) · <?php echo round(($b[1] / $totalStatus) * 100); ?>%</small></span>
                <div class="vn-chart-bar"><i style="width:<?php echo $pct; ?>%;background:<?php echo $b[3]; ?>"></i></div>
                <span class="vn-chart-val"><?php echo vn_money($b[2]); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </article>
    <article class="vn-panel">
        <div class="vn-panel-head"><div><h3>Maiores valores parados no estoque</h3><small>Itens que mais prendem dinheiro (pelo custo investido).</small></div><a class="vn-mini-btn" href="estoque.php">Inventário</a></div>
        <?php
        $topEstoque = [];
        foreach (($estoqueValor['itens'] ?? []) as $i) { if ((float)$i['quantidade'] > 0) $topEstoque[] = $i; }
        $topEstoque = array_slice($topEstoque, 0, 8);
        $topEstoqueMax = 0.0;
        foreach ($topEstoque as $i) $topEstoqueMax = max($topEstoqueMax, (float)$i['sub_custo']);
        ?>
        <?php if (empty($topEstoque)): ?>
            <div class="vn-empty">Nenhum item com estoque no momento.</div>
        <?php else: ?>
            <div class="vn-chart">
                <?php foreach ($topEstoque as $idx => $i): $pct = $topEstoqueMax > 0 ? max(3, round((float)$i['sub_custo'] / $topEstoqueMax * 100)) : 0; ?>
                <div class="vn-chart-row">
                    <span class="vn-chart-label"><?php echo vn_h($i['nome']); ?><small><?php echo number_format((float)$i['quantidade'], 2, ',', '.'); ?> <?php echo vn_h($i['unidade']); ?></small></span>
                    <div class="vn-chart-bar"><i style="width:<?php echo $pct; ?>%;background:<?php echo $chartCores[$idx % 6]; ?>"></i></div>
                    <span class="vn-chart-val"><?php echo vn_money($i['sub_custo']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
<section class="vn-grid vn-grid-2">
    <article class="vn-panel vn-fin-stock">
        <div class="vn-panel-head"><div><h3>Valor do estoque</h3><small>Tudo que está parado em peças, equipamentos e usados</small></div><a class="vn-mini-btn" href="estoque.php">Ver inventário</a></div>
        <div class="vn-grid vn-grid-2" style="margin:8px 0 6px">
            <article class="vn-card"><div class="label"><span>Custo investido</span><span>R$</span></div><div class="value money-big"><?php echo vn_money($estoqueValor['total_custo']); ?></div><div class="sub">Valor pago pelos itens em estoque</div></article>
            <article class="vn-card"><div class="label"><span>Valor de venda</span><span>Σ</span></div><div class="value money-big"><?php echo vn_money($estoqueValor['total_venda']); ?></div><div class="sub">Se vender tudo pelo preço de tabela</div></article>
        </div>
        <div class="vn-grid vn-grid-2" style="margin:0 0 4px">
            <article class="vn-card"><div class="label"><span>Lucro potencial</span><span>↗</span></div><div class="value"><?php echo vn_money($estoqueValor['lucro_potencial']); ?></div><div class="sub">Venda − custo</div></article>
            <article class="vn-card"><div class="label"><span>Itens em estoque</span><span>▣</span></div><div class="value"><?php echo (int)$estoqueValor['itens_com_estoque']; ?></div><div class="sub"><?php echo number_format((float)$estoqueValor['total_qtd'], 2, ',', '.'); ?> un · <?php echo (int)$estoqueValor['itens_distintos']; ?> cadastrados</div></article>
        </div>
    </article>
    <article class="vn-panel vn-fin-cobr">
        <div class="vn-panel-head"><div><h3>Cobranças a receber</h3><small>Parcelas e mensalidades em aberto dos clientes</small></div><a class="vn-mini-btn" href="cobrancas.php">Gerenciar</a></div>
        <div class="vn-grid vn-grid-2" style="margin:8px 0 6px">
            <article class="vn-card"><div class="label"><span>A receber</span><span>R$</span></div><div class="value money-big"><?php echo vn_money($cobr['a_receber']); ?></div><div class="sub"><?php echo (int)$cobr['qtd_abertas']; ?> em aberto (parcelas e mensalidades)</div></article>
            <article class="vn-card"><div class="label"><span>Já recebido</span><span class="material-symbols-outlined" style="font-size:1.1rem">check_circle</span></div><div class="value money-big"><?php echo vn_money($cobr['recebido']); ?></div><div class="sub">Parcelas pagas</div></article>
        </div>
        <div class="vn-grid vn-grid-2" style="margin:0 0 4px">
            <article class="vn-card"><div class="label"><span>Vencidas</span><span class="material-symbols-outlined" style="font-size:1.1rem">warning</span></div><div class="value"><?php echo vn_money($cobr['vencidas']); ?></div><div class="sub"><?php echo (int)$cobr['qtd_vencidas']; ?> atrasada(s)</div></article>
            <article class="vn-card"><div class="label"><span>Clientes</span><span class="material-symbols-outlined" style="font-size:1.1rem">payments</span></div><div class="value"><?php echo (int)$cobr['qtd_cobrancas']; ?></div><div class="sub">Cobranças cadastradas</div></article>
        </div>
        <?php if ((float)$cobr['vencidas'] > 0): ?>
            <p style="margin:10px 0 0;color:var(--danger-text,#c0392b);font-weight:700">Atenção: há <?php echo vn_money($cobr['vencidas']); ?> em parcelas vencidas. <a href="cobrancas.php">Cobrar agora</a>.</p>
        <?php endif; ?>
    </article>
</section>
<section class="vn-panel"><div class="vn-panel-head"><div><h3>Últimos registros</h3><small>Base do relatório</small></div></div><?php if(empty($lista)): ?><div class="vn-empty">Sem registros para este mês.</div><?php else: ?><table class="vn-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Status</th><th>Valor</th><th>Lucro</th></tr></thead><tbody><?php foreach(array_slice($lista,0,12) as $r): $kind=vn_status_badge_class((string)($r['kind']??'pendente')); ?><tr><td data-label="Número"><span class="vn-row-title"><?php echo vn_h($r['numero'] ?? ('ORC #' . $r['id'])); ?></span><span class="vn-row-sub"><?php echo vn_date_br($r['data_ref'] ?? ''); ?></span></td><td data-label="Cliente"><?php echo vn_h($r['cliente_nome'] ?: 'Sem cliente'); ?></td><td data-label="Status"><span class="vn-badge <?php echo $kind; ?>"><?php echo vn_h($r['status'] ?: $kind); ?></span></td><td><?php echo vn_money($r['valor'] ?? 0); ?></td><td data-label="Lucro"><?php echo vn_money($r['lucro_calc'] ?? 0); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<section class="vn-panel vn-stock-saida">
    <div class="vn-panel-head"><div><h3>Saídas de estoque — materiais do mês</h3><small>Baixas de peças, equipamentos e usados</small></div></div>
    <div class="vn-grid vn-grid-4" style="margin:8px 0 16px">
        <article class="vn-card"><div class="label"><span>Registros</span><span>↧</span></div><div class="value"><?php echo (int)$saidasResumo['registros']; ?></div><div class="sub">Movimentos no mês</div></article>
        <article class="vn-card"><div class="label"><span>Qtd total</span><span>Σ</span></div><div class="value"><?php echo number_format((float)$saidasResumo['total_qtd'], 2, ',', '.'); ?></div><div class="sub">Itens baixados</div></article>
        <article class="vn-card"><div class="label"><span>Itens distintos</span><span>▣</span></div><div class="value"><?php echo count($saidasResumo['por_item']); ?></div><div class="sub">Produtos diferentes</div></article>
        <article class="vn-card"><div class="label"><span>Mais retirado</span><span>★</span></div><div class="value" style="font-size:1.05rem;line-height:1.3"><?php echo $saidaTop ? vn_h($saidaTop) : '—'; ?></div><div class="sub"><?php echo $saidaTop ? number_format((float)$saidasResumo['por_item'][$saidaTop], 2, ',', '.') . ' un' : 'Sem saídas'; ?></div></article>
    </div>
    <?php if (empty($saidasResumo['lista'])): ?>
        <div class="vn-empty">Nenhuma saída de estoque registrada neste mês.</div>
    <?php else: ?>
        <table class="vn-table">
            <thead><tr><th>Data</th><th>Item</th><th>Qtd</th><th>Responsável</th><th>Motivo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($saidasResumo['lista'] as $s): ?>
                <tr>
                    <td data-label="Data"><?php echo vn_h(date('d/m/Y H:i', strtotime((string)($s['criado_em'] ?? 'now')))); ?></td>
                    <td data-label="Item"><?php echo vn_h($s['item_nome'] ?? ''); ?></td>
                    <td data-label="Qtd"><?php echo number_format((float)($s['quantidade'] ?? 0), 2, ',', '.'); ?></td>
                    <td data-label="Responsável"><?php echo vn_h(($s['responsavel'] ?? '') ?: '—'); ?></td>
                    <td data-label="Motivo"><?php echo vn_h(($s['motivo'] ?? '') ?: '—'); ?></td>
                    <td data-label="Ações"><?php vn_render_saida_acoes_v1($s, $responsaveis); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<section class="vn-panel vn-stock-saida">
    <div class="vn-panel-head"><div><h3>Saídas de ferramentas do mês</h3><small>Controle de ferramentas retiradas (sem valor)</small></div></div>
    <div class="vn-grid vn-grid-4" style="margin:8px 0 16px">
        <article class="vn-card"><div class="label"><span>Registros</span><span>🔧</span></div><div class="value"><?php echo (int)$ferrResumo['registros']; ?></div><div class="sub">Movimentos no mês</div></article>
        <article class="vn-card"><div class="label"><span>Qtd total</span><span>Σ</span></div><div class="value"><?php echo number_format((float)$ferrResumo['total_qtd'], 2, ',', '.'); ?></div><div class="sub">Ferramentas retiradas</div></article>
        <article class="vn-card"><div class="label"><span>Itens distintos</span><span>▣</span></div><div class="value"><?php echo count($ferrResumo['por_item']); ?></div><div class="sub">Ferramentas diferentes</div></article>
        <article class="vn-card"><div class="label"><span>Mais retirada</span><span>★</span></div><div class="value" style="font-size:1.05rem;line-height:1.3"><?php echo $ferrTop ? vn_h($ferrTop) : '—'; ?></div><div class="sub"><?php echo $ferrTop ? number_format((float)$ferrResumo['por_item'][$ferrTop], 2, ',', '.') . ' un' : 'Sem saídas'; ?></div></article>
    </div>
    <?php if (empty($ferrResumo['lista'])): ?>
        <div class="vn-empty">Nenhuma saída de ferramenta registrada neste mês.</div>
    <?php else: ?>
        <table class="vn-table">
            <thead><tr><th>Data</th><th>Ferramenta</th><th>Qtd</th><th>Responsável</th><th>Motivo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($ferrResumo['lista'] as $s): ?>
                <tr>
                    <td data-label="Data"><?php echo vn_h(date('d/m/Y H:i', strtotime((string)($s['criado_em'] ?? 'now')))); ?></td>
                    <td data-label="Ferramenta"><?php echo vn_h($s['item_nome'] ?? ''); ?></td>
                    <td data-label="Qtd"><?php echo number_format((float)($s['quantidade'] ?? 0), 2, ',', '.'); ?></td>
                    <td data-label="Responsável"><?php echo vn_h(($s['responsavel'] ?? '') ?: '—'); ?></td>
                    <td data-label="Motivo"><?php echo vn_h(($s['motivo'] ?? '') ?: '—'); ?></td>
                    <td data-label="Ações"><?php vn_render_saida_acoes_v1($s, $responsaveis); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php vn_admin_end('relatorios'); ?>
