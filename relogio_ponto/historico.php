<?php
/*
 * Tela do funcionário: histórico de batidas.
 *
 * Pede o PIN, mostra o mês escolhido e fecha sozinho por inatividade.
 * Tocando num dia abre o modal com todas as batidas daquele dia; ali a pessoa
 * escolhe qual horário quer corrigir (ou avisa que faltou bater) e o pedido vai
 * para o painel do administrador aprovar.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_app_shell.php';

function hist_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hist_time(?string $dt): string
{
    return $dt ? date('H:i', strtotime($dt)) : '--:--';
}

function hist_dia_semana(string $data): string
{
    $nomes = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
    return $nomes[(int)date('w', strtotime($data))];
}

function hist_mes_nome(string $ym): string
{
    $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
              'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    [$ano, $mes] = explode('-', $ym);
    return $meses[(int)$mes] . ' ' . $ano;
}

/** Tipos de batida que a pessoa pode pedir para incluir quando esqueceu. */
function hist_tipos_disponiveis(): array
{
    $tipos = ['entrada', 'saida_almoco', 'volta_almoco', 'saida_janta', 'volta_janta', 'saida_descanso', 'volta_descanso', 'saida'];
    $out = [];
    foreach ($tipos as $t) {
        $out[] = ['tipo' => $t, 'label' => tipo_ponto_label($t)];
    }
    return $out;
}

/** Pedidos já feitos no mês, agrupados por dia, para aparecerem no modal. */
function hist_pedidos_do_mes(int $funcionarioId, string $ini, string $fim): array
{
    ensure_solicitacoes_table();
    $stmt = db()->prepare("SELECT * FROM ponto_solicitacoes
        WHERE funcionario_id = ? AND data_ponto BETWEEN ? AND ?
        ORDER BY criado_em DESC");
    $stmt->execute([$funcionarioId, $ini, $fim]);

    $porDia = [];
    foreach ($stmt->fetchAll() as $p) {
        $dia = (string)$p['data_ponto'];
        $porDia[$dia][] = [
            'protocolo' => '#' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT),
            'status' => (string)$p['status'],
            'status_label' => ponto_solicitacao_status_label((string)$p['status']),
            'hora' => $p['data_hora_solicitada'] ? hist_time((string)$p['data_hora_solicitada']) : '--:--',
            'tipo_label' => $p['tipo_registro'] ? tipo_ponto_label((string)$p['tipo_registro']) : 'Batida',
            'resposta' => (string)($p['resposta_admin'] ?? ''),
        ];
    }
    return $porDia;
}

/**
 * Monta o mês inteiro: um item por dia com batida, o total de atrasos e as
 * advertências do período.
 */
function hist_payload(int $funcionarioId, string $ym): array
{
    $primeiro = $ym . '-01';
    $ultimoDoMes = date('Y-m-t', strtotime($primeiro));
    $hoje = today_sp();
    $ultimo = $ultimoDoMes > $hoje ? $hoje : $ultimoDoMes;

    $pedidos = hist_pedidos_do_mes($funcionarioId, $primeiro, $ultimoDoMes);
    /* Jornada esperada vem do cargo; sem cadastro, cai no padrão de 8h. */
    $jornadaCargo = ponto_jornada_do_funcionario($funcionarioId);

    $dias = [];
    $minutosAtrasos = 0;
    $minutosTrabalhados = 0;

    /* Todas as batidas do mês numa consulta só (antes era uma por dia, 31 idas
     * ao banco para desenhar uma tela de celular). Pega um dia antes e dois
     * depois por causa da saída de madrugada do turno noturno. */
    $stmtReg = db()->prepare("SELECT * FROM ponto_registros
        WHERE funcionario_id = ? AND data_hora >= ? AND data_hora < ?
        ORDER BY data_hora ASC, id ASC");
    $stmtReg->execute([
        $funcionarioId,
        date('Y-m-d 00:00:00', strtotime($primeiro . ' -1 day')),
        date('Y-m-d 00:00:00', strtotime($ultimo . ' +2 days')),
    ]);
    $registrosMes = $stmtReg->fetchAll();

    $ts = strtotime($primeiro);
    $fimTs = strtotime($ultimo);
    while ($ts !== false && $ts <= $fimTs) {
        $data = date('Y-m-d', $ts);
        $ts = strtotime('+1 day', $ts);

        $lista = ponto_registros_do_dia_em_memoria($registrosMes, $data);
        if (!$lista && empty($pedidos[$data])) {
            continue;
        }

        $jornadas = ponto_jornadas_from_registros($lista);
        $fechado = $lista ? jornada_fechada($lista) : false;
        $intervaloAberto = open_interval($lista) !== null;

        /* Atraso = horas que faltaram para fechar a jornada do turno cumprido,
         * já com as tolerâncias do cargo (atraso tolerado e chegada antecipada).
         * Pega quem chegou tarde e quem saiu mais cedo, no mesmo número. */
        $falta = ponto_falta_horas_do_dia($lista, $jornadaCargo);
        $trabalho = $falta['trabalhado'];

        if (!$fechado || $intervaloAberto) {
            $status = 'pendente';
            $chip = 'PEND';
            $resumo = $intervaloAberto ? 'Intervalo em aberto' : 'Batida pendente';
        } elseif ($falta['minutos'] > 0) {
            $status = 'atraso';
            $chip = 'ATRASO';
            $resumo = 'Faltou ' . ponto_fmt_hm($falta['minutos'])
                . ' · fez ' . ponto_fmt_hm($falta['trabalhado']) . ' de ' . ponto_fmt_hm($falta['jornada_min']);
        } else {
            $status = 'ok';
            $chip = 'OK';
            $resumo = 'Jornada cumprida' . ($falta['entrada'] ? ' · entrou ' . $falta['entrada'] : '');
            if ($falta['atraso_tolerado'] > 0) {
                $resumo .= ' (' . $falta['atraso_tolerado'] . ' min de atraso tolerado)';
            }
        }

        /* Chegou antes do que o cargo permite: avisa que esse tempo não conta. */
        if ($fechado && !$intervaloAberto && $falta['antecipacao_excedida'] > 0) {
            $resumo .= ' · ' . $falta['antecipacao_excedida'] . ' min antes do permitido não contam';
        }

        /* Cargo de escala: dizer contra QUAL horário a conta foi feita. Sem
         * isso a pessoa vê "faltou 04h 00m" e não tem como saber que o
         * sistema comparou com o turno errado (ou com o certo). */
        if (!empty($jornadaCargo['multi']) && $falta['turno_horario'] !== '' && $fechado && !$intervaloAberto) {
            $resumo .= ' · horário ' . $falta['turno_horario'];
        }

        /* Só dias fechados entram nos totais: um dia ainda em andamento
         * apareceria com números estranhos e assustaria à toa. */
        if ($fechado && !$intervaloAberto) {
            $minutosTrabalhados += $trabalho;
            $minutosAtrasos += $falta['minutos'];
        }

        $registros = [];
        foreach ($jornadas as $indice => $jornada) {
            $numero = $indice + 1;
            foreach (($jornada['eventos'] ?? []) as $r) {
                $registros[] = [
                    'id' => ponto_registro_ordem_id($r),
                    'tipo' => (string)$r['tipo'],
                    'label' => (count($jornadas) > 1 ? 'J' . $numero . ' · ' : '') . tipo_ponto_label((string)$r['tipo']),
                    'hora' => hist_time((string)$r['data_hora']),
                ];
            }
        }

        $dias[] = [
            'data' => $data,
            'data_br' => date('d/m/Y', strtotime($data)),
            'data_extenso' => hist_dia_semana($data) . ', ' . date('d/m/Y', strtotime($data)),
            'dia' => date('d', strtotime($data)),
            'dia_semana' => hist_dia_semana($data),
            'status' => $status,
            'chip' => $chip,
            'resumo' => $resumo,
            'trabalhado' => $lista ? ponto_fmt_hm($trabalho) . ' trabalhadas' : 'Sem batidas',
            'turno' => $falta['turno'],
            'entrada_esperada' => $falta['esperado'],
            'registros' => $registros,
            'pedidos' => $pedidos[$data] ?? [],
        ];
    }

    $dias = array_reverse($dias); // mais recente primeiro

    $listaAdv = [];
    foreach (ponto_advertencias_periodo($funcionarioId, $primeiro, $ultimoDoMes) as $a) {
        $listaAdv[] = [
            'data_br' => date('d/m/Y', strtotime((string)$a['data_advertencia'])),
            'tipo_label' => ponto_advertencia_tipo_label((string)$a['tipo']),
            'tipo' => (string)$a['tipo'],
            'motivo' => (string)$a['motivo'],
        ];
    }

    return [
        'mes' => $ym,
        'mes_nome' => hist_mes_nome($ym),
        'mes_anterior' => $ym <= date('Y-m', strtotime('-24 months')) ? null : date('Y-m', strtotime($primeiro . ' -1 month')),
        'mes_proximo' => $ym >= date('Y-m') ? null : date('Y-m', strtotime($primeiro . ' +1 month')),
        'atrasos' => $minutosAtrasos > 0 ? ponto_fmt_hm_sinal(-$minutosAtrasos) : '0h 00m',
        'total' => ponto_fmt_hm($minutosTrabalhados),
        'advertencias' => [
            'mes' => count($listaAdv),
            'total' => ponto_advertencias_total($funcionarioId),
            'lista' => $listaAdv,
        ],
        'tipos' => hist_tipos_disponiveis(),
        /* Cargo com vários horários não tem "a" jornada: cada dia vale a do
         * turno cumprido. Por isso vai a lista inteira, não só o principal. */
        'jornada' => [
            'definida' => !empty($jornadaCargo['definido']),
            'multi' => !empty($jornadaCargo['multi']),
            'entrada' => $jornadaCargo['entrada'],
            'saida' => $jornadaCargo['saida'],
            'texto' => ponto_fmt_hm($jornadaCargo['jornada_min']),
            'horarios' => (string)($jornadaCargo['turnos_texto'] ?? ''),
            'tolerancia' => $jornadaCargo['tolerancia'],
            'antecipacao' => $jornadaCargo['antecipacao'] ?? null,
            'regras' => ponto_tolerancias_texto($jornadaCargo),
        ],
        'dias' => $dias,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        ponto_pin_guard();

        $pin = trim((string)($_POST['pin'] ?? ''));
        if ($pin === '') {
            throw new RuntimeException('Digite seu PIN.');
        }

        $funcionario = find_funcionario_by_pin(0, $pin);
        if (!$funcionario) {
            ponto_pin_falhou();
            throw new RuntimeException('PIN não encontrado para funcionário ativo.');
        }
        ponto_pin_acertou();
        $funcionarioId = (int)$funcionario['id'];

        /* ---- pedido de ajuste feito dentro do modal do dia ---- */
        if ((string)($_POST['acao'] ?? '') === 'solicitar') {
            ensure_solicitacoes_table();

            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if (mb_strlen($motivo) < 10) {
                throw new RuntimeException('Escreva o motivo com pelo menos 10 letras.');
            }
            $motivo = mb_substr($motivo, 0, 1000);

            $hora = trim((string)($_POST['hora'] ?? ''));
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                throw new RuntimeException('Informe o horário correto no formato 00:00.');
            }

            $registroId = (int)($_POST['registro_id'] ?? 0);
            $dataPonto = (string)($_POST['data_ponto'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPonto)) {
                throw new RuntimeException('Dia inválido.');
            }

            /* O app diz explicitamente o que está fazendo; sem isso, uma batida
             * sem id cairia no caminho de "criar" e pediria um tipo que a tela
             * nem estava mostrando. */
            $modo = (string)($_POST['modo'] ?? ($registroId > 0 ? 'alterar' : 'criar'));
            if ($modo === 'alterar' && $registroId <= 0) {
                throw new RuntimeException('Não consegui identificar a batida que você quer corrigir. Feche e abra o dia de novo.');
            }

            if ($modo === 'alterar') {
                /* Corrigir uma batida existente: mantém o dia dela e troca a hora. */
                $stmtR = db()->prepare("SELECT * FROM ponto_registros WHERE id = ? AND funcionario_id = ?");
                $stmtR->execute([$registroId, $funcionarioId]);
                $registro = $stmtR->fetch();
                if (!$registro) {
                    throw new RuntimeException('Batida não encontrada.');
                }

                $tipoSolicitacao = 'alterar';
                $tipoRegistro = (string)$registro['tipo'];
                $dataPonto = (string)$registro['data_ponto'];
                $dataHoraAtual = (string)$registro['data_hora'];
                /* Dia mais próximo da batida atual: 23:50 -> 00:10 vira o dia seguinte. */
                $novaDataHora = ponto_data_hora_mais_proxima($hora, $dataHoraAtual);

                if ($novaDataHora === $dataHoraAtual) {
                    throw new RuntimeException('O horário informado é igual ao que já está registrado.');
                }
            } else {
                /* Batida que faltou: a pessoa escolhe o tipo. */
                $tipoSolicitacao = 'criar';
                $tipoRegistro = (string)($_POST['tipo_registro'] ?? '');
                if ($tipoRegistro === '') {
                    throw new RuntimeException('Escolha na lista qual batida faltou.');
                }
                if (tipo_ponto_label($tipoRegistro) === $tipoRegistro) {
                    throw new RuntimeException('Tipo de batida desconhecido: ' . $tipoRegistro);
                }
                $registroId = null;
                $novaDataHora = $dataPonto . ' ' . $hora . ':00';
                $dataHoraAtual = null;
            }

            if (strtotime($novaDataHora) > time() + 86400) {
                throw new RuntimeException('O horário informado não pode ser no futuro.');
            }

            /* Evita a pessoa mandar o mesmo pedido várias vezes sem resposta. */
            $stmtDup = db()->prepare("SELECT COUNT(*) FROM ponto_solicitacoes
                WHERE funcionario_id = ? AND data_ponto = ? AND status = 'pendente'
                  AND ((? > 0 AND registro_id = ?) OR (? = 0 AND registro_id IS NULL AND tipo_registro = ?))");
            $stmtDup->execute([
                $funcionarioId, $dataPonto,
                (int)$registroId, (int)$registroId,
                (int)$registroId, $tipoRegistro,
            ]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('Você já tem um pedido em aberto para essa batida. Aguarde a resposta.');
            }

            $stmt = db()->prepare("INSERT INTO ponto_solicitacoes
                (funcionario_id, condominio_id, registro_id, tipo_solicitacao, data_ponto, data_hora_solicitada, data_hora_atual, tipo_registro, motivo, status, criado_em, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?)");
            $stmt->execute([
                $funcionarioId,
                (int)$funcionario['condominio_id'],
                $registroId,
                $tipoSolicitacao,
                $dataPonto,
                $novaDataHora,
                $dataHoraAtual,
                $tipoRegistro,
                $motivo,
                now_sp(),
                ponto_client_ip(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $protocolo = '#' . str_pad((string)db()->lastInsertId(), 5, '0', STR_PAD_LEFT);

            hist_json([
                'ok' => true,
                'protocolo' => $protocolo,
                'mensagem' => 'Pedido ' . $protocolo . ' enviado ao administrador.',
                'historico' => hist_payload($funcionarioId, date('Y-m', strtotime($dataPonto))),
            ]);
        }

        /* ---- consulta normal do mês ---- */
        $ym = (string)($_POST['mes'] ?? date('Y-m'));
        $minimo = date('Y-m', strtotime('-24 months'));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym) || $ym > date('Y-m') || $ym < $minimo) {
            $ym = date('Y-m');
        }

        hist_json([
            'ok' => true,
            'funcionario' => [
                'nome' => (string)$funcionario['nome'],
                'cargo' => (string)($funcionario['cargo_nome'] ?? ''),
                'condominio' => (string)($funcionario['condominio_nome'] ?? ''),
            ],
            'token' => ponto_token_emitir($funcionarioId),
            'historico' => hist_payload($funcionarioId, $ym),
        ]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'mais de um funcionário') !== false || stripos($msg, 'Selecione o condomínio') !== false) {
            $msg = 'PIN duplicado. Peça ao administrador para alterar o PIN de um dos funcionários.';
        }
        hist_json(['ok' => false, 'error' => $msg], 422);
    }
}

$csrf = csrf_token();

$cssTela = <<<'CSS'
<style>
    .pin-dot { transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
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

    .history-card {
        transition: all .3s cubic-bezier(0.4, 0, 0.2, 1);
        border-bottom: 1px solid rgba(148,163,184,.18);
        margin-bottom: 8px;
    }
    .history-card:last-child { border-bottom:none; margin-bottom:0; }
    .history-card:active { transform: scale(.98); background-color: rgba(23,32,51,.95); }

    /* Modal do dia */
    .modal-fundo { transition: opacity .3s ease; opacity:0; pointer-events:none; }
    .modal-fundo.aberto { opacity:1; pointer-events:auto; }
    .modal-folha { transition: transform .35s cubic-bezier(0.16, 1, 0.3, 1); transform: translateY(100%); }
    .modal-folha.aberto { transform: translateY(0); }

    .linha-batida { transition: background-color .2s ease, border-color .2s ease; }
    .linha-batida:active { background-color: rgba(59,130,246,.14); border-color:#3b82f6; }

    input[type="time"] { color-scheme: dark; }
    input[type="time"]::-webkit-calendar-picker-indicator { opacity:.7; filter: invert(1); cursor:pointer; }

    /* O plugin "forms" do Tailwind pinta os campos de branco; aqui o tema
       escuro precisa vencer. */
    .campo-escuro {
        background-color: rgba(5,8,16,.6) !important;
        border: 1px solid rgba(148,163,184,.35) !important;
        color: #fff !important;
    }
    .campo-escuro:focus { outline:none; border-color:#3b82f6 !important; box-shadow:0 0 0 1px #3b82f6 !important; }
    .campo-escuro::placeholder { color:#94a3b8; }

    .tela { display:none; }
    .tela.ativa { display:flex; }

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

app_mobile_head('Histórico - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen font-body-lg', $cssTela, 'manifest.webmanifest');
?>
<div id="app" data-csrf="<?= h($csrf) ?>">

<!-- ============================== TELA: PIN ============================== -->
<section class="tela ativa flex-col min-h-screen overflow-y-auto" data-tela="pin">
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
<p class="font-body-md text-body-md text-secondary">Para ver seu histórico de batidas</p>
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
<span id="btnConfirmarPinTexto">Ver histórico</span>
<span class="material-symbols-outlined">arrow_forward</span>
</button>
</main>
</section>

<!-- ============================ TELA: HISTÓRICO ============================ -->
<section class="tela flex-col min-h-screen pt-[70px] pb-24" data-tela="lista">
<?php app_mobile_topbar(); ?>
<main class="px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-lg">
<div class="flex flex-col gap-stack-gap-sm fade-in-up">
<h2 class="font-headline-md text-headline-md text-on-surface">Histórico</h2>
<p class="font-body-md text-body-md text-secondary" id="histIntro">Acompanhe seus registros de ponto diários.</p>
</div>

<div class="flex items-center justify-between glass-card rounded-xl p-3 border border-surface-variant shadow-sm fade-in-up" style="animation-delay:.1s;">
<button class="w-10 h-10 flex items-center justify-center rounded-lg text-on-surface hover:bg-surface-variant transition-colors disabled:opacity-30" id="mesAnterior" type="button">
<span class="material-symbols-outlined">chevron_left</span>
</button>
<div class="font-headline-md text-headline-md text-primary" id="mesNome">--</div>
<button class="w-10 h-10 flex items-center justify-center rounded-lg text-on-surface hover:bg-surface-variant transition-colors disabled:opacity-30" id="mesProximo" type="button">
<span class="material-symbols-outlined">chevron_right</span>
</button>
</div>

<div class="grid grid-cols-3 gap-3 fade-in-up" style="animation-delay:.2s;">
<div class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-primary/20 bg-primary/5 shadow-sm">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Trabalhadas</span>
<span class="font-headline-md text-headline-md text-primary" id="totalTrabalhado">--</span>
</div>
<div class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-error/20 bg-error/5 shadow-sm">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Atrasos</span>
<span class="font-headline-md text-headline-md text-error" id="totalAtrasos">--</span>
</div>
<button class="glass-card rounded-xl p-3 flex flex-col gap-1 border border-amber-500/20 bg-amber-500/5 shadow-sm text-left active:scale-95 transition-transform" id="cardAdvertencias" type="button">
<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Advert.</span>
<span class="font-headline-md text-headline-md text-amber-500" id="totalAdvertencias">--</span>
<span class="font-label-sm text-label-sm text-secondary" id="subAdvertencias">no mês</span>
</button>
</div>

<div class="hidden flex-col gap-2 fade-in-up" id="blocoAdvertencias">
<h3 class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Advertências do mês</h3>
<div class="flex flex-col gap-2" id="listaAdvertencias"></div>
</div>

<div class="flex flex-col gap-2 glass-card rounded-xl p-2 border border-surface-variant fade-in-up" id="listaDias" style="animation-delay:.3s;"></div>

<button class="w-full h-[44px] rounded-full border border-primary/60 text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 active:scale-95 transition-transform" id="btnTutorial" type="button">
<span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">school</span>
Como usar esta tela
</button>
<p class="font-label-sm text-label-sm text-secondary text-center px-4">Toque em um dia para ver as batidas e pedir correção.</p>
</main>
<?php app_mobile_nav('historico'); ?>
</section>

<!-- ========================= MODAL: DETALHE DO DIA ========================= -->
<div class="modal-fundo fixed inset-0 bg-black/70 z-[80]" id="modalFundo"></div>
<div class="modal-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl flex flex-col" id="modalFolha" style="max-height:84vh;">
<div class="flex justify-center pt-3 pb-1 flex-shrink-0"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="flex items-start justify-between px-container-margin pb-3 flex-shrink-0">
<div class="min-w-0">
<h3 class="font-headline-md text-headline-md text-on-surface" id="modalData">--</h3>
<p class="font-body-md text-body-md text-secondary" id="modalResumo">--</p>
</div>
<button aria-label="Fechar" class="w-10 h-10 flex-shrink-0 flex items-center justify-center rounded-full text-secondary hover:bg-surface-container active:scale-95 transition-all" id="modalFechar" type="button">
<span class="material-symbols-outlined">close</span>
</button>
</div>

<div class="overflow-y-auto px-container-margin pb-8 flex flex-col gap-4" id="modalCorpo">
<div class="flex flex-col gap-2" id="modalBatidas"></div>

<div class="hidden flex-col gap-2" id="modalPedidos">
<h4 class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Pedidos deste dia</h4>
<div class="flex flex-col gap-2" id="modalPedidosLista"></div>
</div>

<button class="w-full h-[46px] rounded-full border border-primary text-primary font-label-bold text-label-bold flex items-center justify-center gap-2 transition-transform active:scale-95" id="btnFaltouBatida" type="button">
<span class="material-symbols-outlined text-[18px]">add_circle</span>
Faltou uma batida
</button>

<div class="hidden flex-col gap-4 rounded-2xl border border-primary/30 bg-primary/5 p-4" id="formAjuste">
<div class="flex items-center justify-between gap-2">
<h4 class="font-headline-md text-headline-md text-on-surface" id="formTitulo">Corrigir batida</h4>
<button aria-label="Cancelar" class="w-8 h-8 flex items-center justify-center rounded-full text-secondary hover:bg-surface-container" id="formCancelar" type="button">
<span class="material-symbols-outlined text-[20px]">close</span>
</button>
</div>

<div class="hidden flex-col gap-2" id="campoTipo">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajusteTipo">Qual batida faltou</label>
<select class="campo-escuro w-full rounded-xl p-3 font-body-lg text-body-lg" id="ajusteTipo">
<?php foreach (hist_tipos_disponiveis() as $t): ?>
<option value="<?= h($t['tipo']) ?>"><?= h($t['label']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajusteHora">Horário correto</label>
<input class="campo-escuro w-full rounded-xl p-3 font-display-time text-[26px] leading-none tabular-nums text-center" id="ajusteHora" type="time"/>
<p class="font-label-sm text-label-sm text-secondary" id="ajusteAtual"></p>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajusteMotivo">Motivo</label>
<textarea class="campo-escuro w-full rounded-xl p-3 font-body-md text-body-md resize-none" id="ajusteMotivo" maxlength="1000" placeholder="Ex: esqueci de bater a saída do almoço" rows="3"></textarea>
</div>

<button class="w-full h-[48px] rounded-full bg-primary text-on-primary font-headline-md text-headline-md flex items-center justify-center gap-2 transition-transform active:scale-95 shadow-md disabled:opacity-40" id="btnEnviarPedido" type="button">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">send</span>
<span id="btnEnviarPedidoTexto">Enviar para o administrador</span>
</button>
<p class="font-label-sm text-label-sm text-secondary text-center">O administrador precisa aprovar antes da batida mudar.</p>
</div>
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

    const ICONES = {
        entrada: 'login', saida: 'logout',
        saida_almoco: 'restaurant', volta_almoco: 'arrow_circle_left',
        saida_janta: 'dinner_dining', volta_janta: 'arrow_circle_left',
        saida_descanso: 'coffee', volta_descanso: 'arrow_circle_left'
    };
    /* Cor do selo do dia: número do dia, selo, ícone e texto do resumo. */
    const CORES = {
        ok:       ['text-on-surface', 'bg-primary/10 text-primary border-primary/20',              'check_circle', 'text-secondary'],
        extra:    ['text-primary',    'bg-primary/10 text-primary border-primary/20',              'schedule',     'text-primary'],
        atraso:   ['text-error',      'bg-error/10 text-error border-error/20',                    'warning',      'text-error'],
        pendente: ['text-on-surface', 'bg-surface-variant text-on-surface-variant border-outline-variant', 'more_horiz', 'text-secondary']
    };
    const CORES_STATUS = {
        pendente: 'bg-amber-500/10 text-amber-500 border-amber-500/30',
        aprovado: 'bg-green-500/10 text-green-500 border-green-500/30',
        recusado: 'bg-error/10 text-error border-error/30'
    };

    /* Fecha sozinho depois de um tempo parado, para o histórico não ficar
     * aberto no celular na mão de outra pessoa. */
    const INATIVIDADE_MS = 120000;
    let pin = '';
    let mesAtual = '<?= date('Y-m') ?>';
    let inatividade = null;
    let carregando = false;
    let enviando = false;
    let dados = null;      // último histórico recebido
    let diaAberto = null;  // dia mostrado no modal
    let alvo = null;       // { modo: 'alterar'|'criar', registroId, label }

    function irPara(nome) {
        document.querySelectorAll('[data-tela]').forEach((el) => el.classList.toggle('ativa', el.dataset.tela === nome));
        window.scrollTo(0, 0);
    }

    function fechar(aviso) {
        clearTimeout(inatividade);
        pin = '';
        dados = null;
        diaAberto = null;
        mesAtual = '<?= date('Y-m') ?>';
        fecharModal();
        $('#listaDias').innerHTML = '';
        renderPin();
        irPara('pin');
        if (aviso) toast('Histórico fechado por segurança.', 'error');
    }

    function adiarFechamento() {
        clearTimeout(inatividade);
        inatividade = setTimeout(() => fechar(true), INATIVIDADE_MS);
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
        $('#btnConfirmarPin').disabled = pin.length < 4 || carregando;
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
    async function consultar(mes) {
        if (carregando || pin.length < 4) return;
        carregando = true;
        $('#btnConfirmarPin').disabled = true;
        $('#btnConfirmarPinTexto').textContent = 'Consultando...';
        try {
            const resposta = await post({ pin, mes: mes || mesAtual });
            dados = resposta;
            render(resposta.historico, resposta.funcionario);
            adiarFechamento();
            if (!$('[data-tela="lista"]').classList.contains('ativa')) {
                $('#pinConteudo').classList.add('fade-out-up');
                setTimeout(() => { $('#pinConteudo').classList.remove('fade-out-up'); irPara('lista'); }, 420);
            }
        } catch (erro) {
            if ($('[data-tela="lista"]').classList.contains('ativa')) toast(erro.message, 'error');
            else erroPin(erro.message);
        } finally {
            carregando = false;
            $('#btnConfirmarPinTexto').textContent = 'Ver histórico';
            renderPin();
        }
    }

    function render(h, funcionario) {
        mesAtual = h.mes;
        if (funcionario) {
            $('#histIntro').textContent = `${funcionario.nome} · ${funcionario.condominio || 'Vss E Acj'}`;
        }
        $('#mesNome').textContent = h.mes_nome;
        $('#totalTrabalhado').textContent = h.total;
        $('#totalAtrasos').textContent = h.atrasos;
        $('#mesAnterior').dataset.mes = h.mes_anterior || '';
        $('#mesAnterior').disabled = !h.mes_anterior;
        $('#mesProximo').dataset.mes = h.mes_proximo || '';
        $('#mesProximo').disabled = !h.mes_proximo;

        renderAdvertencias(h.advertencias);
        renderTipos(h.tipos);
        renderDias(h);
    }

    function renderAdvertencias(adv) {
        $('#totalAdvertencias').textContent = adv.mes;
        $('#subAdvertencias').textContent = adv.total === adv.mes
            ? 'no mês'
            : `no mês · ${adv.total} no total`;

        const bloco = $('#blocoAdvertencias');
        if (!adv.lista.length) {
            bloco.classList.add('hidden');
            bloco.classList.remove('flex');
            return;
        }
        bloco.classList.remove('hidden');
        bloco.classList.add('flex');
        $('#listaAdvertencias').innerHTML = adv.lista.map((a, i) => `
            <div class="surgir glass-card rounded-xl p-3 border border-amber-500/25 flex items-start gap-3" style="animation-delay:${i * 60}ms">
                <span class="material-symbols-outlined text-amber-500 text-[20px] flex-shrink-0 mt-0.5">gavel</span>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-label-bold text-label-bold text-amber-500 uppercase">${escapar(a.tipo_label)}</span>
                        <span class="font-label-sm text-label-sm text-secondary">${escapar(a.data_br)}</span>
                    </div>
                    <p class="font-body-md text-body-md text-on-surface-variant mt-1">${escapar(a.motivo)}</p>
                </div>
            </div>`).join('');
    }

    function renderTipos(tipos) {
        $('#ajusteTipo').innerHTML = tipos
            .map((t) => `<option value="${escapar(t.tipo)}">${escapar(t.label)}</option>`)
            .join('');
    }

    function renderDias(h) {
        const lista = $('#listaDias');
        if (!h.dias.length) {
            lista.innerHTML = `<div class="p-8 text-center">
                <span class="material-symbols-outlined text-outline text-[32px] mb-2">event_busy</span>
                <p class="font-body-md text-body-md text-secondary">Nenhuma batida registrada em ${escapar(h.mes_nome)}.</p>
            </div>`;
            return;
        }

        lista.innerHTML = h.dias.map((dia, i) => {
            const [corDia, corChip, icone, corResumo] = CORES[dia.status] || CORES.ok;
            const pendentes = dia.pedidos.filter((p) => p.status === 'pendente').length;
            const selo = pendentes
                ? `<span class="ml-1 px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-500 border border-amber-500/30 font-label-sm text-label-sm">${pendentes} pedido${pendentes > 1 ? 's' : ''}</span>`
                : '';

            return `
            <div class="history-card surgir rounded-lg p-3 cursor-pointer" data-dia="${dia.data}" style="animation-delay:${Math.min(i, 8) * 55}ms">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-4 min-w-0">
                        <div class="w-10 h-10 rounded-lg bg-surface flex flex-col items-center justify-center shadow-sm border border-surface-variant flex-shrink-0">
                            <span class="font-label-bold text-label-bold text-secondary">${dia.dia_semana}</span>
                            <span class="font-headline-md text-headline-md ${corDia} leading-none">${dia.dia}</span>
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="font-body-lg text-body-lg text-on-surface font-medium truncate">${dia.trabalhado}</span>
                            <span class="font-body-md text-body-md ${corResumo}">${dia.resumo}${selo}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <div class="px-2 py-1 rounded-full font-label-bold text-label-bold flex items-center gap-1 border ${corChip}">
                            <span class="material-symbols-outlined text-[16px]">${icone}</span>${dia.chip}
                        </div>
                        <span class="material-symbols-outlined text-secondary text-[20px]">chevron_right</span>
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    /* ------------------------------ modal do dia ------------------------------ */
    function abrirModal(data) {
        if (!dados) return;
        diaAberto = dados.historico.dias.find((d) => d.data === data);
        if (!diaAberto) return;

        $('#modalData').textContent = diaAberto.data_extenso;
        $('#modalResumo').textContent = `${diaAberto.trabalhado} · ${diaAberto.resumo}`;

        renderBatidas();
        renderPedidosDoDia();
        esconderForm();

        $('#modalFundo').classList.add('aberto');
        $('#modalFolha').classList.add('aberto');
    }

    function fecharModal() {
        $('#modalFundo').classList.remove('aberto');
        $('#modalFolha').classList.remove('aberto');
        esconderForm();
    }

    function renderBatidas() {
        const box = $('#modalBatidas');
        if (!diaAberto.registros.length) {
            box.innerHTML = `<p class="font-body-md text-body-md text-secondary text-center py-4">
                Nenhuma batida registrada neste dia. Use o botão abaixo para pedir a inclusão.
            </p>`;
            return;
        }

        box.innerHTML = diaAberto.registros.map((r) => `
            <button class="linha-batida w-full flex items-center justify-between gap-3 rounded-xl border border-outline-variant/40 p-3 text-left" data-registro="${r.id}" type="button">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="material-symbols-outlined text-primary text-[20px] flex-shrink-0">${ICONES[r.tipo] || 'schedule'}</span>
                    <span class="font-body-md text-body-md text-on-surface-variant truncate">${escapar(r.label)}</span>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="font-body-lg text-body-lg text-on-surface font-medium tabular-nums">${r.hora}</span>
                    <span class="material-symbols-outlined text-primary text-[18px]">edit</span>
                </div>
            </button>`).join('');
    }

    function renderPedidosDoDia() {
        const bloco = $('#modalPedidos');
        if (!diaAberto.pedidos.length) {
            bloco.classList.add('hidden');
            bloco.classList.remove('flex');
            return;
        }
        bloco.classList.remove('hidden');
        bloco.classList.add('flex');
        $('#modalPedidosLista').innerHTML = diaAberto.pedidos.map((p) => `
            <div class="rounded-xl border border-outline-variant/40 p-3 flex flex-col gap-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-body-md text-body-md text-on-surface-variant truncate">${escapar(p.tipo_label)} · ${p.hora}</span>
                    <span class="px-2 py-0.5 rounded-full border font-label-sm text-label-sm flex-shrink-0 ${CORES_STATUS[p.status] || ''}">${escapar(p.status_label)}</span>
                </div>
                <span class="font-label-sm text-label-sm text-secondary">Protocolo ${p.protocolo}</span>
                ${p.resposta ? `<p class="font-body-md text-body-md text-on-surface-variant mt-1">${escapar(p.resposta)}</p>` : ''}
            </div>`).join('');
    }

    /* ----------------------------- form do pedido ----------------------------- */
    function mostrarForm() {
        const form = $('#formAjuste');
        form.classList.remove('hidden');
        form.classList.add('flex');
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function esconderForm() {
        const form = $('#formAjuste');
        form.classList.add('hidden');
        form.classList.remove('flex');
        alvo = null;
        $('#ajusteMotivo').value = '';
    }

    function abrirFormCorrigir(registroId) {
        const r = diaAberto.registros.find((x) => String(x.id) === String(registroId));
        if (!r) return;

        alvo = { modo: 'alterar', registroId: Number(r.id) || 0, label: r.label };
        $('#formTitulo').textContent = 'Corrigir ' + r.label;
        $('#campoTipo').classList.add('hidden');
        $('#campoTipo').classList.remove('flex');
        $('#ajusteHora').value = r.hora;
        $('#ajusteAtual').textContent = `Hoje está registrado como ${r.hora}.`;
        mostrarForm();
    }

    function abrirFormFaltou() {
        alvo = { modo: 'criar', registroId: 0, label: '' };
        $('#formTitulo').textContent = 'Batida que faltou';
        $('#campoTipo').classList.remove('hidden');
        $('#campoTipo').classList.add('flex');
        $('#ajusteHora').value = '';
        $('#ajusteAtual').textContent = 'Informe o horário em que você realmente bateu.';
        mostrarForm();
    }

    async function enviarPedido() {
        if (enviando || !alvo || !diaAberto) return;

        const hora = $('#ajusteHora').value;
        const motivo = $('#ajusteMotivo').value.trim();

        if (!hora) { toast('Informe o horário correto.', 'error'); $('#ajusteHora').focus(); return; }
        if (alvo.modo === 'criar' && !$('#ajusteTipo').value) {
            toast('Escolha qual batida faltou.', 'error');
            $('#ajusteTipo').focus();
            return;
        }
        if (alvo.modo === 'alterar' && !alvo.registroId) {
            toast('Não consegui identificar a batida. Feche e abra o dia de novo.', 'error');
            return;
        }
        if (motivo.length < 10) { toast('Escreva o motivo com pelo menos 10 letras.', 'error'); $('#ajusteMotivo').focus(); return; }

        enviando = true;
        $('#btnEnviarPedido').disabled = true;
        $('#btnEnviarPedidoTexto').textContent = 'Enviando...';
        const dia = diaAberto.data;

        try {
            const resposta = await post({
                acao: 'solicitar',
                pin,
                modo: alvo.modo,
                registro_id: alvo.modo === 'alterar' ? alvo.registroId : 0,
                tipo_registro: alvo.modo === 'criar' ? $('#ajusteTipo').value : '',
                data_ponto: dia,
                hora,
                motivo
            });

            vibrar([40, 60, 90]);
            toast(resposta.mensagem);
            dados.historico = resposta.historico;
            render(resposta.historico, null);

            /* Reabre o dia já com o pedido novo na lista. */
            esconderForm();
            abrirModal(dia);
            adiarFechamento();
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            enviando = false;
            $('#btnEnviarPedido').disabled = false;
            $('#btnEnviarPedidoTexto').textContent = 'Enviar para o administrador';
        }
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

    $('#btnConfirmarPin').addEventListener('click', () => consultar());

    document.addEventListener('keydown', (evento) => {
        if ($('[data-tela="pin"]').classList.contains('ativa')) {
            if (/^\d$/.test(evento.key) && pin.length < 4) {
                pin += evento.key;
                renderPin();
            } else if (evento.key === 'Backspace') {
                pin = pin.slice(0, -1);
                renderPin();
            } else if (evento.key === 'Enter') {
                consultar();
            }
            return;
        }
        if (evento.key === 'Escape' && $('#modalFolha').classList.contains('aberto')) {
            fecharModal();
        }
    });

    ['#mesAnterior', '#mesProximo'].forEach((sel) => {
        $(sel).addEventListener('click', () => {
            const mes = $(sel).dataset.mes;
            if (mes) consultar(mes);
        });
    });

    $('#listaDias').addEventListener('click', (evento) => {
        adiarFechamento();
        const cartao = evento.target.closest('.history-card');
        if (cartao) abrirModal(cartao.dataset.dia);
    });

    $('#cardAdvertencias').addEventListener('click', () => {
        const bloco = $('#blocoAdvertencias');
        if (bloco.classList.contains('hidden')) {
            toast('Nenhuma advertência registrada neste mês.');
            return;
        }
        bloco.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    $('#modalBatidas').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-registro]');
        if (botao) { adiarFechamento(); abrirFormCorrigir(botao.dataset.registro); }
    });

    $('#btnFaltouBatida').addEventListener('click', () => { adiarFechamento(); abrirFormFaltou(); });
    $('#formCancelar').addEventListener('click', esconderForm);
    $('#btnEnviarPedido').addEventListener('click', enviarPedido);
    $('#modalFechar').addEventListener('click', fecharModal);
    $('#modalFundo').addEventListener('click', fecharModal);

    ['click', 'touchstart', 'scroll'].forEach((evt) => {
        document.addEventListener(evt, () => {
            if ($('[data-tela="lista"]').classList.contains('ativa')) adiarFechamento();
        }, { passive: true });
    });

    document.addEventListener('visibilitychange', () => { if (document.hidden) fechar(false); });
    window.addEventListener('pagehide', () => fechar(false));

    /* --------------------------------- tutorial --------------------------------- */
    const PASSOS = [
        {
            titulo: 'Tela de Histórico',
            texto: 'Aqui ficam <b>todas as suas batidas</b>. Vou explicar cada parte em <b>6 passos</b>.'
        },
        {
            alvo: '#mesNome',
            titulo: '1. Trocar o mês',
            texto: 'Mostra o mês que você está vendo.<br><br>Use a <b>setinha da esquerda</b> para ver meses anteriores e a <b>da direita</b> para voltar. Quando a seta some, é porque não tem mais mês para aquele lado.'
        },
        {
            alvo: '#totalTrabalhado',
            titulo: '2. Horas trabalhadas',
            texto: 'Soma de <b>tudo que você trabalhou</b> nos dias fechados do mês.<br><br>Dias que ainda estão em aberto não entram nessa conta.'
        },
        {
            alvo: '#totalAtrasos',
            titulo: '3. Atrasos',
            texto: 'É <b>o que faltou para fechar a jornada</b> do seu cargo em cada dia.<br><br>Pega tanto <b>chegar tarde</b> quanto <b>sair mais cedo</b>. Se você fez a jornada completa, não deve nada — mesmo tendo chegado um pouco depois.<br><br>Cada cargo tem uma <b>tolerância</b>: chegar dentro dela não conta como atraso.<br><br>Trabalhar além da jornada não gera hora extra.'
        },
        {
            alvo: '#cardAdvertencias',
            titulo: '4. Advertências',
            texto: 'Quantas advertências você recebeu <b>no mês</b> e no total. Quem registra é o administrador.<br><br>Se tiver alguma, toque no cartão para ler o motivo.'
        },
        {
            alvo: '#listaDias .history-card',
            titulo: '5. Os dias',
            texto: 'Cada linha é um dia trabalhado, com as horas e o selo <b>OK</b>, <b>ATRASO</b> ou <b>PEND</b> (pendente, faltou bater algo).<br><br><b>Toque em um dia</b> para abrir os detalhes.'
        },
        {
            alvo: '#modalBatidas',
            titulo: '6. Pedir correção',
            texto: 'Dentro do dia aparecem <b>todas as batidas</b>. Se alguma estiver errada:<br><br><b>1.</b> Toque na batida errada (tem um lápis do lado).<br><b>2.</b> Coloque o horário certo.<br><b>3.</b> Escreva o motivo.<br><b>4.</b> Toque em <b>Enviar para o administrador</b>.<br><br>Se você <b>esqueceu de bater</b>, use o botão "Faltou uma batida" e escolha qual foi.',
            antes: function () {
                var primeiro = document.querySelector('#listaDias .history-card');
                if (primeiro && !document.getElementById('modalFolha').classList.contains('aberto')) {
                    abrirModal(primeiro.dataset.dia);
                }
            }
        }
    ];

    const CONFIG_TUTORIAL = {
        chave: 'historico',
        proxima: {
            pergunta: 'Quer ver agora o tutorial da tela de Pedidos?',
            url: 'solicitar-ajuste.php?tutorial=1'
        }
    };

    $('#btnTutorial').addEventListener('click', () => window.Tutorial.iniciar(PASSOS, CONFIG_TUTORIAL));

    /* Veio do tutorial da tela inicial: espera destravar o PIN para continuar. */
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
})();
</script>
<?php app_mobile_end(); ?>
