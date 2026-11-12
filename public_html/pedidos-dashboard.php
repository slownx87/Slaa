<?php
/*
    VN System - Pedidos (lista).
    Cartões com situação, cliente, referência, número, data e valor; busca em todos os pedidos; aba "Pedidos por status".
    Tocar num cartão abre a tela do pedido (pedido.php), onde ficam as ações (editar, duplicar, excluir, documento, WhatsApp...).
    Os pedidos do sistema antigo (tabela "orcamentos") continuam só para consulta, em "Pedidos do sistema antigo".
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
$ok = null; $erro = '';
if (isset($_GET['ok'])) $ok = (string)$_GET['ok'] === 'excluido' ? 'Pedido excluído.' : 'Feito.';

$q    = trim((string)($_GET['q'] ?? ''));
$st   = trim((string)($_GET['st'] ?? ''));
$mes  = (string)($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = '';
$aba  = (string)($_GET['aba'] ?? '') === 'status' ? 'status' : 'todos';
$antigo = isset($_GET['antigo']);
$pag  = max(1, (int)($_GET['pagina'] ?? 1));
$porPag = 20;

function pl_url(array $extra = []): string {
    $m = array_merge(['aba' => $_GET['aba'] ?? '', 'q' => $_GET['q'] ?? '', 'st' => $_GET['st'] ?? '', 'mes' => $_GET['mes'] ?? '', 'pagina' => $_GET['pagina'] ?? ''], $extra);
    foreach ($m as $k => $v) { if ($v === '' || $v === null || ($k === 'pagina' && (int)$v <= 1) || ($k === 'aba' && $v === 'todos')) unset($m[$k]); }
    return 'pedidos-dashboard.php' . ($m ? '?' . http_build_query($m) : '');
}

$lista = []; $total = 0; $contStatus = []; $meses = []; $legado = false;
try {
    vn_ensure_mobile_tables();
    $legado = vn_table_exists('orcamentos');

    if ($antigo) {
        // Sistema antigo: só consulta, por mês.
        $mesAntigo = $mes !== '' ? $mes : vn_mes_atual();
        foreach (vn_orcamentos_listagem_v4($mesAntigo) as $r) {
            if (($r['fonte'] ?? '') === 'vn_mobile_orcamentos') continue;
            $lista[] = $r;
        }
        $total = count($lista);
    } else {
        // Conta por situação ignorando maiúsculas/acentos ("pendente" e "Pendente" são a mesma); as fora da lista padrão ficam como vieram.
        $porChave = [];
        foreach (array_keys(vn_pedido_status_lista()) as $lbl) $porChave[vn_norm_key($lbl)] = $lbl;
        foreach (vn_fetch_all('SELECT status, COUNT(*) AS q FROM vn_mobile_orcamentos GROUP BY status') as $r) {
            $bruto = trim((string)($r['status'] ?? ''));
            $rotulo = $porChave[vn_norm_key($bruto)] ?? ($bruto !== '' ? $bruto : 'Pendente');
            $contStatus[$rotulo] = ($contStatus[$rotulo] ?? 0) + (int)$r['q'];
        }
        foreach (vn_fetch_all("SELECT DATE_FORMAT(criado_em, '%Y-%m') AS m FROM vn_mobile_orcamentos GROUP BY m ORDER BY m DESC LIMIT 36") as $r) {
            if (!empty($r['m'])) $meses[] = (string)$r['m'];
        }
        if ($aba === 'todos') {
            $where = []; $par = [];
            if ($st !== '') { $where[] = 'status = ?'; $par[] = $st; }
            if ($mes !== '') { $where[] = 'criado_em >= ? AND criado_em < ?'; $par[] = $mes . '-01 00:00:00'; $par[] = date('Y-m-01 00:00:00', strtotime($mes . '-01 +1 month')); }
            if ($q !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $c = '(cliente_nome LIKE ? OR numero LIKE ? OR cliente_telefone LIKE ? OR observacoes LIKE ? OR endereco LIKE ? OR equipamento LIKE ? OR defeito LIKE ?';
                $par = array_merge($par, [$like, $like, $like, $like, $like, $like, $like]);
                $num = ltrim(preg_replace('/\D.*$/', '', $q), '0');
                if ($num !== '' && ctype_digit($num)) { $c .= ' OR id = ?'; $par[] = (int)$num; }   // "174" ou "174-2026" acha o pedido n. 174
                $where[] = $c . ')';
            }
            $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $total = (int)(vn_fetch_one('SELECT COUNT(*) AS q FROM vn_mobile_orcamentos' . $w, $par)['q'] ?? 0);
            $paginas = max(1, (int)ceil($total / $porPag));
            $pag = min($pag, $paginas);
            $lista = vn_fetch_all('SELECT id, numero, cliente_nome, status, valor_total, equipamento, endereco, criado_em FROM vn_mobile_orcamentos' . $w
                . ' ORDER BY criado_em DESC, id DESC LIMIT ' . $porPag . ' OFFSET ' . (($pag - 1) * $porPag), $par);
        }
    }
} catch (Throwable $e) {
    $erro = vn_public_error($e, 'pedidos');
}
$paginas = isset($paginas) ? $paginas : 1;

/* situações na ordem do aplicativo, mais as que existirem nos dados e não estão na lista padrão */
$padrao = array_keys(vn_pedido_status_lista());
$extras = array_values(array_diff(array_keys($contStatus), $padrao, ['']));
$situacoes = array_merge($padrao, $extras);

$filtrando = ($st !== '' || $mes !== '');
vn_ui_shell_start('Pedidos', 'pedidos', ['css' => ['assets/css/vn-pedidos.css']]);
vn_alerts($ok, $erro);
?>
<div class="pl">
    <div class="pl-head-desk">
        <h2><?php echo $antigo ? 'Pedidos do sistema antigo' : 'Pedidos'; ?></h2>
        <a class="vn-btn vn-btn-primary" href="orcamento-novo.php"><span class="material-symbols-outlined" aria-hidden="true">add</span>Novo pedido</a>
    </div>

<?php if ($antigo): ?>
    <p style="color:var(--text-soft);font-weight:600;margin:0 0 14px">Pedidos feitos no sistema antigo: só consulta.
        <a href="pedidos-dashboard.php" style="font-weight:800">Voltar aos pedidos</a></p>
    <form method="get" class="pl-bar"><input type="hidden" name="antigo" value="1">
        <div style="flex:1"><?php echo vn_mes_picker_html($mes !== '' ? $mes : vn_mes_atual(), ['rotulo' => 'Mês']); ?></div>
    </form>
    <?php if (!$lista): ?>
        <div class="pl-vazio"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>Nenhum pedido do sistema antigo neste mês.</div>
    <?php else: ?>
    <div class="pl-lista">
        <?php foreach ($lista as $r): $tom = vn_pedido_status_tom((string)($r['status'] ?? '')); ?>
        <div class="pl-card" aria-label="Pedido do sistema antigo">
            <span class="pl-st"><i class="dot tom-<?php echo $tom; ?>"></i><?php echo vn_h((string)($r['status'] ?: 'Pendente')); ?></span>
            <strong class="pl-cli"><?php echo vn_h($r['cliente_nome'] ?: 'Sem cliente'); ?></strong>
            <span class="pl-num"><?php echo vn_h((string)($r['numero'] ?? ('ORC #' . $r['id']))); ?></span>
            <span class="pl-dt">Criado em <?php echo vn_h(vn_date_br($r['data_ref'] ?? '')); ?></span>
            <div class="pl-val"><span>Valor:</span><strong><?php echo vn_h(vn_money($r['valor'] ?? 0)); ?></strong></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php else: ?>
    <nav class="pl-tabs" aria-label="Visão dos pedidos">
        <a href="<?php echo vn_h(pl_url(['aba' => 'todos', 'st' => '', 'pagina' => ''])); ?>" class="<?php echo $aba === 'todos' ? 'on' : ''; ?>"<?php echo $aba === 'todos' ? ' aria-current="page"' : ''; ?>>Todos os pedidos</a>
        <a href="<?php echo vn_h(pl_url(['aba' => 'status', 'q' => '', 'st' => '', 'mes' => '', 'pagina' => ''])); ?>" class="<?php echo $aba === 'status' ? 'on' : ''; ?>"<?php echo $aba === 'status' ? ' aria-current="page"' : ''; ?>>Pedidos por status</a>
    </nav>

    <?php if ($aba === 'status'): ?>
        <div class="pl-sts">
            <?php foreach ($situacoes as $s): ?>
                <a href="<?php echo vn_h('pedidos-dashboard.php?st=' . rawurlencode($s)); ?>">
                    <i class="dot tom-<?php echo vn_pedido_status_tom($s); ?>"></i><span><?php echo vn_h($s); ?></span>
                    <span class="n"><?php echo (int)($contStatus[$s] ?? 0); ?></span><span class="material-symbols-outlined go" aria-hidden="true">chevron_right</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <form method="get" class="pl-bar" role="search">
            <?php if ($st !== ''): ?><input type="hidden" name="st" value="<?php echo vn_h($st); ?>"><?php endif; ?>
            <?php if ($mes !== ''): ?><input type="hidden" name="mes" value="<?php echo vn_h($mes); ?>"><?php endif; ?>
            <label class="pl-busca"><span class="material-symbols-outlined" aria-hidden="true">search</span><input type="search" name="q" value="<?php echo vn_h($q); ?>" placeholder="Buscar" aria-label="Buscar pedido por cliente, número, telefone ou equipamento" autocomplete="off"></label>
            <button type="button" class="pl-fbtn<?php echo $filtrando ? ' on' : ''; ?>" data-abrir="plFiltro" aria-label="Filtrar pedidos"><span class="material-symbols-outlined" aria-hidden="true">tune</span></button>
        </form>
        <?php if ($filtrando): ?>
        <div class="pl-chips">
            <?php if ($st !== ''): ?><span class="pl-chip"><i class="dot tom-<?php echo vn_pedido_status_tom($st); ?>"></i><?php echo vn_h($st); ?><a href="<?php echo vn_h(pl_url(['st' => '', 'pagina' => ''])); ?>" aria-label="Tirar o filtro de situação"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.1rem">close</span></a></span><?php endif; ?>
            <?php if ($mes !== ''): ?><span class="pl-chip"><?php echo vn_h(substr($mes, 5, 2) . '/' . substr($mes, 0, 4)); ?><a href="<?php echo vn_h(pl_url(['mes' => '', 'pagina' => ''])); ?>" aria-label="Tirar o filtro de mês"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.1rem">close</span></a></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$lista): ?>
            <div class="pl-vazio"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
                <?php echo ($q !== '' || $filtrando) ? 'Nenhum pedido encontrado com esses filtros.' : 'Nenhum pedido ainda. Toque em "novo pedido" para começar.'; ?></div>
        <?php else: ?>
        <div class="pl-lista">
            <?php foreach ($lista as $r):
                $tom = vn_pedido_status_tom((string)($r['status'] ?? ''));
                $ref = trim((string)($r['equipamento'] ?? '')) !== '' ? (string)$r['equipamento'] : mb_substr(trim((string)($r['endereco'] ?? '')), 0, 60);
            ?>
            <a class="pl-card" href="pedido.php?id=<?php echo (int)$r['id']; ?>">
                <span class="pl-st"><i class="dot tom-<?php echo $tom; ?>"></i><?php echo vn_h(vn_pedido_status_label((string)($r['status'] ?? ''))); ?></span>
                <strong class="pl-cli"><?php echo vn_h($r['cliente_nome'] ?: 'Sem cliente'); ?></strong>
                <?php if ($ref !== ''): ?><span class="pl-ref"><?php echo vn_h($ref); ?></span><?php endif; ?>
                <span class="pl-num">Pedido n. <?php echo vn_h(vn_pedido_numero($r)); ?></span>
                <span class="pl-dt">Criado em <?php echo vn_h(vn_date_br(substr((string)($r['criado_em'] ?? ''), 0, 10))); ?></span>
                <div class="pl-val"><span>Valor:</span><strong><?php echo vn_h(vn_money($r['valor_total'] ?? 0)); ?></strong></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($paginas > 1): ?>
        <nav class="pl-pag" aria-label="Páginas">
            <?php if ($pag > 1): ?><a href="<?php echo vn_h(pl_url(['pagina' => $pag - 1])); ?>">← Mais novos</a><?php else: ?><span></span><?php endif; ?>
            <span>Página <?php echo $pag; ?> de <?php echo $paginas; ?> · <?php echo $total; ?> pedidos</span>
            <?php if ($pag < $paginas): ?><a href="<?php echo vn_h(pl_url(['pagina' => $pag + 1])); ?>">Mais antigos →</a><?php else: ?><span></span><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($legado): ?>
        <p style="text-align:center;margin:26px 0 0"><a href="pedidos-dashboard.php?antigo=1" style="color:var(--text-soft);font-weight:700">Pedidos do sistema antigo (só consulta)</a></p>
    <?php endif; ?>

    <a class="pl-novo" href="orcamento-novo.php"><span class="material-symbols-outlined" aria-hidden="true">add</span>novo pedido</a>

    <!-- Filtro: situação e mês -->
    <dialog class="pl-sheet" id="plFiltro" aria-labelledby="plFiltroT"><div class="pl-sheet-in">
        <form method="get">
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><?php endif; ?>
            <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button><h3 id="plFiltroT">Filtrar pedidos</h3><span></span></div>
            <div class="pl-sheet-b">
                <label class="pl-lab" for="plFSt">Situação</label>
                <select class="pl-in" id="plFSt" name="st"><option value="">Todas</option>
                    <?php foreach ($situacoes as $s): ?><option value="<?php echo vn_h($s); ?>"<?php echo $st === $s ? ' selected' : ''; ?>><?php echo vn_h($s); ?> (<?php echo (int)($contStatus[$s] ?? 0); ?>)</option><?php endforeach; ?>
                </select>
                <label class="pl-lab" for="plFMes">Mês</label>
                <select class="pl-in" id="plFMes" name="mes"><option value="">Todos os meses</option>
                    <?php foreach ($meses as $m): ?><option value="<?php echo vn_h($m); ?>"<?php echo $mes === $m ? ' selected' : ''; ?>><?php echo vn_h(substr($m, 5, 2) . '/' . substr($m, 0, 4)); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="pl-sheet-f"><a class="pl-btn sec" href="<?php echo vn_h(pl_url(['st' => '', 'mes' => '', 'pagina' => ''])); ?>">limpar</a><button type="submit" class="pl-btn">aplicar</button></div>
        </form>
    </div></dialog>
<?php endif; ?>
</div>
<script>
document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-abrir]'), f = e.target.closest('[data-fechar]');
    if (a) { var d = document.getElementById(a.getAttribute('data-abrir')); if (d && d.showModal) d.showModal(); }
    if (f) { var p = f.closest('dialog'); if (p) p.close(); }
    if (e.target.matches && e.target.matches('dialog.pl-sheet')) e.target.close();
});
</script>
<?php vn_ui_shell_end('pedidos'); ?>
