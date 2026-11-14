<?php
/*
    VN System - Pedido / Orçamento (tela de criar e editar).
    Estrutura igual à do aplicativo de referência: seções recolhíveis, linhas com "+", janelas de peça,
    serviço e desconto e botão "salvar pedido" fixo. O salvamento é o mesmo de sempre
    (vn_salvar_orcamento_mobile_v4 + vn_orcamento_salvar_extras); o visual está em
    assets/css/vn-pedido.css e a lógica da tela em assets/js/vn-pedido.js.
    Tipo do orçamento: CFTV ou Serralheria (campo "categoria"). Serralheria tem o seu próprio "Peças de serralheria",
    com um catálogo à parte (itens com linha = serralheria); os serviços valem para os dois tipos.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
$ok = null; $erro = '';
$editId = (int)($_GET['editar'] ?? ($_POST['id_orcamento'] ?? 0));

/* Cadastro rápido no catálogo (aba "Cadastrar" do modal de adicionar item) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'catalogo_rapido') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        vn_ensure_mobile_tables();
        $_POST['catalogo_auto_calculo'] = '0'; // valor digitado manualmente
        // Foto enviada junto (mesmo upload do Inventário).
        $foto = vn_catalog_upload('catalogo_foto');
        if ($foto !== '') $_POST['catalogo_foto_url'] = $foto;
        // A mesma peça (mesmo tipo, linha, nome e detalhes) não vira dois cadastros: o que já existe só tem o preço e o custo atualizados.
        $tipoRap = vn_normalizar_tipo_orcamento_v6((string)($_POST['catalogo_tipo'] ?? 'material'));
        $nomeRap = vn_clean($_POST['catalogo_nome'] ?? '');
        $descRap = trim((string)($_POST['catalogo_descricao'] ?? ''));
        if ($descRap === '') $descRap = $nomeRap;
        $linhaRap = vn_linha_catalogo($_POST['catalogo_linha'] ?? '', $tipoRap);
        $idRap = 0;
        if ($nomeRap !== '') {
            foreach (vn_fetch_all("SELECT id, nome, descricao, tipo, linha FROM vn_mobile_orcamento_catalogo WHERE status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s')") as $rr) {
                if (vn_normalizar_tipo_orcamento_v6((string)$rr['tipo']) !== $tipoRap || vn_linha_catalogo($rr['linha'] ?? '', $tipoRap) !== $linhaRap) continue;
                $dr = trim((string)$rr['descricao']); if ($dr === '') $dr = (string)$rr['nome'];
                if (vn_norm_key((string)$rr['nome']) === vn_norm_key($nomeRap) && vn_norm_key($dr) === vn_norm_key($descRap)) { $idRap = (int)$rr['id']; break; }
            }
        }
        $jaExistia = $idRap > 0;
        if ($jaExistia) {
            $sets = ['valor_unitario = ?', 'atualizado_em = NOW()']; $par = [vn_money_db($_POST['catalogo_valor'] ?? 0)];
            $custoRap = vn_money_db($_POST['catalogo_custo'] ?? 0);
            if ($custoRap > 0) { $sets[] = 'custo_unitario = ?'; $par[] = $custoRap; }
            if (!empty($_POST['catalogo_foto_url'])) { $sets[] = 'foto_url = ?'; $par[] = (string)$_POST['catalogo_foto_url']; }
            // como cobrar (por unidade ou por medida): cadastrar de novo o mesmo produto também atualiza o cálculo
            if (isset($_POST['catalogo_calculo']) && function_exists('vn_serr_calculo_valido')) {
                $calcRap = vn_serr_calculo_valido($_POST['catalogo_calculo']);
                $sets[] = 'calculo = ?'; $par[] = $calcRap;
                if ($calcRap !== '') { $sets[] = 'unidade = ?'; $par[] = vn_serr_calculo_unidade($calcRap); }
            }
            $par[] = $idRap;
            vn_exec('UPDATE vn_mobile_orcamento_catalogo SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1', $par);
        } else {
            $idRap = (int)vn_salvar_catalogo_orcamento_mobile_v6($_POST);
        }
        $rowRap = vn_fetch_one('SELECT id, tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, estoque_minimo, linha, calculo FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$idRap]);
        $itemRap = null;
        if ($rowRap) {
            $itemRap = ['origem' => 'mobile', 'id' => (int)$rowRap['id'], 'tipo' => $tipoRap, 'nome' => vn_clean($rowRap['nome']),
                'descricao' => trim((string)$rowRap['descricao']) !== '' ? trim((string)$rowRap['descricao']) : vn_clean($rowRap['nome']),
                'foto_url' => trim((string)$rowRap['foto_url']), 'unidade' => vn_clean($rowRap['unidade'] ?? 'un'),
                'valor_unitario' => (float)$rowRap['valor_unitario'], 'custo_unitario' => (float)$rowRap['custo_unitario'],
                'quantidade_estoque' => (float)$rowRap['quantidade_estoque'], 'estoque_minimo' => ($rowRap['estoque_minimo'] !== null && $rowRap['estoque_minimo'] !== '') ? (float)$rowRap['estoque_minimo'] : null,
                'linha' => vn_linha_catalogo($rowRap['linha'] ?? '', $tipoRap),
                'calculo' => function_exists('vn_serr_calculo_valido') ? vn_serr_calculo_valido($rowRap['calculo'] ?? '') : ''];
        }
        echo json_encode(['ok' => true, 'id' => $idRap, 'existente' => $jaExistia, 'item' => $itemRap]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => vn_public_error($e, 'catalogo_rapido')]);
    }
    exit;
}

/* Cadastro rápido de cliente ("Selecione ou adicione um cliente") */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'cliente_rapido') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $nome = vn_clean($_POST['nome'] ?? '');
        if ($nome === '') throw new Exception('Informe o nome do cliente.');
        $tel = vn_clean($_POST['telefone'] ?? '');
        $mail = vn_clean($_POST['email'] ?? '');
        $existe = null;
        foreach (vn_clientes_form() as $c) {
            if (vn_norm_key((string)$c['nome']) === vn_norm_key($nome)) { $existe = $c; break; }
        }
        if ($existe) {
            echo json_encode(['ok' => true, 'existente' => true, 'id' => (int)$existe['id'], 'nome' => (string)$existe['nome'], 'telefone' => (string)($existe['telefone'] ?? ''), 'email' => (string)($existe['email'] ?? '')]);
            exit;
        }
        $idCli = vn_insert_dynamic('clientes', ['nome' => $nome, 'tipo_pessoa' => 'fisica', 'telefone' => $tel, 'whatsapp' => $tel, 'email' => $mail, 'status' => 'ativo']);
        if ($idCli <= 0) throw new Exception('Não foi possível cadastrar o cliente.');
        echo json_encode(['ok' => true, 'existente' => false, 'id' => (int)$idCli, 'nome' => $nome, 'telefone' => $tel, 'email' => $mail]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => vn_public_error($e, 'cliente_rapido')]);
    }
    exit;
}

try {
    vn_ensure_mobile_tables();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Passou do limite do servidor (post_max_size): o PHP descarta TODOS os campos. Avisa em vez de reclamar do cliente.
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new Exception('O envio ficou grande demais para o servidor (provavelmente pelas fotos). Remova algumas fotos e salve de novo.');
        }
        $acao = (string)($_POST['acao'] ?? 'salvar');
        if (empty($_POST['cliente_nome']) && !empty($_POST['cliente_id'])) {
                foreach (vn_clientes_form() as $c) {
                    if ((int)$c['id'] === (int)$_POST['cliente_id']) {
                        $_POST['cliente_nome'] = $c['nome'];
                        $_POST['cliente_telefone'] = $_POST['cliente_telefone'] ?: ($c['telefone'] ?? '');
                        $_POST['cliente_email'] = $_POST['cliente_email'] ?: ($c['email'] ?? '');
                        $_POST['endereco'] = $_POST['endereco'] ?: ($c['endereco'] ?? '');
                        break;
                    }
                }
        }
        $id = vn_salvar_orcamento_mobile_v4($_POST);
        if ($acao === 'salvar_pdf') {
            header('Location: orcamento-pdf.php?id=' . (int)$id);
            exit;
        }
        $_SESSION['pv_flash'] = 'Pedido salvo com sucesso.';
        header('Location: pedido.php?id=' . (int)$id);   // como no aplicativo: depois de salvar, volta para a tela do pedido
        exit;
    }
    if (isset($_GET['ok'])) $ok = 'Pedido salvo com sucesso.';
    if (!empty($_SESSION['pv_flash'])) { $ok = (string)$_SESSION['pv_flash']; unset($_SESSION['pv_flash']); }
    $orcamentoEdit = $editId > 0 ? vn_orcamento_mobile_get_v4($editId) : null;
    $clientes = [];
    $enderecos = [];
    $catalogo = [];
    try { $clientes = vn_clientes_form(); } catch (Throwable $e) { $clientes = []; if (!$erro) $erro = 'Não consegui carregar os clientes: ' . $e->getMessage(); }
    try { $enderecos = vn_enderecos_form(); } catch (Throwable $e) { $enderecos = []; }
    try { $catalogo = vn_catalogo_orcamento_mobile_v6(true); } catch (Throwable $e) { $catalogo = []; if (!$erro) $erro = 'Não consegui carregar o catálogo: ' . $e->getMessage(); }
} catch (Throwable $e) {
    $erro = $e->getMessage();
    $orcamentoEdit = null;
    $clientes = [];
    $enderecos = [];
    $catalogo = [];
}
function vnp_money_val($v) { return number_format((float)$v, 2, ',', '.'); }
$jsonSeguro = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/* Separação por categoria (nos seletores de peça e de serviço) */
$grupos = [
    'material'      => ['titulo' => 'Materiais', 'itens' => []],
    'equipamento'   => ['titulo' => 'Equipamentos', 'itens' => []],
    'outro'         => ['titulo' => 'Outros itens', 'itens' => []],
    'produto_usado' => ['titulo' => 'Produtos usados', 'itens' => []],
    'servico'       => ['titulo' => 'Serviços', 'itens' => []],
    'mao_obra'      => ['titulo' => 'Mão de obra', 'itens' => []],
];
$gruposSerr = [   // peças de serralheria: mesmas categorias de peça, catálogo à parte
    'material'      => ['titulo' => 'Materiais', 'itens' => []],
    'equipamento'   => ['titulo' => 'Equipamentos', 'itens' => []],
    'outro'         => ['titulo' => 'Outros itens', 'itens' => []],
    'produto_usado' => ['titulo' => 'Produtos usados', 'itens' => []],
];
foreach ($catalogo as $it) {
    $tipo = (string)($it['tipo'] ?? 'material');
    if (vn_is_tipo_ferramenta($tipo)) continue; // ferramentas não entram em orçamento (sem valor)
    if (!isset($grupos[$tipo])) $tipo = 'material';
    if (isset($gruposSerr[$tipo]) && (string)($it['linha'] ?? 'cftv') === 'serralheria') { $gruposSerr[$tipo]['itens'][] = $it; continue; }
    $grupos[$tipo]['itens'][] = $it;
}
$countPecas = count($grupos['material']['itens']) + count($grupos['equipamento']['itens']) + count($grupos['outro']['itens']) + count($grupos['produto_usado']['itens']);
$countSerr = count($gruposSerr['material']['itens']) + count($gruposSerr['equipamento']['itens']) + count($gruposSerr['outro']['itens']) + count($gruposSerr['produto_usado']['itens']);
$countServicos = count($grupos['servico']['itens']) + count($grupos['mao_obra']['itens']);
/* Tipo do orçamento: o salvo, ou o escolhido na lista (?cat=serralheria), ou CFTV */
$catAtual = $orcamentoEdit ? vn_categoria_valida($orcamentoEdit['categoria'] ?? 'cftv') : vn_categoria_valida($_GET['cat'] ?? 'cftv');

/* Textos que se repetem em todo pedido: já vêm preenchidos nos pedidos novos */
$padroes = [];
try { $padroes = vn_pedido_padroes(); } catch (Throwable $e) {}
$ov = function (string $col) use ($orcamentoEdit, $padroes): string {
    if ($orcamentoEdit) return (string)($orcamentoEdit[$col] ?? '');
    return (string)($padroes[$col] ?? '');
};
$meiosLista = vn_pedido_meios_lista();
$meiosSel = array_values(array_filter(array_map('trim', explode(',', $ov('meios_pagamento')))));
$flag = function (string $col) use ($orcamentoEdit): string { return ((int)($orcamentoEdit[$col] ?? 1) === 1) ? 'checked' : ''; };

/* Compromissos por data (validades e compromissos marcados em outros pedidos) para o calendário */
$compMap = [];
try {
    foreach (vn_fetch_all("SELECT id, validade, cliente_nome, numero FROM vn_mobile_orcamentos WHERE validade IS NOT NULL AND validade <> '0000-00-00' ORDER BY validade DESC LIMIT 300") as $v) {
        $d = substr((string)$v['validade'], 0, 10);
        if ($d === '' || (int)$v['id'] === (int)($orcamentoEdit['id'] ?? 0)) continue;
        $compMap[$d][] = 'Validade do pedido de ' . trim((string)($v['cliente_nome'] ?: $v['numero'] ?: 'cliente'));
    }
    foreach (vn_fetch_all("SELECT id, compromisso_data, compromisso_hora, compromisso_desc, cliente_nome FROM vn_mobile_orcamentos WHERE compromisso_data IS NOT NULL AND compromisso_data <> '0000-00-00' ORDER BY compromisso_data DESC LIMIT 300") as $v) {
        $d = substr((string)$v['compromisso_data'], 0, 10);
        if ($d === '' || (int)$v['id'] === (int)($orcamentoEdit['id'] ?? 0)) continue;
        $compMap[$d][] = trim(($v['compromisso_hora'] ? $v['compromisso_hora'] . ' · ' : '') . ($v['cliente_nome'] ?: 'Pedido') . ($v['compromisso_desc'] ? ' — ' . $v['compromisso_desc'] : ''));
    }
} catch (Throwable $e) {}

$numeroPedido = $orcamentoEdit ? vn_pedido_numero($orcamentoEdit) : 'novo';
$validadeAtual = $orcamentoEdit ? vn_date_input_value($orcamentoEdit['validade'] ?? '') : '';
$descCfg = [];
if ($orcamentoEdit && !empty($orcamentoEdit['desconto_cfg'])) { $descCfg = json_decode((string)$orcamentoEdit['desconto_cfg'], true) ?: []; }
$itensEdit = $orcamentoEdit['itens'] ?? [];
$fotosEdit = $orcamentoEdit['fotos'] ?? [];
$enderecoAtual = (string)($orcamentoEdit['endereco'] ?? '');

$clausulaModelo = "CONTRATO DE PRESTAÇÃO DE SERVIÇOS\n\n"
    . "1. OBJETO: a CONTRATADA prestará ao CONTRATANTE os serviços e fornecerá os materiais descritos neste documento.\n\n"
    . "2. PREÇO E PAGAMENTO: o CONTRATANTE pagará o valor total indicado, nas condições e pelos meios de pagamento descritos neste documento.\n\n"
    . "3. PRAZO: a execução seguirá o prazo informado, salvo atrasos causados por falta de acesso ao local, por fatores externos ou por pedido do CONTRATANTE.\n\n"
    . "4. OBRIGAÇÕES DO CONTRATANTE: garantir o acesso ao local, a energia elétrica e as demais condições necessárias à execução, além de informar corretamente os dados do atendimento.\n\n"
    . "5. GARANTIA: a garantia cobre defeitos de instalação e de serviço pelo prazo informado neste documento, e não cobre mau uso, quedas, descargas elétricas, danos por terceiros ou alterações feitas sem autorização.\n\n"
    . "6. CANCELAMENTO: o cancelamento após o início da execução pode gerar cobrança proporcional aos serviços realizados e aos materiais adquiridos.\n\n"
    . "7. FORO: as partes elegem o foro da comarca do local da prestação do serviço para resolver eventuais divergências.";

/* ---------- peças para desenhar ---------- */
function pd_ico(string $nome, string $extra = ''): string {
    $classe = 'material-symbols-outlined';
    if (preg_match('/class="([^"]*)"/', $extra, $m)) { $classe .= ' ' . $m[1]; $extra = preg_replace('/\s*class="[^"]*"/', '', $extra); }   // junta as classes (dois class= no mesmo elemento: o segundo é ignorado)
    return '<span class="' . $classe . '"' . $extra . ' aria-hidden="true">' . $nome . '</span>';
}
/* Linha com "+" que abre um campo logo abaixo. */
function pd_linha_reveal(string $icone, string $rotulo, string $id, bool $aberto, string $interno, string $sub = ''): string {
    return '<div class="pd-row">' . pd_ico($icone, ' ') . '<span class="pd-lbl">' . vn_h($rotulo) . ($sub !== '' ? '<small>' . vn_h($sub) . '</small>' : '') . '</span>'
        . '<button type="button" class="pd-plus" data-reveal="' . vn_h($id) . '" aria-expanded="' . ($aberto ? 'true' : 'false') . '" aria-label="' . vn_h($rotulo) . '">' . pd_ico('add') . '</button></div>'
        . '<div class="pd-reveal' . ($aberto ? ' aberto' : '') . '" id="' . vn_h($id) . '">' . $interno . '</div>';
}
function pd_secao(string $id, string $titulo, string $corpo, bool $fechada = false): string {
    return '<section class="pd-sec' . ($fechada ? ' fechada' : '') . '" id="' . vn_h($id) . '">'
        . '<button type="button" class="pd-sec-h" data-secao aria-expanded="' . ($fechada ? 'false' : 'true') . '"><h2>' . vn_h($titulo) . '</h2><span class="pd-chev">' . pd_ico('keyboard_arrow_up') . '</span></button>'
        . '<div class="pd-sec-b">' . $corpo . '</div></section>';
}
function pd_chave(string $nome, string $rotulo, string $marcado): string {
    return '<label class="pd-doc-r">' . vn_h($rotulo) . '<span class="pd-sw"><input type="checkbox" name="' . vn_h($nome) . '" ' . $marcado . '><i></i></span></label>';
}

vn_ui_shell_start($orcamentoEdit ? 'Editar Pedido' : 'Novo Pedido', 'novo', [
    'css' => ['assets/css/vn-pedido.css', 'assets/css/vn-serr-medida.css'],
    'js'  => ['assets/js/vn-download.js'],
    'head_extra' => '<style>@media(max-width:900px){.vn-top,.vn-mobile-nav{display:none!important}.vn-content-wrap{padding:0 14px!important}}</style>',
]);
vn_alerts($ok, $erro);
?>
<div class="pd">
<form method="post" id="pedidoForm" enctype="multipart/form-data">
    <input type="hidden" name="id_orcamento" value="<?php echo (int)($orcamentoEdit['id'] ?? 0); ?>">
    <input type="hidden" name="cliente_nome" data-cliente-nome value="<?php echo vn_h($orcamentoEdit['cliente_nome'] ?? ''); ?>">
    <input type="hidden" name="cliente_telefone" data-cliente-tel value="<?php echo vn_h($orcamentoEdit['cliente_telefone'] ?? ''); ?>">
    <input type="hidden" name="cliente_email" data-cliente-email value="<?php echo vn_h($orcamentoEdit['cliente_email'] ?? ''); ?>">
    <input type="hidden" name="endereco" data-endereco-final value="<?php echo vn_h($enderecoAtual); ?>">
    <input type="hidden" name="validade" id="validadeInput" value="<?php echo vn_h($validadeAtual); ?>">
    <input type="hidden" name="desconto" id="descontoInput" value="<?php echo vnp_money_val($orcamentoEdit['desconto'] ?? 0); ?>">
    <input type="hidden" name="desconto_cfg" id="descontoCfg" value="<?php echo vn_h($descCfg ? json_encode($descCfg) : ''); ?>">
    <input type="hidden" name="acao" id="acaoInput" value="salvar">
    <input type="hidden" name="categoria" id="categoriaInput" value="<?php echo vn_h($catAtual); ?>">

    <div class="pd-bar">
        <a class="pd-x" href="<?php echo $orcamentoEdit ? 'pedido.php?id=' . (int)$orcamentoEdit['id'] : 'pedidos-dashboard.php'; ?>" data-sair aria-label="Fechar o pedido"><?php echo pd_ico('close'); ?></a>
        <strong>Pedido</strong>
        <span></span>
    </div>
    <div class="pd-title">
        <div><h1>Pedido n. <?php echo vn_h($numeroPedido); ?></h1><span class="d"><?php echo $orcamentoEdit ? vn_h(vn_date_br(substr((string)($orcamentoEdit['criado_em'] ?? ''), 0, 10))) : date('d/m/Y'); ?></span></div>
        <div class="pd-title-acts">
            <?php if ($orcamentoEdit): ?><a class="vn-btn" target="_blank" rel="noopener" data-baixar href="orcamento-pdf.php?id=<?php echo (int)$orcamentoEdit['id']; ?>"><?php echo pd_ico('picture_as_pdf'); ?>Baixar PDF</a><?php endif; ?>
            <a class="vn-btn" href="<?php echo $orcamentoEdit ? 'pedido.php?id=' . (int)$orcamentoEdit['id'] : 'pedidos-dashboard.php'; ?>" data-sair><?php echo pd_ico('close'); ?>Fechar</a>
        </div>
    </div>

    <!-- ============ Tipo do orçamento ============ -->
    <div class="pd-tipo" role="group" aria-label="Tipo do orçamento">
        <span class="pd-lab">Tipo do orçamento</span>
        <div class="pd-seg">
            <button type="button" data-categoria="cftv" aria-pressed="<?php echo $catAtual === 'cftv' ? 'true' : 'false'; ?>"><?php echo pd_ico('videocam'); ?>CFTV</button>
            <button type="button" data-categoria="serralheria" aria-pressed="<?php echo $catAtual === 'serralheria' ? 'true' : 'false'; ?>"><?php echo pd_ico('build'); ?>Serralheria</button>
        </div>
    </div>

    <!-- ============ Cliente ============ -->
    <div style="margin-top:16px">
        <select class="pd-in" name="cliente_id" data-cliente-select required aria-label="Cliente">
            <option value="0">Selecione ou adicione um cliente</option>
            <?php foreach ($clientes as $c): $sel = ((int)($orcamentoEdit['cliente_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>
                <option value="<?php echo (int)$c['id']; ?>" <?php echo $sel; ?> data-nome="<?php echo vn_h($c['nome']); ?>" data-tel="<?php echo vn_h($c['telefone'] ?? ''); ?>" data-email="<?php echo vn_h($c['email'] ?? ''); ?>" data-end="<?php echo vn_h($c['endereco'] ?? ''); ?>"><?php echo vn_h($c['nome']); ?></option>
            <?php endforeach; ?>
            <option value="-1">＋ Adicionar novo cliente…</option>
        </select>
        <select class="pd-in" name="endereco_id" data-endereco-select aria-label="Referência: endereço do atendimento">
            <option value="0">Referência (endereço do atendimento)</option>
            <?php foreach ($enderecos as $e): ?>
                <option value="<?php echo (int)$e['id']; ?>" data-cliente-id="<?php echo (int)$e['cliente_id']; ?>" data-end="<?php echo vn_h($e['endereco']); ?>"><?php echo vn_h(($e['label'] ?? 'Endereço') . ' · ' . $e['endereco']); ?></option>
            <?php endforeach; ?>
            <option value="-1">Outro endereço…</option>
        </select>
        <input class="pd-in" id="enderecoLivre" placeholder="Digite o endereço do atendimento" value="<?php echo vn_h($enderecoAtual); ?>" <?php echo ($enderecoAtual !== '' && !$enderecos) ? '' : 'hidden'; ?>>
    </div>

    <div class="pd-cols">
    <div class="pd-left">
    <!-- ============ Informações básicas ============ -->
    <?php
    // a validade abre o calendário em vez de um campo
    $b = '<div class="pd-row">' . pd_ico('event') . '<span class="pd-lbl">Validade do orçamento</span>'
        . '<button type="button" class="pd-chip" id="validadeChip" data-abrir="shCal" ' . ($validadeAtual ? '' : 'hidden') . '></button>'
        . '<button type="button" class="pd-plus" id="validadePlus" data-abrir="shCal" aria-label="Escolher a validade" ' . ($validadeAtual ? 'hidden' : '') . '>' . pd_ico('add') . '</button></div>';
    $b .= pd_linha_reveal('hourglass_top', 'Prazo de execução', 'revPrazo', trim($ov('prazo_execucao')) !== '',
        '<input class="pd-in" name="prazo_execucao" maxlength="160" placeholder="Ex.: 5 dias úteis" value="' . vn_h($ov('prazo_execucao')) . '" aria-label="Prazo de execução" style="margin:0">');
    $st = vn_pedido_status_label((string)($orcamentoEdit['status'] ?? 'Pendente'));
    $b .= '<div class="pd-row">' . pd_ico('flag') . '<span class="pd-lbl">Situação do pedido</span><select class="pd-in" name="status" aria-label="Situação do pedido" style="max-width:210px;margin:0;min-height:48px">';
    $opcoesSt = array_keys(vn_pedido_status_lista());
    if ($st !== '' && !in_array($st, $opcoesSt, true)) $opcoesSt[] = $st;   // situação antiga (ex.: Recusado) continua escolhida
    foreach ($opcoesSt as $o) { $b .= '<option' . ($st === $o ? ' selected' : '') . '>' . vn_h($o) . '</option>'; }
    $b .= '</select></div>';
    $b .= '<div style="margin-top:8px">'
        . '<input class="pd-in" name="equipamento" maxlength="190" placeholder="Equipamento" value="' . vn_h($ov('equipamento')) . '" aria-label="Equipamento">'
        . '<textarea class="pd-in" name="defeito" rows="2" placeholder="Defeito" aria-label="Defeito" style="min-height:76px">' . vn_h($ov('defeito')) . '</textarea>'
        . '<textarea class="pd-in" name="observacoes" placeholder="Observações" aria-label="Observações">' . vn_h($orcamentoEdit['observacoes'] ?? '') . '</textarea></div>';
    echo pd_secao('secBasicas', 'Informações básicas', $b);

    /* ============ Compromissos ============ */
    $visitaAberta = ((float)($orcamentoEdit['visita_valor'] ?? 0) > 0) || !empty($orcamentoEdit['visita_data']);
    $c = pd_linha_reveal('construction', 'Marcar visita técnica', 'revVisita', $visitaAberta,
        '<div class="pd-f"><label class="pd-lab" for="vDesc">Descrição</label><input class="pd-in" id="vDesc" name="visita_descricao" value="' . vn_h($orcamentoEdit['visita_descricao'] ?? 'Visita técnica') . '"></div>'
        . '<div class="pd-grid2"><div class="pd-f"><label class="pd-lab" for="vValor">Valor da visita</label><input class="pd-in js-extra" id="vValor" name="visita_valor" inputmode="decimal" value="' . vnp_money_val($orcamentoEdit['visita_valor'] ?? 0) . '"></div>'
        . '<div class="pd-f"><label class="pd-lab" for="vData">Data (opcional)</label><input class="pd-in" id="vData" type="date" name="visita_data" value="' . vn_h(vn_date_input_value($orcamentoEdit['visita_data'] ?? '')) . '"></div></div>',
        'Descrição, valor e data');
    $maoAberta = (float)($orcamentoEdit['mao_obra_valor'] ?? 0) > 0;
    $c .= pd_linha_reveal('engineering', 'Mão de obra', 'revMao', $maoAberta,
        '<div class="pd-grid2"><div class="pd-f"><label class="pd-lab" for="mDesc">Descrição</label><input class="pd-in" id="mDesc" name="mao_obra_descricao" value="' . vn_h($orcamentoEdit['mao_obra_descricao'] ?? 'Mão de obra') . '"></div>'
        . '<div class="pd-f"><label class="pd-lab" for="mValor">Valor</label><input class="pd-in js-extra" id="mValor" name="mao_obra_valor" inputmode="decimal" value="' . vnp_money_val($orcamentoEdit['mao_obra_valor'] ?? 0) . '"></div></div>',
        'Descrição e valor');
    $compAberto = !empty($orcamentoEdit['compromisso_data']) || trim((string)($orcamentoEdit['compromisso_desc'] ?? '')) !== '';
    $c .= pd_linha_reveal('calendar_month', 'Marcar compromisso', 'revComp', $compAberto,
        '<div class="pd-f"><label class="pd-lab" for="cDesc">O que será feito</label><input class="pd-in" id="cDesc" name="compromisso_desc" maxlength="180" placeholder="Ex.: Instalar as câmeras" value="' . vn_h($orcamentoEdit['compromisso_desc'] ?? '') . '"></div>'
        . '<div class="pd-grid2"><div class="pd-f"><label class="pd-lab" for="cData">Data</label><input class="pd-in" id="cData" type="date" name="compromisso_data" value="' . vn_h(vn_date_input_value($orcamentoEdit['compromisso_data'] ?? '')) . '"></div>'
        . '<div class="pd-f"><label class="pd-lab" for="cHora">Hora</label><input class="pd-in" id="cHora" type="time" name="compromisso_hora" value="' . vn_h((string)($orcamentoEdit['compromisso_hora'] ?? '')) . '"></div></div>',
        'Data e hora do atendimento');
    echo pd_secao('secCompromissos', 'Compromissos', $c);
    ?>
    </div>
    <div class="pd-right">
    <!-- ============ Pedido ============ -->
    <?php
    // Cada item aparece embaixo da sua linha: serviços (e mão de obra) em "Serviços", o resto em "Peças".
    $htmlItem = function (array $it): string {
        return '<div class="pd-item" data-item tabindex="0" role="button" aria-label="Editar item">'
            . '<input type="hidden" name="item_tipo[]" value="' . vn_h($it['tipo'] ?? 'material') . '">'
            . '<input type="hidden" name="item_desc[]" value="' . vn_h($it['descricao'] ?? '') . '">'
            . '<input type="hidden" name="item_qtd[]" value="' . vn_h($it['quantidade'] ?? 1) . '">'
            . '<input type="hidden" name="item_preco[]" value="' . vnp_money_val($it['valor_unitario'] ?? 0) . '">'
            . '<input type="hidden" name="item_custo[]" value="' . vnp_money_val($it['custo_unitario'] ?? 0) . '">'
            . '<input type="hidden" name="item_origem[]" value="' . vn_h($it['origem_peca'] ?? 'estoque') . '">'
            . '<input type="hidden" name="item_linha[]" value="' . vn_h($it['linha'] ?? 'cftv') . '">'
            . '<input type="hidden" name="item_un[]" value="' . vn_h($it['unidade'] ?? '') . '">'
            . '<span class="n">' . vn_h($it['descricao'] ?? '') . '</span><span class="s"></span><span class="t"></span>'
            . '<button type="button" class="x" data-item-remover aria-label="Remover item">' . pd_ico('close') . '</button></div>';
    };
    $htmlServ = ''; $htmlPeca = ''; $htmlSerr = '';
    foreach ($itensEdit as $it) {
        if (in_array((string)($it['tipo'] ?? ''), ['servico', 'mao_obra'], true)) $htmlServ .= $htmlItem($it);
        elseif ((string)($it['linha'] ?? 'cftv') === 'serralheria') $htmlSerr .= $htmlItem($it);
        else $htmlPeca .= $htmlItem($it);
    }
    $p = '<button type="button" class="pd-row clic" data-grupo-abrir="servicos">' . pd_ico('assignment') . '<span class="pd-lbl">Serviços<small id="servResumo" data-dica="' . $countServicos . ' no catálogo · ou cadastre na hora">' . $countServicos . ' no catálogo · ou cadastre na hora</small></span><span class="pd-plus" aria-hidden="true">' . pd_ico('add') . '</span>' . pd_ico('chevron_right', ' class="pd-go" hidden') . '</button>'
        . '<div class="pd-items" id="itemsServicos">' . $htmlServ . '</div>'
        . '<button type="button" class="pd-row clic" data-grupo-abrir="pecas" data-linha-row="cftv">' . pd_ico('deployed_code') . '<span class="pd-lbl">Peças<small id="pecaResumo" data-dica="' . $countPecas . ' da base · ou cadastre uma para comprar">' . $countPecas . ' da base · ou cadastre uma para comprar</small></span><span class="pd-plus" aria-hidden="true">' . pd_ico('add') . '</span>' . pd_ico('chevron_right', ' class="pd-go" hidden') . '</button>'
        . '<div class="pd-items" id="itemsPecas" data-linha-row="cftv">' . $htmlPeca . '</div>'
        . '<button type="button" class="pd-row clic" data-grupo-abrir="serralheria" data-linha-row="serralheria">' . pd_ico('build') . '<span class="pd-lbl">Peças de serralheria<small id="serrResumo" data-dica="' . $countSerr . ' da base · ou cadastre uma para comprar">' . $countSerr . ' da base · ou cadastre uma para comprar</small></span><span class="pd-plus" aria-hidden="true">' . pd_ico('add') . '</span>' . pd_ico('chevron_right', ' class="pd-go" hidden') . '</button>'
        . '<div class="pd-items" id="itemsSerralheria" data-linha-row="serralheria">' . $htmlSerr . '</div>'
        . '<button type="button" class="pd-row clic" data-abrir="shDesconto">' . pd_ico('sell') . '<span class="pd-lbl">Desconto<small id="descontoResumo">Nenhum</small></span><span class="pd-plus" aria-hidden="true">' . pd_ico('add') . '</span></button>'
        . '<div class="pd-total"><div>Total<small id="totalSub"></small></div><span class="v" id="totalView">R$ 0,00</span></div>';
    echo pd_secao('secPedido', 'Pedido', $p);
    ?>
    </div>
    </div>

    <!-- ============ Detalhes ============ -->
    <?php
    $resumoMeios = $meiosSel ? implode(', ', $meiosSel) : 'Escolha como o cliente pode pagar';
    $resumoCla = trim($ov('clausulas')) !== '' ? mb_substr(preg_replace('/\s+/', ' ', trim($ov('clausulas'))), 0, 80) : 'Toque para escrever ou usar o modelo';
    $d = pd_linha_reveal('payments', 'Condições de pagamento', 'revCond', trim($ov('condicoes_pagamento')) !== '',
        '<textarea class="pd-in" name="condicoes_pagamento" placeholder="Ex.: 50% na aprovação e 50% na entrega" aria-label="Condições de pagamento" style="min-height:84px">' . vn_h($ov('condicoes_pagamento')) . '</textarea>'
        . '<label class="pd-check"><input type="checkbox" name="padrao_condicoes_pagamento" value="1">Usar como padrão nos próximos pedidos</label>');
    $d .= '<button type="button" class="pd-row clic" data-abrir="shMeios">' . pd_ico('paid') . '<span class="pd-lbl">Meios de pagamento<small id="meiosResumo">' . vn_h($resumoMeios) . '</small></span>' . pd_ico('chevron_right', ' class="pd-go"') . '</button>';
    $d .= pd_linha_reveal('verified_user', 'Garantia', 'revGar', trim($ov('garantia')) !== '',
        '<textarea class="pd-in" name="garantia" placeholder="Ex.: 90 dias contra defeitos de instalação" aria-label="Garantia" style="min-height:84px">' . vn_h($ov('garantia')) . '</textarea>'
        . '<label class="pd-check"><input type="checkbox" name="padrao_garantia" value="1">Usar como padrão nos próximos pedidos</label>');
    $d .= '<button type="button" class="pd-row clic" data-abrir="shClausulas">' . pd_ico('fact_check') . '<span class="pd-lbl">Cláusulas contratuais<small id="claResumo">' . vn_h($resumoCla) . '</small></span>' . pd_ico('chevron_right', ' class="pd-go"') . '</button>';
    $d .= pd_linha_reveal('note_add', 'Anotações', 'revNotas', trim((string)($orcamentoEdit['anotacoes'] ?? '')) !== '',
        '<textarea class="pd-in" name="anotacoes" placeholder="Só você vê. Não aparece no documento." aria-label="Anotações" style="min-height:84px">' . vn_h($orcamentoEdit['anotacoes'] ?? '') . '</textarea>', 'Só para você, não vai no documento');
    $d .= pd_linha_reveal('assignment', 'Relatório', 'revRel', trim((string)($orcamentoEdit['relatorio'] ?? '')) !== '',
        '<textarea class="pd-in" name="relatorio" placeholder="O que foi feito, testes realizados, recomendações…" aria-label="Relatório" style="min-height:104px">' . vn_h($orcamentoEdit['relatorio'] ?? '') . '</textarea>', 'Relatório técnico do atendimento');
    $d .= pd_linha_reveal('signature', 'Assinatura do técnico', 'revAssin', trim((string)($orcamentoEdit['assinatura_tecnico'] ?? '')) !== '',
        '<input class="pd-in" name="assinatura_tecnico" value="' . vn_h($orcamentoEdit['assinatura_tecnico'] ?? '') . '" placeholder="Nome do técnico/responsável" aria-label="Assinatura do técnico" style="margin:0">');
    $d .= '<button type="button" class="pd-row clic" data-abrir="shDoc">' . pd_ico('picture_as_pdf') . '<span class="pd-lbl">Documento (PDF)<small>O que aparece no orçamento</small></span>' . pd_ico('chevron_right', ' class="pd-go"') . '</button>';
    echo pd_secao('secDetalhes', 'Detalhes', $d);

    /* ============ Fotos ============ */
    $f = '<div class="pd-fotos" id="fotosGrid">';
    foreach ($fotosEdit as $ft) {
        $f .= '<div class="pd-foto" data-foto-id="' . (int)$ft['id'] . '"><img src="' . vn_h($ft['caminho']) . '" alt="Foto do pedido" loading="lazy"><button type="button" data-foto-remover aria-label="Remover foto">' . pd_ico('close') . '</button></div>';
    }
    $f .= '</div><input type="file" id="fotosInput" accept="image/*" multiple hidden>'
        . '<button type="button" class="pd-row clic" id="fotosAdd">' . pd_ico('photo_camera') . '<span class="pd-lbl">Inserir foto<small id="fotosResumo">As fotos aparecem no documento</small></span><span class="pd-plus" aria-hidden="true">' . pd_ico('add') . '</span></button>';
    echo pd_secao('secFotos', 'Fotos', $f);
    ?>

    <!-- ============ Janela: item do pedido ============ -->
    <dialog class="pd-sheet" id="shItem" aria-labelledby="shItemT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shItemT">Editar item</h3><span></span></div>
        <div class="pd-sheet-b">
            <label class="pd-lab" for="iDesc">Descrição</label><input class="pd-in" id="iDesc" maxlength="255">
            <div class="pd-grid2">
                <div class="pd-f"><label class="pd-lab" for="iTipo">Tipo</label><select class="pd-in" id="iTipo"><option value="material">Material</option><option value="equipamento">Equipamento</option><option value="produto_usado">Produto usado</option><option value="servico">Serviço</option><option value="mao_obra">Mão de obra</option><option value="outro">Outro</option></select></div>
                <div class="pd-f" id="iOrigemBox"><label class="pd-lab" for="iOrigem">Origem</label><select class="pd-in" id="iOrigem"><option value="estoque">Da base</option><option value="comprar">Comprar</option></select></div>
            </div>
            <label class="pd-lab" for="iQtd">Quantidade</label>
            <div class="pd-step"><button type="button" class="menos" data-i-menos aria-label="Diminuir">−</button><input id="iQtd" inputmode="decimal"><button type="button" class="mais" data-i-mais aria-label="Aumentar">＋</button></div>
            <div class="pd-f" id="iUnBox" hidden><label class="pd-lab" for="iUn">Cobrado por (medida)</label><select class="pd-in" id="iUn">
                <option value="">Quantidade simples (sem medida)</option><option value="m2">m² (metro quadrado)</option><option value="m">m (metro linear)</option><option value="un">unidade</option><option value="h">hora</option><option value="degrau">degrau</option><option value="kg">kg (quilo)</option></select></div>
            <div class="pd-grid2">
                <div class="pd-f"><label class="pd-lab" for="iPreco">Preço de venda (R$)</label><input class="pd-in" id="iPreco" inputmode="decimal"></div>
                <div class="pd-f"><label class="pd-lab" for="iCusto">Custo por unidade (R$)</label><input class="pd-in" id="iCusto" inputmode="decimal"></div>
            </div>
            <div class="pd-grid2">
                <div class="pd-f"><label class="pd-lab" for="iLucro">Margem de lucro (%)</label><input class="pd-in" id="iLucro" inputmode="decimal" placeholder="Ex.: 30"></div>
                <div class="pd-f"><label class="pd-lab" for="iMarkup">Markup (%)</label><input class="pd-in" id="iMarkup" inputmode="decimal" placeholder="Ex.: 43"></div>
            </div>
            <p class="pd-hint">Preencheu o custo e a margem, o preço de venda é calculado sozinho. Mexeu no preço, as porcentagens se ajustam.</p>
            <div class="pd-valor"><span>Valor</span><span id="iValor">R$ 0,00</span></div>
        </div>
        <div class="pd-sheet-f"><button type="button" class="pd-btn bad" data-i-remover style="flex:0 0 auto"><?php echo pd_ico('delete'); ?>Remover</button><button type="button" class="pd-btn" data-i-salvar>salvar item</button></div>
    </div></dialog>

    <!-- ============ Janela: informar a medida de um produto cobrado por medida (serralheria) ============ -->
    <!-- O produto define COMO se cobra (m², metro, degrau, hora, kg) e o valor de cada medida quando é cadastrado; aqui só se informa a medida. -->
    <dialog class="pd-sheet alta" id="shMedida" aria-labelledby="shMedidaT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shMedidaT">Informar a medida</h3><span></span></div>
        <div class="pd-sheet-b">
            <div class="med-prod"><strong data-med-nome>—</strong><small data-med-det></small></div>

            <div data-med-bloco="m2" hidden>
                <div class="pd-grid2">
                    <div class="pd-f"><label class="pd-lab" for="medLarg">Largura (m)</label><input class="pd-in" id="medLarg" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                    <div class="pd-f"><label class="pd-lab" for="medAlt">Altura (m)</label><input class="pd-in" id="medAlt" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                </div>
                <label class="pd-lab" for="medPecas">Quantas peças iguais</label>
                <div class="pd-step"><button type="button" class="menos" data-med-menos aria-label="Diminuir">−</button><input id="medPecas" inputmode="numeric" value="1" aria-label="Quantas peças iguais"><button type="button" class="mais" data-med-mais aria-label="Aumentar">＋</button></div>
            </div>
            <div data-med-bloco="m" hidden><label class="pd-lab" for="medComp">Comprimento (m)</label><input class="pd-in" id="medComp" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
            <div data-med-bloco="degrau" hidden><label class="pd-lab" for="medDeg">Quantos degraus</label><input class="pd-in" id="medDeg" inputmode="numeric" placeholder="0" autocomplete="off"></div>
            <div data-med-bloco="h" hidden><label class="pd-lab" for="medHoras">Quantas horas</label><input class="pd-in" id="medHoras" inputmode="decimal" placeholder="0" autocomplete="off"></div>
            <div data-med-bloco="kg" hidden><label class="pd-lab" for="medKg">Peso (kg)</label><input class="pd-in" id="medKg" inputmode="decimal" placeholder="0" autocomplete="off"></div>

            <label class="pd-lab" for="medPreco" data-med-preco-rot>Preço por medida (R$)</label>
            <input class="pd-in" id="medPreco" inputmode="decimal" placeholder="0,00" autocomplete="off">
            <label class="pd-lab" for="medObs">Detalhes (opcional)</label>
            <input class="pd-in" id="medObs" maxlength="120" placeholder="Ex.: com pintura eletrostática" autocomplete="off">

            <div class="med-resumo" aria-live="polite">
                <div class="l"><span>Medida</span><b data-r-medida>—</b></div>
                <div class="l"><span data-r-unit-rot>Preço por medida</span><b data-r-unit>R$ 0,00</b></div>
                <div class="l tot"><span>Total</span><b data-r-total>R$ 0,00</b></div>
            </div>
            <p class="pd-hint">O valor de cada medida vem do cadastro do produto. Se precisar, ajuste aqui só para este pedido.</p>
        </div>
        <div class="pd-sheet-f"><div class="med-foot-tot" aria-live="polite"><small>Total</small><b data-r-total-foot>R$ 0,00</b></div><button type="button" class="pd-btn" data-med-add disabled>adicionar ao pedido</button></div>
    </div></dialog>

    <!-- ============ Telas: peças do pedido / serviços do pedido ============ -->
    <?php foreach (['pecas' => ['peças do pedido', 'adicionar peças'], 'serralheria' => ['peças de serralheria do pedido', 'adicionar peças de serralheria'], 'servicos' => ['serviços do pedido', 'adicionar serviços']] as $gk => $gt): ?>
    <dialog class="pd-sheet" id="shLista<?php echo ucfirst($gk); ?>" data-lista-grupo="<?php echo $gk; ?>" aria-labelledby="shLista<?php echo ucfirst($gk); ?>T"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-lista-voltar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shLista<?php echo ucfirst($gk); ?>T"><?php echo $gt[0]; ?></h3><span></span></div>
        <div class="pd-sheet-b" data-lista></div>
        <div class="pd-sheet-f col">
            <div class="pd-subtotal">Subtotal: <strong data-lista-subtotal>R$ 0,00</strong></div>
            <div class="linha"><button type="button" class="pd-btn sec" data-lista-add><?php echo $gt[1]; ?></button><button type="button" class="pd-btn" data-lista-salvar disabled>salvar</button></div>
        </div>
    </div></dialog>
    <?php endforeach; ?>

    <!-- ============ Janela: desconto ============ -->
    <dialog class="pd-sheet" id="shDesconto" aria-labelledby="shDescT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shDescT">Descontos</h3><span></span></div>
        <div class="pd-sheet-b">
            <?php foreach (['servico' => 'Serviço', 'peca' => 'Peça'] as $k => $tit): ?>
            <div data-desc="<?php echo $k; ?>">
                <h4 class="pd-dtit"><?php echo $tit; ?> <span class="pd-hint" style="display:inline;margin:0" data-desc-base></span></h4>
                <p class="pd-dper">Desconto em percentual ou valor?</p>
                <div class="pd-seg"><button type="button" data-tipo="%" aria-pressed="true">%</button><button type="button" data-tipo="R$" aria-pressed="false">R$</button></div>
                <p class="pd-dper">Quanto desconto?</p>
                <div class="pd-pct" data-rapidos>
                    <button type="button" data-v="5" aria-pressed="false">5%</button><button type="button" data-v="10" aria-pressed="false">10%</button>
                    <input class="pd-in" data-outro inputmode="decimal" placeholder="Outros (%)" aria-label="Outro valor de desconto em <?php echo strtolower($tit); ?>">
                </div>
            </div>
            <?php endforeach; ?>
            <div class="pd-total" style="margin-top:0"><div>Desconto total</div><span class="v" id="descontoTotalView">-R$ 0,00</span></div>
            <button type="button" class="pd-btn sec" data-desc-limpar style="width:100%;margin-top:12px">remover desconto</button>
        </div>
        <div class="pd-sheet-f"><button type="button" class="pd-btn" data-desc-salvar>salvar desconto</button></div>
    </div></dialog>

    <!-- ============ Janela: meios de pagamento ============ -->
    <dialog class="pd-sheet" id="shMeios" aria-labelledby="shMeiosT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shMeiosT">Meios de pagamento</h3><span></span></div>
        <div class="pd-sheet-b">
            <p class="pd-hint" style="margin-top:0">Marque os meios que você aceita. Eles aparecem no documento.</p>
            <?php foreach ($meiosLista as $m): ?>
                <label class="pd-meio"><?php echo vn_h($m); ?><input type="checkbox" name="meios_pagamento[]" value="<?php echo vn_h($m); ?>" <?php echo in_array($m, $meiosSel, true) ? 'checked' : ''; ?>></label>
            <?php endforeach; ?>
            <label class="pd-check" style="margin-top:16px"><input type="checkbox" name="padrao_meios_pagamento" value="1">Usar como padrão nos próximos pedidos</label>
        </div>
        <div class="pd-sheet-f"><button type="button" class="pd-btn" data-fechar>continuar</button></div>
    </div></dialog>

    <!-- ============ Janela: cláusulas contratuais ============ -->
    <dialog class="pd-sheet" id="shClausulas" aria-labelledby="shClaT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shClaT">Cláusulas contratuais</h3><span></span></div>
        <div class="pd-sheet-b">
            <textarea class="pd-in" name="clausulas" id="clausulasTxt" aria-label="Cláusulas contratuais" style="min-height:min(52vh,420px)" placeholder="Escreva as cláusulas ou use o modelo abaixo"><?php echo vn_h($ov('clausulas')); ?></textarea>
            <button type="button" class="pd-btn sec" data-cla-modelo style="width:100%;margin-bottom:12px"><?php echo pd_ico('fact_check'); ?>usar o modelo do sistema</button>
            <label class="pd-check"><input type="checkbox" name="padrao_clausulas" value="1">Usar como padrão nos próximos pedidos</label>
            <p class="pd-hint">O modelo é um texto genérico: leia e ajuste antes de usar.</p>
        </div>
        <div class="pd-sheet-f"><button type="button" class="pd-btn" data-fechar>continuar</button></div>
    </div></dialog>

    <!-- ============ Janela: o que mostrar no documento ============ -->
    <dialog class="pd-sheet pequena" id="shDoc" aria-labelledby="shDocT"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shDocT">O que mostrar no documento</h3><span></span></div>
        <div class="pd-sheet-b">
            <p class="pd-hint" style="margin-top:0">Exceto pelas anotações, tudo que estiver preenchido pode aparecer no documento.</p>
            <div class="pd-doc-g"><span class="gi"><?php echo pd_ico('assignment'); ?></span>Serviços</div>
            <?php echo pd_chave('mostrar_visita', 'Mostrar visita técnica', $flag('mostrar_visita')), pd_chave('mostrar_mao_obra', 'Mostrar mão de obra', $flag('mostrar_mao_obra')); ?>
            <div class="pd-doc-g"><span class="gi"><?php echo pd_ico('deployed_code'); ?></span>Peças</div>
            <?php echo pd_chave('mostrar_itens', 'Mostrar lista de peças', $flag('mostrar_itens')), pd_chave('mostrar_valor_itens', 'Mostrar preço de cada peça', $flag('mostrar_valor_itens')),
                pd_chave('mostrar_valor_unitario', 'Mostrar valor unitário', $flag('mostrar_valor_unitario')), pd_chave('mostrar_subtotal_itens', 'Mostrar subtotal de peças', $flag('mostrar_subtotal_itens')); ?>
            <div class="pd-doc-g"><span class="gi"><?php echo pd_ico('fact_check'); ?></span>Informações</div>
            <?php echo pd_chave('mostrar_equipamento', 'Mostrar equipamento e defeito', $flag('mostrar_equipamento')), pd_chave('mostrar_pagamento', 'Mostrar pagamento', $flag('mostrar_pagamento')),
                pd_chave('mostrar_garantia', 'Mostrar garantia', $flag('mostrar_garantia')), pd_chave('mostrar_relatorio', 'Mostrar relatório', $flag('mostrar_relatorio')),
                pd_chave('mostrar_fotos', 'Mostrar fotos', $flag('mostrar_fotos')), pd_chave('mostrar_clausulas', 'Mostrar cláusulas contratuais', $flag('mostrar_clausulas')); ?>
            <div class="pd-doc-g"><span class="gi"><?php echo pd_ico('more_horiz'); ?></span>Outros</div>
            <?php echo pd_chave('mostrar_desconto', 'Mostrar desconto', $flag('mostrar_desconto')), pd_chave('mostrar_total_geral', 'Mostrar total geral', $flag('mostrar_total_geral')); ?>
        </div>
        <div class="pd-sheet-f"><button type="button" class="pd-btn" data-fechar>continuar</button></div>
    </div></dialog>

    <div class="pd-foot">
        <button type="submit" class="pd-save" id="btnSalvar">salvar pedido</button>
        <button type="submit" class="pd-pdf" id="btnPdf" title="Salvar e abrir o PDF">PDF</button>
    </div>
</form>
</div>

<!-- ============ Janela: selecione a data ============ -->
<dialog class="pd-sheet pequena" id="shCal" aria-label="Selecione a data"><div class="pd-sheet-in">
    <div class="pd-cal-h"><h3>Selecione a data</h3><button type="button" data-fechar aria-label="Fechar"><?php echo pd_ico('close'); ?></button></div>
    <div class="pd-sheet-b">
        <div class="pd-cal-nav"><strong id="calMes">—</strong><div><button type="button" id="calPrev" aria-label="Mês anterior"><?php echo pd_ico('arrow_back'); ?></button><button type="button" id="calNext" aria-label="Próximo mês"><?php echo pd_ico('arrow_forward'); ?></button></div></div>
        <div class="pd-cal-grid" id="calGrid"></div>
        <button type="button" class="pd-cal-limpar" id="calLimpar">limpar</button>
        <div class="pd-cal-comp"><strong>Compromissos neste dia</strong><small>Verifique sua disponibilidade</small><ul id="calLista"><li>Nenhum compromisso nesta data.</li></ul></div>
    </div>
    <div class="pd-sheet-f"><button type="button" class="pd-btn" id="calOk">selecionar esta data</button></div>
</div></dialog>

<!-- ============ Janela: novo cliente ============ -->
<dialog class="pd-sheet pequena" id="shCliente" aria-labelledby="shCliT"><div class="pd-sheet-in">
    <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="shCliT">Novo cliente</h3><span></span></div>
    <div class="pd-sheet-b">
        <label class="pd-lab" for="ncNome">Nome ou condomínio *</label><input class="pd-in" id="ncNome" maxlength="180" autocomplete="off">
        <label class="pd-lab" for="ncTel">WhatsApp / telefone</label><input class="pd-in" id="ncTel" inputmode="tel" maxlength="30" placeholder="(11) 90000-0000">
        <label class="pd-lab" for="ncMail">E-mail</label><input class="pd-in" id="ncMail" type="email" maxlength="150">
        <p class="pd-hint">Os demais dados (CPF/CNPJ, endereços) você completa depois em Clientes.</p>
    </div>
    <div class="pd-sheet-f"><button type="button" class="pd-btn" id="ncSalvar">cadastrar cliente</button></div>
</div></dialog>

<!-- ============ Janelas: adicionar peça / serviço (abas Catálogo | Cadastrar) ============ -->
<?php
function pd_janela_add(string $id, string $tipoBase, string $rotulo, array $chaves, array $grupos, string $linha = '', string $linhaExtra = ''): void {
    $isPeca = $tipoBase !== 'servico';
    $nomeLinha = $linha === 'serralheria' ? 'serralheria' : 'CFTV';          // catálogo onde a peça cadastrada aqui fica salva
    $tres = $isPeca && $linhaExtra !== '';                                   // aba extra: cadastrar peça da outra linha direto no catálogo dela
    $rotCad = $tres ? $nomeLinha : $rotulo;
    $comCalc = ($tipoBase === 'servico') || ($linha === 'serralheria');     // cobrar por medida (m², metro, degrau, hora, kg): peça de serralheria e serviço
    $opcCalc = ($comCalc && function_exists('vn_serr_calculos_opcoes')) ? vn_serr_calculos_opcoes() : []; ?>
    <dialog class="pd-sheet alta" id="<?php echo vn_h($id); ?>" data-rotulo="<?php echo vn_h($rotulo); ?>" data-tipo-base="<?php echo vn_h($tipoBase); ?>"<?php echo $linha !== '' ? ' data-linha="' . vn_h($linha) . '"' : ''; ?> aria-labelledby="<?php echo vn_h($id); ?>T"><div class="pd-sheet-in">
        <div class="pd-sheet-h"><button type="button" class="pd-x" data-fechar aria-label="Voltar"><?php echo pd_ico('arrow_back'); ?></button><h3 id="<?php echo vn_h($id); ?>T">Adicionar <?php echo vn_h($rotulo); ?> ao pedido</h3><span></span></div>
        <div class="pd-tabs<?php echo $tres ? ' tres' : ''; ?>">
            <button type="button" class="on" data-add-tab="cat"><?php echo pd_ico($isPeca ? 'deployed_code' : 'assignment'); ?>Catálogo</button>
            <button type="button" data-add-tab="new"><?php echo pd_ico($tres ? 'build' : 'add_circle'); ?>Cadastrar <?php echo vn_h($rotCad); ?></button>
            <?php if ($tres): ?><button type="button" data-add-tab="new" data-add-linha="<?php echo vn_h($linhaExtra); ?>"><?php echo pd_ico('videocam'); ?>Cadastrar <?php echo vn_h($linhaExtra === 'cftv' ? 'CFTV' : $linhaExtra); ?></button><?php endif; ?>
        </div>

        <div class="pd-sheet-b" data-tab-body="cat">
            <label class="pd-busca"><?php echo pd_ico('search'); ?><input type="search" data-add-busca placeholder="Buscar" aria-label="Buscar no catálogo"></label>
            <?php $temAlgum = false; foreach ($chaves as $k): $g = $grupos[$k]; if (!empty($g['itens'])) $temAlgum = true; ?>
                <div data-grupo data-grupo-tipo="<?php echo vn_h($k); ?>" data-titulo="<?php echo vn_h($g['titulo']); ?>"<?php echo empty($g['itens']) ? ' hidden' : ''; ?>>
                    <div class="pd-grp"><?php echo vn_h($g['titulo']); ?> · <?php echo count($g['itens']); ?></div>
                    <?php foreach ($g['itens'] as $c): ?>
                        <div class="pd-sel" data-sel data-id="<?php echo (int)$c['id']; ?>" data-origem="<?php echo vn_h($c['origem']); ?>" data-linha-item="<?php echo vn_h((string)($c['linha'] ?? 'cftv')); ?>" data-calculo="<?php echo vn_h((string)($c['calculo'] ?? '')); ?>" data-busca="<?php echo vn_h(vn_lower(($c['nome'] ?? '') . ' ' . ($c['descricao'] ?? '') . ' ' . $g['titulo'])); ?>">
                            <?php if (!empty($c['foto_url'])): ?><img src="<?php echo vn_h($c['foto_url']); ?>" alt="" loading="lazy"><?php else: ?><span class="sem-img"></span><?php endif; ?>
                            <div class="info"><strong><?php echo vn_h($c['nome']); ?></strong>
                                <?php if (!empty($c['descricao']) && $c['descricao'] !== $c['nome']): ?><small><?php echo vn_h($c['descricao']); ?></small><?php endif; ?>
                                <?php if (!empty($c['calculo'])): ?><small class="med-dica">Toque e informe a medida</small><?php endif; ?></div>
                            <span class="chk">✓</span>
                            <div class="preco"><small>Preço por <?php echo vn_h($c['unidade'] ?? 'unidade'); ?>:</small><strong><?php echo vn_h(vn_money($c['valor_unitario'])); ?></strong></div>
                            <?php if ($isPeca && empty($c['calculo'])): ?><div class="preco" style="border:0;padding-top:0;margin-top:0"><span class="est">Quantidade em estoque:</span><span class="est"><?php echo vn_h(number_format((float)($c['quantidade_estoque'] ?? 0), 2, ',', '.')); ?> <?php echo vn_h($c['unidade'] ?? 'un'); ?></span></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="pd-hint" data-vazio style="text-align:center;padding:30px 0"<?php echo $temAlgum ? ' hidden' : ''; ?>>Nenhum item no catálogo ainda. Use a aba "Cadastrar <?php echo vn_h($rotCad); ?>".</div>
        </div>

        <div class="pd-sheet-b" data-tab-body="new" hidden>
            <?php if ($isPeca): ?>
            <button type="button" class="pd-row clic pd-foto-up" data-new-foto-btn><?php echo pd_ico('photo_camera'); ?><span class="pd-lbl">Inserir foto<small data-new-foto-nome>A foto vai para o catálogo</small></span><span class="pd-plus" aria-hidden="true"><?php echo pd_ico('add'); ?></span></button>
            <input type="file" data-new-foto accept="image/*" hidden>
            <?php endif; ?>
            <input class="pd-in" data-new-nome placeholder="Qual é <?php echo $isPeca ? 'a peça' : 'o serviço'; ?>?" aria-label="Nome">
            <textarea class="pd-in" data-new-det placeholder="Detalhes (opcional)" aria-label="Detalhes" style="min-height:96px"></textarea>
            <div class="pd-grid2">
                <div class="pd-f"><label class="pd-lab">Tipo</label><select class="pd-in" data-new-tipo>
                    <?php if ($isPeca): ?><option value="material">Material</option><option value="equipamento">Equipamento</option><option value="produto_usado">Produto usado</option><option value="outro">Outro</option>
                    <?php else: ?><option value="servico">Serviço</option><option value="mao_obra">Mão de obra</option><?php endif; ?>
                </select></div>
                <div class="pd-f" data-new-un-box><label class="pd-lab">Unidade de medida</label><input class="pd-in" data-new-un value="un"></div>
            </div>
            <?php if ($opcCalc): ?>
            <div class="pd-f" data-new-calc-box>
                <label class="pd-lab" for="<?php echo vn_h($id); ?>Calc">Como cobrar</label>
                <select class="pd-in" id="<?php echo vn_h($id); ?>Calc" data-new-calc>
                    <option value="">Por unidade (quantidade)</option>
                    <?php foreach ($opcCalc as $oc): ?><option value="<?php echo vn_h($oc[0]); ?>"><?php echo vn_h($oc[1]); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div data-new-medida hidden>
                <div data-new-mbloco="m2" hidden>
                    <div class="pd-grid2">
                        <div class="pd-f"><label class="pd-lab">Largura (m)</label><input class="pd-in" data-new-m="larg" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                        <div class="pd-f"><label class="pd-lab">Altura (m)</label><input class="pd-in" data-new-m="alt" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                    </div>
                    <label class="pd-lab">Quantas peças iguais</label>
                    <div class="pd-step"><button type="button" class="menos" data-new-pecas-menos aria-label="Diminuir">−</button><input data-new-m="pecas" inputmode="numeric" value="1" aria-label="Quantas peças iguais"><button type="button" class="mais" data-new-pecas-mais aria-label="Aumentar">＋</button></div>
                </div>
                <div data-new-mbloco="m" hidden><label class="pd-lab">Comprimento (m)</label><input class="pd-in" data-new-m="comp" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                <div data-new-mbloco="degrau" hidden><label class="pd-lab">Quantos degraus</label><input class="pd-in" data-new-m="deg" inputmode="numeric" placeholder="0" autocomplete="off"></div>
                <div data-new-mbloco="h" hidden><label class="pd-lab">Quantas horas</label><input class="pd-in" data-new-m="horas" inputmode="decimal" placeholder="0" autocomplete="off"></div>
                <div data-new-mbloco="kg" hidden><label class="pd-lab">Peso (kg)</label><input class="pd-in" data-new-m="kg" inputmode="decimal" placeholder="0" autocomplete="off"></div>
                <p class="med-conta" data-new-conta hidden></p>
            </div>
            <?php endif; ?>
            <div class="pd-grid2">
                <div class="pd-f" data-new-preco-box><label class="pd-lab" data-new-preco-rot>Preço unitário (R$)</label><input class="pd-in" data-new-preco inputmode="decimal" placeholder="0,00"></div>
                <div class="pd-f" data-new-qtd-box><label class="pd-lab">Quantidade</label><div class="pd-step"><button type="button" class="menos" data-new-menos aria-label="Diminuir">−</button><input data-new-qtd inputmode="decimal" value="1" aria-label="Quantidade"><button type="button" class="mais" data-new-mais aria-label="Aumentar">＋</button></div></div>
            </div>
            <div class="pd-valor"><span>Valor</span><span data-new-valor>R$ 0,00</span></div>
            <button type="button" class="pd-sub-h" data-new-cl-toggle aria-expanded="true"><h4>Custo &amp; Lucro</h4><span class="pd-chev"><?php echo pd_ico('keyboard_arrow_up'); ?></span></button>
            <div data-new-cl-body>
                <input class="pd-in" data-new-custo inputmode="decimal" placeholder="Quanto cada unidade custa pra você?" aria-label="Custo por unidade">
                <div class="pd-grid2">
                    <input class="pd-in" data-new-lucro inputmode="decimal" placeholder="Margem de lucro (%)" value="<?php echo $isPeca ? '30,00' : ''; ?>" aria-label="Margem de lucro">
                    <input class="pd-in" data-new-markup inputmode="decimal" placeholder="Markup (%)" aria-label="Markup">
                </div>
                <p class="pd-hint">Margem = parte do preço que é lucro. Markup = quanto se soma ao custo. Preencheu o custo e uma das duas, o preço é calculado.</p>
            </div>
            <?php if ($isPeca): ?>
            <div class="pd-grid2" data-new-origem-box>
                <div class="pd-f"><label class="pd-lab">Origem da peça</label><select class="pd-in" data-new-origem><option value="comprar">Para comprar</option><option value="estoque">Da base (em estoque)</option></select></div>
                <div class="pd-f"><label class="pd-lab">Qtd em estoque (ao salvar)</label><input class="pd-in" data-new-estoque inputmode="decimal" placeholder="0"></div>
            </div>
            <?php endif; ?>
            <?php if ($isPeca): ?>
            <p class="pd-nota-cat" data-new-nota><?php echo pd_ico('inventory_2'); ?><span data-new-nota-txt>Fica salva no catálogo de <?php echo vn_h($nomeLinha); ?>.</span></p>
            <?php if ($tres): ?><label class="pd-check" data-new-pedido-box hidden><input type="checkbox" data-new-no-pedido checked>Adicionar também a este pedido</label><?php endif; ?>
            <?php else: ?>
            <label class="pd-check" style="margin-top:6px"><input type="checkbox" data-new-salvar>Salvar <?php echo vn_h($rotulo); ?> no meu catálogo</label>
            <?php endif; ?>
        </div>

        <div class="pd-sheet-f"><button type="button" class="pd-btn" data-add-confirmar disabled>adicionar</button></div>
    </div></dialog>
<?php }
pd_janela_add('addPecaModal', 'material', 'peça', ['material', 'equipamento', 'outro', 'produto_usado'], $grupos, 'cftv');
pd_janela_add('addSerralheriaModal', 'material', 'peça de serralheria', ['material', 'equipamento', 'outro', 'produto_usado'], $gruposSerr, 'serralheria', 'cftv');
pd_janela_add('addServicoModal', 'servico', 'serviço', ['servico', 'mao_obra'], $grupos);
?>

<script>
window.VN_PEDIDO = <?php echo json_encode([
    'catalogo'  => $catalogo,
    'comp'      => $compMap ?: new stdClass(),
    'descCfg'   => $descCfg ?: new stdClass(),
    'modelo'    => $clausulaModelo,
    'editando'  => (bool)$orcamentoEdit,
    'enderecos' => $enderecoAtual,
    'categoria' => $catAtual,
], $jsonSeguro); ?>;
</script>
<script defer src="<?php echo vn_h(vn_ui_asset('assets/js/vn-pedido.js')); ?>"></script>
<script defer src="<?php echo vn_h(vn_ui_asset('assets/js/vn-serr-medida.js')); ?>"></script>
<?php vn_ui_shell_end('novo'); ?>
