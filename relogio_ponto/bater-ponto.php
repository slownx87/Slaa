<?php
/*
 * Tela do funcionário: bater ponto.
 *
 * Fluxo (o PIN é pedido a cada ação, nada fica salvo em sessão):
 *   início -> PIN -> tipo de batida -> foto obrigatória -> comprovante
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_app_shell.php';

try { ensure_photo_columns(); } catch (Throwable $e) { /* o erro reaparece no ajax abaixo */ }

function ponto_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ponto_time(?string $dt): string
{
    return $dt ? date('H:i', strtotime($dt)) : '--:--';
}

/* Estado da jornada em linguagem de tela: rótulo, cor do selo e próxima ação. */
function ponto_status_from_registros(array $registros): array
{
    $jornadas = ponto_jornadas_from_registros($registros);
    $qtdJornadas = count($jornadas);
    $jornadaAberta = ponto_jornada_aberta($registros);

    if ($qtdJornadas === 0) {
        return ['key' => 'off', 'tom' => 'neutro', 'label' => 'Fora do expediente', 'next' => 'Toque em Bater Ponto e digite seu PIN para começar.'];
    }

    if ($jornadaAberta === null) {
        /* Mostra a hora da saída para não restar dúvida de que ela entrou. */
        $horaSaida = '';
        foreach (array_reverse($registros) as $r) {
            if ((string)$r['tipo'] === 'saida') {
                $horaSaida = ponto_time((string)$r['data_hora']);
                break;
            }
        }
        $rotulo = $horaSaida !== '' ? 'Saiu às ' . $horaSaida : 'Expediente encerrado';

        if ($qtdJornadas < ponto_max_jornadas_por_dia()) {
            $proxima = $qtdJornadas + 1;
            return ['key' => 'between', 'tom' => 'info', 'label' => $rotulo,
                'next' => 'Saída registrada. Você ainda pode iniciar a ' . ponto_ordem_jornada_label($proxima) . ' de hoje.'];
        }
        return ['key' => 'ended', 'tom' => 'info', 'label' => $rotulo,
            'next' => 'Saída registrada. Seu ponto de hoje está encerrado — não há mais nada para bater.'];
    }

    $open = open_interval($jornadaAberta['eventos'] ?? []);
    if ($open) {
        if ($open['tipo'] === 'saida_almoco') {
            return ['key' => 'lunch', 'tom' => 'pausa', 'label' => 'Em almoço', 'next' => 'Registre a volta do almoço para continuar.'];
        }
        if ($open['tipo'] === 'saida_janta') {
            return ['key' => 'dinner', 'tom' => 'pausa', 'label' => 'Em janta', 'next' => 'Registre a volta da janta para continuar.'];
        }
        return ['key' => 'break', 'tom' => 'pausa', 'label' => 'Em descanso', 'next' => 'Registre a volta do descanso para continuar.'];
    }

    return ['key' => 'working', 'tom' => 'ativo', 'label' => 'Trabalhando', 'next' => 'Escolha a próxima ação da ' . ponto_ordem_jornada_label(max(1, $qtdJornadas)) . '.'];
}

function ponto_funcionario_payload(array $funcionario): array
{
    $id = (int)$funcionario['id'];
    $dataPonto = data_ponto_ativa($id, today_sp());
    $registros = registros_do_dia($id, $dataPonto);

    $jornadaCargo = ponto_jornada_do_funcionario($id);
    /* Cargo de escala: a barra de progresso tem que medir contra o horário que
     * a pessoa está cumprindo hoje. Com o principal, quem entra às 14:00 num
     * turno de 6h aparecia com a barra quebrada. */
    $turnoHoje = ponto_turno_efetivo($registros, $jornadaCargo);
    $jornadaMinHoje = max(1, (int)($turnoHoje['jornada_min'] ?? $jornadaCargo['jornada_min']));
    $minutosHoje = ponto_minutos_trabalhados_dia($id, $dataPonto);
    $minutosSemana = ponto_minutos_trabalhados_semana($id);

    $acoes = [];
    foreach (allowed_ponto_actions($id, $dataPonto) as $tipo => $rotulo) {
        $acoes[] = ['tipo' => $tipo, 'label' => $rotulo];
    }

    /* Dia já encerrado: existe jornada e nenhuma está aberta. A tela precisa
     * deixar isso na cara, senão a pessoa vê "Entrada da 2ª jornada", toca, e
     * o menu volta igual — parecendo que a saída não foi registrada. */
    $jornadasDia = ponto_jornadas_from_registros($registros);
    $encerrado = count($jornadasDia) > 0 && ponto_jornada_aberta($registros) === null;
    $ultimaSaida = '';
    foreach (array_reverse($registros) as $r) {
        if ((string)$r['tipo'] === 'saida') {
            $ultimaSaida = ponto_time((string)$r['data_hora']);
            break;
        }
    }

    $batidas = [];
    foreach ($registros as $r) {
        $batidas[] = [
            'tipo' => (string)$r['tipo'],
            'label' => tipo_ponto_label((string)$r['tipo']),
            'hora' => ponto_time((string)$r['data_hora']),
        ];
    }

    return [
        'id' => $id,
        'nome' => (string)$funcionario['nome'],
        'cargo' => (string)($funcionario['cargo_nome'] ?? ''),
        'condominio' => (string)($funcionario['condominio_nome'] ?? ''),
        'data_ponto' => $dataPonto,
        'data_ponto_br' => date('d/m/Y', strtotime($dataPonto)),
        'dia_anterior' => $dataPonto !== today_sp(),
        'dia_encerrado' => $encerrado,
        'ultima_saida' => $ultimaSaida,
        'jornadas_feitas' => count($jornadasDia),
        'dias_abertos' => ponto_dias_em_aberto($id),
        'status' => ponto_status_from_registros($registros),
        'acoes' => $acoes,
        'batidas' => $batidas,
        'hoje' => [
            'texto' => ponto_fmt_hm($minutosHoje),
            'percentual' => min(100, (int)round($minutosHoje / $jornadaMinHoje * 100)),
            'jornada' => ponto_fmt_hm($jornadaMinHoje),
            'horario' => (string)($turnoHoje['horario'] ?? ''),
        ],
        'semana' => [
            'texto' => ponto_fmt_hm($minutosSemana),
            'percentual' => min(100, (int)round($minutosSemana / PONTO_SEMANA_PADRAO_MINUTOS * 100)),
        ],
        'horarios' => [
            'almoco' => sprintf('%02d:00', PONTO_ALMOCO_HORA_INICIO),
            'janta' => sprintf('%02d:00', PONTO_JANTA_HORA_INICIO),
        ],
        'token' => ponto_token_emitir($id),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        ponto_pin_guard();

        $acao = (string)($_POST['acao'] ?? 'identificar');
        $pin = trim((string)($_POST['pin'] ?? ''));
        $condominioId = (int)($_POST['condominio_id'] ?? 0);

        if ($pin === '') {
            throw new RuntimeException('Digite seu PIN.');
        }

        $funcionario = find_funcionario_by_pin($condominioId, $pin);
        if (!$funcionario) {
            ponto_pin_falhou();
            throw new RuntimeException($condominioId > 0 ? 'PIN inválido para esse condomínio.' : 'PIN não encontrado para funcionário ativo.');
        }
        ponto_pin_acertou();

        /* Dia antigo sem saída: vira um pedido de correção para o admin
         * aprovar. O funcionário não grava batida retroativa sozinho — seria
         * hora sem conferência nenhuma. */
        if ($acao === 'fechar_dia') {
            ensure_solicitacoes_table();
            $id = (int)$funcionario['id'];

            $dia = (string)($_POST['data_ponto'] ?? '');
            $hora = trim((string)($_POST['hora'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
                throw new RuntimeException('Dia inválido.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                throw new RuntimeException('Informe a hora em que você saiu, no formato 00:00.');
            }

            $abertos = ponto_dias_em_aberto($id);
            $alvo = null;
            foreach ($abertos as $d) {
                if ($d['data'] === $dia) { $alvo = $d; break; }
            }
            if (!$alvo) {
                throw new RuntimeException('Esse dia não está em aberto.');
            }

            /* Turno noturno: saiu de madrugada, então a saída é no dia seguinte. */
            $dataSaida = $dia;
            if ($alvo['entrada_dt'] !== '' && $hora < date('H:i', strtotime($alvo['entrada_dt']))) {
                $dataSaida = date('Y-m-d', strtotime($dia . ' +1 day'));
            }
            $dataHora = $dataSaida . ' ' . $hora . ':00';

            $stmtDup = db()->prepare("SELECT COUNT(*) FROM ponto_solicitacoes
                WHERE funcionario_id = ? AND data_ponto = ? AND status = 'pendente' AND tipo_registro = 'saida'");
            $stmtDup->execute([$id, $dia]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('Você já pediu o fechamento desse dia. Aguarde o administrador.');
            }

            $stmt = db()->prepare("INSERT INTO ponto_solicitacoes
                (funcionario_id, condominio_id, registro_id, tipo_solicitacao, data_ponto, data_hora_solicitada, data_hora_atual, tipo_registro, motivo, status, criado_em, ip, user_agent)
                VALUES (?, ?, NULL, 'criar', ?, ?, NULL, 'saida', ?, 'pendente', ?, ?, ?)");
            $stmt->execute([
                $id,
                (int)$funcionario['condominio_id'],
                $dia,
                $dataHora,
                'Esqueci de bater a saída no dia ' . date('d/m/Y', strtotime($dia)) . '. Saí às ' . $hora . '.',
                now_sp(),
                ponto_client_ip(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $protocolo = '#' . str_pad((string)db()->lastInsertId(), 5, '0', STR_PAD_LEFT);

            ponto_json([
                'ok' => true,
                'mensagem' => 'Pedido ' . $protocolo . ' enviado. O administrador vai fechar o dia ' . date('d/m', strtotime($dia)) . '.',
                'funcionario' => ponto_funcionario_payload($funcionario),
            ]);
        }

        if ($acao === 'registrar') {
            $tipo = (string)($_POST['tipo'] ?? '');
            $dataPonto = data_ponto_ativa((int)$funcionario['id'], today_sp());
            $allowed = allowed_ponto_actions((int)$funcionario['id'], $dataPonto);
            if (!isset($allowed[$tipo])) {
                throw new RuntimeException('Esse tipo de batida não está disponível agora. Confira a sequência do seu ponto.');
            }

            [$fotoPath, $fotoMime, $fotoSize] = save_photo_base64((string)($_POST['foto_base64'] ?? ''), (int)$funcionario['id'], $tipo);

            $latPost = $_POST['latitude'] ?? '';
            $lngPost = $_POST['longitude'] ?? '';

            $stmt = db()->prepare("INSERT INTO ponto_registros
                (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, foto_path, foto_mime, foto_tamanho, latitude, longitude, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                (int)$funcionario['id'],
                (int)$funcionario['condominio_id'],
                $tipo,
                $tipo === 'entrada' ? today_sp() : $dataPonto,
                now_sp(),
                mb_substr(trim((string)($_POST['observacao'] ?? '')), 0, 500),
                $fotoPath,
                $fotoMime,
                $fotoSize,
                $latPost !== '' ? $latPost : null,
                $lngPost !== '' ? $lngPost : null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);

            recalc_resumo((int)$funcionario['id'], $tipo === 'entrada' ? today_sp() : $dataPonto);
            $funcionario = get_funcionario((int)$funcionario['id']) ?: $funcionario;

            ponto_json([
                'ok' => true,
                'comprovante' => [
                    'tipo' => $tipo,
                    'label' => tipo_ponto_label($tipo),
                    'hora' => date('H:i:s'),
                    'data' => date('d/m/Y'),
                    'local' => (string)($funcionario['condominio_nome'] ?? 'Vss E Acj'),
                ],
                'funcionario' => ponto_funcionario_payload($funcionario),
            ]);
        }

        ponto_json(['ok' => true, 'funcionario' => ponto_funcionario_payload($funcionario)]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'mais de um funcionário') !== false || stripos($msg, 'Selecione o condomínio') !== false) {
            $msg = 'PIN duplicado. Peça ao administrador para alterar o PIN de um dos funcionários.';
        }
        ponto_json(['ok' => false, 'error' => $msg], 422);
    }
}

$csrf = csrf_token();

$cssTela = <<<'CSS'
<style>
    .pin-dot { transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .pin-dot.filled {
        background-color: #3b82f6;
        transform: scale(1.25);
        box-shadow: 0 0 16px rgba(59, 130, 246, .75);
    }
    .pin-dot.error { background-color: #ef4444; box-shadow: 0 0 16px rgba(239,68,68,.7); animation: shake 0.4s ease-in-out; }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        50% { transform: translateX(5px); }
        75% { transform: translateX(-5px); }
    }
    .keypad-btn { position: relative; overflow: hidden; transition: background-color .12s, transform .12s, border-color .12s; }
    .keypad-btn:active { background-color: rgba(59, 130, 246, .16); border-color: #3b82f6; transform: scale(0.94); }
    .keypad-btn::after {
        content: '';
        position: absolute; top: 50%; left: 50%;
        width: 100%; height: 100%;
        background: radial-gradient(circle, rgba(59,130,246,.45) 0%, transparent 70%);
        transform: translate(-50%, -50%) scale(0);
        opacity: 0;
        transition: transform 0.35s, opacity 0.35s;
        pointer-events: none;
    }
    .keypad-btn:active::after { transform: translate(-50%, -50%) scale(2.2); opacity: 1; transition: 0s; }

    .punch-button {
        background: rgba(17, 24, 39, .86);
        border: 1px solid rgba(148, 163, 184, .18);
        box-shadow: 0 4px 14px rgba(0, 0, 0, .35);
        transition: all .22s ease;
    }
    .punch-button:not(:disabled):hover, .punch-button:not(:disabled):active {
        border-color: #3b82f6;
        box-shadow: 0 10px 28px rgba(59, 130, 246, .28);
        transform: translateY(-3px);
    }
    .punch-button:not(:disabled):active { transform: scale(.97); }
    .punch-button:disabled { opacity:.38; box-shadow:none; cursor:not-allowed; }

    /* Moldura que orienta o enquadramento do rosto na foto. */
    .guia-rosto {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -52%);
        width: 62%;
        aspect-ratio: 3 / 4;
        border: 2px dashed rgba(59, 130, 246, .75);
        border-radius: 999px;
        box-shadow: 0 0 0 9999px rgba(5, 8, 16, .45);
        pointer-events: none;
        animation: respirar 3s ease-in-out infinite;
    }
    @keyframes respirar {
        0%, 100% { opacity: .65; transform: translate(-50%, -52%) scale(1); }
        50%      { opacity: 1;   transform: translate(-50%, -52%) scale(1.03); }
    }

    /* Passo a passo da foto, para não ficar vago o que fazer. */
    .passo { transition: all .25s ease; }
    .passo[data-estado="atual"] { background: rgba(59,130,246,.18); border-color: #3b82f6; color: #fff; }
    .passo[data-estado="feito"] { background: rgba(34,197,94,.16); border-color: #22c55e; color: #22c55e; }

    /* Folha da câmera: cantos de 24px e alça, como pede o design. */
    .sheet { transition: transform .35s cubic-bezier(0.16, 1, 0.3, 1); transform: translateY(100%); }
    .sheet.open { transform: translateY(0); }
    .sheet-backdrop { transition: opacity .3s ease; opacity: 0; pointer-events: none; }
    .sheet-backdrop.open { opacity: 1; pointer-events: auto; }

    .tela { display: none; }
    .tela.ativa { display: flex; }

    /* Visível por padrão; o estado pequeno/invisível fica no @keyframes. Se a
       animação não rodar no aparelho, o certinho verde do comprovante aparece
       sem efeito em vez de sumir. Ver a regra no _app_shell.php. */
    .check-ring { animation: popCheck .5s cubic-bezier(0.16, 1, 0.3, 1) both; }
    @keyframes popCheck {
        from { transform: scale(.4); opacity: 0; }
        to   { transform: scale(1);  opacity: 1; }
    }

    /* Aparelhos de tela curta: encolhe o teclado para o botão de confirmar
       caber sem rolagem. Nos celulares normais o tamanho do design é mantido. */
    @media (max-height: 700px) {
        #keypad button { width: 56px; height: 56px; font-size: 26px; line-height: 1; }
        #keypad > div { width: 56px; height: 56px; }
        #pinDots { margin-bottom: 1rem; }
        #btnConfirmarPin { margin-top: .75rem; }
    }
</style>
CSS;

app_mobile_head(APP_NAME, 'bg-background text-on-background antialiased min-h-screen', $cssTela);
?>
<div id="app" data-csrf="<?= h($csrf) ?>">

<!-- ============================ TELA: INÍCIO ============================ -->
<section class="tela ativa flex-col min-h-screen pt-[70px] pb-[80px]" data-tela="home">
<?php app_mobile_topbar(); ?>
<main class="flex-grow px-container-margin flex flex-col gap-stack-gap-lg w-full max-w-md mx-auto">
<section class="mt-6 fade-in-up" style="animation-delay: 0.1s;">
<div class="bg-surface-container-lowest rounded-[24px] p-5 flex flex-col items-center justify-center text-center relative overflow-hidden shadow-sm border border-outline-variant/20">
<div class="z-10 w-full flex flex-col items-center">
<div class="inline-flex items-center justify-center gap-2 mb-4 px-3 py-1 rounded-full bg-secondary/10 border border-secondary/20" id="statusChip">
<span class="w-2 h-2 rounded-full bg-secondary animate-pulse" id="statusDot"></span>
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" id="statusLabel">Fora do expediente</span>
</div>
<div class="flex flex-col items-center mb-6">
<div class="font-display-time text-[42px] leading-none font-bold tabular-nums text-primary" id="current-time">--:--<span class="text-lg text-primary/70 ml-1 font-medium">:--</span></div>
<div class="font-body-md text-body-md text-secondary mt-2" id="current-date">--</div>
</div>
<button class="glow-pulse relative w-32 h-32 rounded-full bg-primary text-on-primary flex flex-col items-center justify-center gap-1 active:scale-95 transition-transform duration-200 ease-out group" id="btnBaterPonto" type="button">
<div class="absolute inset-0 bg-white/10 scale-0 group-hover:scale-100 transition-transform duration-300 rounded-full"></div>
<span class="material-symbols-outlined text-[34px] z-10 text-on-primary" style="font-variation-settings: 'FILL' 1;">fingerprint</span>
<span class="font-label-bold text-label-bold uppercase z-10 text-on-primary tracking-widest mt-1">Bater Ponto</span>
</button>
</div>
</div>
</section>

<section class="grid grid-cols-2 gap-stack-gap-md fade-in-up mb-6" style="animation-delay: 0.3s;">
<div class="glass-card rounded-xl p-4 flex flex-col items-start border border-surface-variant shadow-sm">
<div class="flex items-center gap-2 mb-3 text-secondary"><span class="material-symbols-outlined text-[18px]">today</span><span class="font-label-bold text-label-bold uppercase tracking-wider">Hoje</span></div>
<div class="font-headline-lg text-headline-lg text-on-surface font-bold mb-4" id="horasHoje">--h --m</div>
<div class="w-full bg-surface-container h-1.5 rounded-full overflow-hidden"><div class="barra-viva bg-primary h-full rounded-full transition-all duration-700" id="barraHoje" style="width: 0%;"></div></div>
</div>
<div class="glass-card rounded-xl p-4 flex flex-col items-start border border-surface-variant shadow-sm">
<div class="flex items-center gap-2 mb-3 text-secondary"><span class="material-symbols-outlined text-[18px]">calendar_month</span><span class="font-label-bold text-label-bold uppercase tracking-wider">Semana</span></div>
<div class="font-headline-lg text-headline-lg text-on-surface font-bold mb-4" id="horasSemana">--h --m</div>
<div class="w-full bg-surface-container h-1.5 rounded-full overflow-hidden"><div class="barra-viva bg-primary h-full rounded-full transition-all duration-700" id="barraSemana" style="width: 0%;"></div></div>
</div>
</section>

<button class="w-full h-[48px] rounded-full border border-primary/60 text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform fade-in-up mb-4" id="btnTutorial" style="animation-delay:.4s;" type="button">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">school</span>
Como usar o aplicativo (tutorial)
</button>
</main>
<?php app_mobile_nav('home'); ?>
</section>

<!-- ============================== TELA: PIN ============================== -->
<section class="tela flex-col min-h-screen overflow-y-auto" data-tela="pin">
<header class="w-full px-container-margin py-stack-gap-md flex justify-between items-center z-10 relative">
<button aria-label="Cancelar" class="w-12 h-12 flex items-center justify-center rounded-full text-primary hover:bg-primary/5 active:scale-95 transition-all" data-voltar="home" type="button">
<span class="material-symbols-outlined font-light text-2xl">close</span>
</button>
<?php app_mobile_logo(); ?>
<div class="w-12 h-12"></div>
</header>
<main class="flex-grow flex flex-col items-center justify-center px-container-margin pb-safe z-10 relative py-4" id="pinConteudo">
<div class="text-center mb-stack-gap-lg">
<h1 class="font-headline-lg text-headline-lg text-on-background mb-2">Digite seu PIN</h1>
<p class="font-body-md text-body-md text-secondary" id="pinSubtitulo">Para confirmar sua identidade</p>
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
<span id="btnConfirmarPinTexto">Confirmar PIN</span>
<span class="material-symbols-outlined">arrow_forward</span>
</button>
</main>
</section>

<!-- ========================= TELA: TIPO DE BATIDA ========================= -->
<section class="tela flex-col min-h-screen pt-[70px] pb-[80px]" data-tela="tipo">
<?php app_mobile_topbar(); ?>
<main class="flex-1 w-full max-w-md mx-auto px-container-margin flex flex-col gap-stack-gap-lg">
<section class="flex flex-col items-center justify-center text-center pt-2">
<h2 class="font-headline-md text-headline-md text-on-surface mb-2">Selecione o Tipo de Ponto</h2>
<p class="font-body-md text-body-md text-on-surface-variant max-w-[280px]" id="tipoSubtitulo">Escolha a opção correspondente ao seu registro atual.</p>
<div class="mt-5 mb-3 font-display-time text-display-time text-on-surface flex items-center gap-2"><span id="tipoRelogio">--:--</span></div>
</section>
<div class="hidden flex-col gap-2 rounded-2xl border border-error/40 bg-error/10 p-3" id="painelDiasAbertos">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-error text-[20px]" id="diasAbertosIcone">running_with_errors</span>
<h3 class="font-headline-md text-headline-md text-on-surface" id="diasAbertosTitulo">Dia sem saída</h3>
</div>
<p class="font-body-md text-body-md text-on-surface-variant" id="diasAbertosTexto">Você esqueceu de bater a saída nestes dias. Informe a hora e o administrador fecha para você.</p>
<div class="flex flex-col gap-2" id="listaDiasAbertos"></div>
</div>

<div class="hidden flex-col gap-3 rounded-2xl border border-green-500/40 bg-green-500/10 p-4 text-center" id="painelEncerrado">
<div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center text-green-500 mx-auto">
<span class="material-symbols-outlined text-[26px]" style="font-variation-settings: 'FILL' 1;">task_alt</span>
</div>
<h3 class="font-headline-md text-headline-md text-on-surface" id="encerradoTitulo">Expediente encerrado</h3>
<p class="font-body-md text-body-md text-on-surface-variant" id="encerradoTexto"></p>
<button class="w-full h-[44px] rounded-full border border-outline text-secondary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform" id="btnNovaJornada" type="button">
<span class="material-symbols-outlined text-[16px]">restart_alt</span>
<span id="btnNovaJornadaTexto">Iniciar nova jornada</span>
</button>
</div>

<section class="grid grid-cols-2 gap-4 w-full" id="listaTipos"></section>
<p class="hidden font-body-md text-body-md text-secondary text-center px-4" id="dicaRefeicao"></p>
<div class="hidden items-start gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3" id="avisoDiaAnterior">
<span class="material-symbols-outlined text-amber-500 text-[18px] flex-shrink-0">warning</span>
<p class="font-body-md text-body-md text-on-surface-variant" id="avisoDiaAnteriorTexto"></p>
</div>
<div class="pt-6 flex justify-center w-full">
<button class="font-label-bold text-label-bold text-primary transition-colors py-3 px-6 rounded-full border border-transparent hover:bg-primary/5 active:bg-primary/10" data-voltar="home" type="button">
Cancelar e Voltar
</button>
</div>
</main>
<?php app_mobile_nav('home'); ?>
</section>

<!-- ============================ TELA: PERFIL ============================ -->
<section class="tela flex-col min-h-screen pt-[70px] pb-[80px]" data-tela="perfil">
<?php app_mobile_topbar(); ?>
<main class="flex-1 w-full max-w-md mx-auto px-container-margin flex flex-col gap-stack-gap-lg">
<section class="mt-2 fade-in-up">
<div class="bg-surface-container-lowest rounded-[24px] p-4 flex flex-col items-center text-center shadow-sm border border-outline-variant/20">
<div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-primary text-[30px]" style="font-variation-settings: 'FILL' 1;">person</span>
</div>
<h2 class="font-headline-lg text-headline-lg text-on-surface" id="perfilNome">--</h2>
<p class="font-body-md text-body-md text-secondary mt-1" id="perfilCargo">--</p>
<div class="inline-flex items-center gap-2 mt-4 px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20">
<span class="material-symbols-outlined text-primary text-[16px]">apartment</span>
<span class="font-label-bold text-label-bold text-primary" id="perfilCondominio">--</span>
</div>
</div>
</section>
<section class="glass-card rounded-xl p-4 border border-surface-variant shadow-sm fade-in-up" style="animation-delay: .15s;">
<div class="flex items-center justify-between mb-4">
<span class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Batidas de hoje</span>
<span class="font-label-sm text-label-sm text-secondary" id="perfilData">--</span>
</div>
<div class="flex flex-col gap-3" id="perfilBatidas"></div>
</section>
<div class="pt-2 flex justify-center">
<button class="font-label-bold text-label-bold text-primary py-3 px-6 rounded-full hover:bg-primary/5" data-voltar="home" type="button">Voltar ao início</button>
</div>
</main>
<?php app_mobile_nav('perfil'); ?>
</section>

<!-- =========================== TELA: COMPROVANTE =========================== -->
<section class="tela flex-col min-h-screen items-center justify-center px-container-margin" data-tela="sucesso">
<main class="w-full max-w-md mx-auto flex flex-col items-center text-center">
<div class="check-ring w-20 h-20 rounded-full bg-primary flex items-center justify-center mb-8 shadow-lg" style="box-shadow: 0 8px 40px rgba(59,130,246,.6);">
<span class="material-symbols-outlined text-on-primary text-[44px]" style="font-variation-settings: 'FILL' 1;">check</span>
</div>
<h1 class="font-headline-lg text-headline-lg text-on-surface mb-2">Ponto registrado</h1>
<p class="font-body-md text-body-md text-secondary mb-8" id="sucessoTexto">Sua jornada foi atualizada.</p>
<div class="w-full bg-surface-container-lowest rounded-[20px] p-4 flex flex-col gap-3 shadow-sm border border-outline-variant/20 mb-6">
<div class="flex items-center justify-between"><span class="font-body-md text-body-md text-secondary">Tipo</span><strong class="font-body-lg text-body-lg text-on-surface" id="reciboTipo">--</strong></div>
<div class="flex items-center justify-between"><span class="font-body-md text-body-md text-secondary">Horário</span><strong class="font-body-lg text-body-lg text-primary tabular-nums" id="reciboHora">--:--</strong></div>
<div class="flex items-center justify-between"><span class="font-body-md text-body-md text-secondary">Data</span><strong class="font-body-lg text-body-lg text-on-surface" id="reciboData">--</strong></div>
<div class="flex items-center justify-between gap-4"><span class="font-body-md text-body-md text-secondary">Local</span><strong class="font-body-lg text-body-lg text-on-surface text-right truncate" id="reciboLocal">--</strong></div>
</div>
<button class="w-full h-[48px] bg-primary text-on-primary rounded-full font-headline-md text-headline-md flex items-center justify-center transition-transform active:scale-95 shadow-md" data-voltar="home" type="button">Voltar para o início</button>
</main>
</section>

<!-- ================== FOLHA: FECHAR DIA ANTERIOR ================== -->
<div class="sheet-backdrop fixed inset-0 bg-black/60 z-[60]" id="fdFundo"></div>
<div class="sheet fixed bottom-0 left-0 right-0 z-[70] bg-surface-container-lowest rounded-t-[20px] shadow-2xl max-w-md mx-auto" id="fdFolha">
<div class="flex justify-center pt-3 pb-1"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="px-container-margin pb-8 pt-2 flex flex-col gap-3">
<h3 class="font-headline-md text-headline-md text-on-surface" id="fdTitulo">Fechar o dia</h3>
<p class="font-body-md text-body-md text-secondary" id="fdTexto"></p>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fdHora">Que horas você saiu?</label>
<input class="w-full rounded-xl p-3 font-display-time text-[26px] leading-none tabular-nums text-center bg-surface-container border border-outline-variant text-on-surface focus:outline-none focus:border-primary" id="fdHora" style="color-scheme: dark;" type="time"/>

<div class="flex gap-2 pt-1">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="fdCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="fdEnviar" type="button">Enviar</button>
</div>
<p class="font-label-sm text-label-sm text-secondary text-center">O administrador precisa aprovar antes de a batida entrar.</p>
</div>
</div>

<!-- ====================== FOLHA INFERIOR: CÂMERA ====================== -->
<div class="sheet-backdrop fixed inset-0 bg-black/40 z-[60]" id="camBackdrop"></div>
<div class="sheet fixed bottom-0 left-0 right-0 z-[70] bg-surface-container-lowest rounded-t-[20px] shadow-2xl max-w-md mx-auto" id="camSheet">
<div class="flex justify-center pt-3 pb-1"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="px-container-margin pb-8 pt-2 flex flex-col gap-4">
<div class="flex items-start justify-between gap-3">
<div class="min-w-0">
<div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/15 border border-primary/30 mb-2">
<span class="material-symbols-outlined text-primary text-[16px]" id="camIcone" style="font-variation-settings: 'FILL' 1;">schedule</span>
<span class="font-label-bold text-label-bold text-primary uppercase tracking-wider truncate" id="camTitulo">Batida</span>
</div>
<p class="font-body-md text-body-md text-secondary" id="camSubtitulo">Enquadre o rosto e tire a foto.</p>
</div>
<button aria-label="Fechar" class="w-10 h-10 flex-shrink-0 flex items-center justify-center rounded-full text-secondary hover:bg-surface-container active:scale-95 transition-all" id="camFechar" type="button">
<span class="material-symbols-outlined">close</span>
</button>
</div>

<!-- Passo a passo, para ficar claro o que falta fazer -->
<div class="flex items-center gap-2">
<div class="passo flex-1 flex items-center gap-2 px-3 py-2 rounded-xl border border-outline-variant text-secondary" data-passo="1" data-estado="atual">
<span class="material-symbols-outlined text-[18px]">photo_camera</span>
<span class="font-label-bold text-label-bold">1 Câmera</span>
</div>
<div class="passo flex-1 flex items-center gap-2 px-3 py-2 rounded-xl border border-outline-variant text-secondary" data-passo="2">
<span class="material-symbols-outlined text-[18px]">center_focus_strong</span>
<span class="font-label-bold text-label-bold">2 Foto</span>
</div>
<div class="passo flex-1 flex items-center gap-2 px-3 py-2 rounded-xl border border-outline-variant text-secondary" data-passo="3">
<span class="material-symbols-outlined text-[18px]">check_circle</span>
<span class="font-label-bold text-label-bold">3 Enviar</span>
</div>
</div>

<div class="relative w-full aspect-[3/4] max-h-[34vh] rounded-[20px] overflow-hidden bg-surface-container flex items-center justify-center">
<video autoplay class="w-full h-full object-cover" id="camVideo" muted playsinline></video>
<img alt="Foto capturada" class="hidden absolute inset-0 w-full h-full object-cover" id="camPreview"/>
<canvas class="hidden" id="camCanvas"></canvas>
<div class="guia-rosto hidden" id="camGuia"></div>
<div class="absolute bottom-0 left-0 right-0 px-4 py-2 bg-black/60 text-white font-label-bold text-label-bold flex items-center justify-between" id="camCarimbo">
<span>Vss E Acj</span><span class="tabular-nums" id="camRelogio">--:--:--</span>
</div>
<div class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-secondary font-body-md text-body-md px-6 text-center bg-surface-container" id="camAviso">
<span class="material-symbols-outlined text-[36px] text-primary animate-pulse">photo_camera</span>
Abrindo a câmera...
</div>
</div>

<textarea class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest p-3 font-body-md text-body-md text-on-surface placeholder:text-secondary resize-none focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary" id="camObservacao" maxlength="500" placeholder="Observação (opcional)" rows="2"></textarea>
<div class="flex gap-3">
<button class="flex-1 h-[46px] rounded-full border border-primary text-primary font-label-bold text-label-bold transition-transform active:scale-95" id="camAbrir" type="button">Abrir câmera</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold transition-transform active:scale-95 disabled:opacity-40" disabled id="camCapturar" type="button">Tirar foto</button>
</div>
<button class="w-full h-[48px] rounded-full bg-primary text-on-primary font-headline-md text-headline-md flex items-center justify-center gap-2 transition-transform active:scale-95 shadow-md disabled:opacity-40" disabled id="camConfirmar" type="button">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">how_to_reg</span>
<span id="camConfirmarTexto">Confirmar batida</span>
</button>
</div>
</div>
</div>

<?php app_mobile_footer($csrf); ?>
<script src="assets/tutorial.js?v=1.0.0"></script>
<script>
(() => {
    'use strict';
    const $ = (sel) => document.querySelector(sel);
    const { toast, post, vibrar } = window.PontoApp;

    /* Ícone de cada tipo de batida, seguindo o desenho das telas. */
    const ICONES = {
        entrada: 'login',
        saida: 'logout',
        saida_almoco: 'restaurant',
        volta_almoco: 'arrow_circle_left',
        saida_janta: 'dinner_dining',
        volta_janta: 'arrow_circle_left',
        saida_descanso: 'coffee',
        volta_descanso: 'arrow_circle_left'
    };
    const TONS = {
        ativo:  ['bg-green-500/10 border-green-500/20', 'bg-green-500', 'text-green-500'],
        pausa:  ['bg-amber-500/10 border-amber-500/20', 'bg-amber-500', 'text-amber-500'],
        info:   ['bg-primary/10 border-primary/20',     'bg-primary',   'text-primary'],
        neutro: ['bg-secondary/10 border-secondary/20', 'bg-secondary', 'text-secondary']
    };

    /* O funcionário só vive na memória desta aba e some sozinho: o PIN é
     * pedido de novo a cada ação. */
    const LIMPAR_APOS_MS = 180000;
    let funcionario = null;
    let limparTimer = null;
    let pin = '';
    let modo = 'ponto';         // ponto | perfil
    let tipoEscolhido = null;
    let fotoBase64 = '';
    let stream = null;
    let enviando = false;
    let verificando = false;

    /* ------------------------------ navegação ------------------------------ */
    function irPara(nome) {
        document.querySelectorAll('[data-tela]').forEach((el) => {
            el.classList.toggle('ativa', el.dataset.tela === nome);
        });
        window.scrollTo(0, 0);
        /* O PIN precisa sobreviver do teclado até o envio da batida: ele é
         * reenviado junto do registro. Só é apagado ao voltar para o início. */
        if (nome === 'home') zerarPin();
        if (nome === 'home' && location.hash === '#perfil') {
            history.replaceState(null, '', location.pathname);
        }
    }

    function esquecerFuncionario() {
        funcionario = null;
        clearTimeout(limparTimer);
        zerarPin();
        renderHome();
    }

    function agendarEsquecimento() {
        clearTimeout(limparTimer);
        limparTimer = setTimeout(() => {
            esquecerFuncionario();
            if ($('[data-tela="perfil"]').classList.contains('ativa')) irPara('home');
        }, LIMPAR_APOS_MS);
    }

    /* -------------------------------- relógio -------------------------------- */
    function tick() {
        const agora = new Date();
        const hh = String(agora.getHours()).padStart(2, '0');
        const mm = String(agora.getMinutes()).padStart(2, '0');
        const ss = String(agora.getSeconds()).padStart(2, '0');
        $('#current-time').innerHTML = `${hh}:${mm}<span class="text-lg text-primary/70 ml-1 font-medium">:${ss}</span>`;
        const data = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
        $('#current-date').textContent = data.charAt(0).toUpperCase() + data.slice(1);
        $('#tipoRelogio').textContent = `${hh}:${mm}`;
        $('#camRelogio').textContent = `${hh}:${mm}:${ss}`;
    }

    /* -------------------------------- início -------------------------------- */
    function renderHome() {
        const chip = $('#statusChip'), ponto = $('#statusDot'), rotulo = $('#statusLabel');
        const st = funcionario ? funcionario.status : { tom: 'neutro', label: 'Fora do expediente', next: 'Toque em Bater Ponto e digite seu PIN para começar.' };
        const [chipCls, pontoCls, textoCls] = TONS[st.tom] || TONS.neutro;

        chip.className = 'inline-flex items-center justify-center gap-2 mb-4 px-3 py-1 rounded-full border ' + chipCls;
        ponto.className = 'w-2 h-2 rounded-full animate-pulse ' + pontoCls;
        rotulo.className = 'font-label-bold text-label-bold uppercase tracking-wider ' + textoCls;
        rotulo.textContent = funcionario ? `${st.label} · ${primeiroNome(funcionario.nome)}` : st.label;

        $('#horasHoje').textContent = funcionario ? funcionario.hoje.texto : '--h --m';
        $('#horasSemana').textContent = funcionario ? funcionario.semana.texto : '--h --m';
        $('#barraHoje').style.width = (funcionario ? funcionario.hoje.percentual : 0) + '%';
        $('#barraSemana').style.width = (funcionario ? funcionario.semana.percentual : 0) + '%';
    }

    function primeiroNome(nome) {
        return String(nome || '').trim().split(/\s+/)[0] || '';
    }

    /* ---------------------------------- PIN ---------------------------------- */
    function renderPin() {
        document.querySelectorAll('#pinDots .pin-dot').forEach((dot, i) => {
            dot.classList.toggle('filled', i < pin.length);
            dot.classList.remove('error');
        });
        $('#btnConfirmarPin').disabled = pin.length < 4 || verificando;
    }

    function zerarPin() {
        pin = '';
        renderPin();
    }

    function erroPin(mensagem) {
        document.querySelectorAll('#pinDots .pin-dot').forEach((dot) => {
            dot.classList.remove('filled');
            dot.classList.add('error');
        });
        vibrar([60, 40, 60]);
        toast(mensagem, 'error');
        setTimeout(zerarPin, 600);
    }

    function abrirPin(novoModo) {
        modo = novoModo;
        $('#pinSubtitulo').textContent = novoModo === 'perfil'
            ? 'Para ver seus dados e as batidas de hoje'
            : 'Para confirmar sua identidade';
        zerarPin();
        irPara('pin');
    }

    async function identificar() {
        if (verificando || pin.length < 4) return;
        verificando = true;
        $('#btnConfirmarPin').disabled = true;
        $('#btnConfirmarPinTexto').textContent = 'Verificando...';

        try {
            const dados = await post({ acao: 'identificar', pin });
            funcionario = dados.funcionario;
            agendarEsquecimento();
            renderHome();
            $('#pinConteudo').classList.add('fade-out-up');
            setTimeout(() => {
                $('#pinConteudo').classList.remove('fade-out-up');
                if (modo === 'perfil') { renderPerfil(); irPara('perfil'); }
                else { renderTipos(); irPara('tipo'); }
            }, 400);
        } catch (erro) {
            erroPin(erro.message);
        } finally {
            verificando = false;
            $('#btnConfirmarPinTexto').textContent = 'Confirmar PIN';
            renderPin();
        }
    }

    /* --------------------------- tipos disponíveis --------------------------- */
    function renderDiasAbertos() {
        const abertos = (funcionario && funcionario.dias_abertos) || [];
        const painel = $('#painelDiasAbertos');

        if (!abertos.length) {
            painel.classList.add('hidden');
            painel.classList.remove('flex');
            return;
        }
        painel.classList.remove('hidden');
        painel.classList.add('flex');

        /* Se todos os dias já viraram pedido, o painel inteiro deixa de ser
         * cobrança e vira aviso de espera — inclusive a cor. */
        const soEsperando = abertos.every((d) => d.pedido);
        painel.classList.toggle('border-error/40', !soEsperando);
        painel.classList.toggle('bg-error/10', !soEsperando);
        painel.classList.toggle('border-amber-500/40', soEsperando);
        painel.classList.toggle('bg-amber-500/10', soEsperando);
        $('#diasAbertosIcone').classList.toggle('text-error', !soEsperando);
        $('#diasAbertosIcone').classList.toggle('text-amber-500', soEsperando);
        $('#diasAbertosIcone').textContent = soEsperando ? 'hourglass_top' : 'running_with_errors';
        $('#diasAbertosTitulo').textContent = soEsperando ? 'Fechamento pedido' : 'Dia sem saída';
        $('#diasAbertosTexto').textContent = soEsperando
            ? 'Seu pedido já foi enviado. Assim que o administrador autorizar, este aviso some sozinho.'
            : 'Você esqueceu de bater a saída nestes dias. Informe a hora e o administrador fecha para você.';

        /* Dia com pedido já enviado fica em âmbar e sem botão: o funcionário
         * precisa ver que a parte dele terminou e que agora é o administrador
         * quem responde. Antes o aviso continuava idêntico ao de um dia
         * intocado e parecia que o pedido não tinha sido enviado. */
        $('#listaDiasAbertos').innerHTML = abertos.map((d) => {
            if (d.pedido) {
                return `
            <div class="w-full flex items-center justify-between gap-2 rounded-xl border border-amber-500/40 bg-surface-container-lowest p-2.5">
                <div class="min-w-0">
                    <p class="font-body-lg text-body-lg text-on-surface font-medium">${d.data_br}</p>
                    <p class="font-label-sm text-label-sm text-amber-500">Pedido ${d.pedido} enviado · aguardando o administrador</p>
                </div>
                <span class="material-symbols-outlined text-amber-500 text-[20px] flex-shrink-0">hourglass_top</span>
            </div>`;
            }
            return `
            <button class="w-full flex items-center justify-between gap-2 rounded-xl border border-error/40 bg-surface-container-lowest p-2.5 text-left active:scale-98 transition-transform"
                    data-fechar-dia="${d.data}" data-dia-br="${d.data_br}" data-entrada="${d.entrada}" type="button">
                <div class="min-w-0">
                    <p class="font-body-lg text-body-lg text-on-surface font-medium">${d.data_br}</p>
                    <p class="font-label-sm text-label-sm text-secondary">Entrou às ${d.entrada} · sem saída</p>
                </div>
                <span class="px-2 py-1 rounded-full bg-error/15 text-error border border-error/30 font-label-bold text-label-bold flex-shrink-0">Fechar</span>
            </button>`;
        }).join('');
    }

    function renderTipos() {
        const box = $('#listaTipos');
        let acoes = (funcionario && funcionario.acoes) || [];
        const painel = $('#painelEncerrado');

        renderDiasAbertos();

        /* Dia já encerrado: em vez de mostrar "Entrada da 2ª jornada" como se
         * fosse a continuação normal, mostra que a saída foi registrada e deixa
         * a nova jornada como uma escolha separada e deliberada. */
        if (funcionario.dia_encerrado) {
            const podeNova = acoes.some((a) => a.tipo === 'entrada');
            $('#encerradoTitulo').textContent = funcionario.ultima_saida
                ? 'Você saiu às ' + funcionario.ultima_saida
                : 'Expediente encerrado';
            $('#encerradoTexto').textContent = podeNova
                ? 'A saída final foi registrada e o dia está fechado. Só toque abaixo se for MESMO voltar a trabalhar hoje.'
                : 'A saída final foi registrada. Não há mais batidas disponíveis hoje.';

            $('#btnNovaJornada').classList.toggle('hidden', !podeNova);
            if (podeNova) {
                const entrada = acoes.find((a) => a.tipo === 'entrada');
                $('#btnNovaJornadaTexto').textContent = entrada.label;
            }

            painel.classList.remove('hidden');
            painel.classList.add('flex');
            /* A entrada sai da grade: ela vive só no botão do painel. */
            acoes = acoes.filter((a) => a.tipo !== 'entrada');
        } else {
            painel.classList.add('hidden');
            painel.classList.remove('flex');
        }

        $('#tipoSubtitulo').textContent = funcionario.dia_encerrado
            ? `${primeiroNome(funcionario.nome)}, seu dia já foi encerrado.`
            : (acoes.length
                ? `Olá, ${primeiroNome(funcionario.nome)}. Escolha o registro de agora.`
                : funcionario.status.next);

        box.innerHTML = acoes.map((acao, i) => `
            <button class="punch-button surgir rounded-2xl p-3 flex flex-col items-center justify-center gap-3 h-28 w-full focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-background group" data-tipo="${acao.tipo}" data-label="${acao.label.replace(/"/g, '&quot;')}" style="animation-delay:${i * 70}ms" type="button">
                <div class="w-10 h-10 rounded-full bg-surface-container-highest flex items-center justify-center group-hover:bg-primary/20 transition-colors duration-300">
                    <span class="material-symbols-outlined text-secondary group-hover:text-primary transition-colors duration-300" style="font-variation-settings: 'FILL' 1;">${ICONES[acao.tipo] || 'schedule'}</span>
                </div>
                <span class="font-label-bold text-label-bold text-on-surface text-center px-1">${acao.label}</span>
            </button>
        `).join('');

        if (!acoes.length && funcionario.dia_encerrado) {
            box.innerHTML = '';
        } else if (!acoes.length) {
            box.innerHTML = `<div class="col-span-2 glass-card rounded-xl p-6 text-center border border-surface-variant">
                <span class="material-symbols-outlined text-primary text-[32px] mb-2">task_alt</span>
                <p class="font-body-md text-body-md text-secondary">Nenhuma batida disponível agora.</p>
            </div>`;
        }

        /* Explica a ausência do botão de refeição, senão parece defeito. */
        const tipos = acoes.map((a) => a.tipo);
        const trabalhando = tipos.includes('saida');
        const faltaAlmoco = trabalhando && !tipos.includes('saida_almoco');
        const faltaJanta = trabalhando && !tipos.includes('saida_janta');
        /* Jornada de um dia anterior ainda aberta: avisa antes de a pessoa bater. */
        const aviso = $('#avisoDiaAnterior');
        if (funcionario.dia_anterior) {
            $('#avisoDiaAnteriorTexto').innerHTML =
                'Ficou uma jornada em aberto do dia <b>' + funcionario.data_ponto_br + '</b>. '
                + 'Bata a <b>Saída final</b> para encerrar aquele dia, ou toque em <b>Entrada de hoje</b> para começar o dia de hoje.';
            aviso.classList.remove('hidden');
            aviso.classList.add('flex');
        } else {
            aviso.classList.add('hidden');
            aviso.classList.remove('flex');
        }

        const dica = $('#dicaRefeicao');
        const h = funcionario.horarios || {};

        if (faltaAlmoco && faltaJanta) {
            dica.textContent = `Almoço aparece a partir das ${h.almoco} e janta a partir das ${h.janta}.`;
        } else if (faltaAlmoco) {
            dica.textContent = `A saída para almoço aparece a partir das ${h.almoco}.`;
        } else if (faltaJanta) {
            dica.textContent = `A saída para janta aparece a partir das ${h.janta}.`;
        }
        dica.classList.toggle('hidden', !(faltaAlmoco || faltaJanta));
    }

    /* --------------------------------- perfil --------------------------------- */
    function renderPerfil() {
        $('#perfilNome').textContent = funcionario.nome;
        $('#perfilCargo').textContent = funcionario.cargo || 'Funcionário';
        $('#perfilCondominio').textContent = funcionario.condominio || 'Vss E Acj';
        $('#perfilData').textContent = funcionario.data_ponto_br;

        const lista = funcionario.batidas || [];
        $('#perfilBatidas').innerHTML = lista.length
            ? lista.map((b) => `
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">${ICONES[b.tipo] || 'schedule'}</span>
                        <span class="font-body-md text-body-md text-secondary">${b.label}</span>
                    </div>
                    <span class="font-body-lg text-body-lg text-on-surface font-medium tabular-nums">${b.hora}</span>
                </div>`).join('')
            : '<p class="font-body-md text-body-md text-secondary text-center py-2">Nenhuma batida registrada hoje.</p>';
    }

    /* --------------------------------- câmera --------------------------------- */
    /* Marca em que ponto da foto a pessoa está: 1 câmera, 2 foto, 3 enviar. */
    function marcarPasso(atual) {
        document.querySelectorAll('.passo').forEach((el) => {
            const n = Number(el.dataset.passo);
            if (n < atual) el.dataset.estado = 'feito';
            else if (n === atual) el.dataset.estado = 'atual';
            else el.removeAttribute('data-estado');
        });
    }

    function abrirSheet(tipo, rotulo) {
        tipoEscolhido = tipo;
        fotoBase64 = '';
        $('#camTitulo').textContent = rotulo;
        $('#camIcone').textContent = ICONES[tipo] || 'schedule';
        $('#camSubtitulo').textContent = 'Enquadre o rosto e tire a foto.';
        $('#camConfirmarTexto').textContent = 'Confirmar ' + rotulo.toLowerCase();
        $('#camPreview').classList.add('hidden');
        $('#camGuia').classList.add('hidden');
        $('#camAviso').classList.remove('hidden');
        $('#camObservacao').value = '';
        $('#camCapturar').disabled = true;
        $('#camCapturar').textContent = 'Tirar foto';
        $('#camConfirmar').disabled = true;
        marcarPasso(1);
        $('#camBackdrop').classList.add('open');
        $('#camSheet').classList.add('open');
        abrirCamera();
    }

    function fecharSheet() {
        $('#camBackdrop').classList.remove('open');
        $('#camSheet').classList.remove('open');
        pararCamera();
        tipoEscolhido = null;
        fotoBase64 = '';
    }

    async function abrirCamera() {
        if (stream) return;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            const video = $('#camVideo');
            video.srcObject = stream;
            await video.play();
            $('#camAviso').classList.add('hidden');
            $('#camGuia').classList.remove('hidden');
            $('#camCapturar').disabled = false;
            marcarPasso(2);
        } catch (erro) {
            $('#camAviso').classList.remove('hidden');
            $('#camAviso').innerHTML = '<span class="material-symbols-outlined text-[36px] text-error">videocam_off</span>Não foi possível abrir a câmera.<br>Use HTTPS e permita o acesso.';
            toast('Permita o uso da câmera para bater o ponto.', 'error');
        }
    }

    function pararCamera() {
        if (!stream) return;
        stream.getTracks().forEach((t) => t.stop());
        stream = null;
        const video = $('#camVideo');
        video.pause();
        video.srcObject = null;
    }

    function capturar() {
        const video = $('#camVideo');
        const canvas = $('#camCanvas');
        const previa = $('#camPreview');

        /* Com a foto na tela, o mesmo botão vira "Tirar outra". */
        if (!previa.classList.contains('hidden')) {
            previa.classList.add('hidden');
            $('#camGuia').classList.remove('hidden');
            fotoBase64 = '';
            $('#camConfirmar').disabled = true;
            $('#camCapturar').textContent = 'Tirar foto';
            $('#camSubtitulo').textContent = 'Enquadre o rosto e tire a foto.';
            marcarPasso(2);
            return;
        }

        if (!video.videoWidth) {
            toast('Abra a câmera antes de capturar.', 'error');
            return;
        }

        const largura = 900;
        canvas.width = largura;
        canvas.height = Math.round(largura * (video.videoHeight / video.videoWidth));
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        /* Carimbo com data e hora, igual ao que o sistema já gravava antes. */
        const carimbo = 'Vss E Acj - Ponto - ' + new Date().toLocaleString('pt-BR');
        const altura = Math.max(44, Math.round(canvas.height * 0.07));
        ctx.fillStyle = 'rgba(0,0,0,.62)';
        ctx.fillRect(0, canvas.height - altura, canvas.width, altura);
        ctx.fillStyle = '#fff';
        ctx.font = Math.max(22, Math.round(canvas.width * 0.026)) + 'px Inter, Arial';
        ctx.fillText(carimbo, 18, canvas.height - Math.round(altura * 0.33));

        fotoBase64 = canvas.toDataURL('image/jpeg', 0.78);
        $('#camPreview').src = fotoBase64;
        $('#camPreview').classList.remove('hidden');
        $('#camGuia').classList.add('hidden');
        $('#camConfirmar').disabled = false;
        $('#camCapturar').textContent = 'Tirar outra';
        $('#camSubtitulo').textContent = 'Foto pronta. Confira e confirme abaixo.';
        marcarPasso(3);
        vibrar(30);
    }

    async function confirmarBatida() {
        if (enviando) return;
        if (!fotoBase64) { toast('Capture a foto antes de confirmar.', 'error'); return; }
        if (pin.length < 4) { toast('Sessão expirada. Digite o PIN novamente.', 'error'); fecharSheet(); abrirPin('ponto'); return; }

        enviando = true;
        const botao = $('#camConfirmar');
        botao.disabled = true;
        $('#camConfirmarTexto').textContent = 'Registrando...';

        try {
            const dados = await post({
                acao: 'registrar',
                pin,
                tipo: tipoEscolhido,
                foto_base64: fotoBase64,
                observacao: $('#camObservacao').value
            });
            funcionario = dados.funcionario;
            agendarEsquecimento();
            renderHome();

            const c = dados.comprovante;
            $('#reciboTipo').textContent = c.label;
            $('#reciboHora').textContent = c.hora;
            $('#reciboData').textContent = c.data;
            $('#reciboLocal').textContent = c.local;
            $('#sucessoTexto').textContent = `${primeiroNome(funcionario.nome)}, sua jornada foi atualizada.`;

            fecharSheet();
            zerarPin();
            irPara('sucesso');
            vibrar([40, 60, 90]);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            enviando = false;
            botao.disabled = !fotoBase64;
            $('#camConfirmarTexto').textContent = 'Confirmar batida';
        }
    }

    /* --------------------------------- eventos --------------------------------- */
    $('#btnBaterPonto').addEventListener('click', () => abrirPin('ponto'));

    document.querySelectorAll('[data-voltar]').forEach((btn) => {
        btn.addEventListener('click', () => irPara(btn.dataset.voltar));
    });

    $('#keypad').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button');
        if (!botao) return;
        if (botao.hasAttribute('data-apagar')) {
            pin = pin.slice(0, -1);
            renderPin();
            return;
        }
        if (!botao.dataset.digito || pin.length >= 4) return;

        pin += botao.dataset.digito;
        renderPin();
        vibrar(12);
    });

    $('#btnConfirmarPin').addEventListener('click', identificar);

    document.addEventListener('keydown', (evento) => {
        if (!$('[data-tela="pin"]').classList.contains('ativa')) return;
        if (/^\d$/.test(evento.key) && pin.length < 4) {
            pin += evento.key;
            renderPin();
        } else if (evento.key === 'Backspace') {
            pin = pin.slice(0, -1);
            renderPin();
        } else if (evento.key === 'Enter') {
            identificar();
        } else if (evento.key === 'Escape') {
            irPara('home');
        }
    });

    /* ------------------------- fechar dia anterior ------------------------- */
    let diaParaFechar = null;

    $('#listaDiasAbertos').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-fechar-dia]');
        if (!botao) return;

        diaParaFechar = botao.dataset.fecharDia;
        $('#fdTitulo').textContent = 'Fechar o dia ' + botao.dataset.diaBr;
        $('#fdTexto').textContent = 'Você entrou às ' + botao.dataset.entrada
            + ' e não bateu a saída. Informe a hora em que foi embora nesse dia.';
        $('#fdHora').value = '';
        $('#fdFundo').classList.add('open');
        $('#fdFolha').classList.add('open');
    });

    function fecharFolhaDia() {
        $('#fdFundo').classList.remove('open');
        $('#fdFolha').classList.remove('open');
        diaParaFechar = null;
    }

    $('#fdCancelar').addEventListener('click', fecharFolhaDia);
    $('#fdFundo').addEventListener('click', fecharFolhaDia);

    $('#fdEnviar').addEventListener('click', async () => {
        if (enviando || !diaParaFechar) return;
        const hora = $('#fdHora').value;
        if (!hora) { toast('Informe a hora em que você saiu.', 'error'); return; }
        if (pin.length < 4) { toast('Sessão expirada. Digite o PIN novamente.', 'error'); fecharFolhaDia(); abrirPin('ponto'); return; }

        enviando = true;
        $('#fdEnviar').disabled = true;
        try {
            const dados = await post({ acao: 'fechar_dia', pin, data_ponto: diaParaFechar, hora });
            funcionario = dados.funcionario;
            toast(dados.mensagem);
            vibrar([40, 60, 90]);
            fecharFolhaDia();
            renderTipos();
            renderHome();
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            enviando = false;
            $('#fdEnviar').disabled = false;
        }
    });

    $('#btnNovaJornada').addEventListener('click', () => {
        const entrada = (funcionario.acoes || []).find((a) => a.tipo === 'entrada');
        if (entrada) abrirSheet(entrada.tipo, entrada.label);
    });

    $('#listaTipos').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-tipo]');
        if (!botao) return;
        abrirSheet(botao.dataset.tipo, botao.dataset.label);
    });

    $('#camAbrir').addEventListener('click', abrirCamera);
    $('#camCapturar').addEventListener('click', capturar);
    $('#camConfirmar').addEventListener('click', confirmarBatida);
    $('#camFechar').addEventListener('click', fecharSheet);
    $('#camBackdrop').addEventListener('click', fecharSheet);

    /* Ao sair da aba, apaga o que foi carregado e desliga a câmera. Se a folha
     * da câmera estiver aberta, a pessoa está no meio da batida (alguns
     * celulares escondem a aba ao pedir permissão), então só desliga a câmera. */
    document.addEventListener('visibilitychange', () => {
        const folhaAberta = $('#camSheet').classList.contains('open');
        if (document.hidden) {
            pararCamera();
            if (!folhaAberta) esquecerFuncionario();
        } else if (folhaAberta && !fotoBase64) {
            abrirCamera(); // religa a prévia ao voltar para a aba
        }
    });
    window.addEventListener('pagehide', () => { pararCamera(); esquecerFuncionario(); });

    /* O item "Perfil" da barra inferior aponta para bater-ponto.php#perfil.
     * Como já estamos nesta página, é o hash que dispara a tela. */
    function abrirPerfilPeloHash() {
        if (location.hash !== '#perfil') return;
        if (funcionario) { renderPerfil(); irPara('perfil'); }
        else abrirPin('perfil');
    }
    window.addEventListener('hashchange', abrirPerfilPeloHash);
    abrirPerfilPeloHash();

    /* --------------------------------- tutorial --------------------------------- */
    const PASSOS = [
        {
            titulo: 'Bem-vindo! Vou te ensinar',
            texto: 'São <b>7 passos</b> bem rapidinhos. Vou acender uma luz em cima de cada botão e explicar para que ele serve.<br><br>Pode ir tocando em <b>Próximo</b>.'
        },
        {
            alvo: '#current-time',
            titulo: '1. O relógio',
            texto: 'Esse é o <b>horário certo do sistema</b>, o mesmo que vai ficar gravado na sua batida.<br><br>Confira sempre antes de bater: é ele que vale, não o relógio do seu celular.'
        },
        {
            alvo: '#statusChip',
            forma: 'circulo',
            titulo: '2. Sua situação agora',
            texto: 'Aqui aparece se você está <b>Trabalhando</b>, <b>Em almoço</b>, <b>Em janta</b> ou <b>Fora do expediente</b>.<br><br>Ele só mostra a sua situação depois que você digitar o PIN.'
        },
        {
            alvo: '#btnBaterPonto',
            forma: 'circulo',
            titulo: '3. O botão principal',
            texto: 'Este é <b>o botão mais importante</b>. Toque nele toda vez que for registrar entrada, almoço, janta ou saída.<br><br>Ao tocar, o app pede o seu <b>PIN de 4 números</b>, depois você escolhe o tipo da batida e por último <b>tira uma foto</b>. A foto é obrigatória — é a prova de que foi você.'
        },
        {
            alvo: '#horasHoje',
            titulo: '4. Horas de hoje',
            texto: 'Mostra <b>quanto tempo você já trabalhou hoje</b>, descontando almoço e janta.<br><br>A barrinha azul embaixo enche conforme você chega perto das 8 horas do dia.'
        },
        {
            alvo: '#horasSemana',
            titulo: '5. Horas da semana',
            texto: 'A mesma coisa, mas somando <b>de segunda até hoje</b>.'
        },
        {
            alvo: 'nav a[href="historico.php"]',
            titulo: '6. Histórico',
            texto: 'Aqui você vê <b>todas as suas batidas</b>, dia por dia, e as horas extras, atrasos e advertências do mês.<br><br>É também de dentro do histórico que você <b>pede para o administrador corrigir</b> uma batida errada.'
        },
        {
            alvo: 'nav a[href="solicitar-ajuste.php"]',
            titulo: '7. Pedidos',
            texto: 'Depois de pedir uma correção, é aqui que você <b>acompanha a resposta</b>: aguardando, aprovado ou recusado.<br><br>Pronto, era só isso! Se esquecer, é só tocar de novo em "Como usar o aplicativo".'
        }
    ];

    const CONFIG_TUTORIAL = {
        chave: 'inicio',
        proxima: {
            pergunta: 'Quer ver agora o tutorial da tela de Histórico?',
            url: 'historico.php?tutorial=1'
        }
    };

    $('#btnTutorial').addEventListener('click', () => {
        irPara('home');
        window.Tutorial.iniciar(PASSOS, CONFIG_TUTORIAL);
    });

    /* Na primeira vez que a pessoa abre o app, o tutorial aparece sozinho. */
    if (new URLSearchParams(location.search).get('tutorial') === '1') {
        setTimeout(() => window.Tutorial.iniciar(PASSOS, CONFIG_TUTORIAL), 600);
    } else {
        window.Tutorial.iniciarSePrimeiraVez(PASSOS, CONFIG_TUTORIAL);
    }

    tick();
    setInterval(tick, 1000);
    renderHome();
    renderPin();
})();
</script>
<?php app_mobile_end(); ?>
