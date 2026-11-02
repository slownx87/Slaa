<?php
/*
 * Montagem do espelho de ponto (relatório em PDF).
 *
 * Estas funções vieram do relatório antigo sem alteração de regra: elas
 * tratam turno noturno, jornadas múltiplas no mesmo dia e saída da madrugada.
 * Agora ficam aqui para o painel novo gerar o PDF sem sair da página.
 */

declare(strict_types=1);

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
    // Mantido por compatibilidade: a regra mora em ponto_saida_noturna_estimada().
    return ponto_saida_noturna_estimada($entradaDt);
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

    /* A conta das horas é a MESMA das telas (ponto_calcular_jornada). Aqui só
     * formatamos os pares de horário para a tabela do PDF. */
    $calc = ponto_calcular_jornada($eventos, [
        'estimar_saida_noturna' => PONTO_PDF_ESTIMA_SAIDA_NOTURNA,
    ]);

    $formatar = function (array $par) use ($dataBase) {
        $ini = rel_fmt_hora($par['ini'], $dataBase);
        if ($par['fim'] === null) {
            return $ini . '-aberto';
        }
        return $ini . '-' . rel_fmt_hora($par['fim'], $dataBase) . ($par['estimado'] ? '*' : '');
    };

    return [
        'pontos' => implode(' | ', array_map($formatar, $calc['pares_trabalho'])),
        'intervalos' => implode(' | ', array_map($formatar, $calc['pares_intervalo'])),
        'min_trabalho' => $calc['trabalho_min'],
        'min_intervalos' => $calc['intervalo_min'],
        'aberta' => !empty($jornada['aberta']),
        'intervalo_aberto' => !empty($calc['intervalo_aberto']),
        'estimado' => $calc['saida_estimada'] !== null,
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
            /* Separa em memória — as batidas do período já vieram todas na
             * consulta de espelho_pdf_dados(). Antes aqui havia uma consulta ao
             * banco por dia e por funcionário. */
            $listaDia = ponto_registros_do_dia_em_memoria($registros, $diaConsulta);
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

    /* Jornada esperada do cargo — é dela que saem as colunas HORAS PROG.,
     * ATRASOS e SALDO. Antes essas três colunas saíam sempre zeradas e o
     * "saldo" era só a repetição das horas trabalhadas, o que deixava o espelho
     * sem a informação que mais importa. A regra é a mesma das telas
     * (ponto_turno_do_dia + faltou para fechar a jornada). */
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
        $intervaloAberto = false;
        $estimado = false;
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
            $intervaloAberto = $intervaloAberto || !empty($linha['intervalo_aberto']);
            $estimado = $estimado || !empty($linha['estimado']);
            foreach (($linha['eventos'] ?? []) as $ev) $eventosDia[] = $ev;
        }

        $pontosTxt = implode(' | ', $pontos);
        $intervalosTxt = implode(' | ', $intervalos);
        $temRegistroNoDia = ($pontosTxt !== '' || $intervalosTxt !== '' || $aberta || !empty($jornadasDia));

        /* Dia sem batida (folga, falta, fim de semana) não tem jornada
         * programada para cobrar: as colunas ficam em branco em vez de zero,
         * para ninguém ler "00:00 de atraso" como se fosse um dia trabalhado. */
        $programadoTxt = '';
        $atrasoTxt = '';
        $saldoTxt = '';
        $horarioTxt = '';

        if ($temRegistroNoDia && $jornadaCargo !== null) {
            $turno = ponto_turno_efetivo($eventosDia, $jornadaCargo);
            $programadoMin = (int)($turno['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS);
            $programadoTxt = rel_fmt_min($programadoMin);
            /* Cargo de escala: sem dizer contra qual horário o dia foi
             * cobrado, HORAS PROG. e ATRASOS ficam impossíveis de conferir. */
            $horarioTxt = (string)($turno['horario'] ?? '');

            /* Jornada que ficou aberta (e que nem o turno noturno fechou por
             * estimativa) ou intervalo sem volta: não dá para dizer quanto
             * faltou, porque o dia nem terminou de ser registrado. Marca com
             * "--" e fica de fora dos totais, igual às telas do app. */
            if (($aberta && !$estimado) || $intervaloAberto) {
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
            // Agora o PDF não repete "Folga"; dia sem batida aparece como "Sem registro".
            'horario' => $horarioTxt,
            'ausencia' => $temRegistroNoDia ? '' : 'Sem registro',
            'pontos' => $pontosTxt,
            'intervalos' => $intervalosTxt,
            'trabalhado' => rel_fmt_min($minTrabalho),
            'programado' => $programadoTxt,
            'atrasos' => $atrasoTxt,
            'saldo' => $saldoTxt,
            'minutos' => $minTrabalho,
            'estimado' => $estimado,
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

/**
 * Devolve os dados prontos do espelho de cada funcionário, no formato que o
 * gerador de PDF do navegador consome.
 *
 * @param array $funcionarioIds Vazio = todos os funcionários do período.
 */
function espelho_pdf_dados(string $dataIni, string $dataFim, int $condominioId = 0, array $funcionarioIds = []): array
{
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
        $temEstimativa = false;
        foreach ($espelho['linhas'] as $l) {
            if (!empty($l['estimado'])) $temEstimativa = true;
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
            'tem_estimativa' => $temEstimativa,
            'total_trab' => rel_fmt_min((int)$espelho['total_min']),
            'total_prog' => rel_fmt_min((int)$espelho['totais']['programado']),
            'total_atraso' => rel_fmt_min((int)$espelho['totais']['atraso']),
            /* Saldo do período = o que trabalhou menos o que estava programado.
             * Antes era só o total trabalhado repetido com um "+" na frente. */
            'total_saldo' => rel_fmt_saldo((int)$espelho['total_min'] - (int)$espelho['totais']['programado']),
        ];
    }

    return [
        'espelhos' => $espelhosPdf,
        'total_min' => $totalGeralMin,
        /* 'periodo' é o texto que sai impresso dentro do PDF. */
        'periodo' => rel_data_br($dataIni) . ' a ' . rel_data_br($dataFim),
        /* 'periodo_arquivo' é o que entra em NOME DE ARQUIVO — sem as barras
         * de 01/08/2026, que o ZIP interpretava como pasta e espalhava cada
         * espelho dentro de "01/08/2026 a 25/08" antes de chegar no PDF. */
        'periodo_arquivo' => $dataIni . '_a_' . $dataFim,
    ];
}
