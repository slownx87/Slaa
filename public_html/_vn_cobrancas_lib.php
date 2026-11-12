<?php
/*
    VN System - Funções da central de cobranças (cobrancas.php)
    - Etiquetas de cliente: agrupam condomínios da mesma empresa (ex.: Lexcopany)
      em Cobranças, Mensalidades e Cobrar Equipamentos.
    - Cobrança automática de material: saída de estoque com cliente vira cobrança na hora.
    - Peças da cobrança: adicionar dá baixa no estoque, excluir devolve ao estoque.
*/

if (!function_exists('vn_ensure_cliente_etiquetas_table')) {
    function vn_ensure_cliente_etiquetas_table(): void {
        vn_exec("CREATE TABLE IF NOT EXISTS vn_cliente_etiquetas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            etiqueta VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_vn_cli_etq (cliente_id),
            INDEX (etiqueta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('vn_cliente_etiquetas_map')) {
    /* cliente_id => etiqueta (ex.: 12 => "Lexcopany") */
    function vn_cliente_etiquetas_map(): array {
        if (!vn_table_exists('vn_cliente_etiquetas')) return [];
        $map = [];
        foreach (vn_fetch_all('SELECT cliente_id, etiqueta FROM vn_cliente_etiquetas') as $r) {
            $map[(int)$r['cliente_id']] = (string)$r['etiqueta'];
        }
        return $map;
    }
}

if (!function_exists('vn_etiqueta_resolver')) {
    /* Etiqueta de um registro: tenta pelo cliente_id e depois pelo nome (cadastro de clientes). */
    function vn_etiqueta_resolver(array $etqMap, array $idPorNome, $clienteId, string $clienteNome): string {
        $cid = (int)$clienteId;
        if ($cid > 0 && isset($etqMap[$cid])) return $etqMap[$cid];
        $k = vn_norm_key($clienteNome);
        if ($k !== '' && isset($idPorNome[$k]) && isset($etqMap[$idPorNome[$k]])) return $etqMap[$idPorNome[$k]];
        return '';
    }
}

if (!function_exists('vn_etiqueta_tag_class')) {
    /* Classe de cor da etiqueta (estável por nome). Usa as classes .vn-tag-c0..c5. */
    function vn_etiqueta_tag_class(string $nome): string {
        return 'vn-tag-c' . (abs(crc32(mb_strtolower($nome))) % 6);
    }
}

if (!function_exists('vn_etiqueta_css')) {
    /* CSS compartilhado das etiquetas (chips coloridos). Imprimir dentro de <style>. */
    function vn_etiqueta_css(): string {
        return '.vn-tag{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:.7rem;font-weight:800;border:1px solid transparent;white-space:nowrap}'
            . ".vn-tag::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.75}"
            . '.vn-tag-c0{background:#e0edff;border-color:#93c5fd;color: var(--primary-ink)}'
            . '.vn-tag-c1{background:#dcfce7;border-color:#86efac;color:#15803d}'
            . '.vn-tag-c2{background:#fef3c7;border-color:#fcd34d;color:#a16207}'
            . '.vn-tag-c3{background:#fae8ff;border-color:#e9d5ff;color:#9333ea}'
            . '.vn-tag-c4{background:#ffe4e6;border-color:#fda4af;color:#be123c}'
            . '.vn-tag-c5{background:#cffafe;border-color:#67e8f9;color:#0e7490}';
    }
}

if (!function_exists('vn_saida_cobrancas_auto_gerar')) {
    /*
        TODA saída de material vira cobrança automaticamente na central de Cobranças.
        Com cliente vinculado usa o cadastro; sem cliente usa o texto do motivo
        ou entra como "Sem cliente" (dá para editar depois).
        O valor sugerido vem do catálogo. Retorna quantas cobranças foram criadas.
    */
    function vn_saida_cobrancas_auto_gerar(): int {
        vn_ensure_saida_cobrancas_table();
        [$cond, $cp] = vn_saida_cat_cond('material');
        // A condição de categoria usa item_tipo sem alias — só existe na tabela de saídas.
        $sql = 'SELECT s.*, cat.valor_unitario AS catalogo_valor
                FROM vn_mobile_estoque_saidas s
                LEFT JOIN vn_mobile_orcamento_catalogo cat ON cat.id = s.catalogo_id
                LEFT JOIN vn_saida_cobrancas sc ON sc.saida_id = s.id
                WHERE sc.id IS NULL AND s.quantidade > 0'
                . ($cond ? ' AND ' . $cond : '') . '
                LIMIT 500';
        $criadas = 0;
        foreach (vn_fetch_all($sql, $cp) as $s) {
            $nome = vn_clean((string)($s['cliente_nome'] ?? ''));
            if ($nome === '' && (int)($s['cliente_id'] ?? 0) > 0) {
                $cli = vn_cliente_por_id((int)$s['cliente_id']);
                $nome = $cli ? vn_clean((string)($cli['nome'] ?? '')) : '';
            }
            if ($nome === '') $nome = vn_saida_cliente_texto($s);
            if ($nome === '') $nome = 'Sem cliente';
            $qtd = (float)($s['quantidade'] ?? 0);
            $valor = (float)($s['catalogo_valor'] ?? 0);
            // A cobrança fica no mesmo mês da saída.
            $criadoEm = (string)($s['criado_em'] ?? '') ?: vn_now_sp();
            vn_exec('INSERT INTO vn_saida_cobrancas (saida_id, cliente_id, cliente_nome, item_nome, quantidade, valor_unitario, valor_total, pago, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [(int)$s['id'], (int)($s['cliente_id'] ?? 0) ?: null, $nome, (string)($s['item_nome'] ?? 'Item'), $qtd, $valor, round($qtd * $valor, 2), $criadoEm]);
            $criadas++;
        }
        return $criadas;
    }
}

if (!function_exists('vn_cobr_add_pecas')) {
    /*
        Adiciona peças à cobrança de um cliente (modal "Adicionar peça" da central):
        valida o estoque de tudo antes, dá baixa, registra a saída com o cliente
        e cria a cobrança com o valor informado.
    */
    function vn_cobr_add_pecas(array $post): string {
        vn_ensure_saida_cobrancas_table();

        $clienteId = (int)($post['peca_cliente_id'] ?? 0);
        $clienteNome = vn_clean((string)($post['peca_cliente_nome'] ?? ''));
        if ($clienteId > 0) {
            $cli = vn_cliente_por_id($clienteId);
            if ($cli) $clienteNome = vn_clean((string)($cli['nome'] ?? ''));
        }
        if ($clienteNome === '') throw new Exception('Escolha o cliente que vai receber a cobrança das peças.');

        $ids  = $post['peca_catalogo_id'] ?? [];
        $qts  = $post['peca_qtd'] ?? [];
        $vals = $post['peca_valor'] ?? [];
        if (!is_array($ids)) $ids = [$ids];

        // Monta as linhas (mesmo item repetido soma a quantidade; o último valor vale).
        $linhas = [];
        foreach ($ids as $i => $idRaw) {
            $id = (int)$idRaw;
            if ($id <= 0) continue;
            $qtd = vn_money_db(is_array($qts) ? ($qts[$i] ?? 0) : 0);
            if ($qtd <= 0) throw new Exception('Informe a quantidade de todas as peças selecionadas.');
            $valor = vn_money_db(is_array($vals) ? ($vals[$i] ?? 0) : 0);
            if (!isset($linhas[$id])) $linhas[$id] = ['qtd' => 0.0, 'valor' => $valor];
            $linhas[$id]['qtd'] += $qtd;
            $linhas[$id]['valor'] = $valor;
        }

        // Serviços criados na hora (serv_nome[]/serv_qtd[]/serv_valor[]): linhas com nome vazio são ignoradas.
        $servNomes = $post['serv_nome'] ?? [];
        $servQts   = $post['serv_qtd'] ?? [];
        $servVals  = $post['serv_valor'] ?? [];
        if (!is_array($servNomes)) $servNomes = [$servNomes];
        $servicosAvulsos = [];
        foreach ($servNomes as $i => $nomeRaw) {
            $nomeServ = vn_clean((string)$nomeRaw);
            if ($nomeServ === '') continue;
            $qtdServ = vn_money_db(is_array($servQts) ? ($servQts[$i] ?? 1) : 1);
            if ($qtdServ <= 0) $qtdServ = 1;
            $valServ = vn_money_db(is_array($servVals) ? ($servVals[$i] ?? 0) : 0);
            if ($valServ <= 0) throw new Exception('Informe o valor do serviço "' . $nomeServ . '".');
            $servicosAvulsos[] = ['nome' => $nomeServ, 'qtd' => $qtdServ, 'valor' => $valServ];
        }

        if (empty($linhas) && empty($servicosAvulsos)) throw new Exception('Selecione ao menos uma peça ou informe um serviço para cobrar.');

        // Data da venda (padrão: hoje). Em outra data, a hora de agora vale só para manter a ordem do dia.
        $dataVenda = vn_date_db_or_null($post['peca_data'] ?? '');
        $outraData = $dataVenda !== null && $dataVenda !== vn_today_sp();
        $quando = $outraData ? $dataVenda . ' ' . date('H:i:s') : vn_now_sp();

        // Valida o estoque de todas as PEÇAS antes de aplicar (serviço não tem estoque).
        $rows = [];
        foreach ($linhas as $id => $l) {
            $row = vn_fetch_one('SELECT id, nome, tipo, quantidade_estoque, valor_unitario FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id]);
            if (!$row) throw new Exception('Peça não encontrada no estoque (ID ' . $id . ').');
            $ehServico = in_array((string)($row['tipo'] ?? ''), ['servico', 'mao_obra'], true);
            $atual = (float)($row['quantidade_estoque'] ?? 0);
            if (!$ehServico && $l['qtd'] > $atual) {
                throw new Exception('"' . $row['nome'] . '": quantidade (' . number_format($l['qtd'], 2, ',', '.') . ') maior que o estoque disponível (' . number_format($atual, 2, ',', '.') . ').');
            }
            $row['eh_servico'] = $ehServico;
            $rows[$id] = $row;
        }

        $resumo = [];
        $baixouEstoque = false;
        foreach ($linhas as $id => $l) {
            $row = $rows[$id];
            $ehServico = !empty($row['eh_servico']);
            $valor = $l['valor'] > 0 ? $l['valor'] : (float)($row['valor_unitario'] ?? 0);
            if (!$ehServico) {
                if (!vn_estoque_ajustar((int)$id, -$l['qtd'])) {
                    throw new Exception('O estoque de "' . $row['nome'] . '" mudou e já não é suficiente. Atualize a página e tente de novo.');
                }
                $baixouEstoque = true;
            }
            // Serviço entra com catalogo_id 0: excluir depois não devolve nada ao estoque.
            vn_exec('INSERT INTO vn_mobile_estoque_saidas (catalogo_id, origem, item_nome, item_tipo, quantidade, motivo, responsavel, cliente_id, cliente_nome, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($outraData ? '?' : 'NOW()') . ')',
                array_merge([$ehServico ? 0 : $id, 'mobile', (string)$row['nome'], (string)($row['tipo'] ?? 'material'), $l['qtd'], $ehServico ? 'Serviço da venda' : 'Cobrança de material', null, $clienteId ?: null, $clienteNome], $outraData ? [$quando] : []));
            $saidaId = vn_last_id();
            vn_exec('INSERT INTO vn_saida_cobrancas (saida_id, cliente_id, cliente_nome, item_nome, quantidade, valor_unitario, valor_total, pago, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$saidaId, $clienteId ?: null, $clienteNome, (string)$row['nome'], $l['qtd'], $valor, round($l['qtd'] * $valor, 2), $quando]);
            $resumo[] = $row['nome'] . ' (' . number_format($l['qtd'], 2, ',', '.') . ')';
        }

        foreach ($servicosAvulsos as $sv) {
            vn_exec('INSERT INTO vn_mobile_estoque_saidas (catalogo_id, origem, item_nome, item_tipo, quantidade, motivo, responsavel, cliente_id, cliente_nome, criado_em) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($outraData ? '?' : 'NOW()') . ')',
                array_merge(['mobile', $sv['nome'], 'servico', $sv['qtd'], 'Serviço da venda', null, $clienteId ?: null, $clienteNome], $outraData ? [$quando] : []));
            $saidaId = vn_last_id();
            vn_exec('INSERT INTO vn_saida_cobrancas (saida_id, cliente_id, cliente_nome, item_nome, quantidade, valor_unitario, valor_total, pago, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$saidaId, $clienteId ?: null, $clienteNome, $sv['nome'], $sv['qtd'], $sv['valor'], round($sv['qtd'] * $sv['valor'], 2), $quando]);
            $resumo[] = $sv['nome'] . ' (' . number_format($sv['qtd'], 2, ',', '.') . ')';
        }

        return count($resumo) . ' item(ns) adicionado(s) na venda de ' . $clienteNome . ($outraData ? ' (data ' . vn_date_br($dataVenda) . ')' : '') . ': ' . implode(', ', $resumo)
            . ($baixouEstoque ? '. O estoque já foi baixado.' : '.');
    }
}

if (!function_exists('vn_cobr_set_data_peca')) {
    /*
        Muda a DATA de uma peça da venda (mantém a hora que ela já tinha). Acompanha a saída de estoque
        correspondente, para os dois relatórios dizerem a mesma coisa. $novaData: 'AAAA-MM-DD'.
    */
    function vn_cobr_set_data_peca(array $peca, string $novaData): void {
        $hora = substr((string)($peca['criado_em'] ?? ''), 11, 8);
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) $hora = date('H:i:s');
        $novo = $novaData . ' ' . $hora;
        vn_exec('UPDATE vn_saida_cobrancas SET criado_em = ? WHERE id = ?', [$novo, (int)$peca['id']]);
        if ((int)($peca['saida_id'] ?? 0) > 0) {
            vn_exec('UPDATE vn_mobile_estoque_saidas SET criado_em = ? WHERE id = ?', [$novo, (int)$peca['saida_id']]);
        }
    }
}

if (!function_exists('vn_cobr_mudar_data_venda')) {
    /*
        Muda a data de TODAS as peças ainda não aprovadas de um cliente em um mês (a "venda" da central).
        Devolve quantas peças mudaram. A venda passa a aparecer no mês da nova data.
    */
    function vn_cobr_mudar_data_venda(string $cliente, string $mes, string $novaData): int {
        if ($cliente === '' || !preg_match('/^\d{4}-\d{2}$/', $mes)) throw new Exception('Cliente ou mês inválido.');
        $iniMes = $mes . '-01 00:00:00';
        $fimMes = date('Y-m-01 00:00:00', strtotime($mes . '-01 +1 month'));
        $itens = vn_fetch_all(
            'SELECT * FROM vn_saida_cobrancas WHERE cliente_nome = ? AND cobranca_id IS NULL AND criado_em >= ? AND criado_em < ? ORDER BY id ASC',
            [$cliente, $iniMes, $fimMes]);
        if (!$itens) throw new Exception('Nenhuma peça aguardando aprovação para este cliente neste mês.');
        foreach ($itens as $i) vn_cobr_set_data_peca($i, $novaData);
        return count($itens);
    }
}

if (!function_exists('vn_cobr_tipos_catalogo')) {
    /* Tipos que dá para escolher ao cadastrar/editar um material na tela de Cobranças (ferramentas têm tela própria). */
    function vn_cobr_tipos_catalogo(): array {
        return ['material' => 'Peça / material', 'equipamento' => 'Equipamento', 'produto_usado' => 'Produto usado',
                'servico' => 'Serviço', 'mao_obra' => 'Mão de obra', 'outro' => 'Outro item'];
    }
}

if (!function_exists('vn_cobr_catalogo_salvar')) {
    /*
        Cadastra (catalogo_id vazio) ou altera um item do catálogo/estoque pela janela "Cobrar peça ou serviço":
        nome, tipo, valor de venda, custo, unidade, quantidade em estoque, foto e descrição.
        $foto = caminho da foto nova já enviada ('' = manter a atual). Só mexe no estoque se a quantidade foi mudada de propósito
        (catalogo_estoque_orig guarda o valor que a pessoa viu ao abrir a janela).
        Devolve ['id' => id do item, 'nome' => ..., 'novo' => bool].
    */
    function vn_cobr_catalogo_salvar(array $post, string $foto = ''): array {
        vn_ensure_mobile_tables();
        $id = (int)($post['catalogo_id'] ?? 0);
        $tipo = (string)($post['catalogo_tipo'] ?? 'material');
        if (!isset(vn_cobr_tipos_catalogo()[$tipo])) $tipo = 'material';
        $ehServico = in_array($tipo, ['servico', 'mao_obra'], true);
        $nome = vn_clean($post['catalogo_nome'] ?? '');
        if ($nome === '') throw new Exception('Informe o nome do material ou serviço.');
        if (mb_strlen($nome) > 190) throw new Exception('O nome passou de 190 letras.');
        $valorTxt = trim((string)($post['catalogo_valor'] ?? ''));
        $valor = vn_money_db($valorTxt);
        if ($valor < 0 || $valor > 9999999.99) throw new Exception('Confira o valor de venda.');
        $custo = vn_money_db($post['catalogo_custo'] ?? 0);
        if ($custo < 0 || $custo > 9999999.99) throw new Exception('Confira o custo.');
        $unidade = vn_clean($post['catalogo_unidade'] ?? 'un') ?: 'un';
        $descricao = trim((string)($post['catalogo_descricao'] ?? ''));
        if ($descricao === '') $descricao = $nome;
        $qtdTxt = trim((string)($post['catalogo_quantidade_estoque'] ?? ''));
        $qtd = $qtdTxt === '' ? 0.0 : vn_money_db($qtdTxt);
        if ($qtd < 0 || $qtd > 9999999.99) throw new Exception('Confira a quantidade em estoque.');
        if ($ehServico) $qtd = 0.0;   // serviço não tem estoque

        $linha = vn_linha_catalogo($post['catalogo_linha'] ?? '', $tipo);   // CFTV, serralheria ou (só serviço) as duas
        if ($id <= 0) {
            vn_exec('INSERT INTO vn_mobile_orcamento_catalogo (tipo, nome, descricao, foto_url, unidade, valor_unitario, custo_unitario, quantidade_estoque, status, linha, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$tipo, $nome, $descricao, $foto !== '' ? $foto : null, $unidade, $valor, $custo, $qtd, 'ativo', $linha]);
            return ['id' => vn_last_id(), 'nome' => $nome, 'novo' => true];
        }

        $atual = vn_fetch_one('SELECT * FROM vn_mobile_orcamento_catalogo WHERE id = ? LIMIT 1', [$id]);
        if (!$atual) throw new Exception('Esse material não está mais no catálogo. Atualize a página.');
        $sets = ['tipo = ?', 'nome = ?', 'descricao = ?', 'unidade = ?', 'valor_unitario = ?', 'custo_unitario = ?', 'atualizado_em = NOW()'];
        $par = [$tipo, $nome, $descricao, $unidade, $valor, $custo];
        if (isset($post['catalogo_linha'])) { $sets[] = 'linha = ?'; $par[] = $linha; }   // só muda a linha se o formulário mandou
        $orig = trim((string)($post['catalogo_estoque_orig'] ?? ''));
        $mudouEstoque = $ehServico ? (float)$atual['quantidade_estoque'] != 0.0 : ($orig === '' || abs(vn_money_db($orig) - $qtd) > 0.0001);
        if ($mudouEstoque) { $sets[] = 'quantidade_estoque = ?'; $par[] = $qtd; }
        if ($foto !== '') { $sets[] = 'foto_url = ?'; $par[] = $foto; }
        $par[] = $id;
        vn_exec('UPDATE vn_mobile_orcamento_catalogo SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1', $par);
        return ['id' => $id, 'nome' => $nome, 'novo' => false];
    }
}

/* ======================================================================
   Mensalidades — funções compartilhadas entre cobrancas.php e mensalidades.php
====================================================================== */

if (!function_exists('vn_ensure_mensalidades_tables')) {
    function vn_ensure_mensalidades_tables(): void {
        vn_exec("CREATE TABLE IF NOT EXISTS vn_mensalidades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            cliente_nome VARCHAR(190) NOT NULL,
            telefone VARCHAR(40) NULL,
            chave_pix VARCHAR(180) NULL,
            valor_mensal DECIMAL(12,2) NOT NULL DEFAULT 0,
            dia_vencimento INT NOT NULL DEFAULT 10,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            observacao TEXT NULL,
            criado_em DATETIME NOT NULL,
            INDEX (cliente_nome),
            INDEX (ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        vn_exec("CREATE TABLE IF NOT EXISTS vn_mensalidade_pagamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mensalidade_id INT NOT NULL,
            competencia CHAR(7) NOT NULL,
            valor DECIMAL(12,2) NOT NULL DEFAULT 0,
            vencimento DATE NULL,
            pago TINYINT(1) NOT NULL DEFAULT 0,
            pago_em DATE NULL,
            UNIQUE KEY uq_vn_mens_comp (mensalidade_id, competencia),
            INDEX (competencia),
            INDEX (pago)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        vn_add_col_if_missing('vn_mensalidades', 'categoria', "VARCHAR(80) NULL");
    }
}

if (!function_exists('vn_mens_vencimento')) {
    /* Data de vencimento de uma competência (Y-m) respeitando meses curtos. */
    function vn_mens_vencimento(string $competencia, int $dia): string {
        $ano = (int)substr($competencia, 0, 4);
        $mes = (int)substr($competencia, 5, 2);
        $ultimoDia = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $diaUsar = max(1, min($dia, $ultimoDia));
        return sprintf('%04d-%02d-%02d', $ano, $mes, $diaUsar);
    }
}

if (!function_exists('vn_mens_gerar_mes')) {
    /* Garante o registro de pagamento do mês para cada mensalidade ativa. */
    function vn_mens_gerar_mes(string $competencia): void {
        // Dá para navegar para meses e anos distantes: não cria linhas de mês inválido nem a mais de 3 anos à frente.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia) || $competencia > date('Y-m', strtotime('+36 months'))) return;
        $lista = vn_fetch_all('SELECT * FROM vn_mensalidades WHERE ativo = 1');
        foreach ($lista as $m) {
            $inicio = substr((string)($m['criado_em'] ?? ''), 0, 7);
            if ($inicio !== '' && $competencia < $inicio) continue; // não gera mês anterior ao cadastro
            $existe = vn_fetch_one('SELECT id FROM vn_mensalidade_pagamentos WHERE mensalidade_id = ? AND competencia = ? LIMIT 1', [(int)$m['id'], $competencia]);
            if ($existe) continue;
            vn_exec('INSERT INTO vn_mensalidade_pagamentos (mensalidade_id, competencia, valor, vencimento, pago) VALUES (?, ?, ?, ?, 0)',
                [(int)$m['id'], $competencia, (float)$m['valor_mensal'], vn_mens_vencimento($competencia, (int)$m['dia_vencimento'])]);
        }
    }
}

if (!function_exists('vn_mens_categoria')) {
    /* Nome da etiqueta/categoria da mensalidade (usa um padrão quando não informada). */
    function vn_mens_categoria(array $m): string {
        $c = trim((string)($m['categoria'] ?? ''));
        return $c !== '' ? $c : 'Mensalidade';
    }
}

require_once __DIR__ . '/_vn_pix.php';

if (!function_exists('vn_mens_pix')) {
    /* Código PIX copia e cola da mensalidade (já com o valor do mês). '' se não houver chave utilizável. */
    function vn_mens_pix(array $m, array $p): string {
        return vn_pix_payload((string)($m['chave_pix'] ?? ''), (float)($p['valor'] ?? 0));
    }
}

if (!function_exists('vn_mens_whatsapp')) {
    /* Link do WhatsApp para lembrar o cliente da mensalidade do mês. */
    function vn_mens_whatsapp(array $m, array $p): string {
        $fone = preg_replace('/\D+/', '', (string)($m['telefone'] ?? ''));
        if ($fone !== '' && strlen($fone) <= 11) $fone = '55' . $fone;
        $comp = (string)($p['competencia'] ?? '');
        $mesLabel = strlen($comp) === 7 ? (substr($comp, 5, 2) . '/' . substr($comp, 0, 4)) : $comp;
        $cat = vn_mens_categoria($m);
        $msg = 'Olá ' . trim((string)($m['cliente_nome'] ?? '')) . ', tudo bem? '
            . 'Passando para lembrar da mensalidade' . ($cat !== 'Mensalidade' ? ' de ' . $cat : '') . ' de ' . $mesLabel
            . ' no valor de ' . vn_money($p['valor'] ?? 0)
            . ' com vencimento em ' . vn_date_br($p['vencimento'] ?? '') . '.';
        if (!empty($m['chave_pix'])) $msg .= "\nChave PIX: " . $m['chave_pix'];
        $pix = vn_mens_pix($m, $p);
        if ($pix !== '') $msg .= "\n\nPIX copia e cola (já com o valor):\n" . $pix;
        return 'https://wa.me/' . $fone . '?text=' . rawurlencode($msg);
    }
}

if (!function_exists('vn_mens_criar')) {
    /* Cadastra uma nova mensalidade (usado por mensalidades.php e cobrancas.php). */
    function vn_mens_criar(array $post, array $clientesLista): string {
        $clienteId = (int)($post['cliente_id'] ?? 0);
        $nome = ''; $telefoneCliente = '';
        foreach ($clientesLista as $c) {
            if ((int)$c['id'] === $clienteId) { $nome = (string)$c['nome']; $telefoneCliente = (string)($c['telefone'] ?? ''); break; }
        }
        if ($nome === '') $nome = vn_clean($post['cliente_nome'] ?? '');
        if ($nome === '') throw new Exception('Selecione um cliente ou informe o nome.');

        $valor = vn_money_db($post['valor_mensal'] ?? '0');
        if ($valor <= 0) throw new Exception('Informe o valor da mensalidade.');
        $dia = (int)($post['dia_vencimento'] ?? 10);
        if ($dia < 1 || $dia > 31) $dia = 10;
        $telefone = vn_clean($post['telefone'] ?? '');
        if ($telefone === '') $telefone = $telefoneCliente;
        $categoria = vn_clean($post['categoria'] ?? '');
        if ($categoria === '') $categoria = 'Mensalidade';

        // Sem limite: o cliente pode ter quantas mensalidades precisar, inclusive com a mesma etiqueta.
        vn_insert_dynamic('vn_mensalidades', [
            'cliente_id'     => $clienteId ?: null,
            'cliente_nome'   => $nome,
            'categoria'      => $categoria,
            'telefone'       => $telefone,
            'chave_pix'      => vn_clean($post['chave_pix'] ?? ''),
            'valor_mensal'   => $valor,
            'dia_vencimento' => $dia,
            'ativo'          => 1,
            'observacao'     => trim((string)($post['observacao'] ?? '')),
            'criado_em'      => vn_now_sp(),
        ]);
        return 'Cobrança "' . $categoria . '" de ' . $nome . ' cadastrada: ' . vn_money($valor) . ' todo dia ' . $dia . '.';
    }
}

if (!function_exists('vn_cobr_excluir_peca')) {
    /*
        Exclui uma peça da cobrança do cliente e DEVOLVE a quantidade ao estoque
        (exclui também a saída vinculada, igual ao excluir de Saídas de estoque).
    */
    function vn_cobr_excluir_peca(int $cobrancaId): string {
        $c = vn_fetch_one('SELECT * FROM vn_saida_cobrancas WHERE id = ? LIMIT 1', [$cobrancaId]);
        if (!$c) throw new Exception('Peça da cobrança não encontrada.');
        vn_exec('DELETE FROM vn_saida_cobrancas WHERE id = ?', [$cobrancaId]);

        $saidaId = (int)($c['saida_id'] ?? 0);
        $s = $saidaId > 0 ? vn_fetch_one('SELECT id, catalogo_id, item_tipo FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [$saidaId]) : null;
        if ($s) {
            // Serviço não tem estoque: só remove o registro, sem devolver nada.
            $ehServico = (int)($s['catalogo_id'] ?? 0) === 0 || in_array((string)($s['item_tipo'] ?? ''), ['servico', 'mao_obra'], true);
            if ($ehServico) {
                vn_exec('DELETE FROM vn_mobile_estoque_saidas WHERE id = ? LIMIT 1', [$saidaId]);
                return 'Serviço removido da venda (serviço não mexe no estoque).';
            }
            return 'Peça removida da cobrança. ' . vn_excluir_saida_estoque_v1($saidaId);
        }
        return 'Peça removida da cobrança. A saída original não existe mais, então o estoque não foi alterado.';
    }
}

/* ======================================================================
   PDFs da central de cobranças (usam a classe VNMobPdfV5 do bootstrap)
====================================================================== */

if (!function_exists('vn_pdf_recibo_cobranca_v1')) {
    /* Recibo em PDF de uma cobrança avulsa — mesmo visual do PDF de orçamento. */
    function vn_pdf_recibo_cobranca_v1(int $id): string {
        $cob = vn_fetch_one('SELECT * FROM vn_cobrancas WHERE id = ? LIMIT 1', [$id]);
        if (!$cob) throw new Exception('Cobrança não encontrada.');
        $parcelas = vn_fetch_all('SELECT * FROM vn_cobranca_parcelas WHERE cobranca_id = ? ORDER BY numero ASC', [$id]);

        $totalPago = 0.0; $totalGeral = 0.0; $ultimoPagoEm = '';
        foreach ($parcelas as $p) {
            $totalGeral += (float)$p['valor'];
            if ((int)$p['pago'] === 1) {
                $totalPago += (float)$p['valor'];
                if (!empty($p['pago_em']) && $p['pago_em'] > $ultimoPagoEm) $ultimoPagoEm = (string)$p['pago_em'];
            }
        }
        $restante = max(0, $totalGeral - $totalPago);

        $pdf = new VNMobPdfV5();
        $pdf->header('');
        $pdf->section('Recibo de pagamento');
        $pdf->two('Recebemos de', $cob['cliente_nome'] ?? '', 'Data de emissão', date('d/m/Y'));
        $pdf->two('Telefone', ($cob['telefone'] ?? '') !== '' ? $cob['telefone'] : 'Não informado', 'Referência', 'Cobrança nº ' . $id . ' de ' . vn_date_br(substr((string)$cob['criado_em'], 0, 10)));
        if (!empty($cob['observacao'])) $pdf->block('Referente a', (string)$cob['observacao']);

        $pdf->section('Parcelas');
        $widths = [30, 90, 120, 105, 178];
        $pdf->row(['#', 'Parcela', 'Vencimento', 'Valor', 'Situação'], $widths, true);
        foreach ($parcelas as $i => $p) {
            $sit = (int)$p['pago'] === 1
                ? ('Paga' . (!empty($p['pago_em']) ? ' em ' . vn_date_br($p['pago_em']) : ''))
                : 'Em aberto';
            $pdf->row([
                (string)($i + 1),
                (int)$p['numero'] . '/' . (int)($cob['parcelas_total'] ?? count($parcelas)),
                vn_date_br($p['vencimento'] ?? ''),
                vn_money($p['valor']),
                $sit,
            ], $widths, false);
        }

        $pdf->section('Valores');
        $pdf->two('Valor total da cobrança', vn_money($totalGeral), 'Restante em aberto', vn_money($restante));
        $pdf->total('Total recebido', vn_money($totalPago));
        $quando = $ultimoPagoEm !== '' ? (' O último pagamento foi registrado em ' . vn_date_br($ultimoPagoEm) . '.') : '';
        $pdf->block('Declaração', 'Declaramos, para os devidos fins, que recebemos de ' . trim((string)($cob['cliente_nome'] ?? ''))
            . ' a quantia de ' . vn_money($totalPago) . ' referente à cobrança acima.' . $quando);
        $pdf->sign();
        return $pdf->output();
    }
}

if (!function_exists('vn_pdf_vendas_material_v1')) {
    /*
        PDF com os materiais das vendas (vn_saida_cobrancas), com opções de conteúdo
        no estilo do PDF de orçamento:
        - mes: '' (todos) ou 'AAAA-MM'
        - status: 'pendentes' (aguardando aprovação) | 'aprovadas' | 'todas'
        - mostrar_valores, mostrar_unitario, mostrar_data, agrupar_cliente: 0/1
    */
    function vn_pdf_vendas_material_v1(array $opt): string {
        $mes            = (string)($opt['mes'] ?? '');
        $status         = (string)($opt['status'] ?? 'todas');
        $mostrarValores = (int)($opt['mostrar_valores'] ?? 1) === 1;
        $mostrarUnit    = (int)($opt['mostrar_unitario'] ?? 1) === 1 && $mostrarValores;
        $mostrarData    = (int)($opt['mostrar_data'] ?? 1) === 1;
        $agruparCliente = (int)($opt['agrupar_cliente'] ?? 1) === 1;

        $where = []; $params = [];
        if (preg_match('/^\d{4}-\d{2}$/', $mes)) {
            // Intervalo de datas em vez de DATE_FORMAT = ? (evita erro de collation em alguns servidores).
            $where[] = 'criado_em >= ? AND criado_em < ?';
            $params[] = $mes . '-01 00:00:00';
            $params[] = date('Y-m-01 00:00:00', strtotime($mes . '-01 +1 month'));
        }
        if ($status === 'pendentes') $where[] = 'cobranca_id IS NULL';
        elseif ($status === 'aprovadas') $where[] = 'cobranca_id IS NOT NULL';
        $sql = 'SELECT * FROM vn_saida_cobrancas'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY criado_em DESC, cliente_nome ASC, id DESC LIMIT 2000';
        $itens = vn_fetch_all($sql, $params);

        // Agrupa por mês e, dentro do mês, por cliente.
        $porMes = [];
        foreach ($itens as $i) {
            $m = substr((string)($i['criado_em'] ?? ''), 0, 7);
            if (strlen($m) !== 7) $m = date('Y-m');
            $porMes[$m][(string)$i['cliente_nome']][] = $i;
        }
        krsort($porMes);

        $tituloStatus = ['pendentes' => 'aguardando aprovação', 'aprovadas' => 'já aprovados'][$status] ?? 'todos';

        $pdf = new VNMobPdfV5();
        $pdf->header('');
        $pdf->section('Materiais das vendas (' . $tituloStatus . ')');
        $pdf->two('Período', $mes !== '' ? (substr($mes, 5, 2) . '/' . substr($mes, 0, 4)) : 'Todos os meses', 'Emitido em', date('d/m/Y H:i'));

        if (!$porMes) {
            $pdf->block('Sem materiais', 'Nenhum material encontrado com esses filtros.');
            $pdf->sign();
            return $pdf->output();
        }

        // Colunas conforme as opções escolhidas (largura útil da página: 523).
        $headers = ['#']; $widths = [24];
        if ($mostrarData)     { $headers[] = 'Data';    $widths[] = 62; }
        if (!$agruparCliente) { $headers[] = 'Cliente'; $widths[] = 120; }
        $wPeca = 523 - array_sum($widths) - 46 - ($mostrarUnit ? 70 : 0) - ($mostrarValores ? 74 : 0);
        $headers[] = 'Peça'; $widths[] = max(120, $wPeca);
        $headers[] = 'Qtd';  $widths[] = 46;
        if ($mostrarUnit)    { $headers[] = 'Valor un.'; $widths[] = 70; }
        if ($mostrarValores) { $headers[] = 'Total';     $widths[] = 74; }

        $totalGeral = 0.0;
        foreach ($porMes as $m => $clientes) {
            $pdf->section('Mês ' . substr($m, 5, 2) . '/' . substr($m, 0, 4));
            $totalMes = 0.0;
            foreach ($clientes as $nomeCli => $lista) {
                if ($agruparCliente) $pdf->block('Cliente', $nomeCli);
                $pdf->row($headers, $widths, true);
                $n = 0; $totalCli = 0.0;
                foreach ($lista as $i) {
                    $n++;
                    $linha = [(string)$n];
                    if ($mostrarData)     $linha[] = date('d/m/y', strtotime((string)($i['criado_em'] ?? 'now')));
                    if (!$agruparCliente) $linha[] = (string)$i['cliente_nome'];
                    $linha[] = (string)$i['item_nome'];
                    $linha[] = number_format((float)$i['quantidade'], 2, ',', '.');
                    if ($mostrarUnit)    $linha[] = vn_money($i['valor_unitario']);
                    if ($mostrarValores) $linha[] = vn_money($i['valor_total']);
                    $pdf->row($linha, $widths, false);
                    $totalCli += (float)$i['valor_total'];
                }
                $totalMes += $totalCli;
                if ($mostrarValores && $agruparCliente) $pdf->block('Total de ' . $nomeCli, vn_money($totalCli));
            }
            $totalGeral += $totalMes;
            if ($mostrarValores) $pdf->block('Total do mês ' . substr($m, 5, 2) . '/' . substr($m, 0, 4), vn_money($totalMes));
        }
        if ($mostrarValores) $pdf->total('Total geral', vn_money($totalGeral));
        $pdf->sign();
        return $pdf->output();
    }
}

