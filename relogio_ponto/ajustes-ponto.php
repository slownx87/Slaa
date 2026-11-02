<?php
require_once __DIR__ . '/_config.php';
require_admin();

$erro = '';
$tiposPonto = ['entrada','saida_almoco','volta_almoco','saida_janta','volta_janta','saida_descanso','volta_descanso','saida'];

function ajuste_data_valida(?string $data, ?string $fallback = null): string
{
    $data = trim((string)$data);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return $data;
    }
    return $fallback ?: today_sp();
}

function ajuste_mes_valido(?string $mes, ?string $fallback = null): string
{
    $mes = trim((string)$mes);
    if (preg_match('/^\d{4}-\d{2}$/', $mes)) {
        return $mes;
    }
    return $fallback ?: date('Y-m');
}

function ajuste_tipo_valido(string $tipo): bool
{
    return in_array($tipo, ['entrada','saida_almoco','volta_almoco','saida_janta','volta_janta','saida_descanso','volta_descanso','saida'], true);
}

function ajuste_datetime_valido(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    $ts = strtotime($valor);
    if (!$ts) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function ajuste_get_funcionario_autorizado(int $funcionarioId): array
{
    if ($funcionarioId <= 0) {
        throw new RuntimeException('Selecione um funcionário.');
    }

    $func = get_funcionario($funcionarioId);
    if (!$func) {
        throw new RuntimeException('Funcionário não encontrado.');
    }

    if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null && admin_condominio_id() !== (int)$func['condominio_id']) {
        throw new RuntimeException('Sem permissão para ajustar esse funcionário.');
    }

    return $func;
}

function ajuste_redirect(int $funcionarioId, string $dataPonto, string $extra = ''): void
{
    $url = 'ajustes-ponto.php?funcionario_id=' . $funcionarioId . '&data_ponto=' . urlencode($dataPonto) . '&mes=' . urlencode(substr($dataPonto, 0, 7));
    if ($extra !== '') {
        $url .= '&' . ltrim($extra, '&');
    }
    redirect($url);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $acao = trim((string)($_POST['acao'] ?? 'alterar'));

        if ($acao === 'alterar') {
            $registroId = (int)($_POST['registro_id'] ?? 0);
            $novaDataHora = trim((string)($_POST['data_hora_nova'] ?? ''));
            $novaDataPonto = ajuste_data_valida($_POST['data_ponto_nova'] ?? null, '');
            $novoTipo = trim((string)($_POST['tipo_novo'] ?? ''));
            $motivo = trim((string)($_POST['motivo'] ?? ''));

            if ($registroId <= 0 || $novaDataHora === '' || $novaDataPonto === '' || $novoTipo === '' || $motivo === '') {
                throw new RuntimeException('Preencha todos os campos do ajuste.');
            }
            if (!ajuste_tipo_valido($novoTipo)) {
                throw new RuntimeException('Tipo de batida inválido.');
            }

            $dt = ajuste_datetime_valido($novaDataHora);
            if (!$dt) {
                throw new RuntimeException('Data e hora inválidas.');
            }
            $dp = $novaDataPonto;

            $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE id = ?");
            $stmt->execute([$registroId]);
            $r = $stmt->fetch();
            if (!$r) throw new RuntimeException('Registro não encontrado.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null && admin_condominio_id() !== (int)$r['condominio_id']) {
                throw new RuntimeException('Sem permissão para ajustar esse registro.');
            }

            $motivoCompleto = $motivo . ' | Dia da jornada/PDF: ' . $dp;

            db()->beginTransaction();
            db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'alterar', ?, ?, ?, ?, ?, ?)")
                ->execute([$registroId, $r['funcionario_id'], $r['condominio_id'], $r['data_hora'], $dt, $r['tipo'], $novoTipo, $motivoCompleto, admin_id()]);
            db()->prepare("UPDATE ponto_registros SET data_hora = ?, data_ponto = ?, tipo = ?, observacao = CONCAT(COALESCE(observacao,''), '\n[Ajustado pelo admin: ', ?, ']') WHERE id = ?")
                ->execute([$dt, $dp, $novoTipo, $motivoCompleto, $registroId]);
            db()->commit();

            recalc_resumo((int)$r['funcionario_id'], (string)$r['data_ponto']);
            recalc_resumo((int)$r['funcionario_id'], $dp);
            log_action('ajustar_ponto', 'Ajustou registro ID ' . $registroId, (int)$r['funcionario_id'], (int)$r['condominio_id']);
            flash_set('success', 'Registro ajustado.');
            ajuste_redirect((int)$r['funcionario_id'], $dp);
        }

        if ($acao === 'criar_lote') {
            $funcionarioId = (int)($_POST['funcionario_id'] ?? 0);
            $dataPonto = ajuste_data_valida($_POST['data_ponto'] ?? null, today_sp());
            $motivo = trim((string)($_POST['motivo_lote'] ?? ''));
            $tipos = $_POST['tipo_lote'] ?? [];
            $horarios = $_POST['data_hora_lote'] ?? [];
            $editIds = $_POST['edit_id'] ?? [];
            $editHoras = $_POST['edit_hora'] ?? [];

            $func = ajuste_get_funcionario_autorizado($funcionarioId);
            if ($motivo === '') {
                throw new RuntimeException('Informe o motivo para adicionar/alterar batida manual.');
            }
            if (!is_array($tipos) || !is_array($horarios) || !is_array($editIds) || !is_array($editHoras)) {
                throw new RuntimeException('Dados de criação inválidos.');
            }

            // Novas batidas (slots ainda sem registro).
            $novos = [];
            foreach ($horarios as $idx => $horaRaw) {
                $horaRaw = trim((string)$horaRaw);
                if ($horaRaw === '') {
                    continue;
                }
                $tipo = isset($tipos[$idx]) ? trim((string)$tipos[$idx]) : '';
                if (!ajuste_tipo_valido($tipo)) {
                    throw new RuntimeException('Tipo de batida inválido em uma das linhas.');
                }
                $dt = ajuste_datetime_valido($horaRaw);
                if (!$dt) {
                    throw new RuntimeException('Data e hora inválidas em uma das linhas.');
                }
                $novos[] = ['tipo' => $tipo, 'data_hora' => $dt];
            }

            // Alterações de batidas que já existem (edita o horário direto na área de adicionar).
            $edicoes = [];
            foreach ($editIds as $idx => $ridRaw) {
                $rid = (int)$ridRaw;
                if ($rid <= 0) {
                    continue;
                }
                $horaRaw = isset($editHoras[$idx]) ? trim((string)$editHoras[$idx]) : '';
                if ($horaRaw === '') {
                    continue; // vazio = não altera essa batida
                }
                $dt = ajuste_datetime_valido($horaRaw);
                if (!$dt) {
                    throw new RuntimeException('Data e hora inválidas em uma batida existente.');
                }
                $edicoes[$rid] = $dt;
            }

            if (!$novos && !$edicoes) {
                throw new RuntimeException('Preencha ou altere pelo menos uma data/hora.');
            }

            usort($novos, function ($a, $b) {
                return strcmp($a['data_hora'], $b['data_hora']);
            });

            $obs = 'Batida adicionada manualmente pelo admin em ' . now_sp() . '. Motivo: ' . $motivo;
            $obsEdit = '[Ajustado pelo admin: ' . $motivo . ' | Dia da jornada/PDF: ' . $dataPonto . ']';
            $ids = [];
            $alterados = 0;
            $diasAfetados = [$dataPonto => true];

            db()->beginTransaction();
            foreach ($novos as $novo) {
                $stmt = db()->prepare("INSERT INTO ponto_registros (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    (int)$func['id'],
                    (int)$func['condominio_id'],
                    $novo['tipo'],
                    $dataPonto,
                    $novo['data_hora'],
                    $obs,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
                $novoId = (int)db()->lastInsertId();
                $ids[] = $novoId;
                db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'criar', NULL, ?, NULL, ?, ?, ?)")
                    ->execute([$novoId, (int)$func['id'], (int)$func['condominio_id'], $novo['data_hora'], $novo['tipo'], $motivo . ' | Dia da jornada/PDF: ' . $dataPonto, admin_id()]);
            }

            foreach ($edicoes as $rid => $dt) {
                $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE id = ?");
                $stmt->execute([$rid]);
                $r = $stmt->fetch();
                if (!$r) {
                    continue;
                }
                // Segurança: só altera batida do próprio funcionário selecionado.
                if ((int)$r['funcionario_id'] !== (int)$func['id']) {
                    continue;
                }
                if ((string)$r['data_hora'] === $dt) {
                    continue; // nada mudou
                }
                db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'alterar', ?, ?, ?, ?, ?, ?)")
                    ->execute([$rid, (int)$r['funcionario_id'], (int)$r['condominio_id'], $r['data_hora'], $dt, $r['tipo'], $r['tipo'], $motivo . ' | Dia da jornada/PDF: ' . $dataPonto, admin_id()]);
                db()->prepare("UPDATE ponto_registros SET data_hora = ?, observacao = CONCAT(COALESCE(observacao,''), '\n', ?) WHERE id = ?")
                    ->execute([$dt, $obsEdit, $rid]);
                $diasAfetados[(string)$r['data_ponto']] = true;
                $alterados++;
            }
            db()->commit();

            foreach (array_keys($diasAfetados) as $dia) {
                recalc_resumo((int)$func['id'], (string)$dia);
            }
            log_action('criar_batida_manual', 'Adicionou IDs ' . implode(',', $ids) . ' / alterou ' . $alterados . ' batida(s) no dia ' . $dataPonto, (int)$func['id'], (int)$func['condominio_id']);
            flash_set('success', count($ids) . ' batida(s) adicionada(s) e ' . $alterados . ' alterada(s). Dia recalculado.');
            ajuste_redirect((int)$func['id'], $dataPonto, 'novo=1');
        }

        if ($acao === 'criar_massa') {
            $funcionarioId = (int)($_POST['funcionario_id'] ?? 0);
            $modo = trim((string)($_POST['modo_massa'] ?? 'periodo'));
            $escala = trim((string)($_POST['escala'] ?? ''));
            $motivo = trim((string)($_POST['motivo_massa'] ?? ''));

            $func = ajuste_get_funcionario_autorizado($funcionarioId);
            if (!in_array($modo, ['periodo', 'dias'], true)) {
                throw new RuntimeException('Modo de preenchimento inválido.');
            }
            if (!in_array($escala, ['diurno', 'noturno'], true)) {
                throw new RuntimeException('Escala inválida.');
            }
            if ($motivo === '') {
                throw new RuntimeException('Informe o motivo do preenchimento em massa.');
            }

            // Monta a lista de dias que vão receber batida.
            $diasParaCriar = [];
            if ($modo === 'dias') {
                $diasEspecificos = $_POST['dias_especificos'] ?? [];
                if (!is_array($diasEspecificos)) {
                    $diasEspecificos = [];
                }
                foreach ($diasEspecificos as $diaRaw) {
                    $diaRaw = trim((string)$diaRaw);
                    if ($diaRaw === '') {
                        continue;
                    }
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $diaRaw) || !strtotime($diaRaw)) {
                        throw new RuntimeException('Data inválida na lista de dias selecionados: ' . $diaRaw);
                    }
                    $diasParaCriar[$diaRaw] = true;
                }
                $diasParaCriar = array_keys($diasParaCriar);
                sort($diasParaCriar);
                if (!$diasParaCriar) {
                    throw new RuntimeException('Adicione pelo menos um dia na lista de dias selecionados.');
                }
                if (count($diasParaCriar) > 62) {
                    throw new RuntimeException('Muitos dias de uma vez: preencha no máximo 62 dias por vez.');
                }
                $descricaoPeriodo = 'dias selecionados (' . implode(', ', $diasParaCriar) . ')';
            } else {
                $dataInicio = ajuste_data_valida($_POST['data_inicio'] ?? null, '');
                $dataFim = ajuste_data_valida($_POST['data_fim'] ?? null, '');
                $diasSemana = $_POST['dias_semana'] ?? [];
                if ($dataInicio === '' || $dataFim === '') {
                    throw new RuntimeException('Informe a data inicial e a data final do período.');
                }
                if ($dataFim < $dataInicio) {
                    throw new RuntimeException('A data final não pode ser antes da data inicial.');
                }
                if (!is_array($diasSemana)) {
                    $diasSemana = [];
                }
                $diasSemana = array_map('intval', $diasSemana);
                if (!$diasSemana) {
                    throw new RuntimeException('Selecione pelo menos um dia da semana.');
                }
                $tsInicio = strtotime($dataInicio);
                $tsFim = strtotime($dataFim);
                if (($tsFim - $tsInicio) > 62 * 86400) {
                    throw new RuntimeException('Período muito longo: preencha no máximo 62 dias por vez.');
                }
                for ($ts = $tsInicio; $ts !== false && $ts <= $tsFim; $ts = strtotime('+1 day', $ts)) {
                    $w = (int)date('w', $ts); // 0=dom ... 6=sáb
                    if (in_array($w, $diasSemana, true)) {
                        $diasParaCriar[] = date('Y-m-d', $ts);
                    }
                }
                $descricaoPeriodo = 'de ' . $dataInicio . ' a ' . $dataFim;
            }

            $criadosDias = 0;
            $pulados = 0;
            $diasAfetados = [];

            $stmtTem = db()->prepare("SELECT COUNT(*) FROM ponto_registros WHERE funcionario_id = ? AND data_ponto = ?");
            $stmtIns = db()->prepare("INSERT INTO ponto_registros (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtAj = db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'criar', NULL, ?, NULL, ?, ?, ?)");

            db()->beginTransaction();
            foreach ($diasParaCriar as $dia) {
                $w = (int)date('w', strtotime($dia)); // 0=dom ... 6=sáb

                // Não duplica: dia que já tem batida é pulado.
                $stmtTem->execute([(int)$func['id'], $dia]);
                if ((int)$stmtTem->fetchColumn() > 0) {
                    $pulados++;
                    continue;
                }

                if ($escala === 'noturno') {
                    $batidas = [
                        ['tipo' => 'entrada', 'data_hora' => $dia . ' 18:00:00'],
                        ['tipo' => 'saida', 'data_hora' => date('Y-m-d', strtotime($dia . ' +1 day')) . ' 07:00:00'],
                    ];
                } elseif ($w === 6) {
                    $batidas = [
                        ['tipo' => 'entrada', 'data_hora' => $dia . ' 08:00:00'],
                        ['tipo' => 'saida', 'data_hora' => $dia . ' 12:00:00'],
                    ];
                } else {
                    $batidas = [
                        ['tipo' => 'entrada', 'data_hora' => $dia . ' 08:00:00'],
                        ['tipo' => 'saida', 'data_hora' => $dia . ' 17:00:00'],
                    ];
                }

                $obs = 'Batida preenchida em massa pelo admin em ' . now_sp() . '. Motivo: ' . $motivo;
                foreach ($batidas as $b) {
                    $stmtIns->execute([
                        (int)$func['id'],
                        (int)$func['condominio_id'],
                        $b['tipo'],
                        $dia,
                        $b['data_hora'],
                        $obs,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ]);
                    $novoId = (int)db()->lastInsertId();
                    $stmtAj->execute([$novoId, (int)$func['id'], (int)$func['condominio_id'], $b['data_hora'], $b['tipo'], $motivo . ' | Preenchimento em massa (' . $escala . ') | Dia da jornada/PDF: ' . $dia, admin_id()]);
                }
                $diasAfetados[] = $dia;
                $criadosDias++;
            }
            db()->commit();

            foreach ($diasAfetados as $dia) {
                recalc_resumo((int)$func['id'], $dia);
            }
            log_action('criar_ponto_massa', 'Preencheu em massa ' . $criadosDias . ' dia(s) na escala ' . $escala . ' ' . $descricaoPeriodo . ($pulados ? ' (pulou ' . $pulados . ' dia(s) que já tinham batida)' : ''), (int)$func['id'], (int)$func['condominio_id']);
            flash_set('success', 'Preenchimento em massa: ' . $criadosDias . ' dia(s) criado(s)' . ($pulados ? ', ' . $pulados . ' dia(s) pulado(s) por já terem batida' : '') . '.');
            ajuste_redirect((int)$func['id'], $diasParaCriar ? (string)$diasParaCriar[0] : today_sp());
        }

        if ($acao === 'excluir_ponto') {
            $registroId = (int)($_POST['registro_id'] ?? 0);
            if ($registroId <= 0) {
                throw new RuntimeException('Batida inválida.');
            }
            $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE id = ?");
            $stmt->execute([$registroId]);
            $r = $stmt->fetch();
            if (!$r) {
                throw new RuntimeException('Batida não encontrada.');
            }
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null && admin_condominio_id() !== (int)$r['condominio_id']) {
                throw new RuntimeException('Sem permissão para excluir essa batida.');
            }
            db()->beginTransaction();
            db()->prepare("DELETE FROM ponto_ajustes WHERE registro_id = ?")->execute([$registroId]);
            db()->prepare("DELETE FROM ponto_registros WHERE id = ?")->execute([$registroId]);
            db()->commit();
            recalc_resumo((int)$r['funcionario_id'], (string)$r['data_ponto']);
            log_action('excluir_ponto', 'Excluiu batida ID ' . $registroId . ' (' . $r['tipo'] . ' em ' . $r['data_hora'] . ')', (int)$r['funcionario_id'], (int)$r['condominio_id']);
            flash_set('success', 'Batida excluída.');
            ajuste_redirect((int)$r['funcionario_id'], (string)$r['data_ponto']);
        }

        if ($acao === 'excluir_dia') {
            $funcionarioId = (int)($_POST['funcionario_id'] ?? 0);
            $dataPonto = ajuste_data_valida($_POST['data_ponto'] ?? null, today_sp());
            $func = ajuste_get_funcionario_autorizado($funcionarioId);
            $stmt = db()->prepare("SELECT id FROM ponto_registros WHERE funcionario_id = ? AND data_ponto = ?");
            $stmt->execute([(int)$func['id'], $dataPonto]);
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
            if (!$ids) {
                throw new RuntimeException('Nenhuma batida nesse dia para excluir.');
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->beginTransaction();
            db()->prepare("DELETE FROM ponto_ajustes WHERE registro_id IN ($ph)")->execute($ids);
            db()->prepare("DELETE FROM ponto_registros WHERE id IN ($ph)")->execute($ids);
            db()->commit();
            recalc_resumo((int)$func['id'], $dataPonto);
            log_action('excluir_dia_ponto', 'Excluiu ' . count($ids) . ' batida(s) do dia ' . $dataPonto . ' (IDs ' . implode(',', $ids) . ')', (int)$func['id'], (int)$func['condominio_id']);
            flash_set('success', count($ids) . ' batida(s) do dia excluída(s).');
            ajuste_redirect((int)$func['id'], $dataPonto);
        }
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $erro = $e->getMessage();
}

$filtroFuncionarioId = (int)($_GET['funcionario_id'] ?? 0);
$filtroDataPonto = ajuste_data_valida($_GET['data_ponto'] ?? null, today_sp());
$filtroMes = ajuste_mes_valido($_GET['mes'] ?? null, substr($filtroDataPonto, 0, 7));

// Se o dia selecionado estiver em outro mês, prioriza o mês do dia selecionado.
if (substr($filtroDataPonto, 0, 7) !== $filtroMes) {
    $filtroMes = substr($filtroDataPonto, 0, 7);
}

if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
    $funcionarios = db()->query("SELECT f.id, f.nome, co.nome AS condominio_nome FROM ponto_funcionarios f INNER JOIN ponto_condominios co ON co.id = f.condominio_id ORDER BY f.nome, co.nome")->fetchAll();
} else {
    $stmtFuncs = db()->prepare("SELECT f.id, f.nome, co.nome AS condominio_nome FROM ponto_funcionarios f INNER JOIN ponto_condominios co ON co.id = f.condominio_id WHERE f.condominio_id = ? ORDER BY f.nome");
    $stmtFuncs->execute([admin_condominio_id()]);
    $funcionarios = $stmtFuncs->fetchAll();
}

$funcionarioSelecionado = null;
if ($filtroFuncionarioId > 0) {
    try {
        $funcionarioSelecionado = ajuste_get_funcionario_autorizado($filtroFuncionarioId);
    } catch (Throwable $e) {
        $erro = $erro ?: $e->getMessage();
        $filtroFuncionarioId = 0;
    }
}

$inicioMes = $filtroMes . '-01';
$fimMes = date('Y-m-t', strtotime($inicioMes));
$mesAnterior = date('Y-m', strtotime($inicioMes . ' -1 month'));
$mesProximo = date('Y-m', strtotime($inicioMes . ' +1 month'));

$registrosMesPorDia = [];
$diasInfo = [];
if ($funcionarioSelecionado) {
    // Calendário agora puxa a jornada real do dia, inclusive saída da madrugada salva no dia seguinte.
    $tsDia = strtotime($inicioMes);
    $tsFim = strtotime($fimMes);
    while ($tsDia !== false && $tsDia <= $tsFim) {
        $dia = date('Y-m-d', $tsDia);
        $lista = registros_jornada_dia_com_turno_noite((int)$funcionarioSelecionado['id'], $dia);
        if ($lista) {
            $registrosMesPorDia[$dia] = $lista;
            $entradas = 0;
            $saidas = 0;
            foreach ($lista as $item) {
                if ($item['tipo'] === 'entrada') $entradas++;
                if ($item['tipo'] === 'saida') $saidas++;
            }
            $status = 'aberto';
            if (jornada_fechada($lista)) {
                $status = 'fechado';
            }
            if (open_interval($lista)) {
                $status = 'incompleto';
            }
            $diasInfo[$dia] = [
                'total' => count($lista),
                'entradas' => $entradas,
                'saidas' => $saidas,
                'status' => $status,
                'primeira' => $lista ? $lista[0]['data_hora'] : null,
                'ultima' => $lista ? $lista[count($lista) - 1]['data_hora'] : null,
                'jornadas' => count(ponto_jornadas_from_registros($lista)),
            ];
        }
        $tsDia = strtotime('+1 day', $tsDia);
    }
}

$registros = [];
if ($funcionarioSelecionado) {
    $registros = registros_jornada_dia_com_turno_noite((int)$funcionarioSelecionado['id'], $filtroDataPonto);
    foreach ($registros as &$row) {
        $row['funcionario_nome'] = (string)$funcionarioSelecionado['nome'];
        $row['condominio_nome'] = (string)$funcionarioSelecionado['condominio_nome'];
    }
    unset($row);
}

$jornadaPorRegistro = ponto_jornada_numero_por_registro($registros);

$linhasCriacao = [];
if ($funcionarioSelecionado) {
    // Preenche a área de adicionar com as batidas já existentes.
    // As linhas existentes ficam travadas para não duplicar registro ao clicar em adicionar.
    // As linhas vazias continuam disponíveis para completar a batida que faltou.
    $tiposPorJornada = ['entrada','saida_almoco','volta_almoco','saida_janta','volta_janta','saida_descanso','volta_descanso','saida'];
    $eventosPorSlot = [];
    foreach (ponto_jornadas_from_registros($registros) as $idxJornada => $jornada) {
        $numJornada = $idxJornada + 1;
        foreach (($jornada['eventos'] ?? []) as $ev) {
            $tipoEv = (string)($ev['tipo'] ?? '');
            if ($tipoEv === '') continue;
            $slotKey = $numJornada . '|' . $tipoEv;
            if (!isset($eventosPorSlot[$slotKey])) {
                $eventosPorSlot[$slotKey] = $ev;
            }
        }
    }
    for ($j = 1; $j <= ponto_max_jornadas_por_dia(); $j++) {
        foreach ($tiposPorJornada as $tipoSlot) {
            $key = $j . '|' . $tipoSlot;
            $linhasCriacao[] = [
                'jornada' => $j,
                'tipo' => $tipoSlot,
                'rotulo' => 'J' . $j . ' - ' . tipo_ponto_label($tipoSlot),
                'existente' => $eventosPorSlot[$key] ?? null,
            ];
        }
    }
}

$porFuncionario = [];
foreach ($registros as $r) {
    $porFuncionario[(int)$r['funcionario_id']]['nome'] = (string)$r['funcionario_nome'];
    $porFuncionario[(int)$r['funcionario_id']]['condominio'] = (string)$r['condominio_nome'];
    $porFuncionario[(int)$r['funcionario_id']]['registros'][] = $r;
}

$firstWeekday = (int)date('w', strtotime($inicioMes));
$daysInMonth = (int)date('t', strtotime($inicioMes));
$mesesPt = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$monthTitle = $mesesPt[(int)date('n', strtotime($inicioMes))] . ' de ' . date('Y', strtotime($inicioMes));

app_header('Ajustes de ponto', '');
flash_show();
if ($erro) echo '<div class="alert error">' . h($erro) . '</div>';
?>
<style>
    .ajuste-toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.ajuste-toolbar label{min-width:220px;flex:1}.cal-wrap{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;margin-top:14px}.cal-head{font-weight:800;text-align:center;color:#8fa3bd;font-size:12px;text-transform:uppercase}.cal-day{min-height:92px;border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:10px;background:rgba(15,23,42,.65);display:flex;flex-direction:column;gap:6px;text-decoration:none;color:inherit}.cal-day:hover{border-color:#38bdf8;transform:translateY(-1px)}.cal-empty{opacity:.35}.cal-num{font-size:17px;font-weight:900}.cal-meta{font-size:12px;color:#a9bad0;line-height:1.25}.cal-day.has{background:rgba(14,116,144,.18);border-color:rgba(56,189,248,.45)}.cal-day.sel{outline:2px solid #38bdf8}.cal-day.aberto{border-color:rgba(251,191,36,.9)}.cal-day.incompleto{border-color:rgba(249,115,22,.9)}.cal-day.fechado{border-color:rgba(34,197,94,.75)}.cal-badge{display:inline-flex;width:max-content;padding:2px 7px;border-radius:999px;font-size:11px;background:rgba(148,163,184,.18);color:#dbeafe}.cal-day.fechado .cal-badge{background:rgba(34,197,94,.18)}.cal-day.aberto .cal-badge{background:rgba(251,191,36,.18)}.cal-day.incompleto .cal-badge{background:rgba(249,115,22,.18)}.quick-days{display:flex;gap:8px;flex-wrap:wrap}.add-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:820px){.cal-wrap{gap:5px}.cal-day{min-height:82px;padding:7px;border-radius:11px}.cal-meta{font-size:11px}.add-grid{grid-template-columns:1fr}.ajuste-toolbar label{min-width:100%}}
</style>
<section class="panel">
    <h1>Ajustes de ponto</h1>
    <p>Selecione o funcionário para abrir o calendário mensal. Dias com batida ficam destacados; o calendário também puxa saída de madrugada do dia seguinte quando pertence à jornada anterior. Dias vazios podem ser selecionados para adicionar batidas esquecidas.</p>
</section>

<section class="card" style="margin-top:18px">
    <h2>Procurar funcionário e período</h2>
    <form method="get" class="form ajuste-toolbar" id="formFiltroAjustes">
        <label>Buscar funcionário
            <input type="search" id="buscaFuncionario" placeholder="Digite o nome para filtrar a lista">
        </label>
        <label>Funcionário
            <select name="funcionario_id" id="funcionarioSelect" required>
                <option value="0">Selecione</option>
                <?php foreach ($funcionarios as $f): ?>
                    <option value="<?= (int)$f['id'] ?>" <?= $filtroFuncionarioId === (int)$f['id'] ? 'selected' : '' ?>><?= h((string)$f['nome'] . ' - ' . (string)$f['condominio_nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mês do calendário
            <input type="month" name="mes" value="<?= h($filtroMes) ?>">
        </label>
        <label>Dia selecionado
            <input type="date" name="data_ponto" value="<?= h($filtroDataPonto) ?>">
        </label>
        <div style="display:flex;align-items:end;gap:10px;flex-wrap:wrap">
            <button class="btn" type="submit">Abrir calendário</button>
            <a class="btn secondary" href="ajustes-ponto.php">Hoje</a>
        </div>
    </form>
</section>

<?php if ($funcionarioSelecionado): ?>
<section class="card" style="margin-top:18px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div>
            <h2 style="margin-bottom:4px">Calendário de <?= h((string)$funcionarioSelecionado['nome']) ?></h2>
            <p class="muted" style="margin-top:0"><?= h((string)$funcionarioSelecionado['condominio_nome']) ?> • <?= h($monthTitle) ?></p>
        </div>
        <div class="quick-days">
            <a class="btn secondary" href="ajustes-ponto.php?funcionario_id=<?= (int)$funcionarioSelecionado['id'] ?>&mes=<?= h($mesAnterior) ?>&data_ponto=<?= h($mesAnterior . '-01') ?>">Mês anterior</a>
            <a class="btn secondary" href="ajustes-ponto.php?funcionario_id=<?= (int)$funcionarioSelecionado['id'] ?>&mes=<?= h($mesProximo) ?>&data_ponto=<?= h($mesProximo . '-01') ?>">Próximo mês</a>
        </div>
    </div>

    <div class="cal-wrap">
        <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $d): ?>
            <div class="cal-head"><?= h($d) ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
            <div class="cal-day cal-empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
            <?php
                $dataDia = sprintf('%s-%02d', $filtroMes, $day);
                $info = $diasInfo[$dataDia] ?? null;
                $classes = ['cal-day'];
                if ($info) {
                    $classes[] = 'has';
                    $classes[] = (string)$info['status'];
                }
                if ($dataDia === $filtroDataPonto) $classes[] = 'sel';
                $link = 'ajustes-ponto.php?funcionario_id=' . (int)$funcionarioSelecionado['id'] . '&mes=' . urlencode($filtroMes) . '&data_ponto=' . urlencode($dataDia);
            ?>
            <a class="<?= h(implode(' ', $classes)) ?>" href="<?= h($link) ?>">
                <span class="cal-num"><?= $day ?></span>
                <?php if ($info): ?>
                    <span class="cal-badge"><?= h($info['status']) ?></span>
                    <span class="cal-meta"><?= (int)$info['total'] ?> batida(s)</span>
                    <span class="cal-meta">E: <?= (int)$info['entradas'] ?> • S: <?= (int)$info['saidas'] ?> • J: <?= (int)($info['jornadas'] ?? 0) ?></span>
                    <?php if ($info['primeira'] && $info['ultima']): ?>
                        <span class="cal-meta"><?= h(date('H:i', strtotime((string)$info['primeira']))) ?> às <?= h(date('H:i', strtotime((string)$info['ultima']))) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="cal-badge">sem ponto</span>
                    <span class="cal-meta">Clique para adicionar</span>
                <?php endif; ?>
            </a>
        <?php endfor; ?>
    </div>
</section>

<section class="card" style="margin-top:18px">
    <h2>Adicionar batida no dia <?= h(date('d/m/Y', strtotime($filtroDataPonto))) ?></h2>
    <p class="muted">Use esta parte para completar batidas esquecidas <b>ou alterar as que já existem</b>: as batidas salvas aparecem preenchidas e você pode mudar o horário direto aqui (deixe igual para não mexer). O campo <b>Dia jornada/PDF</b> é o dia que vai aparecer no relatório/PDF. A <b>hora real</b> pode ser no dia seguinte em turno noturno.</p>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="acao" value="criar_lote">
        <input type="hidden" name="funcionario_id" value="<?= (int)$funcionarioSelecionado['id'] ?>">
        <label>Dia jornada/PDF
            <input type="date" name="data_ponto" value="<?= h($filtroDataPonto) ?>" required>
        </label>
        <div class="add-grid">
            <?php foreach ($linhasCriacao as $linha):
                $existente = $linha['existente'] ?? null;
                $valorExistente = $existente ? date('Y-m-d\TH:i', strtotime((string)$existente['data_hora'])) : '';
            ?>
                <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:8px;align-items:end;border:1px solid rgba(148,163,184,.18);border-radius:12px;padding:8px;background:rgba(15,23,42,.35)">
                    <label>Tipo
                        <?php if ($existente): ?>
                            <input value="<?= h($linha['rotulo'] . ' - já existe') ?>" disabled>
                            <input type="hidden" name="edit_id[]" value="<?= (int)$existente['id'] ?>">
                        <?php else: ?>
                            <select name="tipo_lote[]">
                                <?php foreach ($tiposPonto as $tipo): ?>
                                    <option value="<?= h($tipo) ?>" <?= $tipo === $linha['tipo'] ? 'selected' : '' ?>><?= h('J' . $linha['jornada'] . ' - ' . tipo_ponto_label($tipo)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </label>
                    <label><?= h($linha['rotulo']) ?> - hora real
                        <?php if ($existente): ?>
                            <input type="datetime-local" name="edit_hora[]" value="<?= h($valorExistente) ?>">
                            <small class="muted">Registro #<?= (int)$existente['id'] ?> salvo. Altere o horário aqui e clique em salvar (deixe igual para não mexer).</small>
                        <?php else: ?>
                            <input type="datetime-local" name="data_hora_lote[]" value="">
                        <?php endif; ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <label>Motivo da criação manual
            <input name="motivo_lote" placeholder="Ex.: funcionário esqueceu de bater o ponto" required>
        </label>
        <button class="btn" data-confirm="Confirmar salvar (adicionar novas e alterar horários existentes)?">Salvar batidas (adicionar/alterar)</button>
    </form>
</section>

<section class="card" style="margin-top:18px">
    <h2>Preencher ponto em massa (vários dias de uma vez)</h2>
    <p class="muted">Cria automaticamente a entrada e a saída dos dias escolhidos. <b>Escala diurna:</b> segunda a sexta das 08:00 às 17:00 e sábado das 08:00 às 12:00. <b>Escala noturna:</b> das 18:00 às 07:00 do dia seguinte (a saída fica na madrugada do outro dia, mas conta na jornada do dia que começou). Dias que já têm alguma batida são <b>pulados</b>, então não duplica nada.</p>
    <form method="post" class="form" id="formMassa">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="acao" value="criar_massa">
        <input type="hidden" name="funcionario_id" value="<?= (int)$funcionarioSelecionado['id'] ?>">

        <label>Como escolher os dias</label>
        <div class="quick-days" style="margin-bottom:10px">
            <label style="display:inline-flex;align-items:center;gap:6px;min-width:auto">
                <input type="radio" name="modo_massa" value="periodo" checked style="width:auto"> Período (data inicial até final)
            </label>
            <label style="display:inline-flex;align-items:center;gap:6px;min-width:auto">
                <input type="radio" name="modo_massa" value="dias" style="width:auto"> Dias selecionados (um por um)
            </label>
        </div>

        <div id="massaModoPeriodo">
            <div class="add-grid">
                <label>Data inicial
                    <input type="date" name="data_inicio" value="<?= h($filtroDataPonto) ?>">
                </label>
                <label>Data final
                    <input type="date" name="data_fim" value="<?= h($filtroDataPonto) ?>">
                </label>
            </div>
            <label>Dias da semana que entram no preenchimento</label>
            <div class="quick-days" style="margin-bottom:10px">
                <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 0 => 'Dom'] as $w => $rotuloDia): ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;min-width:auto">
                        <input type="checkbox" name="dias_semana[]" value="<?= (int)$w ?>" <?= $w !== 0 ? 'checked' : '' ?> style="width:auto"> <?= h($rotuloDia) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="massaModoDias" style="display:none">
            <div class="add-grid">
                <label>Escolha o dia
                    <input type="date" id="massaDiaInput" value="<?= h($filtroDataPonto) ?>">
                </label>
                <div style="display:flex;align-items:end">
                    <button class="btn secondary" type="button" id="massaDiaAdd">Adicionar dia à lista</button>
                </div>
            </div>
            <label>Dias na lista (clique em um dia para tirar da lista)</label>
            <div class="quick-days" id="massaDiasLista" style="margin-bottom:10px">
                <span class="muted" id="massaDiasVazio">Nenhum dia adicionado ainda.</span>
            </div>
        </div>

        <div class="add-grid">
            <label>Escala
                <select name="escala" required>
                    <option value="diurno">Diurno (Seg a Sex 08:00-17:00 / Sáb 08:00-12:00)</option>
                    <option value="noturno">Noturno (18:00 às 07:00 do dia seguinte)</option>
                </select>
            </label>
            <label>Motivo do preenchimento em massa
                <input name="motivo_massa" placeholder="Ex.: sistema fora do ar no período" required>
            </label>
        </div>
        <button class="btn" data-confirm="Preencher entrada e saída de TODOS os dias escolhidos? Dias que já têm batida serão pulados.">Preencher em massa</button>
    </form>
</section>
<script>
(function(){
    var form = document.getElementById('formMassa');
    if (!form) return;
    var blocoPeriodo = document.getElementById('massaModoPeriodo');
    var blocoDias = document.getElementById('massaModoDias');
    var diaInput = document.getElementById('massaDiaInput');
    var btnAdd = document.getElementById('massaDiaAdd');
    var lista = document.getElementById('massaDiasLista');
    var vazio = document.getElementById('massaDiasVazio');

    function modoAtual(){
        var r = form.querySelector('input[name="modo_massa"]:checked');
        return r ? r.value : 'periodo';
    }
    function atualizaModo(){
        var modo = modoAtual();
        blocoPeriodo.style.display = modo === 'periodo' ? '' : 'none';
        blocoDias.style.display = modo === 'dias' ? '' : 'none';
    }
    Array.prototype.forEach.call(form.querySelectorAll('input[name="modo_massa"]'), function(r){
        r.addEventListener('change', atualizaModo);
    });
    atualizaModo();

    function formataBr(iso){
        var p = iso.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function jaTem(iso){
        return !!lista.querySelector('input[value="' + iso + '"]');
    }
    function atualizaVazio(){
        vazio.style.display = lista.querySelector('.massa-dia-chip') ? 'none' : '';
    }
    btnAdd.addEventListener('click', function(){
        var iso = (diaInput.value || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) { alert('Escolha uma data válida primeiro.'); return; }
        if (jaTem(iso)) { alert('Esse dia já está na lista.'); return; }
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn secondary massa-dia-chip';
        chip.textContent = formataBr(iso) + ' ✕';
        chip.title = 'Clique para tirar esse dia da lista';
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'dias_especificos[]';
        hidden.value = iso;
        chip.appendChild(hidden);
        chip.addEventListener('click', function(){
            chip.remove();
            atualizaVazio();
        });
        lista.appendChild(chip);
        atualizaVazio();
    });

    form.addEventListener('submit', function(ev){
        if (modoAtual() === 'dias' && !lista.querySelector('.massa-dia-chip')) {
            ev.preventDefault();
            alert('Adicione pelo menos um dia à lista antes de preencher.');
            return;
        }
        if (modoAtual() === 'periodo') {
            var di = form.querySelector('input[name="data_inicio"]');
            var df = form.querySelector('input[name="data_fim"]');
            if (!di.value || !df.value) {
                ev.preventDefault();
                alert('Informe a data inicial e a data final do período.');
            }
        }
    });
})();
</script>
<?php else: ?>
<section class="card" style="margin-top:18px">
    <h2>Calendário</h2>
    <p class="muted">Escolha um funcionário acima para carregar o calendário de pontos e ajustar os dias.</p>
</section>
<?php endif; ?>

<section class="card" style="margin-top:18px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h2 style="margin:0">Batidas do dia <?= h(date('d/m/Y', strtotime($filtroDataPonto))) ?></h2>
        <?php if ($funcionarioSelecionado): ?>
            <div class="quick-days">
                <a class="btn secondary" href="ajustes-ponto.php?funcionario_id=<?= (int)$funcionarioSelecionado['id'] ?>&mes=<?= h(date('Y-m', strtotime($filtroDataPonto . ' -1 day'))) ?>&data_ponto=<?= h(date('Y-m-d', strtotime($filtroDataPonto . ' -1 day'))) ?>">Dia anterior</a>
                <a class="btn secondary" href="ajustes-ponto.php?funcionario_id=<?= (int)$funcionarioSelecionado['id'] ?>&mes=<?= h(date('Y-m', strtotime($filtroDataPonto . ' +1 day'))) ?>&data_ponto=<?= h(date('Y-m-d', strtotime($filtroDataPonto . ' +1 day'))) ?>">Próximo dia</a>
                <?php if ($registros): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="acao" value="excluir_dia">
                        <input type="hidden" name="funcionario_id" value="<?= (int)$funcionarioSelecionado['id'] ?>">
                        <input type="hidden" name="data_ponto" value="<?= h($filtroDataPonto) ?>">
                        <button class="btn warn" data-confirm="Excluir TODAS as batidas do dia <?= h(date('d/m/Y', strtotime($filtroDataPonto))) ?>? Isso não pode ser desfeito.">Excluir todas do dia</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$funcionarioSelecionado): ?>
        <p class="muted">Selecione um funcionário para ver e ajustar as batidas.</p>
    <?php elseif (!$registros): ?>
        <p class="muted">Nenhuma batida encontrada para essa jornada/dia. Use o bloco acima para adicionar o dia ou as batidas esquecidas.</p>
    <?php endif; ?>

    <?php foreach ($porFuncionario as $grupo): ?>
        <div style="margin:18px 0 8px">
            <h3 style="margin-bottom:4px"><?= h($grupo['nome']) ?></h3>
            <p class="muted" style="margin-top:0"><?= h($grupo['condominio']) ?></p>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data/Hora real</th>
                    <th>Dia jornada/PDF</th>
                    <th>Jornada</th>
                    <th>Tipo atual</th>
                    <th>Ajustar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grupo['registros'] as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h(date('d/m/Y H:i:s', strtotime((string)$r['data_hora']))) ?></td>
                    <td><?= h(date('d/m/Y', strtotime((string)$r['data_ponto']))) ?></td>
                    <td><span class="badge">J<?= (int)($jornadaPorRegistro[(int)$r['id']] ?? 0) ?></span></td>
                    <td><span class="badge"><?= h(tipo_ponto_label((string)$r['tipo'])) ?></span></td>
                    <td>
                        <form method="post" class="form" style="gap:8px">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="acao" value="alterar">
                            <input type="hidden" name="registro_id" value="<?= (int)$r['id'] ?>">
                            <label>Hora real
                                <input type="datetime-local" name="data_hora_nova" value="<?= h(date('Y-m-d\TH:i', strtotime((string)$r['data_hora']))) ?>" required>
                            </label>
                            <label>Dia jornada/PDF
                                <input type="date" name="data_ponto_nova" value="<?= h((string)$r['data_ponto']) ?>" required>
                            </label>
                            <label>Tipo
                                <select name="tipo_novo" required>
                                    <?php foreach ($tiposPonto as $tipo): ?>
                                        <option value="<?= h($tipo) ?>" <?= $tipo === $r['tipo'] ? 'selected' : '' ?>><?= h(tipo_ponto_label($tipo)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input name="motivo" placeholder="Motivo do ajuste" required>
                            <button class="btn warn" data-confirm="Confirmar ajuste?">Ajustar</button>
                        </form>
                        <form method="post" style="margin-top:6px">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="acao" value="excluir_ponto">
                            <input type="hidden" name="registro_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn warn" data-confirm="Excluir esta batida (#<?= (int)$r['id'] ?>)? Isso não pode ser desfeito.">Excluir batida</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endforeach; ?>
</section>

<script>
(function(){
    var busca = document.getElementById('buscaFuncionario');
    var select = document.getElementById('funcionarioSelect');
    if (!busca || !select) return;
    var options = Array.prototype.slice.call(select.options).map(function(opt){
        return { option: opt, text: (opt.textContent || '').toLowerCase() };
    });
    busca.addEventListener('input', function(){
        var termo = busca.value.toLowerCase().trim();
        options.forEach(function(item, idx){
            if (idx === 0) return;
            item.option.hidden = termo !== '' && item.text.indexOf(termo) === -1;
        });
    });
})();
</script>
<?php app_footer(); ?>
