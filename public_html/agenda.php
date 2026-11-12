<?php
require_once __DIR__ . '/_vn_orcamento_layout.php';
$mes = vn_mes_atual(); $erro = '';
try { $lista = vn_orcamentos_listagem_v4($mes); }
catch (Throwable $e) { $erro = $e->getMessage(); $lista = []; }
$byDay = [];
foreach ($lista as $r) {
    $data = (string)($r['data_ref'] ?? '');
    if (!$data) continue;
    $d = substr($data, 0, 10);
    $byDay[$d][] = $r;
}
$base = DateTime::createFromFormat('Y-m-d', $mes . '-01') ?: new DateTime('first day of this month');
$days = (int)$base->format('t');
vn_admin_start('Agenda', 'agenda');
vn_alerts(null, $erro);
?>
<section class="vn-page-head"><div><span class="vn-kicker">Agenda</span><h2>Calendário de pedidos do mês.</h2><p>A agenda usa a data de criação/atualização dos orçamentos já salvos no banco.</p></div><a class="vn-btn vn-btn-primary" href="orcamento-novo.php">＋ Novo Pedido</a></section>
<form class="vn-filter" method="get"><?php echo vn_mes_picker_html($mes); ?></form>
<section class="vn-panel"><div class="vn-panel-head"><div><h3><?php echo vn_h($base->format('m/Y')); ?></h3><small><?php echo count($lista); ?> registros no mês</small></div></div><div class="vn-calendar">
<?php for($d=1;$d<=$days;$d++): $date=$base->format('Y-m-') . str_pad((string)$d,2,'0',STR_PAD_LEFT); $items=$byDay[$date] ?? []; ?>
    <div class="vn-day"><strong><?php echo $d; ?></strong><?php if(empty($items)): ?><small>Sem pedidos</small><?php else: foreach(array_slice($items,0,3) as $r): ?><small>• <?php echo vn_h($r['numero'] ?? ('ORC #' . $r['id'])); ?><?php if (($VN_CARGO ?? '') === 'admin'): ?> — <?php echo vn_money($r['valor'] ?? 0); ?><?php endif; ?></small><?php endforeach; if(count($items)>3): ?><small>+<?php echo count($items)-3; ?> registros</small><?php endif; endif; ?></div>
<?php endfor; ?>
</div></section>
<?php vn_admin_end('agenda'); ?>
