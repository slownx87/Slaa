<?php
/*
 * Painel administrativo - calendário de ajustes.
 *
 * Escolhe o funcionário, navega pelo mês e corrige as batidas do dia:
 * alterar horário, incluir batida que faltou ou apagar lançamento errado.
 * Toda mudança fica registrada em ponto_ajustes e refaz o resumo do dia.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_admin();

function cal_tipos(): array
{
    $out = [];
    foreach (['entrada', 'saida_almoco', 'volta_almoco', 'saida_janta', 'volta_janta', 'saida_descanso', 'volta_descanso', 'saida'] as $t) {
        $out[] = ['tipo' => $t, 'label' => tipo_ponto_label($t)];
    }
    return $out;
}

/** Marcações do mês: dias com batida, com jornada aberta e com ajuste manual. */
function cal_mes(int $funcionarioId, string $ym): array
{
    $primeiro = $ym . '-01';
    $ultimo = date('Y-m-t', strtotime($primeiro));

    $stmt = db()->prepare("SELECT data_ponto, COUNT(*) AS total,
            SUM(tipo = 'saida') AS saidas,
            SUM(observacao LIKE '%[Ajustado%' OR observacao LIKE '%manualmente%') AS ajustados
        FROM ponto_registros
        WHERE funcionario_id = ? AND data_ponto BETWEEN ? AND ?
        GROUP BY data_ponto");
    $stmt->execute([$funcionarioId, $primeiro, $ultimo]);

    $dias = [];
    foreach ($stmt->fetchAll() as $linha) {
        $dias[(string)$linha['data_ponto']] = [
            'total' => (int)$linha['total'],
            'aberto' => (int)$linha['saidas'] === 0,
            'ajustado' => (int)$linha['ajustados'] > 0,
        ];
    }
    return $dias;
}

/** Batidas de um dia, já com o número da jornada. */
function cal_dia(int $funcionarioId, string $data): array
{
    $lista = registros_jornada_dia_com_turno_noite($funcionarioId, $data);
    $jornadas = ponto_jornadas_from_registros($lista);

    $out = [];
    foreach ($jornadas as $indice => $jornada) {
        foreach (($jornada['eventos'] ?? []) as $r) {
            $obs = (string)($r['observacao'] ?? '');
            $out[] = [
                'id' => ponto_registro_ordem_id($r),
                'tipo' => (string)$r['tipo'],
                'label' => (count($jornadas) > 1 ? 'J' . ($indice + 1) . ' · ' : '') . tipo_ponto_label((string)$r['tipo']),
                'hora' => date('H:i', strtotime((string)$r['data_hora'])),
                'ajustado' => (stripos($obs, '[Ajustado') !== false || stripos($obs, 'manualmente') !== false),
                /* A foto abre por foto.php (só admin logado); aqui só avisa que existe. */
                'tem_foto' => !empty($r['foto_path']),
            ];
        }
    }
    return $out;
}

/**
 * Horários que o cargo do funcionário aceita, para o preenchimento em lote.
 *
 * É daqui que sai o "puxar os horários do cargo": a tela não inventa hora
 * nenhuma, ela mostra o que está cadastrado em Configurações → Cargos. Cargo
 * sem horário devolve lista vazia e a tela avisa em vez de chutar 08:00.
 */
function cal_horarios_do_cargo(int $funcionarioId): array
{
    $jornada = ponto_jornada_do_funcionario($funcionarioId);
    $turnos = isset($jornada['turnos']) ? $jornada['turnos'] : [];
    if (!$turnos && !empty($jornada['definido'])) {
        $turnos = [$jornada];
    }

    $out = [];
    foreach ($turnos as $t) {
        if (empty($t['definido'])) continue;
        $entradaMin = (int)$t['entrada_min'];
        $saidaMin = (int)$t['saida_min'];
        $intervalo = (int)$t['intervalo_min'];

        /* Meio da jornada: é onde o almoço/janta cai por padrão. Num turno
         * 08:00-17:00 com 1h dá 12:00; num 18:00-06:00 dá 23:30. */
        $meio = ($entradaMin + (int)round($t['jornada_min'] / 2)) % 1440;

        /* O rótulo do seletor sempre começa pelo horário. O apelido entra
         * depois, quando existe: num cargo de escala, ler "Principal" no lugar
         * de "08:00 às 17:00" não diz qual turno está sendo preenchido. */
        $horas = $t['entrada'] . ' às ' . $t['saida'];
        $apelido = (string)(isset($t['descricao']) ? $t['descricao'] : '');
        if ($apelido === 'Principal' || $apelido === (string)(isset($t['horario']) ? $t['horario'] : '')) {
            $apelido = '';
        }

        $out[] = [
            'descricao' => $apelido !== '' ? ($horas . ' · ' . $apelido) : $horas,
            'entrada' => (string)$t['entrada'],
            'saida' => (string)$t['saida'],
            'intervalo_min' => $intervalo,
            'jornada_texto' => ponto_fmt_hm((int)$t['jornada_min']),
            'noturno' => $saidaMin <= $entradaMin,
            'pausa_inicio' => sprintf('%02d:%02d', intdiv($meio, 60), $meio % 60),
            'pausa_fim' => sprintf('%02d:%02d', intdiv(($meio + $intervalo) % 1440, 60), ($meio + $intervalo) % 1440 % 60),
            /* Pausa que cai à noite é janta, não almoço. */
            'pausa_tipo' => ($meio >= 18 * 60 || $meio < 4 * 60) ? 'janta' : 'almoco',
        ];
    }
    return $out;
}

/* Ordem em que as batidas de um dia acontecem. O preenchimento em lote usa
 * esta ordem para montar a linha do tempo do dia e descobrir sozinho o que
 * caiu depois da meia-noite. */
function cal_ordem_tipos(): array
{
    return ['entrada', 'saida_almoco', 'volta_almoco', 'saida_janta', 'volta_janta',
            'saida_descanso', 'volta_descanso', 'saida'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        $acao = (string)($_POST['acao'] ?? 'carregar');
        $funcionarioId = (int)($_POST['funcionario_id'] ?? 0);
        $func = admin_funcionario_autorizado($funcionarioId);

        if ($acao === 'carregar') {
            $ym = (string)($_POST['mes'] ?? date('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
            $data = (string)($_POST['data'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = '';

            admin_json([
                'ok' => true,
                'mes' => $ym,
                'dias' => cal_mes($funcionarioId, $ym),
                'batidas' => $data ? cal_dia($funcionarioId, $data) : [],
                'data' => $data,
                'horarios' => cal_horarios_do_cargo($funcionarioId),
                'cargo' => (string)($func['cargo_nome'] ?: 'sem cargo'),
            ]);
        }

        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if (mb_strlen($motivo) < 5) {
            throw new RuntimeException('Escreva o motivo do ajuste (mínimo 5 letras).');
        }
        $motivo = mb_substr($motivo, 0, 500);

        /* ------------------------- alterar horário ------------------------- */
        if ($acao === 'alterar') {
            $registroId = (int)($_POST['registro_id'] ?? 0);
            $hora = trim((string)($_POST['hora'] ?? ''));
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                throw new RuntimeException('Informe o horário no formato 00:00.');
            }

            $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE id = ? AND funcionario_id = ?");
            $stmt->execute([$registroId, $funcionarioId]);
            $r = $stmt->fetch();
            if (!$r) throw new RuntimeException('Batida não encontrada.');

            /* Escolhe o dia mais próximo da batida atual: corrigir 23:50 para 00:10
             * (ou a saída da madrugada) cruza a meia-noite e o dia tem que acompanhar. */
            $novaDataHora = ponto_data_hora_mais_proxima($hora, (string)$r['data_hora']);
            if ($novaDataHora === (string)$r['data_hora']) {
                throw new RuntimeException('O horário é igual ao que já está gravado.');
            }

            db()->beginTransaction();
            try {
                db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'alterar', ?, ?, ?, ?, ?, ?)")
                    ->execute([$registroId, $funcionarioId, (int)$r['condominio_id'], $r['data_hora'], $novaDataHora, $r['tipo'], $r['tipo'], $motivo . ' | Dia da jornada/PDF: ' . $r['data_ponto'], admin_id()]);
                db()->prepare("UPDATE ponto_registros SET data_hora = ?, observacao = CONCAT(COALESCE(observacao,''), '\n[Ajustado pelo admin: ', ?, ']') WHERE id = ?")
                    ->execute([$novaDataHora, $motivo, $registroId]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }

            recalc_resumo($funcionarioId, (string)$r['data_ponto']);
            log_action('ajustar_ponto', 'Ajustou registro ID ' . $registroId, $funcionarioId, (int)$r['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => 'Horário ajustado.', 'batidas' => cal_dia($funcionarioId, (string)$r['data_ponto'])]);
        }

        /* --------------------------- criar batida --------------------------- */
        if ($acao === 'criar') {
            $data = (string)($_POST['data'] ?? '');
            $tipo = (string)($_POST['tipo'] ?? '');
            $hora = trim((string)($_POST['hora'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) throw new RuntimeException('Dia inválido.');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw new RuntimeException('Informe o horário no formato 00:00.');
            if ($tipo === '' || tipo_ponto_label($tipo) === $tipo) throw new RuntimeException('Escolha o tipo da batida.');

            $dataHora = $data . ' ' . $hora . ':00';

            /* Saída/volta com horário ANTERIOR à entrada do dia só pode ser depois
             * da meia-noite (turno que vira o dia): grava no dia seguinte, como o
             * funcionário teria batido. Antes ficava 06:00 do mesmo dia, antes da
             * entrada, e o sistema precisava "adivinhar" nos relatórios. */
            if ($tipo !== 'entrada') {
                $stmtEnt = db()->prepare("SELECT MIN(data_hora) FROM ponto_registros WHERE funcionario_id = ? AND data_ponto = ? AND tipo = 'entrada'");
                $stmtEnt->execute([$funcionarioId, $data]);
                $entradaDoDia = (string)$stmtEnt->fetchColumn();
                if ($entradaDoDia !== '' && $dataHora < $entradaDoDia) {
                    $dataHora = date('Y-m-d', strtotime($data . ' +1 day')) . ' ' . $hora . ':00';
                }
            }
            if (strtotime($dataHora) > time() + 300) {
                throw new RuntimeException('Não dá para lançar uma batida em um horário que ainda não aconteceu.');
            }

            db()->beginTransaction();
            try {
                db()->prepare("INSERT INTO ponto_registros (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, '', 'admin-calendario')")
                    ->execute([$funcionarioId, (int)$func['condominio_id'], $tipo, $data, $dataHora,
                        'Batida adicionada manualmente pelo admin em ' . now_sp() . '. Motivo: ' . $motivo]);
                $novoId = (int)db()->lastInsertId();
                db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'criar', NULL, ?, NULL, ?, ?, ?)")
                    ->execute([$novoId, $funcionarioId, (int)$func['condominio_id'], $dataHora, $tipo, $motivo . ' | Dia da jornada/PDF: ' . $data, admin_id()]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }

            recalc_resumo($funcionarioId, $data);
            log_action('criar_batida_manual', 'Adicionou batida no dia ' . $data, $funcionarioId, (int)$func['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => tipo_ponto_label($tipo) . ' incluída.', 'batidas' => cal_dia($funcionarioId, $data)]);
        }

        /* --------------------- preencher vários dias ---------------------- */
        if ($acao === 'preencher') {
            $dias = array_values(array_unique(array_filter(
                explode(',', (string)($_POST['dias'] ?? '')),
                function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1; }
            )));
            sort($dias);

            if (!$dias) {
                throw new RuntimeException('Escolha pelo menos um dia no calendário.');
            }
            if (count($dias) > 62) {
                throw new RuntimeException('São muitos dias de uma vez (máximo 62). Faça em duas partes.');
            }
            if (end($dias) > today_sp()) {
                throw new RuntimeException('Não dá para lançar ponto em dia que ainda não aconteceu.');
            }

            $batidas = json_decode((string)($_POST['batidas'] ?? '[]'), true);
            if (!is_array($batidas) || !$batidas) {
                throw new RuntimeException('Escolha pelo menos uma batida para incluir.');
            }

            /* Coloca na ordem real do dia. Isso é o que permite descobrir
             * sozinho quais batidas caíram depois da meia-noite. */
            $ordem = array_flip(cal_ordem_tipos());
            $limpas = [];
            foreach ($batidas as $b) {
                $tipo = (string)(isset($b['tipo']) ? $b['tipo'] : '');
                $hora = (string)(isset($b['hora']) ? $b['hora'] : '');
                if (!isset($ordem[$tipo])) {
                    throw new RuntimeException('Tipo de batida desconhecido: ' . $tipo);
                }
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                    throw new RuntimeException('Informe o horário de ' . tipo_ponto_label($tipo) . ' no formato 00:00.');
                }
                $limpas[$tipo] = ['tipo' => $tipo, 'hora' => $hora, 'ordem' => $ordem[$tipo]];
            }
            usort($limpas, function ($a, $b) { return $a['ordem'] <=> $b['ordem']; });

            $criadas = 0;
            $pulados = 0;
            $diasTocados = [];

            db()->beginTransaction();
            try {
                foreach ($dias as $dia) {
                    $existentes = [];
                    foreach (registros_do_dia($funcionarioId, $dia) as $r) {
                        $existentes[(string)$r['tipo']] = true;
                    }

                    /* Monta a linha do tempo do dia sempre crescente: assim que
                     * uma hora vem antes da anterior, o turno virou a noite e o
                     * resto do dia é gravado na data seguinte — do mesmo jeito
                     * que o funcionário bateria de madrugada. */
                    $dataAtual = $dia;
                    $horaAnterior = null;

                    foreach ($limpas as $b) {
                        /* Só vira o dia quando a hora ANDA PARA TRÁS (23:30 →
                         * 00:30). Hora igual à anterior fica no mesmo dia: virar
                         * o dia aí jogaria a saída 24h para a frente por causa de
                         * um intervalo de duração zero. */
                        if ($horaAnterior !== null && $b['hora'] < $horaAnterior) {
                            $dataAtual = date('Y-m-d', strtotime($dataAtual . ' +1 day'));
                        }
                        $horaAnterior = $b['hora'];
                        $dataHora = $dataAtual . ' ' . $b['hora'] . ':00';

                        /* Nunca sobrescreve o que a pessoa realmente bateu. */
                        if (isset($existentes[$b['tipo']])) {
                            $pulados++;
                            continue;
                        }

                        db()->prepare("INSERT INTO ponto_registros
                            (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, ip, user_agent)
                            VALUES (?, ?, ?, ?, ?, ?, '', 'admin-calendario-lote')")
                            ->execute([
                                $funcionarioId, (int)$func['condominio_id'], $b['tipo'], $dia, $dataHora,
                                'Batida lançada em lote pelo admin em ' . now_sp()
                                    . ' (horário do cargo). Motivo: ' . $motivo,
                            ]);
                        $novoId = (int)db()->lastInsertId();

                        db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'criar', NULL, ?, NULL, ?, ?, ?)")
                            ->execute([$novoId, $funcionarioId, (int)$func['condominio_id'], $dataHora, $b['tipo'],
                                $motivo . ' | Lançamento em lote | Dia da jornada/PDF: ' . $dia, admin_id()]);

                        $criadas++;
                        $diasTocados[$dia] = true;
                    }
                }
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }

            foreach (array_keys($diasTocados) as $dia) {
                recalc_resumo($funcionarioId, (string)$dia);
            }
            log_action('preencher_lote', 'Lançou ' . $criadas . ' batida(s) em ' . count($diasTocados)
                . ' dia(s) pelo horário do cargo. Motivo: ' . $motivo, $funcionarioId, (int)$func['condominio_id']);

            $recado = $criadas > 0
                ? $criadas . ' batida(s) lançada(s) em ' . count($diasTocados) . ' dia(s).'
                : 'Nada a lançar: esses dias já tinham essas batidas.';
            if ($pulados > 0) {
                $recado .= ' ' . $pulados . ' já existia(m) e foi(ram) mantida(s).';
            }

            /* Devolve o mês já remarcado e o dia que está aberto na tela, para
             * o admin ver o resultado sem precisar recarregar. */
            $ym = (string)($_POST['mes'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $ym = date('Y-m', strtotime($dias[0]));
            }
            $diaAberto = (string)($_POST['data'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $diaAberto)) {
                $diaAberto = '';
            }

            admin_json([
                'ok' => true,
                'mensagem' => $recado,
                'dias' => cal_mes($funcionarioId, $ym),
                'batidas' => $diaAberto !== '' ? cal_dia($funcionarioId, $diaAberto) : [],
            ]);
        }

        /* -------------------------- excluir batida -------------------------- */
        if ($acao === 'excluir') {
            $registroId = (int)($_POST['registro_id'] ?? 0);
            $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE id = ? AND funcionario_id = ?");
            $stmt->execute([$registroId, $funcionarioId]);
            $r = $stmt->fetch();
            if (!$r) throw new RuntimeException('Batida não encontrada.');

            /* Apaga primeiro o histórico de
             * ajustes daquele registro (a coluna tipo_ajuste não tem o valor
             * 'excluir') e só depois a batida. O que aconteceu fica no log. */
            db()->beginTransaction();
            try {
                db()->prepare("DELETE FROM ponto_ajustes WHERE registro_id = ?")->execute([$registroId]);
                db()->prepare("DELETE FROM ponto_registros WHERE id = ?")->execute([$registroId]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }

            recalc_resumo($funcionarioId, (string)$r['data_ponto']);
            log_action('excluir_ponto', 'Excluiu batida ID ' . $registroId . ' (' . $r['tipo'] . ' em ' . $r['data_hora'] . '). Motivo: ' . $motivo, $funcionarioId, (int)$r['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => 'Batida excluída.', 'batidas' => cal_dia($funcionarioId, (string)$r['data_ponto'])]);
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $e) {
        admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

[$filtro, $params] = admin_filtro_condominio('f');
$stmt = db()->prepare("SELECT f.id, f.nome, co.nome AS condominio_nome
    FROM ponto_funcionarios f
    INNER JOIN ponto_condominios co ON co.id = f.condominio_id
    WHERE f.ativo = 1 {$filtro} ORDER BY f.nome");
$stmt->execute($params);
$funcionarios = $stmt->fetchAll();

$csrf = csrf_token();
app_mobile_head('Ajustes - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar();
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-md">

<div class="flex flex-col gap-2 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Ajustes de ponto</h1>
<select class="campo font-body-lg text-body-lg" id="selFuncionario">
<option value="">Escolha o funcionário...</option>
<?php foreach ($funcionarios as $f): ?>
<option value="<?= (int)$f['id'] ?>"><?= h((string)$f['nome']) ?> — <?= h((string)$f['condominio_nome']) ?></option>
<?php endforeach; ?>
</select>
</div>

<section class="cartao rounded-xl p-3 flex flex-col gap-3 fade-in-up hidden" id="blocoCalendario">
<div class="flex items-center justify-between">
<button class="w-8 h-8 rounded-full flex items-center justify-center text-secondary hover:bg-surface-variant" id="mesAnterior" type="button">
<span class="material-symbols-outlined text-[18px]">chevron_left</span>
</button>
<h2 class="font-headline-md text-headline-md text-primary" id="mesNome">--</h2>
<button class="w-8 h-8 rounded-full flex items-center justify-center text-secondary hover:bg-surface-variant disabled:opacity-30" id="mesProximo" type="button">
<span class="material-symbols-outlined text-[18px]">chevron_right</span>
</button>
</div>

<div class="grid grid-cols-7 gap-1 text-center">
<div class="font-label-sm text-label-sm text-outline">D</div>
<div class="font-label-sm text-label-sm text-outline">S</div>
<div class="font-label-sm text-label-sm text-outline">T</div>
<div class="font-label-sm text-label-sm text-outline">Q</div>
<div class="font-label-sm text-label-sm text-outline">Q</div>
<div class="font-label-sm text-label-sm text-outline">S</div>
<div class="font-label-sm text-label-sm text-outline">S</div>
</div>
<div class="grid grid-cols-7 gap-1 text-center" id="gradeDias"></div>

<div class="flex justify-around items-center border-t border-outline-variant/30 pt-2">
<div class="flex items-center gap-1"><div class="w-1.5 h-1.5 rounded-full bg-error"></div><span class="font-label-sm text-label-sm text-outline">Falta saída</span></div>
<div class="flex items-center gap-1"><div class="w-1.5 h-1.5 rounded-full bg-amber-500"></div><span class="font-label-sm text-label-sm text-outline">Ajustado</span></div>
<div class="flex items-center gap-1"><div class="w-1.5 h-1.5 rounded-full bg-primary"></div><span class="font-label-sm text-label-sm text-outline">Com batida</span></div>
</div>

<!-- Preenchimento em lote: marca vários dias e lança as batidas de todos de
     uma vez, com os horários que estão cadastrados no cargo da pessoa. -->
<button class="w-full h-[40px] rounded-full border border-primary text-primary font-label-bold text-label-bold flex items-center justify-center gap-1 active:scale-95 transition-transform" id="btnLote" type="button">
<span class="material-symbols-outlined text-[16px]" id="btnLoteIcone">checklist</span>
<span id="btnLoteTexto">PREENCHER VÁRIOS DIAS</span>
</button>

<div class="hidden flex-col gap-2 rounded-xl border border-primary/40 bg-primary/5 p-2.5" id="barraLote">
<p class="font-body-md text-body-md text-on-surface" id="loteContagem">Toque nos dias que quer preencher.</p>
<div class="flex gap-2">
<button class="flex-1 h-[34px] rounded-full border border-outline text-secondary font-label-bold text-label-bold" id="loteSemana" type="button">SEG A SEX</button>
<button class="flex-1 h-[34px] rounded-full border border-outline text-secondary font-label-bold text-label-bold" id="loteLimpar" type="button">LIMPAR</button>
</div>
<button class="w-full h-[40px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold flex items-center justify-center gap-1 disabled:opacity-40" id="loteContinuar" type="button" disabled>
<span class="material-symbols-outlined text-[16px]">arrow_forward</span> CONTINUAR
</button>
</div>
</section>

<section class="flex flex-col gap-2 hidden" id="blocoDia">
<div class="flex items-center justify-between">
<h3 class="font-headline-md text-headline-md text-on-surface" id="tituloDia">Batidas</h3>
<button class="font-label-bold text-label-bold text-primary flex items-center gap-1" id="btnAdicionar" type="button">
<span class="material-symbols-outlined text-[16px]">add</span> INCLUIR
</button>
</div>
<div class="flex flex-col gap-2" id="listaBatidas"></div>
</section>

<p class="font-body-md text-body-md text-secondary text-center py-6" id="aviso">Escolha um funcionário para começar.</p>
</main>

<!-- --------------------------- folha: ajuste --------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="ajFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl flex flex-col" id="ajFolha" style="max-height:88vh;">
<div class="flex justify-center pt-3 pb-1"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="overflow-y-auto px-container-margin pb-8 flex flex-col gap-3">
<h3 class="font-headline-md text-headline-md text-on-surface" id="ajTitulo">Ajustar batida</h3>

<div class="flex flex-col gap-2 hidden" id="ajBlocoTipo">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajTipo">Tipo da batida</label>
<select class="campo font-body-lg text-body-lg" id="ajTipo">
<?php foreach (cal_tipos() as $t): ?>
<option value="<?= h($t['tipo']) ?>"><?= h($t['label']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="flex flex-col gap-2" id="ajBlocoHora">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajHora">Horário</label>
<input class="campo font-display-time text-[26px] text-center tabular-nums" id="ajHora" type="time"/>
</div>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="ajMotivo">Motivo do ajuste</label>
<textarea class="campo font-body-md text-body-md resize-none" id="ajMotivo" maxlength="500" placeholder="Ex: funcionário esqueceu de bater a saída" rows="3"></textarea>

<div class="flex gap-2 pt-1">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="ajCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="ajSalvar" type="button">Salvar</button>
</div>
<button class="w-full h-[42px] rounded-full text-error font-label-bold text-label-bold hidden" id="ajExcluir" type="button">Excluir esta batida</button>
</div>
</div>

<!-- ----------------------- folha: preencher em lote ----------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="loteFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl flex flex-col" id="loteFolha" style="max-height:92vh;">
<div class="flex justify-center pt-3 pb-1"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="overflow-y-auto px-container-margin pb-8 flex flex-col gap-3">
<h3 class="font-headline-md text-headline-md text-on-surface">Preencher vários dias</h3>
<p class="font-body-md text-body-md text-secondary" id="loteResumoDias">--</p>

<!-- Horário do cargo: quando o cargo aceita mais de um turno (escala), o
     admin escolhe qual foi cumprido nesses dias. -->
<div class="flex flex-col gap-2 hidden" id="loteBlocoTurno">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="loteTurno">Horário do cargo</label>
<select class="campo font-body-lg text-body-lg" id="loteTurno"></select>
</div>

<div class="rounded-xl border border-primary/30 bg-primary/5 p-2.5 flex items-start gap-2" id="loteInfoCargo">
<span class="material-symbols-outlined text-primary text-[18px] flex-shrink-0">badge</span>
<p class="font-body-md text-body-md text-on-surface-variant" id="loteInfoTexto">--</p>
</div>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider">Batidas que serão lançadas</label>
<div class="flex flex-col gap-2" id="loteListaTipos"></div>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="loteMotivo">Motivo do lançamento</label>
<textarea class="campo font-body-md text-body-md resize-none" id="loteMotivo" maxlength="500" placeholder="Ex: cartão de ponto do mês conferido com a escala" rows="3"></textarea>

<div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-2.5 flex items-start gap-2">
<span class="material-symbols-outlined text-amber-500 text-[18px] flex-shrink-0">info</span>
<p class="font-body-md text-body-md text-on-surface-variant">Batida que a pessoa já registrou <b>não é alterada</b>. Só entram as que estão faltando.</p>
</div>

<div class="flex gap-2 pt-1">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="loteCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="loteSalvar" type="button">Lançar</button>
</div>
</div>
</div>

<?php admin_nav('calendario'); ?>
<?php app_mobile_footer($csrf); ?>
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
    const MESES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

    /* Datas pelo relógio LOCAL. toISOString() devolve UTC: depois das 21h no
       Brasil já era "amanhã" (e no último dia do mês, o mês seguinte). */
    const doisDig = (n) => String(n).padStart(2, '0');
    const dataLocal = (d) => d.getFullYear() + '-' + doisDig(d.getMonth() + 1) + '-' + doisDig(d.getDate());
    const mesLocal = (ano, indiceMes) => dataLocal(new Date(ano, indiceMes, 1)).slice(0, 7);
    const hojeStr = dataLocal(new Date());
    const hojeYm = hojeStr.slice(0, 7);
    let funcionarioId = 0;
    let mes = hojeYm;
    let diaSelecionado = '';
    let marcados = {};
    let modo = 'alterar';   // alterar | criar
    let registroId = 0;
    let ocupado = false;

    /* ---------------------- preenchimento em lote ----------------------
       Marca vários dias no calendário e lança as batidas de todos de uma vez,
       usando os horários cadastrados no cargo da pessoa. */
    let emLote = false;
    let diasLote = {};        // { '2026-08-03': true }
    let horariosCargo = [];   // turnos do cargo, vindos do servidor
    let nomeCargo = '';

    function escapar(t) {
        const d = document.createElement('div');
        d.textContent = String(t == null ? '' : t);
        return d.innerHTML;
    }

    async function carregar(data) {
        if (!funcionarioId || ocupado) return;
        ocupado = true;
        try {
            const r = await post({ acao: 'carregar', funcionario_id: funcionarioId, mes, data: data || '' });
            marcados = r.dias || {};
            horariosCargo = r.horarios || [];
            nomeCargo = r.cargo || '';
            desenharCalendario();
            if (data) {
                diaSelecionado = data;
                desenharBatidas(r.batidas);
            }
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
        }
    }

    function desenharCalendario() {
        const [ano, m] = mes.split('-').map(Number);
        $('#mesNome').textContent = MESES[m - 1] + ' ' + ano;
        $('#mesProximo').disabled = mes >= hojeYm;

        const primeiroDiaSemana = new Date(ano, m - 1, 1).getDay();
        const totalDias = new Date(ano, m, 0).getDate();

        let html = '';
        for (let i = 0; i < primeiroDiaSemana; i++) html += '<div></div>';

        for (let d = 1; d <= totalDias; d++) {
            const data = `${mes}-${String(d).padStart(2, '0')}`;
            const info = marcados[data];
            /* No modo lote quem manda é a marcação do lote, não o dia aberto. */
            const selecionado = emLote ? !!diasLote[data] : (data === diaSelecionado);
            const futuro = emLote && data > hojeStr;

            let cor = 'bg-primary';
            if (info && info.aberto) cor = 'bg-error';
            else if (info && info.ajustado) cor = 'bg-amber-500';

            let estilo;
            if (selecionado) estilo = 'bg-primary text-on-primary font-bold';
            else if (futuro) estilo = 'text-outline';
            else estilo = 'text-on-surface hover:bg-surface-variant';

            html += `<button class="aspect-square flex items-center justify-center font-body-md text-body-md rounded-full relative transition-colors ${estilo}"
                data-dia="${data}" type="button"${futuro ? ' disabled' : ''}>${d}${
                info && !selecionado ? `<span class="absolute bottom-0.5 w-1 h-1 rounded-full ${cor}"></span>` : ''
            }</button>`;
        }
        $('#gradeDias').innerHTML = html;
    }

    function desenharBatidas(batidas) {
        $('#blocoDia').classList.remove('hidden');
        $('#blocoDia').classList.add('flex');
        const [a, m, d] = diaSelecionado.split('-');
        $('#tituloDia').textContent = `Batidas de ${d}/${m}`;

        if (!batidas.length) {
            $('#listaBatidas').innerHTML = '<div class="cartao rounded-xl p-5 text-center"><p class="font-body-md text-body-md text-secondary">Nenhuma batida neste dia.</p></div>';
            return;
        }

        /* Cada linha = botão de editar + (se houver) link da foto, lado a lado.
           Link dentro de <button> é HTML inválido e falha em alguns navegadores. */
        $('#listaBatidas').innerHTML = batidas.map((b) => `
            <div class="flex items-stretch gap-1">
                <button class="linha-toque cartao rounded-xl p-3 flex items-center justify-between gap-2 text-left flex-1 min-w-0 ${b.ajustado ? 'border-amber-500/40' : ''}"
                        data-registro="${b.id}" data-hora="${b.hora}" data-label="${escapar(b.label)}" type="button">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary flex-shrink-0">
                            <span class="material-symbols-outlined text-[18px]">${ICONES[b.tipo] || 'schedule'}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="font-body-lg text-body-lg text-on-surface font-medium tabular-nums">${b.hora}</p>
                            <p class="font-label-sm text-label-sm text-secondary truncate">${escapar(b.label)}${b.ajustado ? ' · ajustado' : ''}</p>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-primary text-[18px] flex-shrink-0">edit</span>
                </button>
                ${b.tem_foto ? `<a class="cartao rounded-xl w-11 flex items-center justify-center text-secondary hover:text-primary flex-shrink-0"
                        href="foto.php?id=${b.id}" target="_blank" rel="noopener" aria-label="Ver foto da batida">
                    <span class="material-symbols-outlined text-[20px]">photo_camera</span>
                </a>` : ''}
            </div>`).join('');
    }

    /* ------------------------------ folha ------------------------------ */
    function abrirFolha(tipo, dados) {
        modo = tipo;
        registroId = dados.registroId || 0;
        $('#ajTitulo').textContent = tipo === 'criar' ? 'Incluir batida' : 'Ajustar ' + dados.label;
        $('#ajBlocoTipo').classList.toggle('hidden', tipo !== 'criar');
        $('#ajBlocoTipo').classList.toggle('flex', tipo === 'criar');
        $('#ajExcluir').classList.toggle('hidden', tipo === 'criar');
        $('#ajHora').value = dados.hora || '';
        $('#ajMotivo').value = '';
        $('#ajFundo').classList.add('aberto');
        $('#ajFolha').classList.add('aberto');
    }

    function fecharFolha() {
        $('#ajFundo').classList.remove('aberto');
        $('#ajFolha').classList.remove('aberto');
    }

    async function enviar(dados) {
        if (ocupado) return;
        ocupado = true;
        $('#ajSalvar').disabled = true;
        try {
            const r = await post(Object.assign({ funcionario_id: funcionarioId }, dados));
            toast(r.mensagem);
            vibrar(40);
            fecharFolha();
            desenharBatidas(r.batidas);
            /* Libera ANTES de recarregar: carregar() recusa quando 'ocupado' está
               ligado, e os pontinhos (falta saída / ajustado) ficavam velhos. */
            ocupado = false;
            await carregar(diaSelecionado);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
            $('#ajSalvar').disabled = false;
        }
    }

    /* -------------------- folha: preencher vários dias -------------------- */
    function diasLoteOrdenados() {
        return Object.keys(diasLote).filter((d) => diasLote[d]).sort();
    }

    function atualizarBarraLote() {
        const total = diasLoteOrdenados().length;
        $('#loteContagem').textContent = total
            ? total + (total === 1 ? ' dia marcado.' : ' dias marcados.')
            : 'Toque nos dias que quer preencher.';
        $('#loteContinuar').disabled = total === 0;
    }

    function entrarSairDoLote(ligar) {
        emLote = ligar;
        diasLote = {};
        $('#btnLoteTexto').textContent = ligar ? 'CANCELAR SELEÇÃO' : 'PREENCHER VÁRIOS DIAS';
        $('#btnLoteIcone').textContent = ligar ? 'close' : 'checklist';
        $('#barraLote').classList.toggle('hidden', !ligar);
        $('#barraLote').classList.toggle('flex', ligar);
        /* Enquanto marca os dias, a lista de batidas de um dia só atrapalha. */
        $('#blocoDia').classList.toggle('hidden', ligar);
        atualizarBarraLote();
        desenharCalendario();
    }

    /* Monta as linhas de batida a partir do horário do cargo escolhido. É aqui
     * que "puxar o horário do cargo" acontece: nada é chutado, tudo vem do que
     * está cadastrado em Configurações → Cargos. */
    function montarLinhasDoTurno() {
        const t = horariosCargo[Number($('#loteTurno').value) || 0];
        if (!t) return;

        const pausaSaida = t.pausa_tipo === 'janta' ? 'saida_janta' : 'saida_almoco';
        const pausaVolta = t.pausa_tipo === 'janta' ? 'volta_janta' : 'volta_almoco';
        const temPausa = t.intervalo_min > 0;

        /* Cargo sem intervalo cadastrado não tem hora de pausa para sugerir: as
         * linhas aparecem vazias e desmarcadas, para o admin preencher só se
         * quiser. Sugerir 12:00-12:00 criaria um intervalo de duração zero. */
        const linhas = [
            { tipo: 'entrada', label: 'Entrada', hora: t.entrada, marcado: true },
            { tipo: pausaSaida, label: pausaSaida === 'saida_janta' ? 'Saída para janta' : 'Saída para almoço', hora: temPausa ? t.pausa_inicio : '', marcado: temPausa },
            { tipo: pausaVolta, label: pausaVolta === 'volta_janta' ? 'Volta da janta' : 'Volta do almoço', hora: temPausa ? t.pausa_fim : '', marcado: temPausa },
            { tipo: 'saida', label: 'Saída final', hora: t.saida, marcado: true },
            { tipo: 'saida_descanso', label: 'Saída para descanso', hora: '', marcado: false },
            { tipo: 'volta_descanso', label: 'Volta do descanso', hora: '', marcado: false }
        ];

        /* A largura do campo de hora vai no style: a classe .campo do painel usa
         * width:100% e venceria qualquer classe do Tailwind aqui, empurrando o
         * nome da batida para fora da linha. */
        $('#loteListaTipos').innerHTML = linhas.map((l) => `
            <label class="cartao rounded-xl p-2.5 flex items-center gap-2.5">
                <input class="w-5 h-5 flex-shrink-0" data-tipo="${l.tipo}" type="checkbox"${l.marcado ? ' checked' : ''}/>
                <span class="font-body-md text-body-md text-on-surface flex-1 min-w-0 truncate">${escapar(l.label)}</span>
                <input class="campo font-body-lg text-body-lg tabular-nums flex-shrink-0" data-hora="${l.tipo}"
                       style="width:100px;padding-left:.4rem;padding-right:.4rem" type="time" value="${l.hora}"/>
            </label>`).join('');

        $('#loteInfoTexto').innerHTML = 'Cargo <b>' + escapar(nomeCargo) + '</b> · entra <b>' + escapar(t.entrada)
            + '</b>, sai <b>' + escapar(t.saida) + '</b> · jornada de ' + escapar(t.jornada_texto)
            + (t.noturno ? ' · vira a noite (a saída é gravada no dia seguinte)' : '');
    }

    function abrirFolhaLote() {
        const dias = diasLoteOrdenados();
        if (!dias.length) return;

        if (!horariosCargo.length) {
            toast('O cargo dessa pessoa não tem horário cadastrado. Cadastre em Configurações → Cargos.', 'error');
            return;
        }

        const primeiro = dias[0].split('-').reverse().join('/');
        const ultimo = dias[dias.length - 1].split('-').reverse().join('/');
        $('#loteResumoDias').textContent = dias.length === 1
            ? 'Dia ' + primeiro
            : dias.length + ' dias, de ' + primeiro + ' a ' + ultimo + '.';

        $('#loteTurno').innerHTML = horariosCargo
            .map((t, i) => `<option value="${i}">${escapar(t.descricao)}</option>`).join('');
        $('#loteBlocoTurno').classList.toggle('hidden', horariosCargo.length < 2);
        $('#loteBlocoTurno').classList.toggle('flex', horariosCargo.length >= 2);

        montarLinhasDoTurno();
        $('#loteMotivo').value = '';
        $('#loteFundo').classList.add('aberto');
        $('#loteFolha').classList.add('aberto');
    }

    function fecharFolhaLote() {
        $('#loteFundo').classList.remove('aberto');
        $('#loteFolha').classList.remove('aberto');
    }

    async function enviarLote() {
        const dias = diasLoteOrdenados();
        const motivo = $('#loteMotivo').value.trim();
        if (motivo.length < 5) { toast('Escreva o motivo do lançamento.', 'error'); return; }

        const batidas = [];
        const marcadas = $('#loteListaTipos').querySelectorAll('input[data-tipo]:checked');
        for (let i = 0; i < marcadas.length; i++) {
            const tipo = marcadas[i].dataset.tipo;
            const campo = $('#loteListaTipos').querySelector('input[data-hora="' + tipo + '"]');
            const hora = campo ? campo.value : '';
            if (!hora) { toast('Informe o horário de todas as batidas marcadas.', 'error'); return; }
            batidas.push({ tipo: tipo, hora: hora });
        }
        if (!batidas.length) { toast('Marque pelo menos uma batida.', 'error'); return; }

        if (ocupado) return;
        ocupado = true;
        $('#loteSalvar').disabled = true;
        try {
            const r = await post({
                acao: 'preencher',
                funcionario_id: funcionarioId,
                dias: dias.join(','),
                batidas: JSON.stringify(batidas),
                motivo: motivo,
                mes: mes,
                data: diaSelecionado
            });
            toast(r.mensagem);
            vibrar(40);
            fecharFolhaLote();
            entrarSairDoLote(false);
            marcados = r.dias || {};
            desenharCalendario();
            if (diaSelecionado) desenharBatidas(r.batidas || []);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
            $('#loteSalvar').disabled = false;
        }
    }

    /* ----------------------------- eventos ----------------------------- */
    $('#btnLote').addEventListener('click', () => {
        if (!funcionarioId) { toast('Escolha o funcionário primeiro.', 'error'); return; }
        entrarSairDoLote(!emLote);
    });

    $('#loteLimpar').addEventListener('click', () => {
        diasLote = {};
        atualizarBarraLote();
        desenharCalendario();
    });

    /* Atalho para o mês inteiro de segunda a sexta, sem pegar dia futuro. */
    $('#loteSemana').addEventListener('click', () => {
        const partes = mes.split('-').map(Number);
        const total = new Date(partes[0], partes[1], 0).getDate();
        for (let d = 1; d <= total; d++) {
            const data = mes + '-' + String(d).padStart(2, '0');
            const semana = new Date(partes[0], partes[1] - 1, d).getDay();
            if (semana >= 1 && semana <= 5 && data <= hojeStr) diasLote[data] = true;
        }
        atualizarBarraLote();
        desenharCalendario();
    });

    $('#loteContinuar').addEventListener('click', abrirFolhaLote);
    $('#loteCancelar').addEventListener('click', fecharFolhaLote);
    $('#loteFundo').addEventListener('click', fecharFolhaLote);
    $('#loteTurno').addEventListener('change', montarLinhasDoTurno);
    $('#loteSalvar').addEventListener('click', enviarLote);

    $('#selFuncionario').addEventListener('change', (evento) => {
        funcionarioId = Number(evento.target.value) || 0;
        diaSelecionado = '';
        /* Trocou de pessoa: a seleção de dias da anterior não vale mais. */
        if (emLote) entrarSairDoLote(false);
        $('#blocoDia').classList.add('hidden');
        if (!funcionarioId) {
            $('#blocoCalendario').classList.add('hidden');
            $('#aviso').classList.remove('hidden');
            return;
        }
        $('#blocoCalendario').classList.remove('hidden');
        $('#aviso').classList.add('hidden');
        mes = hojeYm;
        carregar('');
    });

    /* Mudar de mês zera a seleção do lote: marcar dias de meses diferentes na
       mesma leva confunde na hora de conferir o que foi lançado. */
    function irParaMes(novoMes) {
        mes = novoMes;
        diaSelecionado = '';
        if (emLote) {
            diasLote = {};
            atualizarBarraLote();
        }
        $('#blocoDia').classList.add('hidden');
        carregar('');
    }

    $('#mesAnterior').addEventListener('click', () => {
        const [a, m] = mes.split('-').map(Number);
        irParaMes(mesLocal(a, m - 2));
    });

    $('#mesProximo').addEventListener('click', () => {
        if (mes >= hojeYm) return;
        const [a, m] = mes.split('-').map(Number);
        irParaMes(mesLocal(a, m));
    });

    $('#gradeDias').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-dia]');
        if (!botao) return;

        /* No modo lote o toque marca/desmarca o dia em vez de abrir as batidas. */
        if (emLote) {
            const data = botao.dataset.dia;
            if (diasLote[data]) delete diasLote[data];
            else diasLote[data] = true;
            atualizarBarraLote();
            desenharCalendario();
            return;
        }
        carregar(botao.dataset.dia);
    });

    $('#listaBatidas').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button[data-registro]');
        if (botao) {
            abrirFolha('alterar', {
                registroId: botao.dataset.registro,
                hora: botao.dataset.hora,
                label: botao.dataset.label
            });
        }
    });

    $('#btnAdicionar').addEventListener('click', () => {
        if (!diaSelecionado) { toast('Escolha um dia no calendário.', 'error'); return; }
        abrirFolha('criar', {});
    });

    $('#ajCancelar').addEventListener('click', fecharFolha);
    $('#ajFundo').addEventListener('click', fecharFolha);

    $('#ajSalvar').addEventListener('click', () => {
        const hora = $('#ajHora').value;
        const motivo = $('#ajMotivo').value.trim();
        if (!hora) { toast('Informe o horário.', 'error'); return; }
        if (motivo.length < 5) { toast('Escreva o motivo do ajuste.', 'error'); return; }

        if (modo === 'criar') {
            enviar({ acao: 'criar', data: diaSelecionado, tipo: $('#ajTipo').value, hora, motivo });
        } else {
            enviar({ acao: 'alterar', registro_id: registroId, hora, motivo });
        }
    });

    $('#ajExcluir').addEventListener('click', () => {
        const motivo = $('#ajMotivo').value.trim();
        if (motivo.length < 5) { toast('Escreva o motivo antes de excluir.', 'error'); return; }
        enviar({ acao: 'excluir', registro_id: registroId, motivo });
    });
})();
</script>
<?php app_mobile_end(); ?>
