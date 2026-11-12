<?php
require_once __DIR__ . '/_vn_orcamento_layout.php';
$mes = vn_mes_atual();
$erro = '';
try { $orc = vn_orcamento_stats($mes); $lista = vn_orcamentos_listagem_v4($mes); }
catch (Throwable $e) { $erro = $e->getMessage(); $orc = []; $lista = []; }

$faltando = [];
try { $faltando = vn_estoque_baixo_v1(3); } catch (Throwable $e) {}
$comprasPend = [];
try { $comprasPend = vn_pecas_para_comprar_v1(true); } catch (Throwable $e) {}

$ap = (int)($orc['aprovados'] ?? 0);
$pe = (int)($orc['pendentes'] ?? 0);
$re = (int)($orc['recusados'] ?? 0);
$totalPedidos = $ap + $pe + $re;

/* Agenda de hoje/próximos: pedidos com validade futura mais próxima */
$proximos = [];
foreach ($lista as $r) {
    $d = substr((string)($r['data_ref'] ?? ''), 0, 10);
    if ($d !== '') $proximos[] = $r;
    if (count($proximos) >= 4) break;
}

vn_admin_start('Visão Geral', 'visao');
vn_alerts(null, $erro);

$hora = (int)date('H');
$saud = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
global $VN_USUARIO_NOME;
$primeiroNome = explode(' ', trim((string)($VN_USUARIO_NOME ?: 'Usuário')))[0];
?>
<section class="vn-page-head">
    <div class="vn-hello">
        <h2><?php echo vn_h($saud . ', ' . $primeiroNome); ?> 👋</h2>
        <p>Sua operação em <?php echo vn_h($orc['mes_label'] ?? date('m/Y')); ?> · <?php echo $pe; ?> pedido(s) aguardando aprovação.</p>
    </div>
    <form class="vn-filter" method="get"><?php echo vn_mes_picker_html($mes); ?></form>
</section>

<section class="vn-grid vn-grid-4">
    <article class="vn-card"><div class="label"><span>Pedidos no mês</span><span class="material-symbols-outlined" style="font-size:1.1rem">receipt_long</span></div><div class="value"><?php echo $totalPedidos; ?></div><div class="sub"><?php echo vn_money($orc['valor_total'] ?? 0); ?> orçados</div></article>
    <article class="vn-card"><div class="label"><span>Aguardando</span><span class="material-symbols-outlined" style="font-size:1.1rem">schedule</span></div><div class="value"><?php echo $pe; ?></div><div class="sub"><?php echo vn_money($orc['valor_pendente'] ?? 0); ?> pendentes</div></article>
    <article class="vn-card"><div class="label"><span>Aprovados</span><span class="material-symbols-outlined" style="font-size:1.1rem">task_alt</span></div><div class="value"><?php echo $ap; ?></div><div class="sub"><?php echo vn_money($orc['valor_aprovado'] ?? 0); ?> fechados</div></article>
    <article class="vn-card"><div class="label"><span>Lucro aprovado</span><span class="material-symbols-outlined" style="font-size:1.1rem">payments</span></div><div class="value money-big"><?php echo vn_money($orc['lucro_aprovado'] ?? 0); ?></div><div class="sub">Lucro previsto: <?php echo vn_money($orc['lucro_total'] ?? 0); ?></div></article>
</section>

<section>
    <div class="vn-items-title" style="margin-top:4px">Acesso rápido</div>
    <div class="vn-quick">
        <a class="primary" href="orcamento-novo.php"><span class="material-symbols-outlined">add_circle</span>Novo Pedido</a>
        <a href="estoque.php"><span class="material-symbols-outlined">inventory_2</span>Inventário</a>
        <a href="agenda.php"><span class="material-symbols-outlined">calendar_month</span>Calendário</a>
        <a href="compras.php"><span class="material-symbols-outlined">shopping_cart</span>Compras</a>
    </div>
</section>

<div class="vn-dash-cols">
    <div class="vn-dash-col">
        <section class="vn-panel">
            <div class="vn-panel-head"><div><h3>Últimos pedidos</h3><small>Movimentação do mês</small></div><a class="vn-mini-btn" href="pedidos-dashboard.php">Ver todos</a></div>
            <?php if (empty($lista)): ?><div class="vn-empty">Nenhum pedido neste mês ainda. Crie o primeiro!</div><?php else: ?>
            <table class="vn-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Status</th><th>Valor</th></tr></thead><tbody>
            <?php foreach (array_slice($lista, 0, 6) as $r): $kind = vn_status_badge_class((string)($r['kind'] ?? 'pendente')); ?>
                <tr>
                    <td data-label="Pedido"><span class="vn-row-title"><?php echo vn_h($r['numero'] ?? ('ORC #' . $r['id'])); ?></span><span class="vn-row-sub"><?php echo vn_date_br($r['data_ref'] ?? ''); ?></span></td>
                    <td data-label="Cliente"><?php echo vn_h($r['cliente_nome'] ?: 'Sem cliente'); ?></td>
                    <td data-label="Status"><span class="vn-badge <?php echo $kind; ?>"><?php echo vn_h($r['status'] ?: $kind); ?></span></td>
                    <td data-label="Valor"><strong><?php echo vn_money($r['valor'] ?? 0); ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </section>
    </div>

    <div class="vn-dash-col">
        <?php if (!empty($faltando)): ?>
        <div class="vn-alert-stock">
            <span class="material-symbols-outlined">warning</span>
            <div style="min-width:0">
                <strong>Alerta de estoque</strong>
                <p><?php echo count($faltando); ?> item(ns) abaixo do mínimo. Reponha antes de acabar.</p>
                <a class="vn-mini-btn vn-btn-danger" href="estoque-baixo.php">Verificar agora</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($comprasPend)): ?>
        <section class="vn-panel">
            <div class="vn-panel-head"><div><h3>Peças para comprar</h3><small><?php echo count($comprasPend); ?> pendente(s)</small></div><a class="vn-mini-btn" href="compras.php">Abrir</a></div>
            <?php foreach (array_slice($comprasPend, 0, 4) as $c): ?>
                <div class="vn-agenda-item">
                    <div class="vn-agenda-data"><strong><?php echo number_format((float)$c['quantidade'], 0, ',', '.'); ?></strong><small>un</small></div>
                    <div class="vn-agenda-info"><strong><?php echo vn_h($c['descricao']); ?></strong><small><?php echo vn_h($c['cliente_nome'] ?: 'Sem cliente'); ?> · <?php echo vn_h($c['numero'] ?? ''); ?></small></div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="vn-panel">
            <div class="vn-panel-head"><div><h3>Agenda</h3><small>Pedidos recentes por data</small></div><a class="vn-mini-btn" href="agenda.php">Calendário</a></div>
            <?php if (empty($proximos)): ?><div class="vn-empty">Sem registros no mês.</div><?php else: foreach ($proximos as $r): $d = substr((string)($r['data_ref'] ?? ''), 0, 10); $dt = $d ? DateTime::createFromFormat('Y-m-d', $d) : null; ?>
                <div class="vn-agenda-item">
                    <div class="vn-agenda-data"><strong><?php echo $dt ? $dt->format('d') : '--'; ?></strong><small><?php echo $dt ? ['','JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'][(int)$dt->format('n')] : ''; ?></small></div>
                    <div class="vn-agenda-info"><strong><?php echo vn_h($r['cliente_nome'] ?: ($r['numero'] ?? 'Pedido')); ?></strong><small><?php echo vn_h($r['numero'] ?? ''); ?> · <?php echo vn_money($r['valor'] ?? 0); ?> · <?php echo vn_h($r['status'] ?? ''); ?></small></div>
                </div>
            <?php endforeach; endif; ?>
        </section>
    </div>
</div>
<?php vn_admin_end('visao'); ?>
