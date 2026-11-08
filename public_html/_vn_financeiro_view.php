<?php
/*
    VN System - Topo do Relatório Financeiro (orcamentos-dashboard.php): o que entra, o que sai e o saldo.
    Só desenha; os números vêm de fin_painel_dados() (_vn_financeiro_lib.php).
    Usa as classes do design system (vn-card, vn-panel, vn-table...) e as barras .vn-mchart / .vn-chart que a própria
    página do relatório já define.
*/

/* "R$ 1.234,56" ou, se negativo, "-R$ 1.234,56" (em vez de "R$ -1.234,56"). */
function fin_dinheiro(float $v): string
{
    return ($v < -0.004 ? '-' : '') . vn_money(abs($v));
}

/* Cartão clicável do resumo. $tom: '' | 'bom' | 'ruim' | 'alerta' (cor do valor). */
function fin_cartao(string $titulo, string $icone, string $valor, string $sub, string $href = '', string $tom = ''): string
{
    $cor = ['bom' => 'var(--ok-text)', 'ruim' => 'var(--danger-text)', 'alerta' => 'var(--danger-text)'][$tom] ?? '';
    $borda = $tom === 'alerta' ? ' style="border-color:var(--danger-line)"' : '';
    $tag = $href !== '' ? 'a' : 'article';
    return '<' . $tag . ' class="vn-card"' . ($href !== '' ? ' href="' . vn_h($href) . '"' : '') . $borda . '>'
        . '<div class="label"><span>' . vn_h($titulo) . '</span><span class="material-symbols-outlined" style="font-size:1.1rem" aria-hidden="true">' . vn_h($icone) . '</span></div>'
        . '<div class="value money-big"' . ($cor !== '' ? ' style="color:' . $cor . '"' : '') . '>' . vn_h($valor) . '</div>'
        . '<div class="sub">' . $sub . '</div></' . $tag . '>';
}

function fin_render_topo(array $f, string $mes): void
{
    $mesRot = substr($mes, 5, 2) . '/' . substr($mes, 0, 4);
    $rec = $f['receber'] ?? null;
    $pag = $f['pagar'] ?? null;
    ?>
<style>
    .vn-mchart-cols .col.entrada{background:#16a34a}
    .vn-mchart-cols .col.saida{background:#e11d48}
    .fin-badge-venc{font-weight:800}
    .fin-vazio-aviso{margin:0}
</style>
<?php if (!empty($f['erros'])): ?>
    <div class="vn-alert warn" role="status">Não consegui carregar: <?php echo vn_h(implode(', ', $f['erros'])); ?>. O resto do relatório continua abaixo.</div>
<?php endif; ?>

<section class="vn-panel" id="fin-resumo">
    <div class="vn-panel-head"><div><h3>Resumo geral</h3><small>O que entra e o que sai. "A receber" e "A pagar" são o que está em aberto hoje; recebido e pago são de <?php echo vn_h($mesRot); ?>.</small></div></div>
<?php if ($rec === null && $pag === null): ?>
    <div class="vn-empty">Não foi possível carregar o resumo agora. Tente de novo em instantes.</div>
<?php else:
    $aReceber = (float)($rec['a_receber'] ?? 0);
    $aPagar = (float)($pag['a_pagar'] ?? 0);
    $saldo = $aReceber - $aPagar;
    $recMes = (float)($rec['recebido_mes'] ?? 0);
    $pagoMes = (float)($pag['pago_mes']['total'] ?? 0);
    $resultado = $recMes - $pagoMes;
    $qRec = (int)($rec['qtd_recebido_mes'] ?? 0);
    $qPag = (int)($pag['pago_mes']['qtd'] ?? 0);
    $subReceber = (int)($rec['qtd_abertas'] ?? 0) . ' em aberto (parcelas e mensalidades)'
        . ((float)($rec['material'] ?? 0) > 0 ? '<br>+ ' . vn_h(vn_money($rec['material'])) . ' de material a aprovar' : '');
?>
    <div class="vn-grid vn-grid-4" style="margin:8px 0 12px">
        <?php echo fin_cartao('A receber', 'payments', vn_money($aReceber), $subReceber, 'cobrancas.php'); ?>
        <?php echo fin_cartao('A pagar', 'request_quote', vn_money($aPagar), (int)($pag['qtd_abertas'] ?? 0) . ' conta(s) em aberto', 'contas-pagar.php'); ?>
        <?php echo fin_cartao('Saldo previsto', 'attach_money', fin_dinheiro($saldo), 'A receber − a pagar', '', $saldo < 0 ? 'ruim' : 'bom'); ?>
        <?php echo fin_cartao('Resultado de ' . $mesRot, 'monitoring', fin_dinheiro($resultado), 'Recebido ' . vn_h(vn_money($recMes)) . ' − pago ' . vn_h(vn_money($pagoMes)), '', $resultado < 0 ? 'ruim' : 'bom'); ?>
    </div>
    <div class="vn-grid vn-grid-4" style="margin:0 0 4px">
        <?php echo fin_cartao('Vencido a receber', 'warning', vn_money($rec['vencidas'] ?? 0), (int)($rec['qtd_vencidas'] ?? 0) . ' em atraso', 'cobrancas.php', (float)($rec['vencidas'] ?? 0) > 0 ? 'alerta' : ''); ?>
        <?php echo fin_cartao('Vencido a pagar', 'warning', vn_money($pag['atrasado'] ?? 0), (int)($pag['qtd_atrasadas'] ?? 0) . ' conta(s) atrasada(s)', 'contas-pagar.php?ver=atrasadas', (float)($pag['atrasado'] ?? 0) > 0 ? 'alerta' : ''); ?>
        <?php echo fin_cartao('Recebido em ' . $mesRot, 'check_circle', vn_money($recMes), $qRec . ($qRec === 1 ? ' pagamento' : ' pagamentos'), 'cobrancas.php'); ?>
        <?php echo fin_cartao('Pago em ' . $mesRot, 'task_alt', vn_money($pagoMes), $qPag . ($qPag === 1 ? ' conta paga' : ' contas pagas'), 'contas-pagar.php?ver=pagas'); ?>
    </div>
<?php endif; ?>
</section>

<?php
    // ---- Entradas x saídas: 6 meses
    $serie = $f['serie'] ?? [];
    $maxSerie = 0.0;
    foreach ($serie as $s) $maxSerie = max($maxSerie, $s['entrada'], $s['saida']);
?>
<section class="vn-panel">
    <div class="vn-panel-head"><div><h3>Entradas × saídas por mês</h3><small>Recebido dos clientes (verde) e pago em contas (vermelho), pela data do pagamento, nos 6 meses até <?php echo vn_h($mesRot); ?>.</small></div></div>
    <?php if (!$serie || $maxSerie <= 0): ?>
        <div class="vn-empty">Ainda não há pagamentos nem recebimentos nesses meses para desenhar o gráfico.</div>
    <?php else: ?>
        <div class="vn-mchart-wrap">
            <div class="vn-mchart">
                <?php foreach ($serie as $comp => $s):
                    $hEnt = max(2, (int)round($s['entrada'] / $maxSerie * 100));
                    $hSai = max(2, (int)round($s['saida'] / $maxSerie * 100));
                ?>
                <div class="vn-mchart-mes">
                    <div class="vn-mchart-cols">
                        <div class="col entrada" style="height:<?php echo $hEnt; ?>%" title="Recebido: <?php echo vn_h(vn_money($s['entrada'])); ?>"></div>
                        <div class="col saida" style="height:<?php echo $hSai; ?>%" title="Pago: <?php echo vn_h(vn_money($s['saida'])); ?>"></div>
                    </div>
                    <small><?php echo vn_h(substr($comp, 5, 2) . '/' . substr($comp, 2, 2)); ?></small>
                    <span class="vals"><?php echo vn_money($s['entrada']); ?><br><?php echo vn_money($s['saida']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="vn-mchart-legend">
            <span><i style="background:#16a34a"></i>Recebido</span>
            <span><i style="background:#e11d48"></i>Pago</span>
        </div>
    <?php endif; ?>
</section>

<?php if ($pag !== null): ?>
<section class="vn-grid vn-grid-2">
    <article class="vn-panel">
        <div class="vn-panel-head"><div><h3>Próximas contas a pagar</h3><small>As mais urgentes primeiro (atrasadas no topo).</small></div><a class="vn-mini-btn" href="contas-pagar.php">Abrir A Pagar</a></div>
        <?php if (empty($pag['proximas'])): ?>
            <div class="vn-empty">Nenhuma conta em aberto. <a href="contas-pagar.php">Cadastrar uma conta</a>.</div>
        <?php else: $hoje = (string)$f['hoje']; ?>
            <table class="vn-table">
                <thead><tr><th>Vencimento</th><th>Conta</th><th class="num">Valor</th></tr></thead>
                <tbody>
                <?php foreach ($pag['proximas'] as $c):
                    $atras = (string)$c['vencimento'] < $hoje;
                    $parc = cp_rotulo_parcela($c['parcela_num'] !== null ? (int)$c['parcela_num'] : null, $c['parcela_total'] !== null ? (int)$c['parcela_total'] : null);
                ?>
                    <tr>
                        <td data-label="Vencimento"><span class="vn-row-title"<?php echo $atras ? ' style="color:var(--danger-text)"' : ''; ?>><?php echo vn_h(vn_date_br($c['vencimento'])); ?></span><span class="vn-row-sub"<?php echo $atras ? ' style="color:var(--danger-text);font-weight:800"' : ''; ?>><?php echo vn_h(cp_quando((string)$c['vencimento'], $hoje)); ?></span></td>
                        <td data-label="Conta"><span class="vn-row-title"><?php echo vn_h($c['descricao']); ?></span><span class="vn-row-sub"><?php echo vn_h(implode(' · ', array_filter([(string)($c['fornecedor'] ?? ''), (string)($c['categoria'] ?? ''), (string)$parc]))); ?></span></td>
                        <td data-label="Valor" class="num"><strong><?php echo vn_money($c['valor']); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>
    <article class="vn-panel">
        <div class="vn-panel-head"><div><h3>Pago por categoria em <?php echo vn_h($mesRot); ?></h3><small>Onde o dinheiro saiu neste mês.</small></div></div>
        <?php if (empty($pag['por_categoria'])): ?>
            <div class="vn-empty">Nenhuma conta paga em <?php echo vn_h($mesRot); ?>.</div>
        <?php else:
            $cores = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#e11d48', '#0891b2'];
            $maxCat = 0.0;
            foreach ($pag['por_categoria'] as $c) $maxCat = max($maxCat, $c['total']);
        ?>
            <div class="vn-chart">
                <?php foreach (array_slice($pag['por_categoria'], 0, 8) as $i => $c): $pct = $maxCat > 0 ? max(3, (int)round($c['total'] / $maxCat * 100)) : 0; ?>
                <div class="vn-chart-row">
                    <span class="vn-chart-label"><?php echo vn_h($c['categoria']); ?><small><?php echo (int)$c['qtd']; ?> conta(s)</small></span>
                    <div class="vn-chart-bar"><i style="width:<?php echo $pct; ?>%;background:<?php echo $cores[$i % 6]; ?>"></i></div>
                    <span class="vn-chart-val"><?php echo vn_money($c['total']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>
    <?php
}
