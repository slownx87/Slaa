<?php
/*
    VN System - A Pagar
    Contas e boletos que a empresa precisa pagar:
    - cadastro (descrição, fornecedor, valor, vencimento, código do boleto/PIX);
    - lista por urgência (atrasadas, esta semana, mais adiante) e as já pagas;
    - "Pagar": registra data, valor e forma, e já aceita a foto do comprovante;
    - fotos e PDFs em qualquer conta (boleto, nota, comprovante), tirados na hora ou escolhidos no aparelho;
    - cada conta é Pública (todos os administradores veem) ou Privada (só o dono vê; o usuário principal vê tudo);
    - contas que se repetem (aluguel, internet...): as próximas aparecem sozinhas; "Contas fixas" mostra e para;
    - contas parceladas: à vista ou parcelado, com entrada (em % ou em R$) e o restante em N parcelas.
    Dados e fotos: _vn_contas_pagar_lib.php. Tela: assets/css/vn-contas-pagar.css e assets/js/vn-contas-pagar.js.
    Só admin abre (funcionário e cliente ficam barrados por vn_role_pages). Quem vê o quê: cp_sql_visivel() na biblioteca.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
require_once __DIR__ . '/_vn_contas_pagar_lib.php';

const CP_VER = ['abertas', 'atrasadas', 'semana', 'pagas', 'todas'];

function cp_ver(string $v): string { return in_array($v, CP_VER, true) ? $v : 'abertas'; }

const CP_DE = ['todas', 'minhas', 'publicas', 'privadas'];

function cp_de(string $v): string { return in_array($v, CP_DE, true) ? $v : 'todas'; }

function cp_url(string $ver, string $q, int $fotos = 0, string $de = 'todas'): string
{
    $p = [];
    if ($ver !== 'abertas') $p['ver'] = $ver;
    if ($q !== '') $p['q'] = $q;
    if ($de !== 'todas') $p['de'] = $de;
    if ($fotos > 0) $p['fotos'] = $fotos;
    return 'contas-pagar.php' . ($p ? '?' . http_build_query($p) : '');
}

/* "2 fotos e 1 PDF anexados." e, se algum não entrou, o motivo. Devolve [tipo do aviso, texto]. */
function cp_texto_fotos(array $r): array
{
    $txt = '';
    if ($r['salvas'] > 0) {
        $partes = [];
        if (($r['fotos'] ?? 0) > 0) $partes[] = $r['fotos'] . ($r['fotos'] === 1 ? ' foto' : ' fotos');
        if (($r['pdfs'] ?? 0) > 0) $partes[] = $r['pdfs'] . ($r['pdfs'] === 1 ? ' PDF' : ' PDFs');
        // só fotos: "2 fotos anexadas."; só PDF ou misturado: "1 PDF anexado." / "1 foto e 2 PDFs anexados."
        $soFotos = ($r['pdfs'] ?? 0) === 0;
        $txt = implode(' e ', $partes) . ($r['salvas'] === 1 ? ($soFotos ? ' anexada.' : ' anexado.') : ($soFotos ? ' anexadas.' : ' anexados.'));
    }
    if ($r['falhas']) $txt = trim($txt . ' Não entrou: ' . implode(' ', array_slice($r['falhas'], 0, 3)));
    return [$r['falhas'] ? ($r['salvas'] > 0 ? 'warn' : 'err') : 'ok', $txt];
}

$hoje = date('Y-m-d');
$ver = cp_ver((string)($_GET['ver'] ?? 'abertas'));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$de = cp_de((string)($_GET['de'] ?? 'todas'));
$eu = cp_usuario();

/* ------------------------------------------------------------------ ações (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoAviso = 'ok';
    $msg = '';
    $verVolta = cp_ver((string)($_POST['ver'] ?? 'abertas'));
    $qVolta = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 80);
    $deVolta = cp_de((string)($_POST['de'] ?? 'todas'));
    $abrirFotos = 0;
    $tokenUsado = '';
    $feito = false;   // a operação (criar, pagar, anexar) chegou a acontecer?

    try {
        cp_ensure_tables();
        $acao = (string)($_POST['acao'] ?? '');
        $contaId = (int)($_POST['conta_id'] ?? 0);

        // Fotos grandes demais para o limite do servidor chegam como POST vazio.
        if ($acao === '' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new CpErro('Os arquivos juntos passaram do limite de envio do servidor (' . ini_get('post_max_size') . '). Envie menos arquivos de cada vez.');
        }

        if ($acao === 'salvar_conta') {
            $dados = cp_validar_conta($_POST);
            $nova = $contaId <= 0;
            if ($nova) {
                $tokenUsado = cp_token_usar((string)($_POST['token'] ?? ''), 'Essa conta já foi cadastrada. Veja na lista.');
                if ($tokenUsado === '' && cp_conta_igual_recente($dados)) throw new CpErro('Essa conta já foi cadastrada agora há pouco. Veja na lista.');
            }
            $plano = $nova ? cp_validar_parcelamento($_POST, $dados) : null;
            $rep = ($nova && !$plano) ? cp_validar_repeticao($_POST, $dados['vencimento'], $hoje) : null;
            if ($plano) {
                $contaId = cp_criar_parcelamento($dados, $plano);
                $qtdPag = count($plano['itens']);
                $msg = 'Conta parcelada em ' . $qtdPag . ' pagamentos, numerados de 1/' . $qtdPag . ' a ' . $qtdPag . '/' . $qtdPag . ': '
                    . ($plano['entrada'] > 0 ? 'entrada de ' . vn_money($plano['entrada']) . ' + ' : '')
                    . $plano['n'] . ($plano['n'] === 1 ? ' parcela' : ' parcelas') . ' (total ' . vn_money($dados['valor']) . ').';
            } elseif ($rep) {
                $contaId = cp_criar_recorrente($dados, $rep['freq'], $rep['vezes'], $hoje);
                $msg = 'Conta adicionada. Ela se repete (' . mb_strtolower(cp_frequencias()[$rep['freq']]) . ($rep['vezes'] ? ', ' . $rep['vezes'] . ' vezes' : ', sem data final') . '): cada conta seguinte aparece sozinha ' . CP_ANTECEDENCIA_DIAS . ' dias antes de vencer.';
            } else {
                $aplicar = !$nova && (!empty($_POST['aplicar_proximas']) || !empty($_POST['aplicar_parcelas']));
                $contaId = cp_salvar_conta($contaId, $dados, $aplicar);
                $msg = $nova ? 'Conta adicionada.' : 'Conta atualizada.' . ($aplicar ? ' As próximas repetições também foram atualizadas.' : '');
            }
            $feito = true;
            if ($nova) {
                $verVolta = 'abertas'; $qVolta = ''; $deVolta = 'todas';
                $msg .= ($dados['visibilidade'] ?? 'privado') === 'publico' ? ' Ela é pública: todos os administradores veem.' : ' Ela é privada: só você vê.';
            }
            if (!empty($_FILES['fotos'])) {
                $r = cp_salvar_fotos($contaId, 'conta', $_FILES['fotos']);
                if ($r['salvas'] || $r['falhas']) { [$tipoAviso, $extra] = cp_texto_fotos($r); $msg .= ' ' . $extra; if ($tipoAviso === 'err') $tipoAviso = 'warn'; }
            }
        } elseif ($acao === 'pagar') {
            $tokenUsado = cp_token_usar((string)($_POST['token'] ?? ''), 'Esse pagamento já foi registrado. Veja na lista.');
            cp_marcar_paga($contaId, $_POST);
            $feito = true;
            $msg = 'Pagamento registrado.';
            if (!empty($_FILES['fotos'])) {
                $r = cp_salvar_fotos($contaId, 'comprovante', $_FILES['fotos']);
                if ($r['salvas'] || $r['falhas']) { [$tipoAviso, $extra] = cp_texto_fotos($r); $msg .= ' ' . $extra; if ($tipoAviso === 'err') $tipoAviso = 'warn'; }
            }
        } elseif ($acao === 'desfazer_pagamento') {
            cp_desfazer_pagamento($contaId);
            $msg = 'Pagamento desfeito: a conta voltou para "em aberto".';
        } elseif ($acao === 'add_fotos') {
            if (!cp_conta($contaId)) throw new CpErro('Conta não encontrada.');
            if (empty($_FILES['fotos'])) throw new CpErro('Escolha ou tire pelo menos uma foto, ou escolha um PDF.');
            $tokenUsado = cp_token_usar((string)($_POST['token'] ?? ''), 'Esses arquivos já foram enviados. Veja na conta.');
            $r = cp_salvar_fotos($contaId, (string)($_POST['tipo'] ?? 'conta'), $_FILES['fotos']);
            $feito = $r['salvas'] > 0;
            [$tipoAviso, $msg] = cp_texto_fotos($r);
            if ($msg === '') throw new CpErro('Escolha ou tire pelo menos uma foto, ou escolha um PDF.');
            $abrirFotos = $contaId;
        } elseif ($acao === 'del_foto') {
            $contaId = cp_excluir_foto((int)($_POST['foto_id'] ?? 0));
            if ($contaId <= 0) throw new CpErro('Anexo não encontrado.');
            $msg = 'Anexo removido.';
            $abrirFotos = $contaId;
        } elseif ($acao === 'parar_recorrente') {
            $recId = (int)($_POST['rec_id'] ?? 0);
            $depoisDe = $hoje;
            if ($recId <= 0) {
                $c = cp_conta($contaId);
                if (!$c || empty($c['recorrente_id'])) throw new CpErro('Essa conta não se repete.');
                $recId = (int)$c['recorrente_id'];
                $depoisDe = (string)$c['vencimento'];   // pela linha: mantém esta conta e as anteriores
            }
            $n = cp_parar_recorrente($recId, $depoisDe);
            $msg = 'Parou de repetir.' . ($n > 0 ? ' ' . $n . ($n === 1 ? ' conta futura sem pagamento foi removida.' : ' contas futuras sem pagamento foram removidas.') : '');
        } elseif ($acao === 'cancelar_parcelamento') {
            $c = cp_conta($contaId);
            if (!$c || empty($c['parcelamento_id'])) throw new CpErro('Essa conta não é parcelada.');
            $n = cp_cancelar_parcelamento((int)$c['parcelamento_id']);
            $msg = 'Parcelamento cancelado.' . ($n > 0 ? ' ' . $n . ($n === 1 ? ' parcela sem pagamento foi removida.' : ' parcelas sem pagamento foram removidas.') : ' Nada foi removido (as parcelas restantes já estão pagas ou têm foto).');
        } elseif ($acao === 'excluir_conta') {
            $parar = !empty($_POST['parar_repeticao']);
            cp_excluir_conta($contaId, $parar);
            $msg = 'Conta excluída.' . ($parar ? ' A repetição foi parada: não vai aparecer mais.' : '');
        } else {
            throw new CpErro('Ação inválida. Recarregue a página e tente de novo.');
        }
    } catch (Throwable $e) {
        if ($tokenUsado !== '' && !$feito) cp_token_liberar($tokenUsado);   // deu erro antes de acontecer: pode tentar de novo
        $tipoAviso = 'err';
        $msg = $e instanceof CpErro ? $e->getMessage() : vn_public_error($e, 'contas-pagar');
        $abrirFotos = 0;
    }

    $_SESSION['cp_flash'] = ['tipo' => $tipoAviso, 'msg' => $msg];
    $destino = cp_url($verVolta, $qVolta, $abrirFotos, $deVolta);
    if (vn_is_ajax()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $tipoAviso !== 'err', 'msg' => $msg, 'redirect' => $destino], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . $destino);
    exit;
}

/* ------------------------------------------------------------------ leitura (GET) */
$flash = $_SESSION['cp_flash'] ?? null;
unset($_SESSION['cp_flash']);

$erroTela = '';
$contas = []; $resumo = []; $fotosPorConta = []; $series = []; $parcelamentos = [];
try {
    cp_ensure_tables();
    // uma única vez: contas repetidas que foram criadas adiantadas (vários meses, ou logo ao pagar) voltam a aparecer só 7 dias antes de vencer
    $enxutas = cp_ajuste_unico('recorrentes_7_dias_antes', function () use ($hoje) { return cp_enxugar_recorrentes($hoje); });
    if ($enxutas) $flash = ['tipo' => 'ok', 'msg' => 'Arrumei as contas que se repetem: cada uma agora só aparece ' . CP_ANTECEDENCIA_DIAS . ' dias antes de vencer (removi ' . (int)$enxutas . ' conta(s) que estavam adiantadas; elas voltam sozinhas na data certa).'];
    cp_gerar_recorrentes($hoje);           // cria as contas repetidas que chegaram a 7 dias do vencimento
    $resumo = cp_resumo($hoje);
    $contas = cp_listar($ver, $q, $hoje, $de);
    $fotosPorConta = cp_fotos_das_contas(array_column($contas, 'id'));
    $series = cp_recorrentes_ativas();
    $parcelamentos = cp_resumo_parcelamentos(array_column($contas, 'parcelamento_id'));
} catch (Throwable $e) {
    $erroTela = vn_public_error($e, 'contas-pagar');
}

$abas = [
    'abertas'   => ['Em aberto', 'q_abertas'],
    'atrasadas' => ['Atrasadas', 'q_atrasadas'],
    'semana'    => ['Próximos 7 dias', 'q_semana'],
    'pagas'     => ['Pagas', 'q_pagas'],
    'todas'     => ['Todas', 'q_todas'],
];
$vazio = [
    'abertas'   => 'Nenhuma conta em aberto. Toque em "Nova conta" para cadastrar uma.',
    'atrasadas' => 'Nenhuma conta atrasada. Tudo em dia!',
    'semana'    => 'Nada vence nos próximos 7 dias.',
    'pagas'     => 'Nenhuma conta paga ainda.',
    'todas'     => 'Nenhuma conta cadastrada ainda. Toque em "Nova conta" para começar.',
];
$totalLista = 0.0;
foreach ($contas as $c) { $totalLista += (int)$c['pago'] === 1 ? (float)($c['valor_pago'] ?? $c['valor']) : (float)$c['valor']; }
$formas = cp_formas();

vn_ui_shell_start('A Pagar', 'contas-pagar', [
    'css' => ['assets/css/vn-contas-pagar.css'],
    'js'  => ['assets/js/vn-download.js', 'assets/js/vn-contas-pagar.js'],
    'sub' => 'Contas e boletos da empresa',
]);
?>
<div id="cpRoot" class="cp" data-hoje="<?php echo vn_h($hoje); ?>" data-ver="<?php echo vn_h($ver); ?>" data-q="<?php echo vn_h($q); ?>" data-de="<?php echo vn_h($de); ?>"<?php echo !empty($_GET['fotos']) ? ' data-abrir-fotos="' . (int)$_GET['fotos'] . '"' : ''; ?>>

<?php if ($flash && !empty($flash['msg'])): $ft = in_array($flash['tipo'] ?? '', ['ok', 'warn', 'err'], true) ? $flash['tipo'] : 'ok'; ?>
    <div class="vn-alert <?php echo $ft; ?>" role="status"><?php echo vn_h($flash['msg']); ?></div>
<?php endif; ?>
<?php if ($erroTela): ?>
    <div class="vn-alert err" role="alert"><?php echo vn_h($erroTela); ?></div>
<?php endif; ?>

<section class="vn-page-head">
    <div>
        <span class="vn-kicker">Contas a pagar</span>
        <h2>O que falta pagar.</h2>
        <p>Cadastre as contas e boletos, marque quando pagar e guarde a foto ou o PDF da conta e do comprovante junto de cada uma. Cada conta é <strong>pública</strong> (todos os administradores veem) ou <strong>privada</strong> (só você vê).</p>
    </div>
    <div class="vn-actions">
        <button type="button" class="vn-btn vn-btn-primary" data-cp-nova><span class="material-symbols-outlined" aria-hidden="true">add</span>Nova conta</button>
    </div>
</section>

<section class="vn-grid vn-grid-4 cp-kpis">
    <a class="vn-card" href="<?php echo vn_h(cp_url('abertas', '')); ?>">
        <div class="label"><span>Total em aberto</span><span class="material-symbols-outlined" style="font-size:1.1rem" aria-hidden="true">request_quote</span></div>
        <div class="value money-big"><?php echo vn_money($resumo['v_abertas'] ?? 0); ?></div>
        <div class="sub"><?php echo (int)($resumo['q_abertas'] ?? 0); ?> conta(s) a pagar</div>
    </a>
    <a class="vn-card cp-kpi-atraso<?php echo ($resumo['q_atrasadas'] ?? 0) > 0 ? ' alerta' : ''; ?>" href="<?php echo vn_h(cp_url('atrasadas', '')); ?>">
        <div class="label"><span>Atrasadas</span><span class="material-symbols-outlined" style="font-size:1.1rem" aria-hidden="true">warning</span></div>
        <div class="value money-big"><?php echo vn_money($resumo['v_atrasadas'] ?? 0); ?></div>
        <div class="sub"><?php echo (int)($resumo['q_atrasadas'] ?? 0); ?> conta(s) vencida(s)</div>
    </a>
    <a class="vn-card" href="<?php echo vn_h(cp_url('semana', '')); ?>">
        <div class="label"><span>Vence em 7 dias</span><span class="material-symbols-outlined" style="font-size:1.1rem" aria-hidden="true">event</span></div>
        <div class="value money-big"><?php echo vn_money($resumo['v_semana'] ?? 0); ?></div>
        <div class="sub"><?php echo (int)($resumo['q_semana'] ?? 0); ?> conta(s) hoje ou nos próximos dias</div>
    </a>
    <a class="vn-card" href="<?php echo vn_h(cp_url('pagas', '')); ?>">
        <div class="label"><span>Pago neste mês</span><span class="material-symbols-outlined" style="font-size:1.1rem" aria-hidden="true">task_alt</span></div>
        <div class="value money-big"><?php echo vn_money($resumo['v_pago_mes'] ?? 0); ?></div>
        <div class="sub"><?php echo (int)($resumo['q_pagas'] ?? 0); ?> conta(s) paga(s) no total</div>
    </a>
</section>

<section class="vn-panel">
    <div class="vn-panel-head">
        <div><h3><?php echo vn_h($abas[$ver][0]); ?></h3><small><?php echo count($contas); ?> conta(s) · <?php echo vn_money($totalLista); ?><?php echo $q !== '' ? ' · busca: "' . vn_h($q) . '"' : ''; ?></small></div>
        <?php if ($series): ?><button type="button" class="vn-mini-btn" data-cp-abrir="cpDlgSeries"><span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>Contas fixas (<?php echo count($series); ?>)</button><?php endif; ?>
    </div>

    <div class="cp-tools">
        <nav class="vn-segment" aria-label="Filtrar contas">
            <?php foreach ($abas as $k => [$rot, $campo]): ?>
                <a href="<?php echo vn_h(cp_url($k, $q, 0, $de)); ?>"<?php echo $k === $ver ? ' class="active" aria-current="page"' : ''; ?>><?php echo vn_h($rot); ?> <small><?php echo (int)($resumo[$campo] ?? 0); ?></small></a>
            <?php endforeach; ?>
        </nav>
        <form class="cp-busca" method="get" action="contas-pagar.php" role="search">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>">
            <select name="de" aria-label="De quem são as contas" onchange="this.form.submit()">
                <option value="todas"<?php echo $de === 'todas' ? ' selected' : ''; ?>>Todas as que posso ver</option>
                <option value="minhas"<?php echo $de === 'minhas' ? ' selected' : ''; ?>>Só as minhas</option>
                <option value="publicas"<?php echo $de === 'publicas' ? ' selected' : ''; ?>>Só as públicas</option>
                <option value="privadas"<?php echo $de === 'privadas' ? ' selected' : ''; ?>>Só as privadas</option>
            </select>
            <input type="search" name="q" value="<?php echo vn_h($q); ?>" placeholder="Buscar conta, fornecedor…" aria-label="Buscar conta" maxlength="80">
            <button type="submit" class="vn-mini-btn"><span class="material-symbols-outlined" aria-hidden="true">search</span>Buscar</button>
            <?php if ($q !== '' || $de !== 'todas'): ?><a class="vn-mini-btn" href="<?php echo vn_h(cp_url($ver, '')); ?>">Limpar</a><?php endif; ?>
        </form>
    </div>
    <?php if ($eu['super']): ?><p class="cp-nota">Você é o administrador principal: vê todas as contas, inclusive as privadas dos outros usuários (aparecem com o nome do dono).</p><?php endif; ?>

<?php if (!$contas): ?>
    <div class="vn-empty"><span class="material-symbols-outlined" aria-hidden="true">request_quote</span><?php echo vn_h($q !== '' ? 'Nenhuma conta encontrada para essa busca.' : $vazio[$ver]); ?></div>
<?php else: ?>
    <table class="vn-table cp-table">
        <thead><tr><th>Conta</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th>Anexos</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($contas as $c):
            $id = (int)$c['id'];
            $estado = cp_estado($c, $hoje);
            $paga = $estado === 'paga';
            $fotos = $fotosPorConta[$id] ?? [];
            $recRotulo = !empty($c['recorrente_id'])
                ? cp_rotulo_repeticao((string)$c['rec_freq'], $c['rec_total'] !== null ? (int)$c['rec_total'] : null, (int)$c['ocorrencia'])
                : null;
            $parcRotulo = !empty($c['parcelamento_id'])
                ? cp_rotulo_parcela($c['parcela_num'] !== null ? (int)$c['parcela_num'] : null, $c['parcela_ordem'] !== null ? (int)$c['parcela_ordem'] : null, $c['parcela_qtd'] !== null ? (int)$c['parcela_qtd'] : null)
                : null;
            $parcNumero = !empty($c['parcelamento_id']) ? cp_numero_parcela($c['parcela_ordem'] !== null ? (int)$c['parcela_ordem'] : null, $c['parcela_qtd'] !== null ? (int)$c['parcela_qtd'] : null) : null;
            $visPriv = (string)($c['visibilidade'] ?? 'publico') === 'privado';
            $donoNome = trim((string)($c['dono_nome'] ?: ($c['dono_usuario'] ?? '')));
            $souDono = (int)($c['dono_id'] ?? 0) > 0 && (int)$c['dono_id'] === $eu['id'];
            $rotulosEstado = ['paga' => ['pago', 'Paga'], 'atrasada' => ['atrasada', 'Atrasada'], 'hoje' => ['hoje', 'Vence hoje'], 'semana' => ['pendente', 'Esta semana'], 'futura' => ['', 'A vencer']];
            $dados = [
                'id' => $id,
                'descricao' => (string)$c['descricao'],
                'fornecedor' => (string)($c['fornecedor'] ?? ''),
                'categoria' => (string)($c['categoria'] ?? ''),
                'valor' => number_format((float)$c['valor'], 2, ',', '.'),
                'vencimento' => (string)$c['vencimento'],
                'codigo' => (string)($c['codigo'] ?? ''),
                'observacao' => (string)($c['observacao'] ?? ''),
                'pago' => $paga ? 1 : 0,
                'pago_em' => (string)($c['pago_em'] ?? ''),
                'valor_pago' => number_format((float)($c['valor_pago'] ?? $c['valor']), 2, ',', '.'),
                'forma' => (string)($c['forma_pagamento'] ?? ''),
                'fotos' => $fotos,
                'vis' => $visPriv ? 'privado' : 'publico',
                'pode_vis' => cp_pode_mudar_visibilidade($c),
                'rec' => $recRotulo === null ? null : ['rotulo' => $recRotulo, 'ativa' => (int)$c['rec_ativa']],
                'parc' => $parcRotulo === null ? null : [
                    'rotulo' => $parcRotulo,
                    'total' => number_format((float)($parcelamentos[(int)$c['parcelamento_id']]['total'] ?? $c['valor']), 2, ',', '.'),
                    'q' => (int)($parcelamentos[(int)$c['parcelamento_id']]['q'] ?? 1),
                    'pagas' => (int)($parcelamentos[(int)$c['parcelamento_id']]['pagas'] ?? 0),
                ],
            ];
        ?>
            <tr class="cp-row <?php echo vn_h($estado); ?>" data-cp="<?php echo vn_h(json_encode($dados, JSON_UNESCAPED_UNICODE)); ?>">
                <td data-label="Conta"><div class="cp-bloco">
                    <span class="vn-row-title"><?php echo vn_h($c['descricao']); ?><?php if ($parcNumero !== null): ?> <span class="cp-num"><?php echo vn_h($parcNumero); ?></span><?php endif; ?></span>
                    <?php $sub = array_filter([(string)($c['fornecedor'] ?? ''), (string)($c['categoria'] ?? '')]); ?>
                    <?php if ($sub): ?><span class="vn-row-sub"><?php echo vn_h(implode(' · ', $sub)); ?></span><?php endif; ?>
                    <?php if ($recRotulo !== null): ?><span class="cp-rec"><span class="material-symbols-outlined" aria-hidden="true">event_repeat</span><?php echo vn_h($recRotulo . ((int)$c['rec_ativa'] ? '' : ' · encerrada')); ?></span><?php endif; ?>
                    <?php if ($parcRotulo !== null): ?><span class="cp-rec"><span class="material-symbols-outlined" aria-hidden="true">payments</span><?php echo vn_h($parcRotulo); ?></span><?php endif; ?>
                    <?php if ($visPriv): ?><span class="cp-vis privada" title="Só o dono vê esta conta"><span class="material-symbols-outlined" aria-hidden="true">lock</span>Privada<?php echo (!$souDono && $donoNome !== '') ? ' · ' . vn_h($donoNome) : ''; ?></span>
                    <?php elseif ((int)($c['dono_id'] ?? 0) > 0): ?><span class="cp-vis publica" title="Todos os administradores veem esta conta"><span class="material-symbols-outlined" aria-hidden="true">groups</span>Pública<?php echo (!$souDono && $donoNome !== '') ? ' · ' . vn_h($donoNome) : ''; ?></span><?php endif; ?>
                    <?php if (!empty($c['observacao'])): ?><span class="vn-row-sub cp-obs"><?php echo vn_h(mb_strimwidth((string)$c['observacao'], 0, 90, '…')); ?></span><?php endif; ?>
                </div></td>
                <td data-label="Vencimento"><div class="cp-bloco">
                    <?php if ($paga): ?>
                        <span class="vn-row-title">Pago em <?php echo vn_h(vn_date_br($c['pago_em'])); ?></span>
                        <span class="vn-row-sub"><?php echo vn_h(($formas[$c['forma_pagamento'] ?? ''] ?? 'Forma não informada') . ' · venc. ' . substr(vn_date_br($c['vencimento']), 0, 5)); ?></span>
                    <?php else: ?>
                        <span class="vn-row-title"><?php echo vn_h(vn_date_br($c['vencimento'])); ?></span>
                        <span class="vn-row-sub cp-quando"><?php echo vn_h(cp_quando((string)$c['vencimento'], $hoje)); ?></span>
                    <?php endif; ?>
                </div></td>
                <td data-label="Valor" class="num"><div class="cp-bloco">
                    <strong><?php echo vn_money($c['valor']); ?></strong>
                    <?php if ($paga && $c['valor_pago'] !== null && abs((float)$c['valor_pago'] - (float)$c['valor']) >= 0.005): ?><span class="vn-row-sub">Pago: <?php echo vn_money($c['valor_pago']); ?></span><?php endif; ?>
                </div></td>
                <td data-label="Situação"><span class="vn-badge <?php echo vn_h($rotulosEstado[$estado][0]); ?>"><?php echo vn_h($rotulosEstado[$estado][1]); ?></span></td>
                <td data-label="Anexos">
                    <?php if ($fotos): ?>
                        <button type="button" class="cp-thumbs" data-cp-acao="fotos" aria-label="Ver os <?php echo count($fotos); ?> anexo(s) de <?php echo vn_h($c['descricao']); ?>">
                            <?php foreach (array_slice($fotos, 0, 3) as $f): ?>
                                <?php if (($f['formato'] ?? 'img') === 'pdf'): ?><span class="cp-pdf-mini" title="<?php echo vn_h($f['nome']); ?>"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>PDF</span>
                                <?php else: ?><img loading="lazy" alt="" src="contas-pagar-foto.php?id=<?php echo (int)$f['id']; ?>&amp;mini=1"><?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($fotos) > 3): ?><span class="cp-mais">+<?php echo count($fotos) - 3; ?></span><?php endif; ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="vn-mini-btn" data-cp-acao="fotos"><span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>Anexar foto ou PDF</button>
                    <?php endif; ?>
                </td>
                <td data-label="Ações"><div class="vn-actions">
                    <?php if ($paga): ?>
                        <form method="post" data-danger data-confirm="Desfazer o pagamento de &quot;<?php echo vn_h($c['descricao']); ?>&quot;? A conta volta para &quot;em aberto&quot;.">
                            <input type="hidden" name="acao" value="desfazer_pagamento"><input type="hidden" name="conta_id" value="<?php echo $id; ?>">
                            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
                            <button type="submit" class="vn-mini-btn"><span class="material-symbols-outlined" aria-hidden="true">history</span>Desfazer</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="vn-mini-btn vn-btn-primary" data-cp-acao="pagar"><span class="material-symbols-outlined" aria-hidden="true">check</span>Pagar</button>
                        <?php if (!empty($c['codigo'])): ?>
                            <button type="button" class="vn-mini-btn" data-copy="<?php echo vn_h($c['codigo']); ?>" data-copy-ok="Código copiado."><span class="material-symbols-outlined" aria-hidden="true">content_copy</span>Código</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="vn-mini-btn" data-cp-acao="editar"><span class="material-symbols-outlined" aria-hidden="true">edit</span>Editar</button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($contas) >= 400): ?><p class="cp-nota">Mostrando as 400 primeiras. Use a busca para achar uma conta específica.</p><?php endif; ?>
<?php endif; ?>
</section>

<?php /* ------------------------------------------------------------ janelas ------------------------------------------------------------ */ ?>

<dialog class="vn-dialog cp-dlg" id="cpDlgConta" aria-labelledby="cpContaTitulo">
    <form method="post" enctype="multipart/form-data" data-cp-form>
        <input type="hidden" name="acao" value="salvar_conta">
        <input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="token" value="">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
        <div class="vn-dialog-head"><h4 id="cpContaTitulo" data-cp-titulo>Nova conta</h4></div>
        <div class="vn-dialog-body">
            <div class="vn-form-grid">
                <div class="vn-field full"><label for="cpDescricao">O que é a conta? *</label><input id="cpDescricao" name="descricao" required maxlength="200" placeholder="Ex.: Aluguel da loja, Conta de luz"></div>
                <div class="vn-field"><label for="cpValor" data-cp-rot-valor>Valor (R$) *</label><input id="cpValor" name="valor" required inputmode="numeric" autocomplete="off" placeholder="0,00" data-cp-money></div>
                <div class="vn-field"><label for="cpVenc" data-cp-rot-venc>Vencimento *</label><input id="cpVenc" name="vencimento" type="date" required></div>
                <div class="vn-field"><label for="cpFornecedor">Fornecedor</label><input id="cpFornecedor" name="fornecedor" maxlength="180" placeholder="Quem vai receber"></div>
                <div class="vn-field"><label for="cpCategoria">Categoria</label><input id="cpCategoria" name="categoria" maxlength="60" list="cpCategorias" placeholder="Escolha ou digite"></div>
                <div class="vn-field full" data-cp-so-nova><label for="cpCondicao">Forma de pagamento</label>
                    <select id="cpCondicao" name="condicao"><option value="avista">À vista (uma conta só)</option><option value="parcelado">Parcelado (entrada + parcelas)</option></select></div>
                <div class="vn-field" data-cp-so-nova data-cp-parc hidden><label for="cpEntradaTipo">Entrada</label>
                    <select id="cpEntradaTipo" name="entrada_tipo"><option value="">Sem entrada</option><option value="pct">Em porcentagem (%)</option><option value="val">Em valor (R$)</option></select></div>
                <div class="vn-field" data-cp-so-nova data-cp-parc data-cp-entrada hidden><label for="cpEntradaValor" data-cp-rot-entrada>Entrada (%)</label>
                    <input id="cpEntradaValor" name="entrada_valor" inputmode="decimal" autocomplete="off" placeholder="Ex.: 30"></div>
                <div class="vn-field" data-cp-so-nova data-cp-parc hidden><label for="cpParcelas" data-cp-rot-parcelas>Restante em quantas parcelas?</label>
                    <input id="cpParcelas" name="parcelas" inputmode="numeric" maxlength="3" autocomplete="off" value="2"></div>
                <div class="vn-field" data-cp-so-nova data-cp-parc hidden><label for="cpIntervalo">Intervalo entre parcelas</label>
                    <select id="cpIntervalo" name="intervalo"><?php foreach (cp_intervalos() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="vn-field" data-cp-so-nova data-cp-parc data-cp-entrada hidden><label for="cpVencParcela">Vencimento da 1ª parcela</label>
                    <input id="cpVencParcela" name="venc_parcela" type="date"></div>
                <div class="vn-field full cp-resumo-parc" data-cp-so-nova data-cp-parc hidden role="status" data-cp-resumo-parc></div>
                <div class="vn-field" data-cp-so-nova data-cp-repetir><label for="cpRepetir">Repetir</label>
                    <select id="cpRepetir" name="repetir"><option value="">Não repete</option><?php foreach (cp_frequencias() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="vn-field" data-cp-so-nova data-cp-repetir data-cp-vezes hidden><label for="cpVezes">Quantas vezes?</label><input id="cpVezes" name="vezes" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="Vazio = sem data final"></div>
                <div class="vn-field full"><label for="cpCodigo">Código do boleto ou PIX copia e cola</label><input id="cpCodigo" name="codigo" maxlength="512" autocomplete="off" placeholder="Opcional (para copiar na hora de pagar)"></div>
                <div class="vn-field full"><label for="cpObs">Observação</label><textarea id="cpObs" name="observacao" maxlength="2000" rows="2" placeholder="Opcional"></textarea></div>
                <div class="vn-field full"><label for="cpVis">Quem pode ver esta conta</label>
                    <select id="cpVis" name="visibilidade"><?php foreach (cp_visibilidades() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select>
                    <small class="cp-ajuda" data-cp-vis-ajuda>Privada: aparece só para você (e para o administrador principal). Pública: todos os administradores veem.</small></div>
                <div class="vn-field full cp-rec-box" data-cp-rec-box hidden>
                    <p><span class="material-symbols-outlined" aria-hidden="true">event_repeat</span><span data-cp-rec-texto></span></p>
                    <label class="cp-check"><input type="checkbox" name="aplicar_proximas" value="1"> Aplicar o que mudei (nome, valor, fornecedor, categoria, observação) também às próximas repetições</label>
                    <button type="button" class="vn-mini-btn" data-cp-parar><span class="material-symbols-outlined" aria-hidden="true">close</span>Parar de repetir</button>
                </div>
                <div class="vn-field full cp-rec-box" data-cp-parc-box hidden>
                    <p><span class="material-symbols-outlined" aria-hidden="true">payments</span><span data-cp-parc-texto></span></p>
                    <label class="cp-check"><input type="checkbox" name="aplicar_parcelas" value="1"> Aplicar nome, fornecedor, categoria e observação também às outras parcelas ainda não pagas</label>
                    <button type="button" class="vn-mini-btn" data-cp-cancelar-parc><span class="material-symbols-outlined" aria-hidden="true">close</span>Cancelar parcelamento</button>
                </div>
                <div class="vn-field full" data-cp-so-nova>
                    <label for="cpFotosConta">Fotos ou PDF da conta (boleto, nota…)</label>
                    <input id="cpFotosConta" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos>
                    <div class="cp-previews" data-cp-previews></div>
                </div>
            </div>
            <datalist id="cpCategorias"><?php foreach (cp_categorias() as $cat): ?><option value="<?php echo vn_h($cat); ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="vn-dialog-foot">
            <button type="button" class="vn-btn cp-excluir" data-cp-excluir hidden><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir</button>
            <span class="vn-spacer"></span>
            <button type="button" class="vn-btn" data-cp-fechar>Cancelar</button>
            <button type="submit" class="vn-btn vn-btn-primary" data-cp-enviar>Salvar</button>
        </div>
    </form>
    <form method="post" id="cpFormCancelarParc" data-danger data-confirm="Cancelar o parcelamento? As parcelas que ainda não foram pagas (e não têm foto) serão removidas. As pagas ficam." hidden>
        <input type="hidden" name="acao" value="cancelar_parcelamento"><input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
    </form>
    <form method="post" id="cpFormParar" data-danger data-confirm="Parar de repetir esta conta? As próximas contas que ainda não foram pagas (e não têm foto) serão removidas. Esta e as anteriores ficam." hidden>
        <input type="hidden" name="acao" value="parar_recorrente"><input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
    </form>
    <form method="post" id="cpFormExcluir" data-danger data-confirm="Excluir esta conta e as fotos dela? Isso não pode ser desfeito." hidden>
        <input type="hidden" name="acao" value="excluir_conta"><input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
    </form>
</dialog>

<dialog class="vn-dialog cp-dlg cp-dlg-wide" id="cpDlgSeries" aria-labelledby="cpSeriesTitulo">
    <div class="vn-dialog-head"><h4 id="cpSeriesTitulo">Contas fixas (que se repetem)</h4><p class="cp-dlg-sub">Cada conta seguinte é criada sozinha 7 dias antes de vencer. Para mudar uma, abra a conta na lista e toque em Editar.</p></div>
    <div class="vn-dialog-body">
        <?php if (!$series): ?><div class="cp-sem-fotos">Nenhuma conta fixa no momento.</div><?php endif; ?>
        <?php foreach ($series as $r): ?>
            <div class="cp-serie">
                <div class="cp-serie-info">
                    <strong><?php echo vn_h($r['descricao']); ?></strong>
                    <small><?php echo vn_h(cp_frequencias()[$r['frequencia']] ?? 'Repete'); ?> · <?php echo vn_money($r['valor']); ?><?php echo !empty($r['fornecedor']) ? ' · ' . vn_h($r['fornecedor']) : ''; ?></small>
                    <small>Em aberto: <?php echo vn_h($r['proxima'] ? 'vence ' . vn_date_br($r['proxima']) : 'nenhuma'); ?> · <?php echo $r['total_vezes'] !== null ? (int)$r['proximo_n'] . ' de ' . (int)$r['total_vezes'] . ' criadas' : 'sem data final'; ?></small>
                    <?php $pc = cp_proxima_a_criar($r); ?>
                    <small><?php echo $pc ? 'Próxima: vence ' . vn_h(vn_date_br($pc['vence'])) . ' · aparece em ' . vn_h(substr(vn_date_br($pc['aparece']), 0, 5)) : 'Todas as contas já foram criadas'; ?></small>
                </div>
                <form method="post" data-danger data-confirm="Parar de repetir &quot;<?php echo vn_h($r['descricao']); ?>&quot;? As contas futuras que ainda não foram pagas (e não têm foto) serão removidas.">
                    <input type="hidden" name="acao" value="parar_recorrente"><input type="hidden" name="rec_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
                    <button type="submit" class="vn-mini-btn"><span class="material-symbols-outlined" aria-hidden="true">close</span>Parar</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="vn-dialog-foot"><button type="button" class="vn-btn" data-cp-fechar>Fechar</button></div>
</dialog>

<dialog class="vn-dialog cp-dlg" id="cpDlgExcluirRec" aria-labelledby="cpExcluirRecTitulo">
    <div class="vn-dialog-head"><h4 id="cpExcluirRecTitulo">Excluir conta que se repete</h4><p class="cp-dlg-sub" data-cp-excluir-rec-nome></p></div>
    <div class="vn-dialog-body">
        <p style="margin:0">Esta conta se repete. O que você quer fazer?</p>
        <form method="post" class="cp-opcao">
            <input type="hidden" name="acao" value="excluir_conta"><input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <button type="submit" class="vn-btn vn-btn-danger vn-btn-block"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir só esta</button>
            <small>A conta do próximo mês continua e aparece 7 dias antes de vencer.</small>
        </form>
        <form method="post" class="cp-opcao">
            <input type="hidden" name="acao" value="excluir_conta"><input type="hidden" name="parar_repeticao" value="1"><input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <button type="submit" class="vn-btn vn-btn-danger vn-btn-block"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir e parar de repetir</button>
            <small>Remove esta e as próximas que ainda não foram pagas. Nenhuma conta nova será criada.</small>
        </form>
    </div>
    <div class="vn-dialog-foot"><button type="button" class="vn-btn" data-cp-fechar>Cancelar</button></div>
</dialog>

<dialog class="vn-dialog cp-dlg" id="cpDlgPagar" aria-labelledby="cpPagarTitulo">
    <form method="post" enctype="multipart/form-data" data-cp-form>
        <input type="hidden" name="acao" value="pagar">
        <input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="token" value="">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
        <div class="vn-dialog-head"><h4 id="cpPagarTitulo">Registrar pagamento</h4><p class="cp-dlg-sub" data-cp-resumo></p></div>
        <div class="vn-dialog-body">
            <div class="vn-form-grid">
                <div class="vn-field"><label for="cpPagoEm">Data do pagamento *</label><input id="cpPagoEm" name="pago_em" type="date" required></div>
                <div class="vn-field"><label for="cpValorPago">Valor pago (R$) *</label><input id="cpValorPago" name="valor_pago" required inputmode="numeric" autocomplete="off" data-cp-money></div>
                <div class="vn-field full"><label for="cpForma">Como pagou?</label>
                    <select id="cpForma" name="forma_pagamento"><option value="">Não informar</option><?php foreach ($formas as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="vn-field full">
                    <label for="cpFotosPagar">Foto ou PDF do comprovante</label>
                    <input id="cpFotosPagar" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos>
                    <div class="cp-previews" data-cp-previews></div>
                </div>
            </div>
        </div>
        <div class="vn-dialog-foot">
            <button type="button" class="vn-btn" data-cp-fechar>Cancelar</button>
            <button type="submit" class="vn-btn vn-btn-primary" data-cp-enviar><span class="material-symbols-outlined" aria-hidden="true">check</span>Confirmar pagamento</button>
        </div>
    </form>
</dialog>

<dialog class="vn-dialog cp-dlg cp-dlg-wide" id="cpDlgFotos" aria-labelledby="cpFotosTitulo">
    <div class="vn-dialog-head"><h4 id="cpFotosTitulo">Fotos e PDFs</h4><p class="cp-dlg-sub" data-cp-resumo></p></div>
    <div class="vn-dialog-body">
        <div class="cp-galeria" data-cp-galeria></div>
        <form method="post" enctype="multipart/form-data" class="cp-envio" data-cp-form>
            <input type="hidden" name="acao" value="add_fotos">
            <input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <div class="vn-form-grid">
                <div class="vn-field"><label for="cpTipoFoto">Tipo do anexo</label>
                    <select id="cpTipoFoto" name="tipo"><?php foreach (cp_tipos_foto() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="vn-field"><label for="cpFotosAdd">Tirar foto, escolher da galeria ou um PDF</label>
                    <input id="cpFotosAdd" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos></div>
            </div>
            <div class="cp-previews" data-cp-previews></div>
            <div class="cp-envio-acoes"><button type="submit" class="vn-btn vn-btn-primary" data-cp-enviar><span class="material-symbols-outlined" aria-hidden="true">upload</span>Enviar anexos</button></div>
        </form>
    </div>
    <div class="vn-dialog-foot"><button type="button" class="vn-btn" data-cp-fechar>Fechar</button></div>
</dialog>

<dialog class="cp-viewer" id="cpViewer" aria-label="Foto ampliada">
    <div class="cp-viewer-top">
        <span class="cp-viewer-legenda" data-cp-legenda></span>
        <a class="vn-mini-btn" data-cp-original target="_blank" rel="noopener"><span class="material-symbols-outlined" aria-hidden="true">download</span>Abrir original</a>
        <button type="button" class="vn-icon-btn" data-cp-fechar aria-label="Fechar foto"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
    <div class="cp-viewer-palco">
        <button type="button" class="vn-icon-btn cp-seta" data-cp-anterior aria-label="Foto anterior"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
        <img alt="" data-cp-img>
        <button type="button" class="vn-icon-btn cp-seta" data-cp-proxima aria-label="Próxima foto"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
    </div>
</dialog>

</div>
<?php vn_ui_shell_end('contas-pagar'); ?>
