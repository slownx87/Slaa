<?php
/*
 * Tela do funcionário: acompanhar os pedidos de ajuste.
 *
 * O pedido em si é criado no histórico (tocando no dia e escolhendo qual
 * batida corrigir). Aqui a pessoa só acompanha o que já mandou e vê a
 * resposta do administrador.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_app_shell.php';

function pedidos_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pedidos_lista(int $funcionarioId, int $limite = 60): array
{
    ensure_solicitacoes_table();
    $stmt = db()->prepare("SELECT * FROM ponto_solicitacoes
        WHERE funcionario_id = ?
        ORDER BY criado_em DESC, id DESC
        LIMIT " . (int)$limite);
    $stmt->execute([$funcionarioId]);

    $out = [];
    $contagem = ['pendente' => 0, 'aprovado' => 0, 'recusado' => 0];

    foreach ($stmt->fetchAll() as $p) {
        $status = (string)$p['status'];
        if (isset($contagem[$status])) {
            $contagem[$status]++;
        }

        $criar = (string)$p['tipo_solicitacao'] === 'criar' || empty($p['registro_id']);

        $out[] = [
            'protocolo' => '#' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT),
            'status' => $status,
            'status_label' => ponto_solicitacao_status_label($status),
            'acao' => $criar ? 'Incluir' : 'Corrigir',
            'tipo_label' => $p['tipo_registro'] ? tipo_ponto_label((string)$p['tipo_registro']) : 'Batida',
            'dia_br' => $p['data_ponto'] ? date('d/m/Y', strtotime((string)$p['data_ponto'])) : '--',
            'hora_pedida' => $p['data_hora_solicitada'] ? date('H:i', strtotime((string)$p['data_hora_solicitada'])) : '--:--',
            'hora_atual' => $p['data_hora_atual'] ? date('H:i', strtotime((string)$p['data_hora_atual'])) : '',
            'motivo' => (string)$p['motivo'],
            'resposta' => (string)($p['resposta_admin'] ?? ''),
            'criado_br' => date('d/m/Y H:i', strtotime((string)$p['criado_em'])),
            'respondido_br' => $p['respondido_em'] ? date('d/m/Y H:i', strtotime((string)$p['respondido_em'])) : '',
        ];
    }

    return ['itens' => $out, 'contagem' => $contagem];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();

        /* Quem veio do histórico já provou quem é; quem abriu direto digita o PIN. */
        $funcionarioId = null;
        $tokenPost = trim((string)($_POST['token'] ?? ''));
        if ($tokenPost !== '') {
            $funcionarioId = ponto_token_funcionario($tokenPost);
        }

        if ($funcionarioId === null) {
            ponto_pin_guard();
            $pin = trim((string)($_POST['pin'] ?? ''));
            if ($pin === '') {
                throw new RuntimeException('Digite seu PIN.');
            }
            $encontrado = find_funcionario_by_pin(0, $pin);
            if (!$encontrado) {
                ponto_pin_falhou();
                throw new RuntimeException('PIN não encontrado para funcionário ativo.');
            }
            ponto_pin_acertou();
            $funcionarioId = (int)$encontrado['id'];
        }

        $funcionario = get_funcionario($funcionarioId);
        if (!$funcionario || (int)$funcionario['ativo'] !== 1) {
            throw new RuntimeException('Funcionário não encontrado ou inativo.');
        }

        $lista = pedidos_lista($funcionarioId);

        pedidos_json([
            'ok' => true,
            'token' => ponto_token_emitir($funcionarioId),
            'funcionario' => [
                'nome' => (string)$funcionario['nome'],
                'cargo' => (string)($funcionario['cargo_nome'] ?? ''),
                'condominio' => (string)($funcionario['condominio_nome'] ?? ''),
            ],
            'pedidos' => $lista['itens'],
            'contagem' => $lista['contagem'],
        ]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'mais de um funcionário') !== false || stripos($msg, 'Selecione o condomínio') !== false) {
            $msg = 'PIN duplicado. Peça ao administrador para alterar o PIN de um dos funcionários.';
        }
        pedidos_json(['ok' => false, 'error' => $msg], 422);
    }
}

/* Token vindo do histórico: evita digitar o PIN de novo. */
$tokenUrl = (string)($_GET['token'] ?? '');
$funcionarioToken = $tokenUrl !== '' ? ponto_token_funcionario($tokenUrl) : null;

$csrf = csrf_token();

$cssTela = <<<'CSS'
<style>
    .pin-dot { transition: all .2s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .pin-dot.filled { background-color:#3b82f6; transform:scale(1.25); box-shadow:0 0 16px rgba(59,130,246,.75); }
    .pin-dot.error { background-color:#ef4444; box-shadow:0 0 16px rgba(239,68,68,.7); animation: shake .4s ease-in-out; }
    @keyframes shake {
        0%,100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        50% { transform: translateX(5px); }
        75% { transform: translateX(-5px); }
    }
    .keypad-btn { position:relative; overflow:hidden; transition: background-color .12s, transform .12s, border-color .12s; }
    .keypad-btn:active { background-color: rgba(59,130,246,.16); border-color:#3b82f6; transform: scale(.94); }
    .keypad-btn::after {
        content:''; position:absolute; top:50%; left:50%; width:100%; height:100%;
        background: radial-gradient(circle, rgba(59,130,246,.45) 0%, transparent 70%);
        transform: translate(-50%, -50%) scale(0); opacity:0;
        transition: transform .35s, opacity .35s; pointer-events:none;
    }
    .keypad-btn:active::after { transform: translate(-50%,-50%) scale(2.2); opacity:1; transition:0s; }

    .tela { display:none; }
    .tela.ativa { display:flex; }

    @media (max-height: 700px) {
        #keypad button { width: 56px; height: 56px; font-size: 26px; line-height: 1; }
        #keypad > div { width: 56px; height: 56px; }
        #pinDots { margin-bottom: 1rem; }
        #btnConfirmarPin { margin-top: .75rem; }
    }
</style>
CSS;

app_mobile_head('Meus pedidos - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', $cssTela);
?>
<div id="app" data-csrf="<?= h($csrf) ?>" data-token="<?= h($funcionarioToken !== null ? $tokenUrl : '') ?>">

<!-- ============================== TELA: PIN ============================== -->
<section class="tela <?= $funcionarioToken === null ? 'ativa' : '' ?> flex-col min-h-screen overflow-y-auto" data-tela="pin">
<header class="w-full px-container-margin py-stack-gap-md flex justify-between items-center z-10 relative">
<a aria-label="Voltar" class="w-12 h-12 flex items-center justify-center rounded-full text-primary hover:bg-primary/5 active:scale-95 transition-all" href="bater-ponto.php">
<span class="material-symbols-outlined font-light text-2xl">close</span>
</a>
<?php app_mobile_logo(); ?>
<div class="w-12 h-12"></div>
</header>
<main class="flex-grow flex flex-col items-center justify-center px-container-margin pb-safe z-10 relative py-4" id="pinConteudo">
<div class="text-center mb-stack-gap-lg">
<h1 class="font-headline-lg text-headline-lg text-on-background mb-2">Digite seu PIN</h1>
<p class="font-body-md text-body-md text-secondary">Para ver seus pedidos de ajuste</p>
</div>
<div class="flex gap-5 mb-6 h-10 items-center justify-center w-full max-w-[280px]" id="pinDots">
<div class="pin-dot w-3 h-3 rounded-full bg-outline-variant/50 border border-outline-variant/20 shadow-sm"></div>
<div class="pin-dot w-3 h-3 rounded-full bg-outline-variant/50 border border-outline-variant/20 shadow-sm"></div>
<div class="pin-dot w-3 h-3 rounded-full bg-outline-variant/50 border border-outline-variant/20 shadow-sm"></div>
<div class="pin-dot w-3 h-3 rounded-full bg-outline-variant/50 border border-outline-variant/20 shadow-sm"></div>
</div>
<div class="grid grid-cols-3 gap-y-3 gap-x-6 max-w-[268px] mx-auto w-full" id="keypad">
<?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
<button class="keypad-btn w-[66px] h-[66px] rounded-full flex items-center justify-center font-display-time text-display-time text-on-surface bg-surface-container-lowest shadow-sm border border-outline-variant/20" data-digito="<?= $n ?>" type="button"><?= $n ?></button>
<?php endforeach; ?>
<div class="w-[66px] h-[66px]"></div>
<button class="keypad-btn w-[66px] h-[66px] rounded-full flex items-center justify-center font-display-time text-display-time text-on-surface bg-surface-container-lowest shadow-sm border border-outline-variant/20" data-digito="0" type="button">0</button>
<button aria-label="Apagar" class="keypad-btn w-[66px] h-[66px] rounded-full flex items-center justify-center text-on-surface-variant hover:text-primary transition-colors" data-apagar type="button">
<span class="material-symbols-outlined" style="font-size: 26px; font-variation-settings: 'FILL' 0;">backspace</span>
</button>
</div>
<button class="w-full max-w-[268px] h-[48px] mt-5 mb-2 rounded-full bg-primary text-on-primary font-headline-md text-headline-md flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg disabled:opacity-30 disabled:shadow-none" disabled id="btnConfirmarPin" type="button">
<span id="btnConfirmarPinTexto">Ver meus pedidos</span>
<span class="material-symbols-outlined">arrow_forward</span>
</button>
</main>
</section>

<!-- ============================ TELA: PEDIDOS ============================ -->
<section class="tela <?= $funcionarioToken !== null ? 'ativa' : '' ?> flex-col min-h-screen pt-[70px] pb-24" data-tela="lista">
<?php app_mobile_topbar(); ?>
<main class="px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-lg">
<div class="flex flex-col gap-stack-gap-sm fade-in-up">
<h2 class="font-headline-md text-headline-md text-on-surface">Meus pedidos</h2>
<p class="font-body-md text-body-md text-secondary" id="pedidosIntro">Acompanhe as correções que você pediu ao administrador.</p>
</div>

<div class="grid grid-cols-3 gap-3 fade-in-up" style="animation-delay:.1s;">
<div class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-amber-500/20 bg-amber-500/5 shadow-sm">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Aguardando</span>
<span class="font-headline-md text-headline-md text-amber-500" id="qtdPendente">0</span>
</div>
<div class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-green-500/20 bg-green-500/5 shadow-sm">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Aprovados</span>
<span class="font-headline-md text-headline-md text-green-500" id="qtdAprovado">0</span>
</div>
<div class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-error/20 bg-error/5 shadow-sm">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Recusados</span>
<span class="font-headline-md text-headline-md text-error" id="qtdRecusado">0</span>
</div>
</div>

<div class="flex flex-col gap-3 fade-in-up" id="listaPedidos" style="animation-delay:.2s;"></div>

<a class="w-full h-[46px] rounded-full border border-primary text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 transition-transform active:scale-95" href="historico.php">
<span class="material-symbols-outlined text-[18px]">edit_calendar</span>
Pedir uma correção no histórico
</a>
<button class="w-full h-[44px] rounded-full border border-primary/60 text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform" id="btnTutorial" type="button">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">school</span>
Como usar esta tela
</button>
</main>
<?php app_mobile_nav('pedidos'); ?>
</section>

</div>

<?php app_mobile_footer($csrf); ?>
<script src="assets/tutorial.js?v=1.0.0"></script>
<script>
(() => {
    'use strict';
    const $ = (sel) => document.querySelector(sel);
    const { toast, post, vibrar } = window.PontoApp;

    const CORES_STATUS = {
        pendente: ['bg-amber-500/10 text-amber-500 border-amber-500/30', 'hourglass_top', 'border-amber-500/25'],
        aprovado: ['bg-green-500/10 text-green-500 border-green-500/30', 'check_circle', 'border-green-500/25'],
        recusado: ['bg-error/10 text-error border-error/30', 'cancel', 'border-error/25']
    };

    const app = $('#app');
    let token = app.dataset.token || '';
    let pin = '';
    let verificando = false;

    function irPara(nome) {
        document.querySelectorAll('[data-tela]').forEach((el) => el.classList.toggle('ativa', el.dataset.tela === nome));
        window.scrollTo(0, 0);
    }

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto == null ? '' : texto);
        return div.innerHTML;
    }

    /* ---------------------------------- PIN ---------------------------------- */
    function renderPin() {
        document.querySelectorAll('#pinDots .pin-dot').forEach((dot, i) => {
            dot.classList.toggle('filled', i < pin.length);
            dot.classList.remove('error');
        });
        $('#btnConfirmarPin').disabled = pin.length < 4 || verificando;
    }

    function erroPin(mensagem) {
        document.querySelectorAll('#pinDots .pin-dot').forEach((dot) => {
            dot.classList.remove('filled');
            dot.classList.add('error');
        });
        vibrar([60, 40, 60]);
        toast(mensagem, 'error');
        setTimeout(() => { pin = ''; renderPin(); }, 600);
    }

    /* -------------------------------- consulta -------------------------------- */
    async function carregar(comPin) {
        if (verificando) return;
        verificando = true;
        $('#btnConfirmarPin').disabled = true;
        $('#btnConfirmarPinTexto').textContent = 'Consultando...';

        try {
            const dados = await post(comPin ? { pin } : { token });
            token = dados.token;
            render(dados);

            if (!$('[data-tela="lista"]').classList.contains('ativa')) {
                $('#pinConteudo').classList.add('fade-out-up');
                setTimeout(() => { $('#pinConteudo').classList.remove('fade-out-up'); irPara('lista'); }, 400);
            }
        } catch (erro) {
            if (comPin) {
                erroPin(erro.message);
            } else {
                /* Autorização do histórico venceu: cai para a tela de PIN. */
                token = '';
                irPara('pin');
                toast(erro.message, 'error');
            }
        } finally {
            verificando = false;
            $('#btnConfirmarPinTexto').textContent = 'Ver meus pedidos';
            renderPin();
        }
    }

    function render(dados) {
        $('#pedidosIntro').textContent = `${dados.funcionario.nome} · ${dados.funcionario.condominio || 'Vss E Acj'}`;
        $('#qtdPendente').textContent = dados.contagem.pendente;
        $('#qtdAprovado').textContent = dados.contagem.aprovado;
        $('#qtdRecusado').textContent = dados.contagem.recusado;

        const lista = $('#listaPedidos');
        if (!dados.pedidos.length) {
            lista.innerHTML = `<div class="glass-card rounded-xl p-8 text-center border border-surface-variant">
                <span class="material-symbols-outlined text-outline text-[32px] mb-2">inbox</span>
                <p class="font-body-md text-body-md text-secondary">Você ainda não pediu nenhuma correção.</p>
            </div>`;
            return;
        }

        lista.innerHTML = dados.pedidos.map((p, i) => {
            const [selo, icone, borda] = CORES_STATUS[p.status] || CORES_STATUS.pendente;
            const de = p.hora_atual ? `<span class="line-through text-secondary">${p.hora_atual}</span> → ` : '';

            return `
            <article class="surgir glass-card rounded-xl p-4 border ${borda} flex flex-col gap-2" style="animation-delay:${Math.min(i, 8) * 55}ms">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-label-sm text-label-sm text-secondary">${p.protocolo} · ${p.dia_br}</span>
                    <span class="px-2 py-0.5 rounded-full border font-label-bold text-label-bold flex items-center gap-1 flex-shrink-0 ${selo}">
                        <span class="material-symbols-outlined text-[14px]">${icone}</span>${escapar(p.status_label)}
                    </span>
                </div>
                <span class="font-body-lg text-body-lg text-on-surface font-medium">${escapar(p.acao)} ${escapar(p.tipo_label)}</span>
                <div class="font-body-lg text-body-lg tabular-nums">${de}<span class="text-primary font-medium">${p.hora_pedida}</span></div>
                <p class="font-body-md text-body-md text-on-surface-variant">${escapar(p.motivo)}</p>
                ${p.resposta ? `<div class="mt-1 pt-2 border-t border-outline-variant/30">
                    <span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Resposta do admin</span>
                    <p class="font-body-md text-body-md text-on-surface-variant mt-1">${escapar(p.resposta)}</p>
                </div>` : ''}
                <span class="font-label-sm text-label-sm text-secondary">Enviado em ${p.criado_br}${p.respondido_br ? ' · respondido em ' + p.respondido_br : ''}</span>
            </article>`;
        }).join('');
    }

    /* --------------------------------- eventos --------------------------------- */
    $('#keypad').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button');
        if (!botao) return;
        if (botao.hasAttribute('data-apagar')) { pin = pin.slice(0, -1); renderPin(); return; }
        if (!botao.dataset.digito || pin.length >= 4) return;

        pin += botao.dataset.digito;
        renderPin();
        vibrar(12);
    });

    $('#btnConfirmarPin').addEventListener('click', () => carregar(true));

    document.addEventListener('keydown', (evento) => {
        if (!$('[data-tela="pin"]').classList.contains('ativa')) return;
        if (/^\d$/.test(evento.key) && pin.length < 4) {
            pin += evento.key;
            renderPin();
        } else if (evento.key === 'Backspace') {
            pin = pin.slice(0, -1);
            renderPin();
        } else if (evento.key === 'Enter') {
            carregar(true);
        }
    });

    /* Nada de dado pessoal fica na tela depois que a pessoa sai. */
    window.addEventListener('pagehide', () => { $('#listaPedidos').innerHTML = ''; });

    /* --------------------------------- tutorial --------------------------------- */
    const PASSOS = [
        {
            titulo: 'Tela de Pedidos',
            texto: 'Aqui você <b>acompanha as correções</b> que pediu. São <b>4 passos</b>.'
        },
        {
            alvo: '#qtdPendente',
            titulo: '1. Aguardando',
            texto: 'Pedidos que o administrador <b>ainda não respondeu</b>. É só esperar.'
        },
        {
            alvo: '#qtdAprovado',
            titulo: '2. Aprovados',
            texto: 'Pedidos <b>aceitos</b>. A batida já foi corrigida no sistema e as horas do dia foram recalculadas sozinhas.'
        },
        {
            alvo: '#qtdRecusado',
            titulo: '3. Recusados',
            texto: 'Pedidos <b>negados</b>. O administrador escreve o motivo, e ele aparece dentro do cartão do pedido.'
        },
        {
            alvo: '#listaPedidos',
            titulo: '4. Seus pedidos',
            texto: 'Cada cartão mostra o <b>protocolo</b>, o dia, o horário antigo riscado, o horário novo em azul e o motivo que você escreveu.<br><br>Para <b>criar</b> um pedido novo, vá no <b>Histórico</b> e toque no dia que está errado.'
        }
    ];

    const CONFIG_TUTORIAL = { chave: 'pedidos' };

    $('#btnTutorial').addEventListener('click', () => window.Tutorial.iniciar(PASSOS, CONFIG_TUTORIAL));

    if (new URLSearchParams(location.search).get('tutorial') === '1') {
        const aguardar = setInterval(() => {
            if ($('[data-tela="lista"]').classList.contains('ativa')) {
                clearInterval(aguardar);
                setTimeout(() => window.Tutorial.iniciar(PASSOS, CONFIG_TUTORIAL), 700);
            }
        }, 500);
        toast('Digite seu PIN para o tutorial continuar.');
    }

    renderPin();
    if (token) carregar(false);
})();
</script>
<?php app_mobile_end(); ?>
