<?php
require_once __DIR__ . '/_config.php';
require_admin();
ensure_photo_columns();

date_default_timezone_set('America/Sao_Paulo');

$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dataIni)) { $dataIni = today_sp(); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dataFim)) { $dataFim = today_sp(); }
if (strtotime((string)$dataIni) > strtotime((string)$dataFim)) {
    [$dataIni, $dataFim] = [$dataFim, $dataIni];
}

$condominioId = (int)($_GET['condominio_id'] ?? 0);
$funcionarioIdsRaw = $_GET['funcionario_ids'] ?? ($_GET['funcionario_id'] ?? []);
if (!is_array($funcionarioIdsRaw)) { $funcionarioIdsRaw = [$funcionarioIdsRaw]; }
$funcionarioIds = array_values(array_unique(array_filter(array_map('intval', $funcionarioIdsRaw), function ($v) { return $v > 0; })));

function rel_fmt_hora(?string $dt, string $dataBase): string
{
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    // Mostra apenas a hora, sem marcação de "(dia seg.)" / "(X dias depois)".
    return date('H:i', $ts);
}

function rel_saida_noite_gerada(string $entradaDt): string
{
    // Turno noturno sem saída batida: gera uma saída entre 05:50 e 06:00 da madrugada
    // do dia seguinte. Determinístico pela entrada para não mudar a cada vez que abre o PDF.
    $ts = strtotime($entradaDt);
    if (!$ts) return $entradaDt;
    $diaSeguinte = strtotime(date('Y-m-d', strtotime('+1 day', $ts)) . ' 00:00:00');
    mt_srand($ts);
    $seg = mt_rand(21000, 21600); // 05:50:00 = 21000s, 06:00:00 = 21600s
    mt_srand();
    return date('Y-m-d H:i:s', $diaSeguinte + $seg);
}

function rel_fmt_min(int $min): string
{
    $min = max(0, $min);
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function rel_fmt_saldo(int $min): string
{
    if ($min === 0) return '00:00';
    return ($min > 0 ? '+' : '-') . rel_fmt_min(abs($min));
}

function rel_diff_min(?string $ini, ?string $fim): int
{
    if (!$ini || !$fim) return 0;
    $a = strtotime($ini);
    $b = strtotime($fim);
    if (!$a || !$b || $b <= $a) return 0;
    return (int)floor(($b - $a) / 60);
}

function rel_dia_semana(string $data): string
{
    $map = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
    return $map[(int)date('N', strtotime($data))] ?? '';
}

function rel_periodo_datas(string $ini, string $fim): array
{
    $out = [];
    $ts = strtotime($ini);
    $end = strtotime($fim);
    while ($ts !== false && $ts <= $end) {
        $out[] = date('Y-m-d', $ts);
        $ts = strtotime('+1 day', $ts);
    }
    return $out;
}

function rel_data_br(string $data): string
{
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : $data;
}

function rel_func_val(array $func, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($func[$key]) && trim((string)$func[$key]) !== '') {
            return (string)$func[$key];
        }
    }
    return '';
}

function rel_endereco_empresa(array $func): string
{
    $partes = [];
    foreach (['condominio_endereco', 'condominio_numero', 'condominio_bairro', 'condominio_cidade', 'condominio_uf'] as $k) {
        if (!empty($func[$k])) $partes[] = (string)$func[$k];
    }
    $txt = implode(', ', $partes);
    if (!empty($func['condominio_cep'])) $txt .= ($txt ? ' - ' : '') . 'CEP ' . (string)$func['condominio_cep'];
    return $txt;
}

function rel_build_jornadas(array $registros): array
{
    // Usa a normalização do _config.php para fechar corretamente turno noturno:
    // Entrada 17:00+ e saída 00:00-08:00 cadastrada com a mesma data é tratada como saída do dia seguinte no PDF.
    $registros = ponto_normalizar_turno_noite_registros($registros);
    $jornadas = [];
    $atual = null;

    foreach ($registros as $r) {
        $tipo = (string)$r['tipo'];

        if ($tipo === 'entrada') {
            if ($atual) {
                $jornadas[] = $atual;
            }
            $atual = [
                'data_ref' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['data_ponto'] ?? '')) ? (string)$r['data_ponto'] : date('Y-m-d', strtotime((string)$r['data_hora'])),
                'entrada' => $r,
                'saida' => null,
                'eventos' => [$r],
                'aberta' => true,
            ];
            continue;
        }

        // Evita bagunçar o espelho quando existe uma saída no dia seguinte sem entrada no mesmo dia.
        // Essa saída só entra na jornada se existir uma entrada anterior aberta.
        if (!$atual) {
            continue;
        }

        $atual['eventos'][] = $r;
        if ($tipo === 'saida') {
            $atual['saida'] = $r;
            $atual['aberta'] = false;
            $jornadas[] = $atual;
            $atual = null;
        }
    }

    if ($atual) {
        $jornadas[] = $atual;
    }

    return $jornadas;
}

function rel_linha_jornada(array $jornada, string $dataBase): array
{
    $eventos = $jornada['eventos'];
    usort($eventos, function ($a, $b) {
        $cmp = strcmp((string)$a['data_hora'], (string)$b['data_hora']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ponto_registro_ordem_id($a) <=> ponto_registro_ordem_id($b);
    });

    $inicioTrabalho = null;
    $inicioIntervalo = null;
    $pontos = [];
    $intervalos = [];
    $minTrabalho = 0;
    $minIntervalos = 0;
    $inicioIntervalos = ['saida_almoco' => true, 'saida_janta' => true, 'saida_descanso' => true];
    $fimIntervalos = ['volta_almoco' => true, 'volta_janta' => true, 'volta_descanso' => true];

    foreach ($eventos as $ev) {
        $tipo = (string)$ev['tipo'];
        $dt = (string)$ev['data_hora'];

        if ($tipo === 'entrada') {
            $inicioTrabalho = $dt;
            continue;
        }

        if (isset($inicioIntervalos[$tipo])) {
            if ($inicioTrabalho) {
                $pontos[] = rel_fmt_hora($inicioTrabalho, $dataBase) . '-' . rel_fmt_hora($dt, $dataBase);
                $minTrabalho += rel_diff_min($inicioTrabalho, $dt);
                $inicioTrabalho = null;
            }
            $inicioIntervalo = $dt;
            continue;
        }

        if (isset($fimIntervalos[$tipo])) {
            if ($inicioIntervalo) {
                $intervalos[] = rel_fmt_hora($inicioIntervalo, $dataBase) . '-' . rel_fmt_hora($dt, $dataBase);
                $minIntervalos += rel_diff_min($inicioIntervalo, $dt);
                $inicioIntervalo = null;
            }
            $inicioTrabalho = $dt;
            continue;
        }

        if ($tipo === 'saida') {
            if ($inicioTrabalho) {
                $pontos[] = rel_fmt_hora($inicioTrabalho, $dataBase) . '-' . rel_fmt_hora($dt, $dataBase);
                $minTrabalho += rel_diff_min($inicioTrabalho, $dt);
                $inicioTrabalho = null;
            } elseif ($inicioIntervalo) {
                $intervalos[] = rel_fmt_hora($inicioIntervalo, $dataBase) . '-' . rel_fmt_hora($dt, $dataBase);
                $minIntervalos += rel_diff_min($inicioIntervalo, $dt);
                $inicioIntervalo = null;
            }
        }
    }

    if ($inicioTrabalho) {
        if (ponto_eh_entrada_noite($inicioTrabalho)) {
            // Turno noturno sem saída batida: gera uma saída entre 05:50 e 06:00 e usa ela.
            $saidaGerada = rel_saida_noite_gerada($inicioTrabalho);
            $pontos[] = rel_fmt_hora($inicioTrabalho, $dataBase) . '-' . rel_fmt_hora($saidaGerada, $dataBase);
            $minTrabalho += rel_diff_min($inicioTrabalho, $saidaGerada);
        } else {
            $pontos[] = rel_fmt_hora($inicioTrabalho, $dataBase) . '-aberto';
        }
        $inicioTrabalho = null;
    }
    if ($inicioIntervalo) {
        $intervalos[] = rel_fmt_hora($inicioIntervalo, $dataBase) . '-aberto';
    }

    return [
        'pontos' => implode(' | ', $pontos),
        'intervalos' => implode(' | ', $intervalos),
        'min_trabalho' => $minTrabalho,
        'min_intervalos' => $minIntervalos,
        'aberta' => !empty($jornada['aberta']),
        /* As batidas cruas: é delas que sai o horário do cargo cumprido no
         * dia, para quem tem mais de um (escala, 12x36). */
        'eventos' => $eventos,
    ];
}

function rel_rows_espelho(array $registros, string $dataIni, string $dataFim, ?int $funcionarioId = null): array
{
    // Quando temos o ID do funcionário, monta cada dia usando a mesma função do calendário.
    // Isso é o ponto principal da correção: o PDF agora enxerga a saída real da madrugada
    // do dia seguinte exatamente como a tela de Ajustes/Calendário já enxerga.
    $porData = [];
    if ($funcionarioId !== null && $funcionarioId > 0) {
        foreach (rel_periodo_datas($dataIni, $dataFim) as $diaConsulta) {
            $listaDia = registros_jornada_dia_com_turno_noite($funcionarioId, $diaConsulta);
            if (!$listaDia) {
                continue;
            }
            $jornadasDia = rel_build_jornadas($listaDia);
            foreach ($jornadasDia as $j) {
                $data = (string)($j['data_ref'] ?? $diaConsulta);
                if ($data < $dataIni || $data > $dataFim) continue;
                $porData[$data][] = $j;
            }
        }
    } else {
        $jornadas = rel_build_jornadas($registros);
        foreach ($jornadas as $j) {
            $data = (string)$j['data_ref'];
            if ($data < $dataIni || $data > $dataFim) continue;
            $porData[$data][] = $j;
        }
    }

    /* Jornada esperada do cargo — mesma regra das telas e do painel novo.
     * Antes esta página imprimia HORAS PROG. e ATRASOS fixos em 00:00 e o
     * "saldo" era só a repetição das horas trabalhadas. */
    $jornadaCargo = ($funcionarioId !== null && $funcionarioId > 0)
        ? ponto_jornada_do_funcionario($funcionarioId)
        : null;

    $linhas = [];
    $total = 0;
    $totalProgramado = 0;
    $totalAtraso = 0;
    foreach (rel_periodo_datas($dataIni, $dataFim) as $data) {
        $pontos = [];
        $intervalos = [];
        $minTrabalho = 0;
        $aberta = false;
        $eventosDia = [];

        $jornadasDia = $porData[$data] ?? [];
        $multiJornada = count($jornadasDia) > 1;
        foreach ($jornadasDia as $idxJornada => $jornada) {
            $linha = rel_linha_jornada($jornada, $data);
            $prefixo = $multiJornada ? ('J' . ($idxJornada + 1) . ': ') : '';
            if ($linha['pontos'] !== '') $pontos[] = $prefixo . $linha['pontos'];
            if ($linha['intervalos'] !== '') $intervalos[] = $prefixo . $linha['intervalos'];
            $minTrabalho += (int)$linha['min_trabalho'];
            $aberta = $aberta || !empty($linha['aberta']);
            foreach (($linha['eventos'] ?? []) as $ev) $eventosDia[] = $ev;
        }

        $pontosTxt = implode(' | ', $pontos);
        $intervalosTxt = implode(' | ', $intervalos);
        $temRegistroNoDia = ($pontosTxt !== '' || $intervalosTxt !== '' || $aberta || !empty($jornadasDia));

        /* Dia sem batida (folga, falta, fim de semana) não tem jornada
         * programada para cobrar: as colunas ficam em branco em vez de zero. */
        $horarioTxt = '';
        $programadoTxt = '';
        $atrasoTxt = '';
        $saldoTxt = '';

        if ($temRegistroNoDia && $jornadaCargo !== null) {
            /* Cargo com vários horários: vale o que a pessoa cumpriu no dia. */
            $turno = ponto_turno_efetivo($eventosDia, $jornadaCargo);
            $programadoMin = (int)($turno['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS);
            $horarioTxt = (string)($turno['horario'] ?? '');
            $programadoTxt = rel_fmt_min($programadoMin);

            if ($aberta) {
                // Jornada sem saída: não dá para dizer quanto faltou.
                $atrasoTxt = '--';
                $saldoTxt = '--';
            } else {
                $atrasoMin = max(0, $programadoMin - $minTrabalho);
                $atrasoTxt = rel_fmt_min($atrasoMin);
                $saldoTxt = rel_fmt_saldo($minTrabalho - $programadoMin);
                $totalProgramado += $programadoMin;
                $totalAtraso += $atrasoMin;
            }
        }

        $total += $minTrabalho;
        $linhas[] = [
            'data' => $data,
            'dia' => rel_dia_semana($data),
            // Antes ficava sempre "Folga", inclusive nos dias trabalhados.
            // Agora traz o horário do cargo que valeu no dia; sem batida aparece como "Sem registro".
            'horario' => $horarioTxt,
            'ausencia' => $temRegistroNoDia ? '' : 'Sem registro',
            'pontos' => $pontosTxt,
            'intervalos' => $intervalosTxt,
            'trabalhado' => rel_fmt_min($minTrabalho),
            'programado' => $programadoTxt,
            'atrasos' => $atrasoTxt,
            'saldo' => $saldoTxt,
            'minutos' => $minTrabalho,
        ];
    }

    return [$linhas, $total, ['programado' => $totalProgramado, 'atraso' => $totalAtraso]];
}

function rel_select_extras(string $tabela, string $alias, string $prefixo, array $cols): string
{
    $sql = '';
    foreach ($cols as $col) {
        if (column_exists($tabela, $col)) {
            $sql .= ", {$alias}.`{$col}` AS {$prefixo}{$col}";
        }
    }
    return $sql;
}

$inicioBusca = date('Y-m-d 00:00:00', strtotime($dataIni . ' -1 day'));
$fimBusca = date('Y-m-d 00:00:00', strtotime($dataFim . ' +2 days'));
$where = ["r.data_hora >= ? AND r.data_hora < ?"];
$params = [$inicioBusca, $fimBusca];

if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null) {
    $where[] = "r.condominio_id = ?";
    $params[] = admin_condominio_id();
} elseif ($condominioId > 0) {
    $where[] = "r.condominio_id = ?";
    $params[] = $condominioId;
}
if ($funcionarioIds) {
    $ph = implode(',', array_fill(0, count($funcionarioIds), '?'));
    $where[] = "r.funcionario_id IN ($ph)";
    $params = array_merge($params, $funcionarioIds);
}

$funcExtras = rel_select_extras('ponto_funcionarios', 'f', 'funcionario_', ['rg', 'pis', 'ctps', 'numero_ctps', 'admissao', 'data_admissao', 'matricula']);
$condExtras = rel_select_extras('ponto_condominios', 'co', 'condominio_', ['fone', 'telefone']);

$sql = "SELECT
        r.id AS registro_id, r.funcionario_id, r.condominio_id, r.tipo, r.data_ponto, r.data_hora, r.observacao, r.foto_path,
        f.nome AS funcionario_nome, f.cpf AS funcionario_cpf, f.email AS funcionario_email, f.telefone AS funcionario_telefone,
        ca.nome AS cargo_nome,
        co.nome AS condominio_nome, co.cnpj AS condominio_cnpj, co.endereco AS condominio_endereco, co.numero AS condominio_numero,
        co.bairro AS condominio_bairro, co.cidade AS condominio_cidade, co.uf AS condominio_uf, co.cep AS condominio_cep
        {$funcExtras} {$condExtras}
    FROM ponto_registros r
    INNER JOIN ponto_funcionarios f ON f.id = r.funcionario_id
    LEFT JOIN ponto_cargos ca ON ca.id = f.cargo_id
    INNER JOIN ponto_condominios co ON co.id = r.condominio_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY f.nome ASC, r.data_hora ASC, r.id ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll();
$registros = ponto_normalizar_turno_noite_registros($registros);
usort($registros, function ($a, $b) {
    $cmp = strcmp((string)($a['funcionario_nome'] ?? ''), (string)($b['funcionario_nome'] ?? ''));
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp((string)($a['data_hora'] ?? ''), (string)($b['data_hora'] ?? ''));
    if ($cmp !== 0) return $cmp;
    return ponto_registro_ordem_id($a) <=> ponto_registro_ordem_id($b);
});

$porFuncionario = [];
$funcionariosRel = [];
foreach ($registros as $r) {
    $fid = (int)$r['funcionario_id'];
    $porFuncionario[$fid][] = $r;
    if (!isset($funcionariosRel[$fid])) {
        $funcionariosRel[$fid] = $r;
    }
}

// Se o admin escolher um funcionário sem batidas no período, ainda mostra o espelho vazio dele.
if ($funcionarioIds) {
    $whereF = ["f.id IN (" . implode(',', array_fill(0, count($funcionarioIds), '?')) . ")"];
    $paramsF = $funcionarioIds;
    if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null) {
        $whereF[] = "f.condominio_id = ?";
        $paramsF[] = admin_condominio_id();
    }
    $sqlF = "SELECT
            f.id AS funcionario_id, f.nome AS funcionario_nome, f.cpf AS funcionario_cpf, f.email AS funcionario_email, f.telefone AS funcionario_telefone,
            ca.nome AS cargo_nome,
            co.id AS condominio_id, co.nome AS condominio_nome, co.cnpj AS condominio_cnpj, co.endereco AS condominio_endereco, co.numero AS condominio_numero,
            co.bairro AS condominio_bairro, co.cidade AS condominio_cidade, co.uf AS condominio_uf, co.cep AS condominio_cep
            {$funcExtras} {$condExtras}
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos ca ON ca.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE " . implode(' AND ', $whereF) . "
        ORDER BY f.nome";
    $stmtF2 = db()->prepare($sqlF);
    $stmtF2->execute($paramsF);
    foreach ($stmtF2->fetchAll() as $frow) {
        $fid = (int)$frow['funcionario_id'];
        if (!isset($funcionariosRel[$fid])) $funcionariosRel[$fid] = $frow;
    }
}

$espelhos = [];
$totalGeralMin = 0;
foreach ($funcionariosRel as $fid => $func) {
    [$linhas, $totalMin, $totais] = rel_rows_espelho($porFuncionario[(int)$fid] ?? [], $dataIni, $dataFim, (int)$fid);
    $espelhos[] = ['func' => $func, 'linhas' => $linhas, 'total_min' => $totalMin, 'totais' => $totais];
    $totalGeralMin += $totalMin;
}

// Dados estruturados usados para gerar 1 PDF por funcionário (ZIP) direto no navegador via jsPDF.
$espelhosPdf = [];
foreach ($espelhos as $espelho) {
    $func = $espelho['func'];
    $admissao = rel_func_val($func, ['funcionario_admissao', 'funcionario_data_admissao']);
    if ($admissao && preg_match('/^\d{4}-\d{2}-\d{2}/', $admissao)) $admissao = date('d/m/Y', strtotime($admissao));
    $rows = [];
    foreach ($espelho['linhas'] as $l) {
        $rows[] = [
            rel_data_br($l['data']) . ' ' . $l['dia'],
            $l['horario'],
            $l['ausencia'],
            $l['pontos'],
            $l['intervalos'],
            $l['trabalhado'],
            $l['programado'],
            $l['atrasos'],
            $l['saldo'],
        ];
    }
    $espelhosPdf[] = [
        'nome' => (string)$func['funcionario_nome'],
        'empresa' => rel_func_val($func, ['condominio_nome']),
        'endereco' => rel_endereco_empresa($func),
        'cnpj' => rel_func_val($func, ['condominio_cnpj']),
        'fone' => rel_func_val($func, ['condominio_fone', 'condominio_telefone']),
        'cargo' => rel_func_val($func, ['cargo_nome']),
        'ctps' => rel_func_val($func, ['funcionario_numero_ctps', 'funcionario_ctps']),
        'matricula' => rel_func_val($func, ['funcionario_matricula']),
        'admissao' => $admissao,
        'rg' => rel_func_val($func, ['funcionario_rg']),
        'pis' => rel_func_val($func, ['funcionario_pis']),
        'cpf' => rel_func_val($func, ['funcionario_cpf']),
        'rows' => $rows,
        'total_trab' => rel_fmt_min((int)$espelho['total_min']),
        'total_prog' => rel_fmt_min((int)$espelho['totais']['programado']),
        'total_atraso' => rel_fmt_min((int)$espelho['totais']['atraso']),
        /* Saldo do período = trabalhado menos o programado. Antes era o total
         * trabalhado repetido com um "+" na frente. */
        'total_saldo' => rel_fmt_saldo((int)$espelho['total_min'] - (int)$espelho['totais']['programado']),
    ];
}

$condominios = get_condominios(true);
if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
    $funcs = db()->query("SELECT id, nome FROM ponto_funcionarios WHERE ativo = 1 ORDER BY nome")->fetchAll();
} else {
    $stmtF = db()->prepare("SELECT id, nome FROM ponto_funcionarios WHERE ativo = 1 AND condominio_id = ? ORDER BY nome");
    $stmtF->execute([admin_condominio_id()]);
    $funcs = $stmtF->fetchAll();
}

$registrosTela = array_filter($registros, function ($r) use ($dataIni, $dataFim) {
    $d = date('Y-m-d', strtotime((string)$r['data_hora']));
    return $d >= $dataIni && $d <= $dataFim;
});

$emissao = date('d/m/Y H:i');
$emissaoCompleta = date('d/m/Y H:i:s');

app_header('Relatório Espelho de Ponto', '');
?>
<style>
    .report-actions {display:flex;gap:10px;flex-wrap:wrap;align-items:end}.report-actions label{display:flex;flex-direction:column;gap:6px}.report-actions select[multiple]{min-width:240px;min-height:130px}.mirror-wrap{margin-top:18px;display:flex;flex-direction:column;gap:22px}.mirror-sheet{background:#fff;color:#26324b;border-radius:12px;padding:18px;box-shadow:0 12px 36px rgba(0,0,0,.18);font-family:Arial,Helvetica,sans-serif}.mirror-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}.mirror-title{font-size:23px;font-weight:800;letter-spacing:-.3px;color:#28324d}.mirror-period{font-size:15px;color:#28324d;white-space:nowrap}.mirror-info{display:grid;grid-template-columns:1fr 1fr;gap:34px;margin:10px 0 18px}.mirror-info h4{font-size:9px;margin:0 0 8px;font-weight:800;color:#2f3a59}.mirror-info p{font-size:9px;line-height:1.45;margin:0 0 5px;color:#25304a}.func-grid{display:grid;grid-template-columns:1.55fr 1fr;column-gap:32px}.func-grid h4{grid-column:1/-1}.mirror-table{width:100%;border-collapse:collapse;font-size:8.5px;color:#2b3550}.mirror-table th{background:#f1f4f9;color:#2b3550;text-align:left;padding:5px 4px;font-weight:800;border:0}.mirror-table td{padding:4px 4px;border:0;vertical-align:middle}.mirror-table tbody tr:nth-child(even){background:#f4f7fb}.mirror-table tbody tr:nth-child(odd){background:#fff}.mirror-table .num{text-align:center;white-space:nowrap}.mirror-table .pontos{white-space:normal;word-break:break-word;overflow-wrap:anywhere}.mirror-table td{vertical-align:top}.mirror-table tfoot td{background:#fff!important;font-weight:800;padding-top:7px}.mirror-foot{display:grid;grid-template-columns:1fr 1fr;margin-top:34px;align-items:end}.cert{font-size:9px;line-height:1.35;color:#2b3550}.signs{display:grid;grid-template-columns:1fr 1fr;gap:80px;font-size:9px;color:#2b3550;text-align:center}.sign-line{border-top:1px solid transparent;padding-top:2px;font-weight:700}.muted2{color:#475569}.screen-note{font-size:13px;color:#94a3b8;margin-top:8px}
    @media print {
        @page { size: A4 landscape; margin: 8mm; }
        html, body { background:#fff!important; color:#26324b!important; }
        .topbar, .no-print, .panel.no-print, .card.no-print { display:none!important; }
        .container { max-width:none!important; width:100%!important; margin:0!important; padding:0!important; }
        .mirror-wrap { margin:0!important; gap:0!important; }
        .mirror-sheet { box-shadow:none!important; border-radius:0!important; padding:0!important; page-break-after:always; break-after:page; }
        .mirror-sheet:last-child { page-break-after:auto; break-after:auto; }
        .mirror-title { font-size:18px!important; }
        .mirror-period { font-size:12px!important; }
        .mirror-info { margin:8px 0 14px!important; gap:30px!important; }
        .mirror-info h4, .mirror-info p { font-size:7.8px!important; }
        .mirror-table { font-size:6.9px!important; table-layout:fixed; word-break:break-word; }
        .mirror-table th, .mirror-table td { padding:2.3px 3px!important; vertical-align:top!important; }
        .mirror-table .pontos { white-space:normal!important; word-break:break-word; overflow-wrap:anywhere; }
        .mirror-table th:nth-child(1){width:12%}.mirror-table th:nth-child(2){width:9%}.mirror-table th:nth-child(3){width:8%}.mirror-table th:nth-child(4){width:22%}.mirror-table th:nth-child(5){width:18%}.mirror-table th:nth-child(6){width:8%}.mirror-table th:nth-child(7){width:8%}.mirror-table th:nth-child(8){width:7%}.mirror-table th:nth-child(9){width:8%}
        .mirror-foot { margin-top:28px!important; }
        .cert, .signs { font-size:7.8px!important; }
    }
</style>

<section class="panel no-print">
    <h1>Relatório Espelho de Ponto</h1>
    <p class="muted">Esse relatório usa a mesma leitura do calendário de ajustes para cada dia. A jornada que começa em um dia e fecha no outro é mantida no dia da entrada: se a entrada foi após 17:00, o PDF procura a saída real da madrugada do dia seguinte e mostra o horário fechado. Se não existir saída registrada num turno noturno, o PDF gera automaticamente uma saída entre 05:50 e 06:00 da madrugada do dia seguinte. Se houver 2 ou 3 jornadas no mesmo dia, o PDF mostra J1, J2 e J3 na mesma linha do dia.</p>
    <form method="get" class="report-actions">
        <label>Data inicial <input type="date" name="data_ini" value="<?= h((string)$dataIni) ?>"></label>
        <label>Data final <input type="date" name="data_fim" value="<?= h((string)$dataFim) ?>"></label>
        <?php if (admin_nivel() === 'super_admin' || admin_condominio_id() === null): ?>
        <label>Condomínio
            <select name="condominio_id">
                <option value="0">Todos</option>
                <?php foreach ($condominios as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $condominioId === (int)$c['id'] ? 'selected' : '' ?>><?= h((string)$c['nome']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Funcionários
            <select name="funcionario_ids[]" multiple size="6">
                <?php foreach ($funcs as $f): ?><option value="<?= (int)$f['id'] ?>" <?= in_array((int)$f['id'], $funcionarioIds, true) ? 'selected' : '' ?>><?= h((string)$f['nome']) ?></option><?php endforeach; ?>
            </select>
            <small>Sem selecionar = funcionários com batidas no período. Para vários, segure Ctrl no computador.</small>
        </label>
        <button type="submit">Gerar espelho</button>
        <button type="button" onclick="window.print()" class="btn secondary">Imprimir / salvar PDF (tudo junto)</button>
        <button type="button" id="btnPdfUnico" onclick="baixarPdfUnico(this)" class="btn secondary">Baixar PDF (todos num arquivo só)</button>
        <button type="button" id="btnZipPdf" onclick="baixarZipPorFuncionario(this)" class="btn secondary">Baixar ZIP (1 PDF por funcionário)</button>
    </form>
    <div class="grid grid-3" style="margin-top:18px">
        <div class="stat"><strong><?= count($registrosTela) ?></strong><span>Batidas no período</span></div>
        <div class="stat"><strong><?= count($espelhos) ?></strong><span>Funcionários no PDF</span></div>
        <div class="stat"><strong><?= h(rel_fmt_min($totalGeralMin)) ?></strong><span>Total trabalhado</span></div>
    </div>
    <?php if (!$espelhos): ?><p class="screen-note">Nenhum funcionário encontrado para esse filtro.</p><?php endif; ?>
</section>

<div class="mirror-wrap">
<?php foreach ($espelhos as $espelho):
    $func = $espelho['func'];
    $cargo = rel_func_val($func, ['cargo_nome']);
    $empresa = rel_func_val($func, ['condominio_nome']);
    $cnpj = rel_func_val($func, ['condominio_cnpj']);
    $fone = rel_func_val($func, ['condominio_fone', 'condominio_telefone']);
    $ctps = rel_func_val($func, ['funcionario_numero_ctps', 'funcionario_ctps']);
    $admissao = rel_func_val($func, ['funcionario_admissao', 'funcionario_data_admissao']);
    if ($admissao && preg_match('/^\d{4}-\d{2}-\d{2}/', $admissao)) $admissao = date('d/m/Y', strtotime($admissao));
    $rg = rel_func_val($func, ['funcionario_rg']);
    $pis = rel_func_val($func, ['funcionario_pis']);
    $cpf = rel_func_val($func, ['funcionario_cpf']);
    $matricula = rel_func_val($func, ['funcionario_matricula']);
?>
    <section class="mirror-sheet" data-func="<?= h((string)$func['funcionario_nome']) ?>">
        <div class="mirror-head">
            <div class="mirror-title">Relatório Espelho de Ponto</div>
            <div class="mirror-period">Período: <?= h((string)$dataIni) ?> a <?= h((string)$dataFim) ?></div>
        </div>

        <div class="mirror-info">
            <div>
                <h4>Identificação da Empresa:</h4>
                <p><b>Empresa:</b> <?= h($empresa) ?></p>
                <p><b>Endereço:</b> <?= h(rel_endereco_empresa($func)) ?></p>
                <p><b>CNPJ:</b> <?= h($cnpj) ?></p>
                <p><b>Emissão:</b> <?= h($emissao) ?></p>
                <p><b>Fone:</b> <?= h($fone) ?></p>
            </div>
            <div class="func-grid">
                <h4>Identificação do Funcionário:</h4>
                <div>
                    <p><b>Funcionário:</b> <?= h((string)$func['funcionario_nome']) ?></p>
                    <p><b>Cargo:</b> <?= h($cargo) ?></p>
                    <p><b>Departamento:</b> <?= h($cargo) ?></p>
                    <p><b>Centro de custo:</b> <?= h($empresa) ?></p>
                    <p><b>Numero CTPS:</b> <?= h($ctps) ?></p>
                    <?php if ($matricula !== ''): ?><p><b>Matrícula:</b> <?= h($matricula) ?></p><?php endif; ?>
                </div>
                <div>
                    <p><b>Admissão:</b> <?= h($admissao) ?></p>
                    <p><b>RG:</b> <?= h($rg) ?></p>
                    <p><b>PIS:</b> <?= h($pis) ?></p>
                    <p><b>CPF:</b> <?= h($cpf) ?></p>
                </div>
            </div>
        </div>

        <table class="mirror-table">
            <thead>
                <tr>
                    <th>DATA</th>
                    <th>HORÁRIO</th>
                    <th>AUSÊNCIA</th>
                    <th>PONTOS</th>
                    <th>INTERVALOS</th>
                    <th class="num">HORAS<br>TRABALH.</th>
                    <th class="num">HORAS<br>PROGRAM.</th>
                    <th class="num">ATRASOS</th>
                    <th class="num">SALDO</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($espelho['linhas'] as $l): ?>
                <tr>
                    <td><?= h(rel_data_br($l['data']) . ' ' . $l['dia']) ?></td>
                    <td><?= h($l['horario']) ?></td>
                    <td><?= h($l['ausencia']) ?></td>
                    <td class="pontos"><?= h($l['pontos']) ?></td>
                    <td class="pontos"><?= h($l['intervalos']) ?></td>
                    <td class="num"><?= h($l['trabalhado']) ?></td>
                    <td class="num"><?= h($l['programado']) ?></td>
                    <td class="num"><?= h($l['atrasos']) ?></td>
                    <td class="num"><?= h($l['saldo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="num">TOTAL</td>
                    <td class="num"><?= h(rel_fmt_min((int)$espelho['total_min'])) ?></td>
                    <td class="num"><?= h(rel_fmt_min((int)$espelho['totais']['programado'])) ?></td>
                    <td class="num"><?= h(rel_fmt_min((int)$espelho['totais']['atraso'])) ?></td>
                    <td class="num"><?= h(rel_fmt_saldo((int)$espelho['total_min'] - (int)$espelho['totais']['programado'])) ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="mirror-foot">
            <div class="cert">
                <b>Certificado digitalmente por Vss E Acj</b><br>
                Data: <?= h($emissaoCompleta) ?>
            </div>
            <div class="signs">
                <div><div class="sign-line">Assinatura</div><div>Empresa</div></div>
                <div><div class="sign-line">&nbsp;</div><div>Funcionário</div></div>
            </div>
        </div>
    </section>
<?php endforeach; ?>
</div>

<section class="card no-print" style="margin-top:18px">
    <h2>Batidas com foto</h2>
    <div class="table-wrap"><table><thead><tr><th>Data/Hora</th><th>Funcionário</th><th>Cargo</th><th>Condomínio</th><th>Tipo</th><th>Foto</th><th>Observação</th></tr></thead><tbody>
    <?php foreach ($registrosTela as $r): ?>
        <tr>
            <td><?= h(date('d/m/Y H:i:s', strtotime((string)$r['data_hora']))) ?></td>
            <td><?= h((string)$r['funcionario_nome']) ?></td>
            <td><?= h((string)($r['cargo_nome'] ?? '')) ?></td>
            <td><?= h((string)$r['condominio_nome']) ?></td>
            <td><span class="badge"><?= h(tipo_ponto_label((string)$r['tipo'])) ?></span></td>
            <td><?php if (!empty($r['foto_path'])): ?><a target="_blank" class="photo-link" href="<?= h((string)$r['foto_path']) ?>">Ver foto</a><?php else: ?>Sem foto<?php endif; ?></td>
            <td><?= h((string)($r['observacao'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$registrosTela): ?><tr><td colspan="7">Nenhuma batida encontrada.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script src="assets/espelho-pdf.js?v=1.1.0"></script>
<script>
/* O desenho do PDF mora em assets/espelho-pdf.js, o mesmo que o painel novo
 * usa. Esta pagina mantinha uma copia inteira do gerador aqui dentro, que ja
 * tinha ficado para tras (HORAS PROG. e ATRASOS saiam sempre 00:00). */
window.RELATORIO_PERIODO = <?= json_encode(rel_data_br((string)$dataIni) . ' a ' . rel_data_br((string)$dataFim), JSON_UNESCAPED_UNICODE) ?>;
/* Sem barras: e este que entra em nome de arquivo. */
window.RELATORIO_PERIODO_ARQUIVO = <?= json_encode((string)$dataIni . '_a_' . (string)$dataFim, JSON_UNESCAPED_UNICODE) ?>;
window.RELATORIO_EMISSAO = <?= json_encode((string)$emissao, JSON_UNESCAPED_UNICODE) ?>;
window.RELATORIO_EMISSAO_COMPLETA = <?= json_encode((string)$emissaoCompleta, JSON_UNESCAPED_UNICODE) ?>;
window.ESPELHOS_PDF = <?= json_encode($espelhosPdf, JSON_UNESCAPED_UNICODE) ?>;
(function(){
    async function exportar(btn, modo){
        var espelhos = window.ESPELHOS_PDF || [];
        if (!espelhos.length) { alert('Gere o espelho primeiro: nenhum funcionario para exportar.'); return; }
        try {
            await window.EspelhoPDF.gerar({
                espelhos: espelhos,
                periodo: window.RELATORIO_PERIODO || '',
                periodoArquivo: window.RELATORIO_PERIODO_ARQUIVO || '',
                emissao: window.RELATORIO_EMISSAO || '',
                emissaoCompleta: window.RELATORIO_EMISSAO_COMPLETA || '',
                modo: modo,
                botao: btn
            });
        } catch (e) {
            alert('Nao foi possivel gerar o PDF: ' + e.message);
        }
    }
    /* Um arquivo so, uma pagina por pessoa. */
    window.baixarPdfUnico = function(btn){ exportar(btn, 'unico'); };
    /* Um PDF por pessoa, todos na raiz do ZIP (sem pasta nenhuma). */
    window.baixarZipPorFuncionario = function(btn){ exportar(btn, 'zip'); };
})();
</script>
<?php app_footer(); ?>
