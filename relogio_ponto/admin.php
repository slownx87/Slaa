<?php
/*
 * Painel administrativo - início.
 *
 * Resumo do dia, fila de pedidos de ajuste esperando resposta e as batidas
 * de hoje com filtro por tipo.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_admin();

try { ensure_solicitacoes_table(); } catch (Throwable $e) { /* aparece no ajax */ }

[$filtroCond, $paramsCond] = admin_filtro_condominio('r');

/* ------------------------------- ações ajax ------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'aprovar' || $acao === 'recusar') {
            $id = (int)($_POST['id'] ?? 0);
            $resposta = mb_substr(trim((string)($_POST['resposta'] ?? '')), 0, 500);

            /* Confere se o pedido é de alguém que este admin administra. */
            $stmt = db()->prepare("SELECT funcionario_id FROM ponto_solicitacoes WHERE id = ?");
            $stmt->execute([$id]);
            $funcId = (int)$stmt->fetchColumn();
            if (!$funcId) {
                throw new RuntimeException('Pedido não encontrado.');
            }
            admin_funcionario_autorizado($funcId);

            if ($acao === 'aprovar') {
                $descricao = ponto_solicitacao_aprovar(
                    $id,
                    admin_id(),
                    $resposta,
                    (string)($_POST['tipo_registro'] ?? ''),
                    (int)($_POST['registro_id'] ?? 0)
                );
                admin_json(['ok' => true, 'mensagem' => 'Aprovado: ' . $descricao]);
            }

            if ($resposta === '') {
                throw new RuntimeException('Escreva o motivo da recusa.');
            }
            ponto_solicitacao_recusar($id, admin_id(), $resposta);
            admin_json(['ok' => true, 'mensagem' => 'Pedido recusado.']);
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $e) {
        admin_json(['ok' => false, 'error' => ponto_msg_erro($e)], 422);
    }
}

/* --------------------------------- dados --------------------------------- */
function admin_conta(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

[$filtroF, $paramsF] = admin_filtro_condominio('f');
$totalFuncionarios = admin_conta("SELECT COUNT(*) FROM ponto_funcionarios f WHERE f.ativo = 1 {$filtroF}", $paramsF);
/* "Hoje" = o dia da jornada OU qualquer batida feita desde a meia-noite: a
 * saída/volta da madrugada de quem faz turno da noite tem data_ponto de ONTEM e
 * sumia da lista enquanto a pessoa ainda estava trabalhando. */
$inicioHoje = today_sp() . ' 00:00:00';
$batidasHoje = admin_conta("SELECT COUNT(*) FROM ponto_registros r WHERE (r.data_ponto = ? OR r.data_hora >= ?) {$filtroCond}", array_merge([today_sp(), $inicioHoje], $paramsCond));

[$filtroS, $paramsS] = admin_filtro_condominio('s');
$pedidosPendentes = admin_conta("SELECT COUNT(*) FROM ponto_solicitacoes s WHERE s.status = 'pendente' {$filtroS}", $paramsS);

/* Funcionários que estão no meio do expediente agora.
 *
 * Olha hoje E ontem porque o turno noturno começa num dia e termina no outro:
 * quem entrou ontem às 18:00 e está trabalhando às 02:00 tem data_ponto de
 * ontem e, contando só hoje, sumia do painel. O corte de 16 horas evita o outro
 * extremo — quem esqueceu de bater a saída semana passada não pode ficar
 * marcado como "em expediente" para sempre. */
$emAberto = admin_conta(
    "SELECT COUNT(DISTINCT r.funcionario_id) FROM ponto_registros r
      WHERE r.data_ponto IN (?, ?)
        AND r.data_hora >= ? {$filtroCond}
        AND NOT EXISTS (SELECT 1 FROM ponto_registros r2
                         WHERE r2.funcionario_id = r.funcionario_id
                           AND r2.data_ponto = r.data_ponto AND r2.tipo = 'saida')",
    array_merge([
        today_sp(),
        date('Y-m-d', strtotime('-1 day')),
        date('Y-m-d H:i:s', time() - (PONTO_JORNADA_ABERTA_MAX_MIN * 60)),
    ], $paramsCond)
);

/* Fila de pedidos aguardando resposta. */
$stmt = db()->prepare("SELECT s.*, f.nome AS funcionario_nome, c.nome AS condominio_nome
    FROM ponto_solicitacoes s
    INNER JOIN ponto_funcionarios f ON f.id = s.funcionario_id
    INNER JOIN ponto_condominios c ON c.id = s.condominio_id
    WHERE s.status = 'pendente' {$filtroS}
    ORDER BY s.criado_em ASC LIMIT 30");
$stmt->execute($paramsS);
$pedidos = $stmt->fetchAll();

/* Batidas de hoje. */
$stmt = db()->prepare("SELECT r.*, f.nome AS funcionario_nome, c.nome AS condominio_nome
    FROM ponto_registros r
    INNER JOIN ponto_funcionarios f ON f.id = r.funcionario_id
    INNER JOIN ponto_condominios c ON c.id = r.condominio_id
    WHERE (r.data_ponto = ? OR r.data_hora >= ?) {$filtroCond}
    ORDER BY r.data_hora DESC LIMIT 60");
$stmt->execute(array_merge([today_sp(), $inicioHoje], $paramsCond));
$batidas = $stmt->fetchAll();

/* Atraso da chegada, pelo horário do CARGO de cada um.
 *
 * Antes esta tela chamava ponto_atraso_entrada_minutos() sem passar jornada
 * nenhuma, então caía na regra automática de 08:00/18:00: todo mundo de escala
 * (06:00-14:00, 14:00-22:00, 12x36) aparecia em vermelho sem estar atrasado.
 * Agora o turno sai de ponto_turno_efetivo(), o mesmo do histórico e do PDF.
 *
 * As batidas de hoje já vieram todas na consulta acima, então agrupar aqui não
 * custa consulta nova — só ponto_jornada_do_funcionario(), que tem cache. */
$batidasPorFuncionario = [];
foreach ($batidas as $b) {
    $batidasPorFuncionario[(int)$b['funcionario_id']][] = $b;
}
$atrasoPorBatida = [];
$cedoPorBatida = [];
foreach ($batidasPorFuncionario as $fid => $regs) {
    usort($regs, function (array $a, array $b) {
        return strcmp((string)$a['data_hora'], (string)$b['data_hora']);
    });
    $turnoDoDia = ponto_turno_efetivo($regs, ponto_jornada_do_funcionario($fid));
    foreach ($regs as $r) {
        if ((string)$r['tipo'] !== 'entrada') continue;
        /* Só a chegada conta: entrada de 2ª jornada não é atraso de chegada. */
        $atrasoPorBatida[(int)$r['id']] = ponto_atraso_entrada_minutos((string)$r['data_hora'], $turnoDoDia);
        /* Chegou antes do limite que o cargo permite (só se o cargo tem limite). */
        $cedoPorBatida[(int)$r['id']] = ponto_antecipacao_entrada_minutos((string)$r['data_hora'], $turnoDoDia);
        break;
    }
}

$icones = [
    'entrada' => 'login', 'saida' => 'logout',
    'saida_almoco' => 'restaurant', 'volta_almoco' => 'arrow_circle_left',
    'saida_janta' => 'dinner_dining', 'volta_janta' => 'arrow_circle_left',
    'saida_descanso' => 'coffee', 'volta_descanso' => 'arrow_circle_left',
];

$csrf = csrf_token();
app_mobile_head('Painel - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar();
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-lg">

<div class="flex flex-col gap-1 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Painel</h1>
<p class="font-body-md text-body-md text-secondary"><?= h(admin_nome()) ?> · <?= h(date('d/m/Y')) ?></p>
</div>

<!-- ------------------------------- resumo ------------------------------- -->
<section class="grid grid-cols-2 gap-3 fade-in-up" style="animation-delay:.1s;">
<div class="cartao rounded-xl p-3 flex flex-col gap-1">
<span class="material-symbols-outlined text-primary text-[18px]">groups</span>
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Funcionários</span>
<span class="font-headline-lg text-headline-lg text-on-surface"><?= $totalFuncionarios ?></span>
</div>
<div class="cartao rounded-xl p-3 flex flex-col gap-1">
<span class="material-symbols-outlined text-tertiary text-[18px]">how_to_reg</span>
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Batidas hoje</span>
<span class="font-headline-lg text-headline-lg text-on-surface"><?= $batidasHoje ?></span>
</div>
<div class="cartao rounded-xl p-3 flex flex-col gap-1 border-amber-500/25">
<span class="material-symbols-outlined text-amber-500 text-[18px]">schedule</span>
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Em expediente</span>
<span class="font-headline-lg text-headline-lg text-amber-500"><?= $emAberto ?></span>
</div>
<!-- Este cartão é atualizado pelo JS ao responder um pedido: antes ele era só
     desenhado pelo PHP e continuava marcando pedidos em vermelho mesmo depois
     de o último ter sido autorizado, até alguém recarregar a página. -->
<div class="cartao rounded-xl p-3 flex flex-col gap-1 <?= $pedidosPendentes ? 'border-error/30' : '' ?>" id="cartaoPedidos">
<span class="material-symbols-outlined <?= $pedidosPendentes ? 'text-error' : 'text-secondary' ?> text-[18px]" id="iconePedidos">pending_actions</span>
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Pedidos</span>
<span class="font-headline-lg text-headline-lg <?= $pedidosPendentes ? 'text-error' : 'text-on-surface' ?>" id="numeroPedidos"><?= $pedidosPendentes ?></span>
</div>
</section>

<!-- ---------------------------- atalhos rápidos ---------------------------- -->
<section class="flex gap-3 fade-in-up" style="animation-delay:.15s;">
<a class="flex-1 bg-primary text-on-primary rounded-xl h-[48px] font-label-bold text-label-bold flex items-center justify-center gap-2 shadow-lg active:scale-95 transition-transform" href="admin-config.php#condominios">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">domain_add</span>
Condomínio
</a>
<a class="flex-1 border border-primary text-primary rounded-xl h-[48px] font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform" href="admin-config.php#cargos">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">badge</span>
Cargo
</a>
</section>

<!-- --------------------------- pedidos pendentes --------------------------- -->
<section class="flex flex-col gap-2 fade-in-up" style="animation-delay:.2s;" id="secaoPedidos">
<div class="flex items-center justify-between">
<h2 class="font-headline-md text-headline-md text-on-surface">Pedidos de ajuste</h2>
<span class="font-label-sm text-label-sm text-secondary" id="contadorPedidos"><?= count($pedidos) ?> aguardando</span>
</div>

<?php if (!$pedidos): ?>
<div class="cartao rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-outline text-[28px] mb-1">task_alt</span>
<p class="font-body-md text-body-md text-secondary">Nenhum pedido aguardando resposta.</p>
</div>
<?php else: ?>
<?php foreach ($pedidos as $i => $p):
    $criar = (string)($p['tipo_solicitacao'] ?? 'criar') === 'criar' || empty($p['registro_id']);
    $protocolo = '#' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
    /* Pedido antigo, sem dizer qual batida é: o admin escolhe ao autorizar. */
    $semTipo = $criar && empty($p['tipo_registro']); ?>
<article class="surgir cartao rounded-xl p-3 flex flex-col gap-2 <?= $semTipo ? 'border-error/30' : 'border-amber-500/25' ?>" data-pedido="<?= (int)$p['id'] ?>" data-sem-tipo="<?= $semTipo ? '1' : '0' ?>" data-funcionario="<?= h((string)$p['funcionario_nome']) ?>" style="animation-delay:<?= min($i, 8) * 55 ?>ms">
<div class="flex items-center justify-between gap-2">
<span class="font-body-lg text-body-lg text-on-surface font-medium truncate"><?= h((string)$p['funcionario_nome']) ?></span>
<span class="font-label-sm text-label-sm text-secondary flex-shrink-0"><?= h($protocolo) ?></span>
</div>
<div class="flex items-center gap-2 flex-wrap font-body-md text-body-md">
<span class="px-2 py-0.5 rounded-full bg-primary/10 text-primary border border-primary/25 font-label-bold text-label-bold">
<?= $criar ? 'Incluir' : 'Corrigir' ?>
</span>
<?php if ($semTipo): ?>
<span class="text-error flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">help</span>batida não informada</span>
<?php else: ?>
<span class="text-on-surface-variant"><?= h(tipo_ponto_label((string)$p['tipo_registro'])) ?></span>
<?php endif; ?>
<span class="text-secondary"><?= h(date('d/m', strtotime((string)$p['data_ponto']))) ?></span>
</div>
<div class="font-body-lg text-body-lg tabular-nums">
<?php if (!empty($p['data_hora_atual'])): ?>
<span class="line-through text-secondary"><?= h(date('H:i', strtotime((string)$p['data_hora_atual']))) ?></span> →
<?php endif; ?>
<span class="text-primary font-medium"><?= h(date('H:i', strtotime((string)$p['data_hora_solicitada']))) ?></span>
</div>
<p class="font-body-md text-body-md text-on-surface-variant"><?= h((string)$p['motivo']) ?></p>
<div class="flex gap-2 pt-1">
<button class="flex-1 h-9 rounded-lg bg-green-600 text-white font-label-bold text-label-bold flex items-center justify-center gap-1 active:scale-95 transition-transform" data-aprovar="<?= (int)$p['id'] ?>" type="button">
<span class="material-symbols-outlined text-[16px]">check</span> Autorizar
</button>
<button class="flex-1 h-9 rounded-lg border border-error text-error font-label-bold text-label-bold flex items-center justify-center gap-1 active:scale-95 transition-transform" data-recusar="<?= (int)$p['id'] ?>" type="button">
<span class="material-symbols-outlined text-[16px]">close</span> Recusar
</button>
</div>
</article>
<?php endforeach; ?>
<?php endif; ?>
</section>

<!-- ---------------------------- batidas de hoje ---------------------------- -->
<section class="flex flex-col gap-2 fade-in-up" style="animation-delay:.25s;">
<div class="flex items-center justify-between gap-2">
<h2 class="font-headline-md text-headline-md text-on-surface">Batidas de hoje</h2>
<div class="flex gap-1" id="filtros">
<button class="px-2 py-1 rounded-full bg-primary text-on-primary font-label-sm text-label-sm" data-filtro="" type="button">Todas</button>
<button class="px-2 py-1 rounded-full bg-surface-container text-secondary font-label-sm text-label-sm" data-filtro="entrada" type="button">Entrada</button>
<button class="px-2 py-1 rounded-full bg-surface-container text-secondary font-label-sm text-label-sm" data-filtro="intervalo" type="button">Intervalos</button>
<button class="px-2 py-1 rounded-full bg-surface-container text-secondary font-label-sm text-label-sm" data-filtro="saida" type="button">Saída</button>
</div>
</div>

<div class="flex flex-col gap-2" id="listaBatidas">
<?php if (!$batidas): ?>
<div class="cartao rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-outline text-[28px] mb-1">event_busy</span>
<p class="font-body-md text-body-md text-secondary">Nenhuma batida registrada hoje.</p>
</div>
<?php else: ?>
<?php foreach ($batidas as $b):
    $tipo = (string)$b['tipo'];
    /* Só a chegada é avaliada quanto a atraso, e contra o horário do cargo. */
    $atrasoMin = (int)($atrasoPorBatida[(int)$b['id']] ?? 0);
    $cedoMin = (int)($cedoPorBatida[(int)$b['id']] ?? 0); ?>
<div class="cartao rounded-lg p-2.5 flex items-center justify-between gap-2 <?= $atrasoMin > 0 ? 'border-error/40' : '' ?>" data-tipo="<?= h($tipo) ?>">
<div class="flex items-center gap-2 min-w-0">
<div class="w-8 h-8 rounded-full <?= $atrasoMin > 0 ? 'bg-error/15 text-error' : 'bg-primary/10 text-primary' ?> flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;"><?= h($icones[$tipo] ?? 'schedule') ?></span>
</div>
<div class="min-w-0">
<p class="font-body-md text-body-md text-on-surface font-medium truncate"><?= h((string)$b['funcionario_nome']) ?></p>
<p class="font-label-sm text-label-sm text-secondary truncate"><?= h(tipo_ponto_label($tipo)) ?> · <?= h((string)$b['condominio_nome']) ?></p>
</div>
</div>
<div class="flex items-center gap-2 flex-shrink-0">
<?php if ($atrasoMin > 0): ?>
<span class="px-1.5 py-0.5 rounded-full bg-error/10 text-error border border-error/30 font-label-sm text-label-sm">+<?= (int)$atrasoMin ?>min</span>
<?php elseif ($cedoMin > 0): ?>
<span class="px-1.5 py-0.5 rounded-full bg-primary/10 text-primary border border-primary/30 font-label-sm text-label-sm" title="Chegou antes do limite do cargo">-<?= (int)$cedoMin ?>min cedo</span>
<?php endif; ?>
<span class="font-body-md text-body-md <?= $atrasoMin > 0 ? 'text-error' : 'text-on-surface' ?> font-bold tabular-nums"><?= h(date('H:i', strtotime((string)$b['data_hora']))) ?></span>
<?php if (!empty($b['foto_path'])): ?>
<a class="w-7 h-7 rounded-full flex items-center justify-center text-secondary hover:text-primary" href="foto.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener" aria-label="Ver foto">
<span class="material-symbols-outlined text-[16px]">photo_camera</span>
</a>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</section>

<button class="w-full h-[44px] rounded-full border border-primary/60 text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform" id="btnTutorial" type="button">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">school</span>
Como usar o painel
</button>

<div class="flex flex-col gap-2 pt-2">
<a class="font-label-bold text-label-bold text-error text-center py-2" href="logout.php">Sair</a>
</div>
</main>

<!-- ------------------ folha: escolher a batida do pedido antigo ------------------ -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="tipoFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl p-container-margin pb-8 flex flex-col gap-3" id="tipoFolha">
<div class="flex justify-center"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<h3 class="font-headline-md text-headline-md text-on-surface">Qual batida é essa?</h3>
<p class="font-body-md text-body-md text-secondary" id="tipoTexto">Este pedido foi feito numa versão anterior do app e não guardou o tipo da batida. Escolha para poder autorizar.</p>
<select class="campo font-body-lg text-body-lg" id="tipoEscolhido">
<?php foreach (['entrada', 'saida_almoco', 'volta_almoco', 'saida_janta', 'volta_janta', 'saida_descanso', 'volta_descanso', 'saida'] as $t): ?>
<option value="<?= h($t) ?>"><?= h(tipo_ponto_label($t)) ?></option>
<?php endforeach; ?>
</select>
<div class="flex gap-2">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="tipoCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-green-600 text-white font-label-bold text-label-bold" id="tipoConfirmar" type="button">Autorizar</button>
</div>
</div>

<!-- --------------------------- folha: recusar --------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="recusaFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl p-container-margin pb-8 flex flex-col gap-3" id="recusaFolha">
<div class="flex justify-center"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<h3 class="font-headline-md text-headline-md text-on-surface">Recusar pedido</h3>
<p class="font-body-md text-body-md text-secondary">Explique o motivo. O funcionário vê essa resposta no app.</p>
<textarea class="campo font-body-md text-body-md resize-none" id="recusaMotivo" maxlength="500" placeholder="Ex: o horário informado não confere com a escala" rows="3"></textarea>
<div class="flex gap-2">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="recusaCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-error text-on-error font-label-bold text-label-bold" id="recusaConfirmar" type="button">Recusar</button>
</div>
</div>

<?php admin_nav('painel'); ?>
<?php app_mobile_footer($csrf); ?>
<script src="assets/tutorial.js?v=1.0.0"></script>
<script>
(() => {
    'use strict';
    const $ = (sel) => document.querySelector(sel);
    const { toast, post, vibrar } = window.PontoApp;

    let ocupado = false;
    let idRecusa = 0;
    let idTipo = 0;

    /* Deixa o cartão "Pedidos" do resumo igual à fila de verdade. Sem isso o
     * número em vermelho continuava lá depois de autorizar, como se o pedido
     * não tivesse sido resolvido. */
    function atualizarCartaoPedidos(quantos) {
        const cartao = $('#cartaoPedidos');
        const icone = $('#iconePedidos');
        const numero = $('#numeroPedidos');
        if (!cartao || !icone || !numero) return;

        numero.textContent = quantos;
        cartao.classList.toggle('border-error/30', quantos > 0);
        icone.classList.toggle('text-error', quantos > 0);
        icone.classList.toggle('text-secondary', quantos === 0);
        numero.classList.toggle('text-error', quantos > 0);
        numero.classList.toggle('text-on-surface', quantos === 0);
    }

    function removerCartao(id) {
        const cartao = document.querySelector(`[data-pedido="${id}"]`);
        if (!cartao) return;
        cartao.style.transition = 'opacity .3s ease, transform .3s ease';
        cartao.style.opacity = '0';
        cartao.style.transform = 'translateX(20px)';
        setTimeout(() => {
            cartao.remove();
            const restantes = document.querySelectorAll('[data-pedido]').length;
            $('#contadorPedidos').textContent = restantes + ' aguardando';
            atualizarCartaoPedidos(restantes);
            if (!restantes) {
                $('#secaoPedidos').insertAdjacentHTML('beforeend',
                    '<div class="cartao rounded-xl p-6 text-center"><span class="material-symbols-outlined text-outline text-[28px] mb-1">task_alt</span><p class="font-body-md text-body-md text-secondary">Nenhum pedido aguardando resposta.</p></div>');
            }
        }, 300);
    }

    async function responder(id, acao, resposta, tipoRegistro) {
        if (ocupado) return;
        ocupado = true;
        try {
            const dados = await post({
                acao,
                id,
                resposta: resposta || '',
                tipo_registro: tipoRegistro || ''
            });
            toast(dados.mensagem);
            vibrar(40);
            removerCartao(id);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
        }
    }

    document.addEventListener('click', (evento) => {
        const aprovar = evento.target.closest('[data-aprovar]');
        if (aprovar) {
            const id = aprovar.dataset.aprovar;
            const cartao = document.querySelector(`[data-pedido="${id}"]`);

            /* Pedido antigo sem o tipo: pergunta antes de aplicar. */
            if (cartao && cartao.dataset.semTipo === '1') {
                idTipo = id;
                $('#tipoTexto').textContent = 'O pedido de ' + cartao.dataset.funcionario
                    + ' foi feito numa versão anterior do app e não guardou o tipo da batida. Escolha para poder autorizar.';
                $('#tipoFundo').classList.add('aberto');
                $('#tipoFolha').classList.add('aberto');
                return;
            }
            responder(id, 'aprovar', '', '');
            return;
        }
        const recusar = evento.target.closest('[data-recusar]');
        if (recusar) {
            idRecusa = recusar.dataset.recusar;
            $('#recusaMotivo').value = '';
            $('#recusaFundo').classList.add('aberto');
            $('#recusaFolha').classList.add('aberto');
        }
    });

    function fecharRecusa() {
        $('#recusaFundo').classList.remove('aberto');
        $('#recusaFolha').classList.remove('aberto');
    }

    function fecharTipo() {
        $('#tipoFundo').classList.remove('aberto');
        $('#tipoFolha').classList.remove('aberto');
    }

    $('#tipoCancelar').addEventListener('click', fecharTipo);
    $('#tipoFundo').addEventListener('click', fecharTipo);
    $('#tipoConfirmar').addEventListener('click', async () => {
        const tipo = $('#tipoEscolhido').value;
        fecharTipo();
        await responder(idTipo, 'aprovar', '', tipo);
    });

    $('#recusaCancelar').addEventListener('click', fecharRecusa);
    $('#recusaFundo').addEventListener('click', fecharRecusa);
    $('#recusaConfirmar').addEventListener('click', async () => {
        const motivo = $('#recusaMotivo').value.trim();
        if (motivo.length < 5) { toast('Escreva o motivo da recusa.', 'error'); return; }
        fecharRecusa();
        await responder(idRecusa, 'recusar', motivo);
    });

    /* --------------------------------- tutorial --------------------------------- */
    const PASSOS = [
        {
            titulo: 'Painel do administrador',
            texto: 'Vou explicar cada parte em <b>6 passos</b>.'
        },
        {
            alvo: '#secaoPedidos',
            titulo: '1. Pedidos de ajuste',
            texto: 'Quando um funcionário pede correção de batida, o pedido cai aqui.<br><br>• <b>Autorizar</b> — o sistema corrige a batida sozinho e refaz as horas do dia.<br>• <b>Recusar</b> — você escreve o motivo e ele vê no app.'
        },
        {
            alvo: '#listaBatidas',
            titulo: '2. Batidas de hoje',
            texto: 'Tudo que foi batido hoje, em tempo real. Entradas <b>atrasadas ficam em vermelho</b> com os minutos (já descontada a tolerância do cargo).<br><br>O ícone de câmera abre a <b>foto</b> tirada na hora da batida.'
        },
        {
            alvo: 'nav a[href="admin-funcionarios.php"]',
            titulo: '3. Equipe',
            texto: 'Cadastrar, editar, <b>trocar o PIN</b> e desativar funcionários.<br><br><b>Atenção:</b> não existe "ver PIN". O PIN é criptografado e nem o sistema consegue ler. Se a pessoa esqueceu, você gera um novo.'
        },
        {
            alvo: 'nav a[href="admin-calendario.php"]',
            titulo: '4. Ajustes',
            texto: 'Escolha o funcionário e navegue pelo calendário para <b>corrigir batidas na mão</b>: mudar horário, incluir a que faltou ou apagar a errada.<br><br>Os pontinhos marcam: vermelho = falta a saída, âmbar = já foi ajustado.'
        },
        {
            alvo: 'nav a[href="admin-relatorios.php"]',
            titulo: '5. Relatórios',
            texto: 'Horas, extras e atrasos por funcionário.<br><br>Dá para <b>exportar o espelho de ponto em PDF</b> — escolhendo um funcionário ou todos — e o resumo em CSV para abrir no Excel.'
        },
        {
            alvo: 'header a[href="admin-config.php"]',
            forma: 'circulo',
            titulo: '6. Configurações',
            texto: 'A engrenagem no topo abre os cadastros: <b>condomínios</b>, <b>cargos</b>, <b>advertências</b> e novos administradores.'
        }
    ];

    $('#btnTutorial').addEventListener('click', () => window.Tutorial.iniciar(PASSOS, { chave: 'admin' }));
    window.Tutorial.iniciarSePrimeiraVez(PASSOS, { chave: 'admin' });

    /* Filtro das batidas do dia. */
    $('#filtros').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-filtro]');
        if (!botao) return;

        document.querySelectorAll('#filtros button').forEach((b) => {
            b.className = 'px-2 py-1 rounded-full bg-surface-container text-secondary font-label-sm text-label-sm';
        });
        botao.className = 'px-2 py-1 rounded-full bg-primary text-on-primary font-label-sm text-label-sm';

        const filtro = botao.dataset.filtro;
        document.querySelectorAll('#listaBatidas [data-tipo]').forEach((linha) => {
            const tipo = linha.dataset.tipo;
            let mostra = true;
            if (filtro === 'entrada') mostra = tipo === 'entrada';
            else if (filtro === 'saida') mostra = tipo === 'saida';
            else if (filtro === 'intervalo') mostra = /almoco|janta|descanso/.test(tipo);
            linha.style.display = mostra ? '' : 'none';
        });
    });
})();
</script>
<?php app_mobile_end(); ?>
