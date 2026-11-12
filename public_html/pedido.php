<?php
/*
    VN System - Tela do pedido (consulta e ações), no formato do aplicativo de referência.
    Abas: pedido (resumo), agenda (compromissos) e financeiro (recebimentos, custos e resultado).
    Menu: Editar, Duplicar, Excluir, enviar por WhatsApp, copiar link, gerar OS/cobrança e baixa de materiais.
    "Gerar documento" escolhe o título (Proposta, Fatura, Laudo...) e abre o PDF do pedido.
    Edição completa fica em orcamento-novo.php?editar=ID.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
$erro = null; $ok = null;
vn_ensure_mobile_tables();
$id = (int)($_GET['id'] ?? $_POST['id_orcamento'] ?? 0);
$o = $id > 0 ? vn_orcamento_mobile_get_v4($id) : null;
if (!$o) { header('Location: pedidos-dashboard.php'); exit; }
$aba = (string)($_POST['aba'] ?? $_GET['aba'] ?? 'pedido');
if (!in_array($aba, ['pedido', 'agenda', 'financeiro'], true)) $aba = 'pedido';

function pv_volta(int $id, string $aba, string $msg = ''): void {
    if ($msg !== '') $_SESSION['pv_flash'] = $msg;
    header('Location: pedido.php?id=' . $id . '&aba=' . $aba);
    exit;
}
if (!empty($_SESSION['pv_flash'])) { $ok = (string)$_SESSION['pv_flash']; unset($_SESSION['pv_flash']); }

$statusLista = vn_pedido_status_lista();
$meiosLista = vn_pedido_meios_lista();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string)($_POST['acao'] ?? '');
        $hoje = vn_today_sp();

        if ($acao === 'status') {
            $novo = trim((string)($_POST['status'] ?? ''));
            $atual = (string)($o['status'] ?? '');
            if (!isset($statusLista[$novo]) && $novo !== $atual) throw new Exception('Situação inválida.');
            vn_exec('UPDATE vn_mobile_orcamentos SET status = ?, atualizado_em = ? WHERE id = ?', [$novo, vn_now_sp(), $id]);
            pv_volta($id, $aba, 'Situação do pedido: ' . $novo . '.');
        }
        if ($acao === 'pagamento_add') {
            $valor = vn_money_db($_POST['valor'] ?? '0');
            if ($valor <= 0) throw new Exception('Informe o valor recebido.');
            $forma = trim((string)($_POST['forma'] ?? ''));
            if ($forma !== '' && !in_array($forma, array_merge($meiosLista, ['Outro']), true)) $forma = '';
            vn_exec('INSERT INTO vn_pedido_pagamentos (orcamento_id, valor, data, forma, obs) VALUES (?, ?, ?, ?, ?)',
                [$id, $valor, vn_date_db_or_null($_POST['data'] ?? '') ?: $hoje, $forma, mb_substr(vn_clean($_POST['obs'] ?? ''), 0, 255)]);
            pv_volta($id, 'financeiro', 'Recebimento de ' . vn_money($valor) . ' lançado.');
        }
        if ($acao === 'pagamento_del') {
            vn_exec('DELETE FROM vn_pedido_pagamentos WHERE id = ? AND orcamento_id = ?', [(int)($_POST['item_id'] ?? 0), $id]);
            pv_volta($id, 'financeiro', 'Recebimento removido.');
        }
        if ($acao === 'custo_add') {
            $desc = mb_substr(vn_clean($_POST['descricao'] ?? ''), 0, 190);
            $valor = vn_money_db($_POST['valor'] ?? '0');
            if ($desc === '') throw new Exception('Descreva o custo.');
            if ($valor <= 0) throw new Exception('Informe o valor do custo.');
            vn_exec('INSERT INTO vn_pedido_custos (orcamento_id, descricao, valor, data) VALUES (?, ?, ?, ?)', [$id, $desc, $valor, vn_date_db_or_null($_POST['data'] ?? '') ?: $hoje]);
            pv_volta($id, 'financeiro', 'Custo de ' . vn_money($valor) . ' lançado.');
        }
        if ($acao === 'custo_del') {
            vn_exec('DELETE FROM vn_pedido_custos WHERE id = ? AND orcamento_id = ?', [(int)($_POST['item_id'] ?? 0), $id]);
            pv_volta($id, 'financeiro', 'Custo removido.');
        }
        if ($acao === 'compromisso_add') {
            $titulo = mb_substr(vn_clean($_POST['titulo'] ?? ''), 0, 190);
            $data = vn_date_db_or_null($_POST['data'] ?? '');
            $hora = (string)($_POST['hora'] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = null;
            if ($titulo === '') throw new Exception('Dê um título ao compromisso.');
            if (!$data) throw new Exception('Escolha a data do compromisso.');
            vn_exec("INSERT INTO vn_pedido_compromissos (orcamento_id, titulo, data, hora, obs, origem) VALUES (?, ?, ?, ?, ?, 'agenda')", [$id, $titulo, $data, $hora, mb_substr(vn_clean($_POST['obs'] ?? ''), 0, 255)]);
            pv_volta($id, 'agenda', 'Compromisso marcado.');
        }
        if ($acao === 'compromisso_feito') {
            vn_exec('UPDATE vn_pedido_compromissos SET feito = IF(feito = 1, 0, 1) WHERE id = ? AND orcamento_id = ?', [(int)($_POST['item_id'] ?? 0), $id]);
            pv_volta($id, 'agenda');
        }
        if ($acao === 'compromisso_del') {
            vn_exec('DELETE FROM vn_pedido_compromissos WHERE id = ? AND orcamento_id = ?', [(int)($_POST['item_id'] ?? 0), $id]);
            pv_volta($id, 'agenda', 'Compromisso removido.');
        }
        if ($acao === 'duplicar') {
            $novoId = vn_duplicar_orcamento_mobile($id);
            $_SESSION['pv_flash'] = 'Pedido duplicado. Ajuste o que for preciso e salve.';
            header('Location: orcamento-novo.php?editar=' . $novoId);
            exit;
        }
        if ($acao === 'excluir') {
            vn_excluir_orcamento_mobile_v4($id);
            header('Location: pedidos-dashboard.php?ok=excluido');
            exit;
        }
        if ($acao === 'baixar_materiais') { vn_orc_baixa_setup(); pv_volta($id, $aba, vn_baixar_materiais_orcamento_v1($id, (string)($VN_USUARIO_NOME ?? ''))); }
        if ($acao === 'estornar_materiais') { vn_orc_baixa_setup(); pv_volta($id, $aba, vn_estornar_materiais_orcamento_v1($id)); }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$o = vn_orcamento_mobile_get_v4($id) ?: $o;
$total = (float)($o['valor_total'] ?? 0);
$fin = vn_pedido_financeiro($id, $total);
$status = vn_pedido_status_label((string)($o['status'] ?? ''));
$kind = vn_orc_status_kind($status);
$tom = vn_pedido_status_tom($status);
$numero = vn_pedido_numero($o);
$dataPedido = vn_date_br(substr((string)($o['criado_em'] ?? ''), 0, 10));
$compromissos = vn_fetch_all('SELECT * FROM vn_pedido_compromissos WHERE orcamento_id = ? ORDER BY data ASC, hora ASC, id ASC', [$id]);

/* serviços x peças (mesma separação da tela de edição) */
$servicos = []; $pecas = []; $pecasSerr = []; $somaServ = 0.0; $somaPeca = 0.0; $somaSerr = 0.0;
$catPedido = vn_categoria_valida($o['categoria'] ?? 'cftv');
foreach ($o['itens'] ?? [] as $it) {
    $t = (float)($it['total'] ?? 0);
    if (in_array((string)($it['tipo'] ?? ''), ['servico', 'mao_obra'], true)) { $servicos[] = $it; $somaServ += $t; }
    elseif ((string)($it['linha'] ?? 'cftv') === 'serralheria') { $pecasSerr[] = $it; $somaSerr += $t; }
    else { $pecas[] = $it; $somaPeca += $t; }
}
$somaServ += (float)($o['visita_valor'] ?? 0) + (float)($o['mao_obra_valor'] ?? 0);
$desconto = (float)($o['desconto'] ?? 0);

$jaBaixado = false;
try { vn_orc_baixa_setup(); $jaBaixado = !empty(vn_orc_baixados_ids()[$id]); } catch (Throwable $e) {}
$linkPub = vn_base_url() . '/orcamento-pdf-publico.php?id=' . $id . '&t=' . vn_orc_public_token($id);
$msgWa = 'Olá' . (($o['cliente_nome'] ?? '') !== '' ? ', ' . $o['cliente_nome'] : '') . '! Segue o orçamento ' . $numero . ' da VN System: ' . $linkPub;
$mostrarPontoFin = $fin['a_receber'] > 0 && $kind !== 'recusado';

function pv_ico(string $n, string $extra = ''): string { return '<span class="material-symbols-outlined"' . $extra . ' aria-hidden="true">' . $n . '</span>'; }
function pv_kv(string $rotulo, string $valor, bool $pre = false): string {
    $valor = trim($valor);
    return $valor === '' ? '' : '<div class="pv-kv"><span>' . vn_h($rotulo) . '</span><p' . ($pre ? ' class="pre"' : '') . '>' . vn_h($valor) . '</p></div>';
}

vn_ui_shell_start('Pedido ' . $numero, 'pedidos', [
    'css' => ['assets/css/vn-pedidos.css'],
    'head_extra' => '<style>@media(max-width:900px){.vn-top,.vn-tabbar{display:none!important}.vn-content-wrap{padding:0 14px!important}}</style>',
]);
vn_alerts($ok, $erro);
?>
<div class="pv">
    <div class="pv-bar">
        <a class="pl-x" href="pedidos-dashboard.php" aria-label="Voltar para os pedidos"><?php echo pv_ico('arrow_back'); ?></a>
        <div class="tit"><strong>Pedido n. <?php echo vn_h($numero); ?></strong><span><?php echo vn_h($dataPedido); ?></span></div>
        <div class="pv-menu-wrap">
            <button type="button" class="pl-x" data-menu aria-haspopup="true" aria-expanded="false" aria-label="Mais ações do pedido"><?php echo pv_ico('more_vert'); ?></button>
            <div class="pv-menu" role="menu" hidden>
                <a role="menuitem" href="orcamento-novo.php?editar=<?php echo $id; ?>"><?php echo pv_ico('edit'); ?>Editar</a>
                <form method="post" data-confirm="Duplicar este pedido? Será criada uma cópia como Pendente, sem fotos, recebimentos nem agenda." data-confirm-ok="Duplicar"><input type="hidden" name="acao" value="duplicar"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><button role="menuitem" type="submit"><?php echo pv_ico('content_copy'); ?>Duplicar</button></form>
                <form method="post" data-confirm="Excluir o pedido n. <?php echo vn_h($numero); ?>? Os recebimentos, custos e compromissos dele também serão apagados." data-danger data-confirm-ok="Excluir"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><button role="menuitem" type="submit" class="bad"><?php echo pv_ico('delete'); ?>Excluir</button></form>
                <div class="sep"></div>
                <a role="menuitem" target="_blank" rel="noopener" href="<?php echo vn_h(vn_wa_link($o['cliente_telefone'] ?? '', $msgWa)); ?>"><?php echo pv_ico('chat'); ?>Enviar por WhatsApp</a>
                <button role="menuitem" type="button" data-copy="<?php echo vn_h($linkPub); ?>"><?php echo pv_ico('link'); ?>Copiar link do orçamento</button>
                <?php if ($kind === 'aprovado'): ?>
                    <a role="menuitem" href="nova-os.php?orc=<?php echo $id; ?>"><?php echo pv_ico('note_add'); ?>Gerar OS</a>
                    <a role="menuitem" href="cobrancas.php?orc=<?php echo $id; ?>"><?php echo pv_ico('payments'); ?>Cobrar</a>
                <?php endif; ?>
                <?php if ($jaBaixado): ?>
                    <form method="post" data-confirm="Desfazer a baixa e devolver os materiais ao estoque?" data-danger data-confirm-ok="Desfazer baixa"><input type="hidden" name="acao" value="estornar_materiais"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><button role="menuitem" type="submit"><?php echo pv_ico('history'); ?>Desfazer baixa do estoque</button></form>
                <?php else: ?>
                    <form method="post" data-confirm="Dar baixa no estoque dos materiais deste pedido?" data-confirm-ok="Dar baixa"><input type="hidden" name="acao" value="baixar_materiais"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><button role="menuitem" type="submit"><?php echo pv_ico('inventory_2'); ?>Dar baixa nos materiais</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="pv-tabs" role="tablist" aria-label="Seções do pedido">
        <button type="button" role="tab" data-aba="pedido" aria-selected="<?php echo $aba === 'pedido' ? 'true' : 'false'; ?>"><?php echo pv_ico('assignment'); ?>pedido</button>
        <button type="button" role="tab" data-aba="agenda" aria-selected="<?php echo $aba === 'agenda' ? 'true' : 'false'; ?>"><?php echo pv_ico('calendar_month'); ?>agenda</button>
        <button type="button" role="tab" data-aba="financeiro" aria-selected="<?php echo $aba === 'financeiro' ? 'true' : 'false'; ?>"><span class="ico"><?php echo pv_ico('paid'); ?><?php if ($mostrarPontoFin): ?><i class="ponto" title="Falta receber"></i><?php endif; ?></span>financeiro</button>
    </nav>

    <form method="post" class="pv-status" data-quando="pedido financeiro">
        <input type="hidden" name="acao" value="status"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="aba" value="<?php echo vn_h($aba); ?>" data-aba-atual>
        <i class="dot tom-<?php echo $tom; ?>"></i>
        <select name="status" aria-label="Situação do pedido" onchange="this.form.submit()">
            <?php $opts = array_keys($statusLista); if (!in_array($status, $opts, true)) $opts[] = $status;
            foreach ($opts as $so): ?><option<?php echo $so === $status ? ' selected' : ''; ?>><?php echo vn_h($so); ?></option><?php endforeach; ?>
        </select>
        <?php echo pv_ico('expand_more'); ?>
    </form>

    <!-- ===================== ABA: PEDIDO ===================== -->
    <section data-painel="pedido" <?php echo $aba === 'pedido' ? '' : 'hidden'; ?>>
        <button type="button" class="pv-linha" data-abrir="pvCliente"><span class="t">Cliente</span><span class="v"><?php echo vn_h($o['cliente_nome'] ?: 'Sem cliente'); ?></span><?php echo pv_ico('chevron_right', ' class="go"'); ?></button>
        <div class="pv-linha"><span class="t">Responsável</span><span class="v"><?php echo vn_h((string)($o['criado_por_nome'] ?: '—')); ?></span></div>
        <div class="pv-linha"><span class="t">Tipo do orçamento</span><span class="v"><span class="pl-cat <?php echo $catPedido; ?>" style="margin-left:0"><?php echo vn_h(vn_categoria_rotulo($catPedido)); ?></span></span></div>
        <hr class="pv-hr">

        <section class="pv-sec">
            <button type="button" class="pv-sec-h" data-secao aria-expanded="true"><h2>Pedido</h2><span class="chev"><?php echo pv_ico('keyboard_arrow_up'); ?></span></button>
            <div class="pv-sec-b">
                <button type="button" class="pv-lin2" data-abrir="pvServicos"><span class="t">Serviços</span><span class="r"><?php echo vn_h(vn_money($somaServ)); ?></span><?php echo pv_ico('chevron_right', ' class="go"'); ?></button>
                <?php if ($pecas || !$pecasSerr || $catPedido === 'cftv'): ?><button type="button" class="pv-lin2" data-abrir="pvPecas"><span class="t">Peças</span><span class="r"><?php echo vn_h(vn_money($somaPeca)); ?></span><?php echo pv_ico('chevron_right', ' class="go"'); ?></button><?php endif; ?>
                <?php if ($pecasSerr || $catPedido === 'serralheria'): ?><button type="button" class="pv-lin2" data-abrir="pvSerralheria"><span class="t">Peças de serralheria</span><span class="r"><?php echo vn_h(vn_money($somaSerr)); ?></span><?php echo pv_ico('chevron_right', ' class="go"'); ?></button><?php endif; ?>
                <?php if ($desconto > 0): ?><div class="pv-lin2"><span class="t">Desconto</span><span class="r desc">-<?php echo vn_h(vn_money($desconto)); ?></span></div><?php endif; ?>
            </div>
        </section>
        <hr class="pv-hr">

        <?php
        $det = pv_kv('Validade do orçamento', $o['validade'] ? vn_date_br($o['validade']) : '')
            . pv_kv('Prazo de execução', (string)($o['prazo_execucao'] ?? ''))
            . pv_kv('Equipamento', (string)($o['equipamento'] ?? ''))
            . pv_kv('Defeito', (string)($o['defeito'] ?? ''), true)
            . pv_kv('Condições de pagamento', (string)($o['condicoes_pagamento'] ?? ''), true)
            . pv_kv('Meios de pagamento', (string)($o['meios_pagamento'] ?? ''))
            . pv_kv('Garantia', (string)($o['garantia'] ?? ''), true)
            . pv_kv('Observações', (string)($o['observacoes'] ?? ''), true)
            . pv_kv('Relatório', (string)($o['relatorio'] ?? ''), true)
            . pv_kv('Anotações (só você vê)', (string)($o['anotacoes'] ?? ''), true);
        $cla = trim((string)($o['clausulas'] ?? ''));
        ?>
        <section class="pv-sec">
            <button type="button" class="pv-sec-h" data-secao aria-expanded="true"><h2>Detalhes</h2><span class="chev"><?php echo pv_ico('keyboard_arrow_up'); ?></span></button>
            <div class="pv-sec-b">
                <?php echo $det; ?>
                <?php if ($cla !== ''): ?><button type="button" class="pv-lin2" data-abrir="pvClausulas"><span class="t">Cláusulas contratuais<small><?php echo vn_h(mb_substr(preg_replace('/\s+/', ' ', $cla), 0, 70)); ?>…</small></span><?php echo pv_ico('chevron_right', ' class="go"'); ?></button><?php endif; ?>
                <?php if ($det === '' && $cla === ''): ?><p class="pv-nada">Nada preenchido ainda. Use <strong>Editar</strong> no menu para completar o pedido.</p><?php endif; ?>
            </div>
        </section>

        <?php if (!empty($o['fotos'])): ?>
        <hr class="pv-hr">
        <section class="pv-sec">
            <button type="button" class="pv-sec-h" data-secao aria-expanded="true"><h2>Fotos</h2><span class="chev"><?php echo pv_ico('keyboard_arrow_up'); ?></span></button>
            <div class="pv-sec-b"><div class="pv-fotos">
                <?php foreach ($o['fotos'] as $ft): ?><button type="button" class="pv-foto" data-foto="<?php echo vn_h($ft['caminho']); ?>" aria-label="Ampliar foto"><img src="<?php echo vn_h($ft['caminho']); ?>" alt="Foto do pedido" loading="lazy"></button><?php endforeach; ?>
            </div></div>
        </section>
        <?php endif; ?>
    </section>

    <!-- ===================== ABA: AGENDA ===================== -->
    <section data-painel="agenda" <?php echo $aba === 'agenda' ? '' : 'hidden'; ?>>
        <?php if (!$compromissos): ?>
            <div class="pv-vazio"><span class="circ"><?php echo pv_ico('calendar_month'); ?></span><strong>Agendar um compromisso</strong><span>É super fácil organizar sua rotina de trabalho!</span></div>
        <?php else: $hojeStr = vn_today_sp(); ?>
            <div class="pv-comps">
            <?php foreach ($compromissos as $c): $passou = (string)$c['data'] < $hojeStr && (int)$c['feito'] !== 1; ?>
                <div class="pv-comp<?php echo (int)$c['feito'] === 1 ? ' feito' : ''; ?><?php echo $passou ? ' passou' : ''; ?>">
                    <form method="post"><input type="hidden" name="acao" value="compromisso_feito"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="item_id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="chk" aria-label="<?php echo (int)$c['feito'] === 1 ? 'Marcar como não feito' : 'Marcar como feito'; ?>"><?php echo pv_ico((int)$c['feito'] === 1 ? 'check_circle' : 'task_alt'); ?></button></form>
                    <div class="tx"><strong><?php echo vn_h($c['titulo']); ?></strong>
                        <span><?php echo vn_h(vn_date_br($c['data'])); ?><?php echo $c['hora'] ? ' · ' . vn_h($c['hora']) : ''; ?><?php echo $passou ? ' · atrasado' : ''; ?></span>
                        <?php if (!empty($c['obs'])): ?><span><?php echo vn_h($c['obs']); ?></span><?php endif; ?></div>
                    <form method="post" data-confirm="Remover este compromisso?" data-danger data-confirm-ok="Remover"><input type="hidden" name="acao" value="compromisso_del"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="item_id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="x" aria-label="Remover compromisso"><?php echo pv_ico('delete'); ?></button></form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===================== ABA: FINANCEIRO ===================== -->
    <section data-painel="financeiro" <?php echo $aba === 'financeiro' ? '' : 'hidden'; ?>>
        <h2 class="pv-h2">Últimos pagamentos</h2>
        <?php if (!$fin['pagamentos']): ?><p class="pv-nada">Não há nada aqui ainda. Lance um recebimento clicando no botão abaixo.</p>
        <?php else: foreach (array_slice($fin['pagamentos'], 0, 8) as $p): ?>
            <div class="pv-led"><div class="tx"><strong><?php echo vn_h(vn_money($p['valor'])); ?></strong><span><?php echo vn_h(vn_date_br($p['data'])); ?><?php echo $p['forma'] ? ' · ' . vn_h($p['forma']) : ''; ?><?php echo $p['obs'] ? ' · ' . vn_h($p['obs']) : ''; ?></span></div>
                <form method="post" data-confirm="Remover este recebimento?" data-danger data-confirm-ok="Remover"><input type="hidden" name="acao" value="pagamento_del"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="item_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" class="x" aria-label="Remover recebimento"><?php echo pv_ico('delete'); ?></button></form></div>
        <?php endforeach; endif; ?>
        <button type="button" class="pv-out" data-abrir="pvPagamento">lançar recebimento</button>

        <h2 class="pv-h2">Últimos custos</h2>
        <?php if (!$fin['custos']): ?><p class="pv-nada">Não há nada aqui ainda. Lance um custo clicando no botão abaixo.</p>
        <?php else: foreach (array_slice($fin['custos'], 0, 8) as $c): ?>
            <div class="pv-led"><div class="tx"><strong><?php echo vn_h(vn_money($c['valor'])); ?></strong><span><?php echo vn_h($c['descricao']); ?> · <?php echo vn_h(vn_date_br($c['data'])); ?></span></div>
                <form method="post" data-confirm="Remover este custo?" data-danger data-confirm-ok="Remover"><input type="hidden" name="acao" value="custo_del"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="item_id" value="<?php echo (int)$c['id']; ?>"><button type="submit" class="x" aria-label="Remover custo"><?php echo pv_ico('delete'); ?></button></form></div>
        <?php endforeach; endif; ?>
        <button type="button" class="pv-out" data-abrir="pvCusto">lançar custo</button>

        <hr class="pv-hr">
        <div class="pv-fin">
            <div class="pv-fin-h"><strong>Finanças do pedido</strong><button type="button" class="info" data-abrir="pvInfoFin" aria-label="Como o resultado é calculado"><?php echo pv_ico('info'); ?></button></div>
            <div class="pv-fin-g">
                <svg viewBox="0 0 120 120" role="img" aria-label="<?php echo vn_h($fin['pct'] . '% recebido'); ?>">
                    <circle cx="60" cy="60" r="48" fill="none" stroke="var(--surface-3)" stroke-width="16"/>
                    <circle cx="60" cy="60" r="48" fill="none" stroke="var(--ok)" stroke-width="16" stroke-linecap="butt" stroke-dasharray="<?php echo round($fin['pct'] * 3.0159, 2); ?> 301.59" transform="rotate(-90 60 60)"/>
                    <text x="60" y="58" text-anchor="middle" class="n"><?php echo vn_h(number_format($fin['pct'], 1, ',', '')); ?>%</text><text x="60" y="76" text-anchor="middle" class="l">recebido</text>
                </svg>
                <dl>
                    <div><dt>Total do pedido</dt><dd><?php echo vn_h(vn_money($total)); ?></dd></div>
                    <div><dt>Recebido</dt><dd class="ok"><?php echo vn_h(vn_money($fin['recebido'])); ?></dd></div>
                    <div><dt>A receber</dt><dd><?php echo vn_h(vn_money($fin['a_receber'])); ?></dd></div>
                    <div><dt>Custos lançados</dt><dd><?php echo vn_h(vn_money($fin['custo'])); ?></dd></div>
                    <div><dt>Custo previsto</dt><dd><?php echo vn_h(vn_money($o['valor_custo'] ?? 0)); ?></dd></div>
                </dl>
            </div>
        </div>
    </section>

    <!-- ===================== barras fixas de baixo ===================== -->
    <div class="pv-foot" data-rodape="pedido" <?php echo $aba === 'pedido' ? '' : 'hidden'; ?>>
        <div class="tot">Total: <strong><?php echo vn_h(vn_money($total)); ?></strong></div>
        <button type="button" class="pl-btn" data-abrir="pvDocs">gerar documento</button>
    </div>
    <div class="pv-foot" data-rodape="agenda" <?php echo $aba === 'agenda' ? '' : 'hidden'; ?>>
        <button type="button" class="pl-btn" data-abrir="pvCompromisso"><?php echo pv_ico('calendar_month'); ?>novo compromisso</button>
    </div>
    <div class="pv-foot" data-rodape="financeiro" <?php echo $aba === 'financeiro' ? '' : 'hidden'; ?>>
        <div class="tot">Resultado: <strong class="<?php echo $fin['resultado'] < 0 ? 'neg' : ''; ?>"><?php echo vn_h(vn_money($fin['resultado'])); ?></strong></div>
    </div>

    <!-- ===================== janelas ===================== -->
    <dialog class="pl-sheet cheia" id="pvDocs" aria-labelledby="pvDocsT"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvDocsT">Documento</h3><span></span></div>
        <div class="pl-sheet-b">
            <h4 class="pv-q">Qual documento<br>você quer gerar?</h4><p class="pv-qs">Geralmente, apenas o título muda. O que aparece no documento você define em <strong>Editar → Documento (PDF)</strong>.</p>
            <div class="pv-docs">
                <?php foreach (vn_pedido_documentos() as $slug => $tit): ?><a target="_blank" rel="noopener" href="orcamento-pdf.php?id=<?php echo $id; ?>&amp;doc=<?php echo vn_h($slug); ?>"><?php echo vn_h($tit); ?></a><?php endforeach; ?>
            </div>
        </div>
    </div></dialog>

    <dialog class="pl-sheet" id="pvCliente" aria-labelledby="pvClienteT"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvClienteT">Cliente</h3><span></span></div>
        <div class="pl-sheet-b">
            <p class="pv-cli-n"><?php echo vn_h($o['cliente_nome'] ?: 'Sem cliente'); ?></p>
            <?php if (!empty($o['cliente_telefone'])): ?><a class="pv-cli-l" href="tel:<?php echo vn_h(preg_replace('/[^\d+]/', '', (string)$o['cliente_telefone'])); ?>"><?php echo pv_ico('call'); ?><?php echo vn_h($o['cliente_telefone']); ?></a>
                <a class="pv-cli-l" target="_blank" rel="noopener" href="<?php echo vn_h(vn_wa_link($o['cliente_telefone'], '')); ?>"><?php echo pv_ico('chat'); ?>Abrir no WhatsApp</a><?php endif; ?>
            <?php if (!empty($o['cliente_email'])): ?><a class="pv-cli-l" href="mailto:<?php echo vn_h($o['cliente_email']); ?>"><?php echo pv_ico('mail'); ?><?php echo vn_h($o['cliente_email']); ?></a><?php endif; ?>
            <?php if (!empty($o['endereco'])): ?><a class="pv-cli-l" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo rawurlencode((string)$o['endereco']); ?>"><?php echo pv_ico('location_on'); ?><?php echo vn_h($o['endereco']); ?></a><?php endif; ?>
        </div>
    </div></dialog>

    <?php foreach (['pvServicos' => ['Serviços', $servicos, (float)($o['visita_valor'] ?? 0), (float)($o['mao_obra_valor'] ?? 0)], 'pvPecas' => ['Peças', $pecas, 0.0, 0.0], 'pvSerralheria' => ['Peças de serralheria', $pecasSerr, 0.0, 0.0]] as $sid => $sv): ?>
    <dialog class="pl-sheet cheia" id="<?php echo $sid; ?>" aria-labelledby="<?php echo $sid; ?>T"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Voltar"><?php echo pv_ico('arrow_back'); ?></button><h3 id="<?php echo $sid; ?>T"><?php echo $sv[0]; ?> do pedido</h3><span></span></div>
        <div class="pl-sheet-b">
            <?php $tem = false; foreach ($sv[1] as $it): $tem = true; ?>
                <div class="pv-it"><div class="tx"><strong><?php echo vn_h(explode(' — ', (string)$it['descricao'])[0]); ?></strong>
                    <?php $dt = trim(implode(' — ', array_slice(explode(' — ', (string)$it['descricao']), 1))); if ($dt !== ''): ?><span><?php echo vn_h($dt); ?></span><?php endif; ?>
                    <span><?php echo vn_h(number_format((float)$it['quantidade'], 2, ',', '.')); ?> × <?php echo vn_h(vn_money($it['valor_unitario'])); ?><?php echo (($it['origem_peca'] ?? '') === 'comprar' && $sid !== 'pvServicos') ? ' · para comprar' : ''; ?></span></div>
                    <strong class="v"><?php echo vn_h(vn_money($it['total'])); ?></strong></div>
            <?php endforeach; ?>
            <?php if ($sid === 'pvServicos'): if ($sv[2] > 0): $tem = true; ?><div class="pv-it"><div class="tx"><strong><?php echo vn_h((string)($o['visita_descricao'] ?: 'Visita técnica')); ?></strong></div><strong class="v"><?php echo vn_h(vn_money($sv[2])); ?></strong></div><?php endif;
                if ($sv[3] > 0): $tem = true; ?><div class="pv-it"><div class="tx"><strong><?php echo vn_h((string)($o['mao_obra_descricao'] ?: 'Mão de obra')); ?></strong></div><strong class="v"><?php echo vn_h(vn_money($sv[3])); ?></strong></div><?php endif; endif; ?>
            <?php if (!$tem): ?><p class="pv-nada">Nenhum item neste grupo. Use <strong>Editar</strong> para adicionar.</p><?php endif; ?>
        </div>
        <div class="pl-sheet-f"><a class="pl-btn" href="orcamento-novo.php?editar=<?php echo $id; ?>">editar itens</a></div>
    </div></dialog>
    <?php endforeach; ?>

    <?php if ($cla !== ''): ?>
    <dialog class="pl-sheet cheia" id="pvClausulas" aria-labelledby="pvClausulasT"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Voltar"><?php echo pv_ico('arrow_back'); ?></button><h3 id="pvClausulasT">Cláusulas contratuais</h3><span></span></div>
        <div class="pl-sheet-b"><p class="pv-pre"><?php echo vn_h($cla); ?></p></div>
    </div></dialog>
    <?php endif; ?>

    <dialog class="pl-sheet" id="pvPagamento" aria-labelledby="pvPagT"><div class="pl-sheet-in"><form method="post">
        <input type="hidden" name="acao" value="pagamento_add"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="aba" value="financeiro">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvPagT">Lançar recebimento</h3><span></span></div>
        <div class="pl-sheet-b">
            <label class="pl-lab" for="pgValor">Valor recebido (R$) *</label><input class="pl-in" id="pgValor" name="valor" inputmode="decimal" required value="<?php echo $fin['a_receber'] > 0 ? vn_h(number_format($fin['a_receber'], 2, ',', '.')) : ''; ?>">
            <label class="pl-lab" for="pgData">Data</label><input class="pl-in" id="pgData" type="date" name="data" value="<?php echo vn_h(vn_today_sp()); ?>">
            <label class="pl-lab" for="pgForma">Forma de pagamento</label><select class="pl-in" id="pgForma" name="forma"><option value="">Não informada</option><?php foreach (array_merge($meiosLista, ['Outro']) as $m): ?><option><?php echo vn_h($m); ?></option><?php endforeach; ?></select>
            <label class="pl-lab" for="pgObs">Observação</label><input class="pl-in" id="pgObs" name="obs" maxlength="255" placeholder="Ex.: entrada, parcela 2/3">
        </div>
        <div class="pl-sheet-f"><button type="submit" class="pl-btn">lançar recebimento</button></div>
    </form></div></dialog>

    <dialog class="pl-sheet" id="pvCusto" aria-labelledby="pvCusT"><div class="pl-sheet-in"><form method="post">
        <input type="hidden" name="acao" value="custo_add"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="aba" value="financeiro">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvCusT">Lançar custo</h3><span></span></div>
        <div class="pl-sheet-b">
            <label class="pl-lab" for="cuDesc">O que foi gasto *</label><input class="pl-in" id="cuDesc" name="descricao" maxlength="190" required placeholder="Ex.: material, combustível, terceiro">
            <label class="pl-lab" for="cuValor">Valor (R$) *</label><input class="pl-in" id="cuValor" name="valor" inputmode="decimal" required>
            <label class="pl-lab" for="cuData">Data</label><input class="pl-in" id="cuData" type="date" name="data" value="<?php echo vn_h(vn_today_sp()); ?>">
        </div>
        <div class="pl-sheet-f"><button type="submit" class="pl-btn">lançar custo</button></div>
    </form></div></dialog>

    <dialog class="pl-sheet" id="pvCompromisso" aria-labelledby="pvCompT"><div class="pl-sheet-in"><form method="post">
        <input type="hidden" name="acao" value="compromisso_add"><input type="hidden" name="id_orcamento" value="<?php echo $id; ?>"><input type="hidden" name="aba" value="agenda">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvCompT">Novo compromisso</h3><span></span></div>
        <div class="pl-sheet-b">
            <label class="pl-lab" for="coTit">O que será feito *</label><input class="pl-in" id="coTit" name="titulo" maxlength="190" required placeholder="Ex.: Visita técnica, instalação">
            <label class="pl-lab" for="coData">Data *</label><input class="pl-in" id="coData" type="date" name="data" required value="<?php echo vn_h(vn_today_sp()); ?>">
            <label class="pl-lab" for="coHora">Hora</label><input class="pl-in" id="coHora" type="time" name="hora">
            <label class="pl-lab" for="coObs">Observação</label><input class="pl-in" id="coObs" name="obs" maxlength="255">
        </div>
        <div class="pl-sheet-f"><button type="submit" class="pl-btn">marcar compromisso</button></div>
    </form></div></dialog>

    <dialog class="pl-sheet" id="pvInfoFin" aria-labelledby="pvInfoT"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3 id="pvInfoT">Como é calculado</h3><span></span></div>
        <div class="pl-sheet-b"><p class="pv-qs" style="margin-top:0"><strong>Resultado</strong> = tudo o que você <strong>recebeu</strong> deste pedido menos os <strong>custos que lançou</strong>. O percentual do gráfico é quanto do total do pedido já foi recebido. O custo previsto nos itens vem do cadastro das peças e não entra no resultado.</p></div>
    </div></dialog>

    <dialog class="pl-sheet pv-foto-dlg" id="pvFoto" aria-label="Foto do pedido"><div class="pl-sheet-in">
        <div class="pl-sheet-h"><button type="button" class="pl-x" data-fechar aria-label="Fechar"><?php echo pv_ico('close'); ?></button><h3>Foto</h3><span></span></div>
        <div class="pl-sheet-b" style="display:flex;align-items:center;justify-content:center"><img id="pvFotoImg" alt="Foto do pedido" style="max-width:100%;max-height:70dvh;border-radius:12px"></div>
    </div></dialog>
</div>
<script defer src="<?php echo vn_h(vn_ui_asset('assets/js/vn-pedido-view.js')); ?>"></script>
<?php vn_ui_shell_end('pedidos'); ?>
