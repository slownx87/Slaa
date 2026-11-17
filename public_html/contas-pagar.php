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

const CP_DE = ['publicas', 'privadas', 'minhas', 'todas', 'outros'];   // publicas = as contas normais (padrão); os totais de cada uma não se misturam

function cp_de(string $v): string
{
    if ($v === 'outros' && !cp_usuario()['super']) return 'publicas';   // privadas dos outros: só o administrador principal consulta
    return in_array($v, CP_DE, true) ? $v : 'publicas';
}

function cp_url(string $ver, string $q, int $fotos = 0, string $de = 'publicas'): string
{
    $p = [];
    if ($ver !== 'abertas') $p['ver'] = $ver;
    if ($q !== '') $p['q'] = $q;
    if ($de !== 'publicas') $p['de'] = $de;
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
$de = cp_de((string)($_GET['de'] ?? 'publicas'));
$eu = cp_usuario();

/* ------------------------------------------------------------------ ações (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoAviso = 'ok';
    $msg = '';
    $verVolta = cp_ver((string)($_POST['ver'] ?? 'abertas'));
    $qVolta = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 80);
    $deVolta = cp_de((string)($_POST['de'] ?? 'publicas'));
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
                $verVolta = 'abertas'; $qVolta = '';
                $deVolta = ($dados['visibilidade'] ?? 'privado') === 'publico' ? 'publicas' : 'privadas';   // volta para onde a conta nova aparece
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
$contas = []; $resumo = []; $resumoPriv = null; $fotosPorConta = []; $series = []; $parcelamentos = [];
try {
    cp_ensure_tables();
    // uma única vez: contas repetidas que foram criadas adiantadas (vários meses, ou logo ao pagar) voltam a aparecer só 7 dias antes de vencer
    $enxutas = cp_ajuste_unico('recorrentes_7_dias_antes', function () use ($hoje) { return cp_enxugar_recorrentes($hoje); });
    if ($enxutas) $flash = ['tipo' => 'ok', 'msg' => 'Arrumei as contas que se repetem: cada uma agora só aparece ' . CP_ANTECEDENCIA_DIAS . ' dias antes de vencer (removi ' . (int)$enxutas . ' conta(s) que estavam adiantadas; elas voltam sozinhas na data certa).'];
    cp_gerar_recorrentes($hoje);           // cria as contas repetidas que chegaram a 7 dias do vencimento
    $resumo = cp_resumo($hoje, $de);                                              // cartões e abas: só o escopo escolhido
    $resumoPriv = $de === 'publicas' ? cp_resumo($hoje, 'privadas') : null;       // as privadas ficam num bloco à parte, fora dos totais
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
/* De quem são as contas mostradas (e somadas): cada escopo tem os seus totais, as privadas não se misturam com as normais. */
$escopos = [
    'publicas' => ['Públicas', '', 'Contas da empresa, que todos os administradores veem. As privadas ficam fora destes totais.'],
    'privadas' => ['Privadas', '', 'Só você vê estas contas, e a soma é só das suas. Elas não entram nos totais da empresa.'],
    'minhas'   => ['Minhas', '', 'Tudo que você cadastrou: públicas e privadas juntas.'],
    'todas'    => ['Todas', '', 'Públicas e as suas privadas somadas juntas, só enquanto esta opção estiver escolhida.'],
];
if ($eu['super']) $escopos['outros'] = ['Privadas dos outros', '', 'Só consulta (você é o administrador principal): contas privadas de outros usuários. Sem somas: nada daqui entra nos seus totais nem nos da empresa.'];
$formas = cp_formas();
$rotulosEstado = ['paga' => ['pago', 'Paga'], 'atrasada' => ['atrasada', 'Atrasada'], 'hoje' => ['hoje', 'Vence hoje'], 'semana' => ['pendente', 'Esta semana'], 'futura' => ['', 'A vencer']];

/* Soma de uma lista de contas: públicas e privadas separadas (cada uma com o seu valor). */
$somar = function (array $lista): array {
    $r = ['pub' => 0.0, 'priv' => 0.0, 'qpub' => 0, 'qpriv' => 0];
    foreach ($lista as $c) {
        $v = (int)$c['pago'] === 1 ? (float)($c['valor_pago'] ?? $c['valor']) : (float)$c['valor'];
        if ((string)($c['visibilidade'] ?? 'publico') === 'privado') { $r['priv'] += $v; $r['qpriv']++; } else { $r['pub'] += $v; $r['qpub']++; }
    }
    return $r;
};
$textoSoma = function (array $r) use ($de): string {
    if ($de === 'outros') return '';   // privadas de outras pessoas: nunca viram soma
    if ($de === 'publicas' || $de === 'privadas' || $r['qpriv'] === 0 || $r['qpub'] === 0) return vn_money($r['pub'] + $r['priv']);
    return 'Públicas ' . vn_money($r['pub']) . ' · Privadas ' . vn_money($r['priv']);   // escopos mistos: cada uma com o seu valor
};

/* Uma linha da lista (mesmo desenho da Central de Cobranças). Clicar nela abre o detalhe. */
$linha = function (array $c) use ($fotosPorConta, $hoje, $eu, $parcelamentos, $formas, $ver, $q, $de, $rotulosEstado): string {
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
    $donoOutro = (!$souDono && $donoNome !== '') ? $donoNome : '';
    $sub = array_filter([(string)($c['fornecedor'] ?? ''), (string)($c['categoria'] ?? '')]);
    $dados = [
        'id' => $id,
        'descricao' => (string)$c['descricao'],
        'fornecedor' => (string)($c['fornecedor'] ?? ''),
        'categoria' => (string)($c['categoria'] ?? ''),
        'valor' => number_format((float)$c['valor'], 2, ',', '.'),
        'vencimento' => (string)$c['vencimento'],
        'venc_br' => vn_date_br((string)$c['vencimento']),
        'quando' => $paga ? '' : cp_quando((string)$c['vencimento'], $hoje),
        'estado' => $estado,
        'estado_rot' => $rotulosEstado[$estado][1],
        'num' => $parcNumero,
        'codigo' => (string)($c['codigo'] ?? ''),
        'observacao' => (string)($c['observacao'] ?? ''),
        'pago' => $paga ? 1 : 0,
        'pago_em' => (string)($c['pago_em'] ?? ''),
        'pago_em_br' => $paga ? vn_date_br((string)$c['pago_em']) : '',
        'valor_pago' => number_format((float)($c['valor_pago'] ?? $c['valor']), 2, ',', '.'),
        'forma' => (string)($c['forma_pagamento'] ?? ''),
        'forma_rot' => $paga ? (string)($formas[$c['forma_pagamento'] ?? ''] ?? '') : '',
        'fotos' => $fotos,
        'vis' => $visPriv ? 'privado' : 'publico',
        'dono' => $donoOutro,
        'pode_vis' => cp_pode_mudar_visibilidade($c),
        'rec' => $recRotulo === null ? null : ['rotulo' => $recRotulo, 'ativa' => (int)$c['rec_ativa']],
        'parc' => $parcRotulo === null ? null : [
            'rotulo' => $parcRotulo,
            'total' => number_format((float)($parcelamentos[(int)$c['parcelamento_id']]['total'] ?? $c['valor']), 2, ',', '.'),
            'q' => (int)($parcelamentos[(int)$c['parcelamento_id']]['q'] ?? 1),
            'pagas' => (int)($parcelamentos[(int)$c['parcelamento_id']]['pagas'] ?? 0),
        ],
    ];
    $partes = $sub;
    if ($recRotulo !== null) $partes[] = $recRotulo . ((int)$c['rec_ativa'] ? '' : ' · encerrada');
    if ($parcRotulo !== null) $partes[] = $parcRotulo;
    if ($fotos) $partes[] = count($fotos) . (count($fotos) === 1 ? ' anexo' : ' anexos');
    $quando = $paga
        ? '<span class="cb-venc paga">Pago em ' . vn_h(substr(vn_date_br((string)$c['pago_em']), 0, 5)) . '</span>'
        : '<span class="cb-venc ' . ($estado === 'atrasada' ? 'atrasada' : ($estado === 'hoje' ? 'hoje' : '')) . '">' . vn_h(cp_quando((string)$c['vencimento'], $hoje)) . '</span>';
    $valorMostrado = $paga && $c['valor_pago'] !== null ? (float)$c['valor_pago'] : (float)$c['valor'];
    $acts = '';
    if ($paga) {
        $acts .= '<form method="post" data-danger data-confirm="Desfazer o pagamento de &quot;' . vn_h($c['descricao']) . '&quot;? A conta volta para &quot;em aberto&quot;.">'
            . '<input type="hidden" name="acao" value="desfazer_pagamento"><input type="hidden" name="conta_id" value="' . $id . '">'
            . '<input type="hidden" name="ver" value="' . vn_h($ver) . '"><input type="hidden" name="q" value="' . vn_h($q) . '"><input type="hidden" name="de" value="' . vn_h($de) . '">'
            . '<button type="submit" class="cb-btn"><span class="material-symbols-outlined" aria-hidden="true">history</span>Desfazer</button></form>';
    } else {
        $acts .= '<button type="button" class="cb-btn pri" data-cp-acao="pagar"><span class="material-symbols-outlined" aria-hidden="true">check</span>Pagar</button>';
        if (!empty($c['codigo'])) $acts .= '<button type="button" class="cb-btn" data-copy="' . vn_h($c['codigo']) . '" data-copy-ok="Código copiado."><span class="material-symbols-outlined" aria-hidden="true">content_copy</span>Código</button>';
    }
    $acts .= '<button type="button" class="cb-btn icon" data-cp-acao="detalhe" aria-label="Abrir ' . vn_h($c['descricao']) . '"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>';
    $badges = '';
    if ($parcNumero !== null) $badges .= ' <span class="cp-num">' . vn_h($parcNumero) . '</span>';
    if ($visPriv) $badges .= ' <span class="cp-vis privada" title="Só o dono vê esta conta"><span class="material-symbols-outlined" aria-hidden="true">lock</span>Privada' . ($donoOutro !== '' ? ' · ' . vn_h($donoOutro) : '') . '</span>';
    elseif ($donoOutro !== '' && $de !== 'publicas') $badges .= ' <span class="cp-vis publica" title="Conta pública"><span class="material-symbols-outlined" aria-hidden="true">groups</span>' . vn_h($donoOutro) . '</span>';
    return '<article class="cb-row cp-row is-' . vn_h($estado) . ' ' . vn_h($estado) . '" data-cp="' . vn_h(json_encode($dados, JSON_UNESCAPED_UNICODE)) . '">'
        . '<span class="cb-ico"><span class="material-symbols-outlined" aria-hidden="true">' . ($visPriv ? 'lock' : 'request_quote') . '</span></span>'
        . '<div class="cb-main"><button type="button" class="cb-name" data-cp-acao="detalhe">' . vn_h($c['descricao']) . '</button>' . $badges
        . '<div class="cb-sub">' . ($partes ? vn_h(implode(' · ', $partes)) : '&nbsp;') . '</div></div>'
        . '<div class="cb-when"><strong class="cb-valor">' . vn_h(vn_money($valorMostrado)) . '</strong>' . $quando . '</div>'
        . '<div class="cb-acts">' . $acts . '</div>'
        . '</article>';
};

/* Seções por urgência (como na Central de Cobranças) nas abas "Em aberto" e "Todas"; nas outras, uma lista só. */
$secoes = [];
if (in_array($ver, ['abertas', 'todas'], true)) {
    $grupos = ['atrasada' => [], 'semana' => [], 'futura' => [], 'paga' => []];
    foreach ($contas as $c) { $e = cp_estado($c, $hoje); $grupos[$e === 'hoje' ? 'semana' : $e][] = $c; }
    $tit = ['atrasada' => ['warning', 'Atrasadas'], 'semana' => ['schedule', 'Hoje e próximos 7 dias'], 'futura' => ['event', 'Mais adiante'], 'paga' => ['task_alt', 'Pagas']];
    foreach ($grupos as $k => $l) if ($l) $secoes[] = ['chave' => $k, 'icone' => $tit[$k][0], 'titulo' => $tit[$k][1], 'lista' => $l];
} elseif ($contas) {
    $secoes[] = ['chave' => $ver === 'atrasadas' ? 'atrasada' : ($ver === 'pagas' ? 'paga' : 'semana'), 'icone' => ['atrasadas' => 'warning', 'pagas' => 'task_alt'][$ver] ?? 'schedule', 'titulo' => $abas[$ver][0], 'lista' => $contas];
}
$soma = $somar($contas);

vn_ui_shell_start('A Pagar', 'contas-pagar', [
    'css' => ['assets/css/vn-cobrancas.css', 'assets/css/vn-contas-pagar.css'],
    'js'  => ['assets/js/vn-download.js', 'assets/js/vn-contas-pagar.js'],
    'sub' => 'Contas e boletos da empresa',
]);
?>
<div id="cpRoot" class="cp cb" data-hoje="<?php echo vn_h($hoje); ?>" data-ver="<?php echo vn_h($ver); ?>" data-q="<?php echo vn_h($q); ?>" data-de="<?php echo vn_h($de); ?>"<?php echo !empty($_GET['fotos']) ? ' data-abrir-fotos="' . (int)$_GET['fotos'] . '"' : ''; ?>>

<?php if ($flash && !empty($flash['msg'])): $ft = in_array($flash['tipo'] ?? '', ['ok', 'warn', 'err'], true) ? $flash['tipo'] : 'ok'; ?>
    <div class="vn-alert <?php echo $ft; ?>" role="status"><?php echo vn_h($flash['msg']); ?></div>
<?php endif; ?>
<?php if ($erroTela): ?>
    <div class="vn-alert err" role="alert"><?php echo vn_h($erroTela); ?></div>
<?php endif; ?>

<div class="cb-head">
    <div class="cb-head-text"><strong>Contas a pagar</strong><span>O que falta pagar, por urgência. Cada conta é pública ou privada.</span></div>
    <div class="cb-head-acts">
        <?php if ($series): ?><button type="button" class="cb-btn" data-cp-abrir="cpDlgSeries"><span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>Contas fixas (<?php echo count($series); ?>)</button><?php endif; ?>
        <button type="button" class="cb-btn pri cb-primary" data-cp-nova><span class="material-symbols-outlined" aria-hidden="true">add</span>Nova conta</button>
    </div>
</div>

<nav class="cb-seg cp-escopo" aria-label="De quais contas">
    <?php foreach ($escopos as $k => [$rot, $ico]): ?>
        <a href="<?php echo vn_h(cp_url($ver, $q, 0, $k)); ?>"<?php echo $k === $de ? ' aria-pressed="true" aria-current="page"' : ' aria-pressed="false"'; ?>><?php echo vn_h($rot); ?></a>
    <?php endforeach; ?>
</nav>
<p class="cp-nota cp-escopo-nota"><?php echo vn_h($escopos[$de][2]); ?></p>

<?php if ($de !== 'outros'): ?>
<div class="cb-kpis">
    <a class="cb-kpi" href="<?php echo vn_h(cp_url('abertas', '', 0, $de)); ?>">
        <span class="l"><span class="material-symbols-outlined" aria-hidden="true">request_quote</span>Total em aberto</span>
        <span class="v"><?php echo vn_h(vn_money($resumo['v_abertas'] ?? 0)); ?></span>
        <span class="s"><?php echo (int)($resumo['q_abertas'] ?? 0); ?> conta(s) a pagar</span>
    </a>
    <a class="cb-kpi<?php echo ($resumo['q_atrasadas'] ?? 0) > 0 ? ' is-danger' : ''; ?>" href="<?php echo vn_h(cp_url('atrasadas', '', 0, $de)); ?>">
        <span class="l"><span class="material-symbols-outlined" aria-hidden="true">warning</span>Atrasadas</span>
        <span class="v"><?php echo vn_h(vn_money($resumo['v_atrasadas'] ?? 0)); ?></span>
        <span class="s"><?php echo (int)($resumo['q_atrasadas'] ?? 0); ?> conta(s) vencida(s)</span>
    </a>
    <a class="cb-kpi is-warn" href="<?php echo vn_h(cp_url('semana', '', 0, $de)); ?>">
        <span class="l"><span class="material-symbols-outlined" aria-hidden="true">schedule</span>Vence em 7 dias</span>
        <span class="v"><?php echo vn_h(vn_money($resumo['v_semana'] ?? 0)); ?></span>
        <span class="s"><?php echo (int)($resumo['q_semana'] ?? 0); ?> conta(s) hoje ou nos próximos dias</span>
    </a>
    <a class="cb-kpi is-ok" href="<?php echo vn_h(cp_url('pagas', '', 0, $de)); ?>">
        <span class="l"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Pago neste mês</span>
        <span class="v"><?php echo vn_h(vn_money($resumo['v_pago_mes'] ?? 0)); ?></span>
        <span class="s"><?php echo (int)($resumo['q_pagas'] ?? 0); ?> conta(s) paga(s) no total</span>
    </a>
</div>
<?php endif; ?>

<?php if ($resumoPriv && (int)$resumoPriv['q_todas'] > 0): ?>
<a class="cp-priv" href="<?php echo vn_h(cp_url('abertas', '', 0, 'privadas')); ?>">
    <span class="ico"><span class="material-symbols-outlined" aria-hidden="true">lock</span></span>
    <span class="txt"><strong>Suas contas privadas · só você vê</strong><small>Somadas à parte (só as suas): não entram nos totais acima.</small></span>
    <span class="nums">
        <span>Em aberto<b><?php echo vn_h(vn_money($resumoPriv['v_abertas'])); ?></b><small><?php echo (int)$resumoPriv['q_abertas']; ?> conta(s)</small></span>
        <span class="<?php echo $resumoPriv['q_atrasadas'] > 0 ? 'ruim' : ''; ?>">Atrasadas<b><?php echo vn_h(vn_money($resumoPriv['v_atrasadas'])); ?></b><small><?php echo (int)$resumoPriv['q_atrasadas']; ?> conta(s)</small></span>
        <span>Pago no mês<b><?php echo vn_h(vn_money($resumoPriv['v_pago_mes'])); ?></b></span>
    </span>
    <span class="material-symbols-outlined go" aria-hidden="true">chevron_right</span>
</a>
<?php endif; ?>

<div class="cb-tools cp-tools">
    <form class="cb-search" method="get" action="contas-pagar.php" role="search">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
        <input type="search" name="q" value="<?php echo vn_h($q); ?>" placeholder="Buscar conta ou fornecedor" aria-label="Buscar conta" maxlength="80">
        <?php if ($q !== ''): ?><a class="cp-limpar" href="<?php echo vn_h(cp_url($ver, '', 0, $de)); ?>" aria-label="Limpar a busca"><span class="material-symbols-outlined" aria-hidden="true">close</span></a><?php endif; ?>
    </form>
    <nav class="cb-seg" aria-label="Filtrar contas">
        <?php foreach ($abas as $k => [$rot, $campo]): ?>
            <a href="<?php echo vn_h(cp_url($k, $q, 0, $de)); ?>"<?php echo $k === $ver ? ' aria-pressed="true" aria-current="page"' : ' aria-pressed="false"'; ?>><?php echo vn_h($rot); ?> <span class="n"><?php echo (int)($resumo[$campo] ?? 0); ?></span></a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="cb-sec-lista">
<?php if (!$contas): ?>
    <div class="cb-empty"><span class="material-symbols-outlined" aria-hidden="true">request_quote</span><strong><?php echo vn_h($q !== '' ? 'Nenhuma conta encontrada' : 'Nada por aqui'); ?></strong><?php echo vn_h($q !== '' ? 'Tente outra busca.' : $vazio[$ver]); ?></div>
<?php else: foreach ($secoes as $sec): $sm = $somar($sec['lista']); ?>
    <section class="cb-sec" data-sec="<?php echo vn_h($sec['chave']); ?>">
        <h3 class="cb-sec-title"><span class="material-symbols-outlined" aria-hidden="true"><?php echo $sec['icone']; ?></span><?php echo vn_h($sec['titulo']); ?> <span class="cb-count"><?php echo count($sec['lista']); ?></span><span class="cb-sum"><?php echo vn_h($textoSoma($sm)); ?></span></h3>
        <div class="cb-list">
            <?php foreach ($sec['lista'] as $c) echo $linha($c); ?>
        </div>
    </section>
<?php endforeach; ?>
    <?php if ($de !== 'outros'): ?><p class="cp-nota">Total mostrado: <?php echo vn_h($textoSoma($soma)); ?><?php echo $q !== '' ? ' · busca: "' . vn_h($q) . '"' : ''; ?></p><?php endif; ?>
    <?php if (count($contas) >= 400): ?><p class="cp-nota">Mostrando as 400 primeiras. Use a busca para achar uma conta específica.</p><?php endif; ?>
<?php endif; ?>
</div>

<?php /* ------------------------------------------------------------ janelas ------------------------------------------------------------ */ ?>

<!-- Detalhe da conta (abre ao tocar na linha): pouca informação e as ações principais. O conteúdo é montado por vn-contas-pagar.js. -->
<dialog class="cb-sheet" id="cpDlgDetalhe" aria-labelledby="cpDetTitulo"><div class="cb-sheet-in">
    <div class="cb-sheet-head"><h3 id="cpDetTitulo" data-cp-det-titulo>Conta<small data-cp-det-sub></small></h3>
        <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <div class="cb-sheet-body" data-cp-det-corpo></div>
    <div class="cb-sheet-foot" data-cp-det-rodape></div>
    <form method="post" id="cpFormDesfazer" data-danger data-confirm="Desfazer o pagamento desta conta? Ela volta para &quot;em aberto&quot;." hidden>
        <input type="hidden" name="acao" value="desfazer_pagamento"><input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
    </form>
</div></dialog>

<!-- Nova conta / editar: só o essencial aparece; o resto fica em "Mais opções". -->
<dialog class="cb-sheet" id="cpDlgConta" aria-labelledby="cpContaTitulo"><div class="cb-sheet-in">
    <form method="post" enctype="multipart/form-data" data-cp-form class="cb-sheet-form">
        <input type="hidden" name="acao" value="salvar_conta">
        <input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="token" value="">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
        <div class="cb-sheet-head"><h3 id="cpContaTitulo" data-cp-titulo>Nova conta</h3>
            <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
        <div class="cb-sheet-body">
            <div class="cb-form cp-duas">
                <div class="cb-f full"><label for="cpDescricao">O que é a conta? *</label><input id="cpDescricao" name="descricao" required maxlength="200" placeholder="Ex.: Aluguel da loja, Conta de luz"></div>
                <div class="cb-f"><label for="cpValor" data-cp-rot-valor>Valor (R$) *</label><input id="cpValor" name="valor" required inputmode="numeric" autocomplete="off" placeholder="0,00" data-cp-money></div>
                <div class="cb-f"><label for="cpVenc" data-cp-rot-venc>Vencimento *</label><input id="cpVenc" name="vencimento" type="date" required></div>
                <div class="cb-f full"><label for="cpVis">Quem pode ver esta conta</label>
                    <select id="cpVis" name="visibilidade"><?php foreach (cp_visibilidades() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select>
                    <small class="cp-ajuda" data-cp-vis-ajuda>Privada: aparece só para você (e para o administrador principal). Pública: todos os administradores veem.</small></div>
            </div>
            <details class="cb-more" data-cp-mais>
                <summary>Mais opções <span class="cp-mais-dica">fornecedor, parcelar, repetir, boleto, anexos</span></summary>
                <div class="cb-form cp-duas">
                    <div class="cb-f"><label for="cpFornecedor">Fornecedor</label><input id="cpFornecedor" name="fornecedor" maxlength="180" placeholder="Quem vai receber"></div>
                    <div class="cb-f"><label for="cpCategoria">Categoria</label><input id="cpCategoria" name="categoria" maxlength="60" list="cpCategorias" placeholder="Escolha ou digite"></div>
                    <div class="cb-f full" data-cp-so-nova><label for="cpCondicao">Forma de pagamento</label>
                        <select id="cpCondicao" name="condicao"><option value="avista">À vista (uma conta só)</option><option value="parcelado">Parcelado (entrada + parcelas)</option></select></div>
                    <div class="cb-f" data-cp-so-nova data-cp-parc hidden><label for="cpEntradaTipo">Entrada</label>
                        <select id="cpEntradaTipo" name="entrada_tipo"><option value="">Sem entrada</option><option value="pct">Em porcentagem (%)</option><option value="val">Em valor (R$)</option></select></div>
                    <div class="cb-f" data-cp-so-nova data-cp-parc data-cp-entrada hidden><label for="cpEntradaValor" data-cp-rot-entrada>Entrada (%)</label>
                        <input id="cpEntradaValor" name="entrada_valor" inputmode="decimal" autocomplete="off" placeholder="Ex.: 30"></div>
                    <div class="cb-f" data-cp-so-nova data-cp-parc hidden><label for="cpParcelas" data-cp-rot-parcelas>Restante em quantas parcelas?</label>
                        <input id="cpParcelas" name="parcelas" inputmode="numeric" maxlength="3" autocomplete="off" value="2"></div>
                    <div class="cb-f" data-cp-so-nova data-cp-parc hidden><label for="cpIntervalo">Intervalo entre parcelas</label>
                        <select id="cpIntervalo" name="intervalo"><?php foreach (cp_intervalos() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-f" data-cp-so-nova data-cp-parc data-cp-entrada hidden><label for="cpVencParcela">Vencimento da 1ª parcela</label>
                        <input id="cpVencParcela" name="venc_parcela" type="date"></div>
                    <div class="cb-f full cp-resumo-parc" data-cp-so-nova data-cp-parc hidden role="status" data-cp-resumo-parc></div>
                    <div class="cb-f" data-cp-so-nova data-cp-repetir><label for="cpRepetir">Repetir</label>
                        <select id="cpRepetir" name="repetir"><option value="">Não repete</option><?php foreach (cp_frequencias() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-f" data-cp-so-nova data-cp-repetir data-cp-vezes hidden><label for="cpVezes">Quantas vezes?</label><input id="cpVezes" name="vezes" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="Vazio = sem data final"></div>
                    <div class="cb-f full"><label for="cpCodigo">Código do boleto ou PIX copia e cola</label><input id="cpCodigo" name="codigo" maxlength="512" autocomplete="off" placeholder="Opcional (para copiar na hora de pagar)"></div>
                    <div class="cb-f full"><label for="cpObs">Observação</label><textarea id="cpObs" name="observacao" maxlength="2000" rows="2" placeholder="Opcional"></textarea></div>
                    <div class="cp-rec-box full" data-cp-rec-box hidden>
                        <p><span class="material-symbols-outlined" aria-hidden="true">event_repeat</span><span data-cp-rec-texto></span></p>
                        <label class="cp-check"><input type="checkbox" name="aplicar_proximas" value="1"> Aplicar o que mudei (nome, valor, fornecedor, categoria, observação) também às próximas repetições</label>
                        <button type="button" class="cb-btn sm" data-cp-parar><span class="material-symbols-outlined" aria-hidden="true">close</span>Parar de repetir</button>
                    </div>
                    <div class="cp-rec-box full" data-cp-parc-box hidden>
                        <p><span class="material-symbols-outlined" aria-hidden="true">payments</span><span data-cp-parc-texto></span></p>
                        <label class="cp-check"><input type="checkbox" name="aplicar_parcelas" value="1"> Aplicar nome, fornecedor, categoria e observação também às outras parcelas ainda não pagas</label>
                        <button type="button" class="cb-btn sm" data-cp-cancelar-parc><span class="material-symbols-outlined" aria-hidden="true">close</span>Cancelar parcelamento</button>
                    </div>
                    <div class="cb-f full" data-cp-so-nova>
                        <label for="cpFotosConta">Fotos ou PDF da conta (boleto, nota…)</label>
                        <input id="cpFotosConta" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos>
                        <div class="cp-previews" data-cp-previews></div>
                    </div>
                </div>
            </details>
            <datalist id="cpCategorias"><?php foreach (cp_categorias() as $cat): ?><option value="<?php echo vn_h($cat); ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="cb-sheet-foot">
            <button type="button" class="cb-btn bad cp-excluir" data-cp-excluir hidden><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir</button>
            <span class="cp-spacer"></span>
            <button type="button" class="cb-btn" data-cp-fechar>Cancelar</button>
            <button type="submit" class="cb-btn pri" data-cp-enviar>Salvar</button>
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
</div></dialog>

<dialog class="cb-sheet" id="cpDlgSeries" aria-labelledby="cpSeriesTitulo"><div class="cb-sheet-in">
    <div class="cb-sheet-head"><h3 id="cpSeriesTitulo">Contas fixas<small>Cada conta seguinte é criada sozinha 7 dias antes de vencer. Para mudar uma, abra a conta na lista e toque em Editar.</small></h3>
        <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <div class="cb-sheet-body">
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
                    <button type="submit" class="cb-btn sm"><span class="material-symbols-outlined" aria-hidden="true">close</span>Parar</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="cb-sheet-foot"><button type="button" class="cb-btn grow" data-cp-fechar>Fechar</button></div>
</div></dialog>

<dialog class="cb-sheet" id="cpDlgExcluirRec" aria-labelledby="cpExcluirRecTitulo"><div class="cb-sheet-in">
    <div class="cb-sheet-head"><h3 id="cpExcluirRecTitulo">Excluir conta que se repete<small data-cp-excluir-rec-nome></small></h3>
        <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <div class="cb-sheet-body">
        <p style="margin:0">Esta conta se repete. O que você quer fazer?</p>
        <form method="post" class="cp-opcao">
            <input type="hidden" name="acao" value="excluir_conta"><input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <button type="submit" class="cb-btn bad"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir só esta</button>
            <small>A conta do próximo mês continua e aparece 7 dias antes de vencer.</small>
        </form>
        <form method="post" class="cp-opcao">
            <input type="hidden" name="acao" value="excluir_conta"><input type="hidden" name="parar_repeticao" value="1"><input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <button type="submit" class="cb-btn bad"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Excluir e parar de repetir</button>
            <small>Remove esta e as próximas que ainda não foram pagas. Nenhuma conta nova será criada.</small>
        </form>
    </div>
    <div class="cb-sheet-foot"><button type="button" class="cb-btn grow" data-cp-fechar>Cancelar</button></div>
</div></dialog>

<dialog class="cb-sheet" id="cpDlgPagar" aria-labelledby="cpPagarTitulo"><div class="cb-sheet-in">
    <form method="post" enctype="multipart/form-data" data-cp-form class="cb-sheet-form">
        <input type="hidden" name="acao" value="pagar">
        <input type="hidden" name="conta_id" value="0">
        <input type="hidden" name="token" value="">
        <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
        <div class="cb-sheet-head"><h3 id="cpPagarTitulo">Registrar pagamento<small data-cp-resumo></small></h3>
            <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
        <div class="cb-sheet-body">
            <div class="cb-form cp-duas">
                <div class="cb-f"><label for="cpPagoEm">Data do pagamento *</label><input id="cpPagoEm" name="pago_em" type="date" required></div>
                <div class="cb-f"><label for="cpValorPago">Valor pago (R$) *</label><input id="cpValorPago" name="valor_pago" required inputmode="numeric" autocomplete="off" data-cp-money></div>
                <div class="cb-f full"><label for="cpForma">Como pagou?</label>
                    <select id="cpForma" name="forma_pagamento"><option value="">Não informar</option><?php foreach ($formas as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="cb-f full">
                    <label for="cpFotosPagar">Foto ou PDF do comprovante</label>
                    <input id="cpFotosPagar" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos>
                    <div class="cp-previews" data-cp-previews></div>
                </div>
            </div>
        </div>
        <div class="cb-sheet-foot">
            <button type="button" class="cb-btn" data-cp-fechar>Cancelar</button>
            <button type="submit" class="cb-btn pri grow" data-cp-enviar><span class="material-symbols-outlined" aria-hidden="true">check</span>Confirmar pagamento</button>
        </div>
    </form>
</div></dialog>

<dialog class="cb-sheet" id="cpDlgFotos" aria-labelledby="cpFotosTitulo"><div class="cb-sheet-in">
    <div class="cb-sheet-head"><h3 id="cpFotosTitulo">Anexos<small data-cp-resumo></small></h3>
        <button type="button" class="cb-x" data-cp-fechar aria-label="Fechar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>
    <div class="cb-sheet-body">
        <div class="cp-galeria" data-cp-galeria></div>
        <form method="post" enctype="multipart/form-data" class="cp-envio" data-cp-form>
            <input type="hidden" name="acao" value="add_fotos">
            <input type="hidden" name="conta_id" value="0">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="ver" value="<?php echo vn_h($ver); ?>"><input type="hidden" name="q" value="<?php echo vn_h($q); ?>"><input type="hidden" name="de" value="<?php echo vn_h($de); ?>">
            <div class="cb-form">
                <div class="cb-f"><label for="cpTipoFoto">Tipo do anexo</label>
                    <select id="cpTipoFoto" name="tipo"><?php foreach (cp_tipos_foto() as $k => $rot): ?><option value="<?php echo vn_h($k); ?>"><?php echo vn_h($rot); ?></option><?php endforeach; ?></select></div>
                <div class="cb-f"><label for="cpFotosAdd">Foto, galeria ou PDF</label>
                    <input id="cpFotosAdd" type="file" name="fotos[]" accept="image/*,application/pdf,.pdf" multiple data-cp-fotos></div>
            </div>
            <div class="cp-previews" data-cp-previews></div>
            <div class="cp-envio-acoes"><button type="submit" class="cb-btn pri" data-cp-enviar><span class="material-symbols-outlined" aria-hidden="true">upload</span>Enviar anexos</button></div>
        </form>
    </div>
    <div class="cb-sheet-foot"><button type="button" class="cb-btn grow" data-cp-fechar>Fechar</button></div>
</div></dialog>

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
