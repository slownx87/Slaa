<?php
/*
    VN System - Central de Cobranças
    UMA lista só, ordenada por urgência (atrasadas, esta semana, mais adiante, material para aprovar):
    - cobranças em parcelas (PIX), mensalidades do mês e vendas de material (vêm das saídas de estoque);
    - cobrar no WhatsApp, copiar PIX e marcar como pago direto na linha;
    - detalhe, edição e exclusão em janelas; etiquetas agrupam condomínios da mesma empresa;
    - venda de material: a data da venda (e de cada peça) pode ser escolhida e mudada, e o vencimento é escolhido ao aprovar;
    - "Cobrar peça ou serviço": dá para editar (nome, tipo, valor, foto...) e cadastrar materiais novos sem sair da janela.
    As ações (POST) são as mesmas de sempre; a tela fica em _vn_cobrancas_view.php, assets/css/vn-cobrancas.css e assets/js/vn-cobrancas.js.
*/
require_once __DIR__ . '/_vn_orcamento_layout.php';
require_once __DIR__ . '/_vn_cobranca_equip.php';
require_once __DIR__ . '/_vn_cobrancas_lib.php';
require_once __DIR__ . '/_vn_cobrancas_view.php';

/* ----------------------------------------------------------------------
   Tabelas (criadas automaticamente na primeira vez)
---------------------------------------------------------------------- */
function vn_ensure_cobrancas_tables(): void {
    vn_exec("CREATE TABLE IF NOT EXISTS vn_cobrancas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_nome VARCHAR(180) NOT NULL,
        telefone VARCHAR(40) NULL,
        chave_pix VARCHAR(180) NULL,
        valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        parcelas_total INT NOT NULL DEFAULT 1,
        dia_pix INT NULL,
        observacao TEXT NULL,
        criado_em DATETIME NOT NULL,
        INDEX (cliente_nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    vn_exec("CREATE TABLE IF NOT EXISTS vn_cobranca_parcelas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cobranca_id INT NOT NULL,
        numero INT NOT NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        vencimento DATE NULL,
        pago TINYINT(1) NOT NULL DEFAULT 0,
        pago_em DATE NULL,
        INDEX (cobranca_id),
        INDEX (vencimento),
        INDEX (pago)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Soma N meses a uma data tratando o "dia do mês" (ex.: dia 31 vira último dia do mês curto). */
function vn_venc_data(string $primeira, int $dia, int $mesesOffset): string {
    $base = DateTime::createFromFormat('Y-m-d', $primeira) ?: new DateTime('today');
    $ano = (int)$base->format('Y');
    $mes = (int)$base->format('n') + $mesesOffset;
    $ano += intdiv($mes - 1, 12);
    $mes = (($mes - 1) % 12) + 1;
    if ($mes <= 0) { $mes += 12; $ano--; }
    $ultimoDia = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));
    $diaUsar = max(1, min($dia, $ultimoDia));
    return sprintf('%04d-%02d-%02d', $ano, $mes, $diaUsar);
}

/* WhatsApp para cobrar o cliente de uma parcela. */
/* Código PIX copia e cola da parcela (com o valor dela). '' se a chave não for utilizável. */
function vn_cobranca_pix(array $cob, array $parc): string {
    return vn_pix_payload((string)($cob['chave_pix'] ?? ''), (float)($parc['valor'] ?? 0));
}

function vn_cobranca_whatsapp(array $cob, array $parc): string {
    $fone = preg_replace('/\D+/', '', (string)($cob['telefone'] ?? ''));
    if ($fone !== '' && strlen($fone) <= 11) $fone = '55' . $fone;
    $venc = vn_date_br($parc['vencimento'] ?? '');
    $msg = 'Olá ' . trim((string)($cob['cliente_nome'] ?? '')) . ', tudo bem? '
        . 'Passando para lembrar da parcela ' . (int)$parc['numero'] . '/' . (int)$cob['parcelas_total']
        . ' no valor de ' . vn_money($parc['valor'] ?? 0) . ' com vencimento em ' . $venc . '.';
    if (!empty($cob['chave_pix'])) $msg .= "\nChave PIX: " . $cob['chave_pix'];
    $pix = vn_cobranca_pix($cob, $parc);
    if ($pix !== '') $msg .= "\n\nPIX copia e cola (já com o valor):\n" . $pix;
    $base = 'https://wa.me/' . ($fone !== '' ? $fone : '') . '?text=' . rawurlencode($msg);
    return $base;
}

/* Estado de uma parcela: pago | vencida | pendente. */
function vn_parcela_estado(array $p, string $hoje): string {
    if ((int)($p['pago'] ?? 0) === 1) return 'pago';
    if (!empty($p['vencimento']) && $p['vencimento'] < $hoje) return 'vencida';
    return 'pendente';
}


/* Lê as parcelas montadas no formulário (parc_venc[]/parc_valor[]/parc_id[]),
   valida e devolve ordenado por vencimento. Lança exceção em linha inválida. */
function cobr_parse_parcelas_post(array $post): array {
    $vencs = $post['parc_venc'] ?? [];
    $vals  = $post['parc_valor'] ?? [];
    $ids   = $post['parc_id'] ?? [];
    if (!is_array($vencs) || !is_array($vals)) return [];
    $out = [];
    foreach ($vencs as $i => $vRaw) {
        $venc  = vn_date_db_or_null($vRaw);
        $valor = vn_money_db($vals[$i] ?? '0');
        $id    = isset($ids[$i]) ? (int)$ids[$i] : 0;
        if (!$venc && $valor <= 0) continue; // linha em branco: ignora
        if (!$venc)      throw new Exception('Há uma parcela sem vencimento válido.');
        if ($valor <= 0) throw new Exception('Há uma parcela sem valor válido.');
        $out[] = ['id' => $id, 'vencimento' => $venc, 'valor' => $valor];
    }
    usort($out, function ($a, $b) { return strcmp($a['vencimento'], $b['vencimento']); });
    return $out;
}

$ok = null; $erro = '';
$abrirId = 0;   // cobrança em parcelas cuja janela abre depois da ação
$abrirCli = ''; // venda de material cuja janela abre depois da ação (chave "AAAA-MM|nome do cliente")
$abrirPeca = false;   // reabrir a janela "Cobrar peça ou serviço" (depois de editar/cadastrar um material)
$catalogoSalvoId = 0; // material que acabou de ser cadastrado/alterado
$catalogoNovo = false; // ...e se ele é novo (aí já vem marcado para cobrar)
$clientesLista = [];
$orcPre = null; // dados vindos de um pedido (?orc=ID) para pré-preencher a nova cobrança

try {
    vn_ensure_cobrancas_tables();
    vn_ensure_saida_cobrancas_table();
    vn_ensure_cliente_etiquetas_table();
    vn_ensure_mensalidades_tables();
    $clientesLista = vn_clientes_form();

    /* Vindo de um pedido aprovado (?orc=ID): abre "Cobrança em parcelas" já com cliente, telefone, valor e referência. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (int)($_GET['orc'] ?? 0) > 0) {
        $o = vn_orcamento_mobile_get_v4((int)$_GET['orc']);
        if ($o) {
            $orcPre = [
                'cliente_id' => (int)($o['cliente_id'] ?? 0),
                'telefone'   => (string)($o['cliente_telefone'] ?? ''),
                'valor'      => (float)($o['valor_total'] ?? 0),
                'obs'        => 'Referente ao pedido ' . ($o['numero'] ?? ('#' . (int)$_GET['orc'])) . '.',
                'numero'     => (string)($o['numero'] ?? ''),
            ];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string)($_POST['acao'] ?? '');

        /* ---- Peças das vendas de material (aguardando aprovação) ---- */
        if ($acao === 'adicionar_pecas') {
            $ok = vn_cobr_add_pecas($_POST);
            $nomeCli = vn_clean((string)($_POST['peca_cliente_nome'] ?? ''));
            if ($nomeCli === '' && (int)($_POST['peca_cliente_id'] ?? 0) > 0) {
                $cli = vn_cliente_por_id((int)$_POST['peca_cliente_id']);
                if ($cli) $nomeCli = vn_clean((string)($cli['nome'] ?? ''));
            }
            $dataPeca = vn_date_db_or_null($_POST['peca_data'] ?? '') ?: vn_today_sp();
            if ($nomeCli !== '') $abrirCli = substr($dataPeca, 0, 7) . '|' . $nomeCli;
        }

        if ($acao === 'editar_peca') {
            $id = (int)($_POST['peca_id'] ?? 0);
            $c = vn_fetch_one('SELECT * FROM vn_saida_cobrancas WHERE id = ? LIMIT 1', [$id]);
            if (!$c) throw new Exception('Peça da venda não encontrada.');
            $qtd = vn_money_db($_POST['quantidade'] ?? '0');
            $valorUnit = vn_money_db($_POST['valor_unitario'] ?? '0');
            if ($qtd <= 0) throw new Exception('Informe uma quantidade maior que zero.');
            if ($valorUnit < 0) $valorUnit = 0;
            // nome (só na cobrança: o que o cliente vê) e data da peça
            $nomePeca = isset($_POST['item_nome']) ? vn_clean((string)$_POST['item_nome']) : (string)$c['item_nome'];
            if ($nomePeca === '') throw new Exception('Informe o nome da peça.');
            $dataNova = null;
            if (isset($_POST['data_peca']) && trim((string)$_POST['data_peca']) !== '') {
                $dataNova = vn_date_db_or_null($_POST['data_peca']);
                if ($dataNova === null) throw new Exception('A data da peça não é válida.');
            }
            vn_exec('UPDATE vn_saida_cobrancas SET item_nome = ?, quantidade = ?, valor_unitario = ?, valor_total = ? WHERE id = ?',
                [$nomePeca, $qtd, $valorUnit, round($qtd * $valorUnit, 2), $id]);
            $mudouData = $dataNova !== null && $dataNova !== substr((string)$c['criado_em'], 0, 10);
            if ($mudouData) vn_cobr_set_data_peca($c, $dataNova);
            $ok = 'Peça "' . $nomePeca . '" atualizada (' . vn_money($qtd * $valorUnit) . ')' . ($mudouData ? ', agora na data ' . vn_date_br($dataNova) : '') . '.';
            $abrirCli = substr($mudouData ? $dataNova : (string)$c['criado_em'], 0, 7) . '|' . (string)$c['cliente_nome'];
        }

        /* ---- Mudar a data da venda inteira (todas as peças do cliente naquele mês) ---- */
        if ($acao === 'mudar_data_venda') {
            $nomeCli = vn_clean((string)($_POST['av_cliente'] ?? ''));
            $mesAv = (string)($_POST['av_mes'] ?? '');
            $dataNova = vn_date_db_or_null($_POST['nova_data'] ?? '');
            if ($dataNova === null) throw new Exception('Escolha uma data válida para a venda.');
            $qtdMov = vn_cobr_mudar_data_venda($nomeCli, $mesAv, $dataNova);
            $ok = 'Data da venda de ' . $nomeCli . ' mudou para ' . vn_date_br($dataNova) . ' (' . $qtdMov . ($qtdMov === 1 ? ' peça' : ' peças') . ').';
            $abrirCli = substr($dataNova, 0, 7) . '|' . $nomeCli;
        }

        /* ---- Material do catálogo: editar ou cadastrar sem sair da janela "Cobrar peça ou serviço" ---- */
        if ($acao === 'salvar_catalogo_cb') {
            $foto = vn_catalog_upload('catalogo_foto');
            $r = vn_cobr_catalogo_salvar($_POST, $foto);
            $catalogoSalvoId = (int)$r['id'];
            $catalogoNovo = (bool)$r['novo'];
            $abrirPeca = true;
            $ok = $r['novo'] ? 'Material "' . $r['nome'] . '" cadastrado. Já está marcado para cobrar.' : 'Material "' . $r['nome'] . '" atualizado.';
        }

        if ($acao === 'excluir_peca') {
            $id = (int)($_POST['peca_id'] ?? 0);
            $c = vn_fetch_one('SELECT cliente_nome, criado_em FROM vn_saida_cobrancas WHERE id = ? LIMIT 1', [$id]);
            $ok = vn_cobr_excluir_peca($id);
            if ($c) $abrirCli = substr((string)$c['criado_em'], 0, 7) . '|' . (string)$c['cliente_nome'];
        }

        /* ---- Aprovar venda: o material do cliente/mês vira uma cobrança avulsa ---- */
        if ($acao === 'aprovar_venda') {
            $nomeCli = vn_clean((string)($_POST['av_cliente'] ?? ''));
            $mesAv = (string)($_POST['av_mes'] ?? '');
            if ($nomeCli === '' || !preg_match('/^\d{4}-\d{2}$/', $mesAv)) throw new Exception('Cliente ou mês inválido.');
            // Compara por intervalo de datas (DATE_FORMAT = ? dava erro de collation em alguns servidores).
            $iniMes = $mesAv . '-01 00:00:00';
            $fimMes = date('Y-m-01 00:00:00', strtotime($mesAv . '-01 +1 month'));
            $itensAv = vn_fetch_all(
                'SELECT * FROM vn_saida_cobrancas
                 WHERE cliente_nome = ? AND cobranca_id IS NULL AND criado_em >= ? AND criado_em < ?
                 ORDER BY id ASC', [$nomeCli, $iniMes, $fimMes]);
            if (!$itensAv) throw new Exception('Nenhuma peça aguardando aprovação para este cliente neste mês.');
            $vencAv = vn_today_sp();   // vencimento da cobrança (a pessoa pode escolher outro dia ao aprovar)
            if (trim((string)($_POST['av_venc'] ?? '')) !== '') {
                $vencAv = vn_date_db_or_null($_POST['av_venc']);
                if ($vencAv === null) throw new Exception('O vencimento escolhido não é uma data válida.');
            }

            $totalAv = 0.0; $linhas = [];
            foreach ($itensAv as $i) {
                $totalAv += (float)$i['valor_total'];
                $linhas[] = '- ' . number_format((float)$i['quantidade'], 2, ',', '.') . 'x ' . $i['item_nome'] . ' = ' . vn_money($i['valor_total']);
            }
            if ($totalAv <= 0) throw new Exception('Defina o valor das peças (Editar) antes de aprovar a venda.');

            // Telefone e id do cliente pelo cadastro, se existir.
            $telAv = ''; $cliIdAv = (int)($itensAv[0]['cliente_id'] ?? 0);
            foreach ($clientesLista as $c) {
                if (vn_norm_key((string)$c['nome']) === vn_norm_key($nomeCli)) {
                    $telAv = (string)($c['telefone'] ?? '');
                    if ($cliIdAv <= 0) $cliIdAv = (int)$c['id'];
                    break;
                }
            }

            $mesLabelAv = substr($mesAv, 5, 2) . '/' . substr($mesAv, 0, 4);
            $idCobAv = vn_insert_dynamic('vn_cobrancas', [
                'cliente_nome'   => $nomeCli,
                'telefone'       => $telAv,
                'chave_pix'      => '',
                'valor_total'    => round($totalAv, 2),
                'parcelas_total' => 1,
                'dia_pix'        => (int)substr($vencAv, 8, 2),
                'observacao'     => "Venda de material $mesLabelAv aprovada:\n" . implode("\n", $linhas),
                'criado_em'      => vn_now_sp(),
            ]);
            if ($idCobAv <= 0) throw new Exception('Não foi possível criar a cobrança da venda.');
            vn_exec('INSERT INTO vn_cobranca_parcelas (cobranca_id, numero, valor, vencimento, pago) VALUES (?, 1, ?, ?, 0)',
                [$idCobAv, round($totalAv, 2), $vencAv]);
            foreach ($itensAv as $i) {
                vn_exec('UPDATE vn_saida_cobrancas SET cobranca_id = ? WHERE id = ?', [$idCobAv, (int)$i['id']]);
            }
            $ok = 'Venda de ' . $nomeCli . ' (' . $mesLabelAv . ') aprovada: virou a cobrança avulsa nº ' . $idCobAv . ' de ' . vn_money($totalAv) . ', vencendo em ' . vn_date_br($vencAv) . '. Ajuste as parcelas se precisar.';
            $abrirId = $idCobAv;
        }

        /* ---- Nova mensalidade (criada por aqui mesmo) ---- */
        if ($acao === 'nova_mensalidade') {
            $ok = vn_mens_criar($_POST, $clientesLista);
            vn_mens_gerar_mes(vn_mes_atual());
        }

        /* ---- Pagar/desfazer mensalidade do mês (igual à página de Mensalidades) ---- */
        if ($acao === 'marcar_pagamento') {
            $pid = (int)($_POST['pagamento_id'] ?? 0);
            $pago = (int)($_POST['pago'] ?? 0) === 1 ? 1 : 0;
            vn_exec('UPDATE vn_mensalidade_pagamentos SET pago = ?, pago_em = ? WHERE id = ?', [$pago, $pago ? vn_today_sp() : null, $pid]);
            $ok = $pago ? 'Mensalidade do mês marcada como paga.' : 'Pagamento desfeito, mês voltou a pendente.';
        }

        /* ---- Etiquetas de cliente (grupo/empresa) ---- */

        if ($acao === 'salvar_etiqueta') {
            $clienteId = (int)($_POST['etq_cliente_id'] ?? 0);
            if ($clienteId <= 0) throw new Exception('Selecione o cliente que vai receber a etiqueta.');
            $etiqueta = vn_clean($_POST['etq_nome'] ?? '');
            if ($etiqueta === '') {
                vn_exec('DELETE FROM vn_cliente_etiquetas WHERE cliente_id = ?', [$clienteId]);
                $ok = 'Etiqueta removida do cliente.';
            } else {
                vn_exec('DELETE FROM vn_cliente_etiquetas WHERE cliente_id = ?', [$clienteId]);
                vn_exec('INSERT INTO vn_cliente_etiquetas (cliente_id, etiqueta) VALUES (?, ?)', [$clienteId, $etiqueta]);
                $ok = 'Etiqueta "' . $etiqueta . '" aplicada. O cliente aparece agrupado em Cobranças e Mensalidades.';
            }
        }

        if ($acao === 'remover_etiqueta') {
            $clienteId = (int)($_POST['etq_cliente_id'] ?? 0);
            vn_exec('DELETE FROM vn_cliente_etiquetas WHERE cliente_id = ?', [$clienteId]);
            $ok = 'Etiqueta removida.';
        }

        if ($acao === 'nova_cobranca') {
            // Cliente vem da lista do banco de dados (tabela clientes).
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $nome = ''; $telefoneCliente = '';
            foreach ($clientesLista as $c) {
                if ((int)$c['id'] === $clienteId) {
                    $nome = (string)$c['nome'];
                    $telefoneCliente = (string)($c['telefone'] ?? '');
                    break;
                }
            }
            if ($nome === '') throw new Exception('Selecione um cliente da lista.');

            $telefone = vn_clean($_POST['telefone'] ?? '');
            if ($telefone === '') $telefone = $telefoneCliente;
            $chave    = vn_clean($_POST['chave_pix'] ?? '');
            $obs      = trim((string)($_POST['observacao'] ?? ''));
            $dia      = (int)($_POST['dia_pix'] ?? 0);

            // Parcelas montadas linha a linha (igual "adicionar item" do orçamento).
            $parcelasMontadas = cobr_parse_parcelas_post($_POST);
            if (!$parcelasMontadas) throw new Exception('Adicione pelo menos uma parcela com valor e vencimento.');
            if ($dia < 1 || $dia > 31) $dia = (int)substr($parcelasMontadas[0]['vencimento'], 8, 2);
            $total = 0.0;
            foreach ($parcelasMontadas as $pm) $total += $pm['valor'];

            $idCob = vn_insert_dynamic('vn_cobrancas', [
                'cliente_nome'   => $nome,
                'telefone'       => $telefone,
                'chave_pix'      => $chave,
                'valor_total'    => round($total, 2),
                'parcelas_total' => count($parcelasMontadas),
                'dia_pix'        => $dia,
                'observacao'     => $obs,
                'criado_em'      => vn_now_sp(),
            ]);
            if ($idCob <= 0) throw new Exception('Não foi possível salvar a cobrança.');

            $n = 0;
            foreach ($parcelasMontadas as $pm) {
                $n++;
                vn_exec(
                    'INSERT INTO vn_cobranca_parcelas (cobranca_id, numero, valor, vencimento, pago) VALUES (?, ?, ?, ?, 0)',
                    [$idCob, $n, $pm['valor'], $pm['vencimento']]
                );
            }
            $ok = 'Cobrança de ' . $nome . ' registrada: ' . count($parcelasMontadas) . 'x (total ' . vn_money($total) . ').';
            $abrirId = $idCob;
        }

        if ($acao === 'marcar_parcela') {
            $parcelaId = (int)($_POST['parcela_id'] ?? 0);
            $pago = (int)($_POST['pago'] ?? 0) === 1 ? 1 : 0;
            $pagoEm = $pago ? vn_today_sp() : null;
            vn_exec('UPDATE vn_cobranca_parcelas SET pago = ?, pago_em = ? WHERE id = ?', [$pago, $pagoEm, $parcelaId]);
            $ok = $pago ? 'Parcela marcada como paga.' : 'Parcela marcada como pendente.';
        }

        if ($acao === 'excluir_cobranca') {
            $idCob = (int)($_POST['cobranca_id'] ?? 0);
            vn_exec('DELETE FROM vn_cobranca_parcelas WHERE cobranca_id = ?', [$idCob]);
            vn_exec('DELETE FROM vn_cobrancas WHERE id = ?', [$idCob]);
            // Se a cobrança veio de uma venda aprovada, o material volta a aguardar aprovação.
            $devolvidos = 0;
            foreach (vn_fetch_all('SELECT id FROM vn_saida_cobrancas WHERE cobranca_id = ?', [$idCob]) as $iv) {
                vn_exec('UPDATE vn_saida_cobrancas SET cobranca_id = NULL WHERE id = ?', [(int)$iv['id']]);
                $devolvidos++;
            }
            $ok = 'Cobrança excluída.' . ($devolvidos > 0 ? ' ' . $devolvidos . ' item(ns) voltaram para a aba Vendas (aguardando aprovação).' : '');
        }

        /* ---- Voltar para a venda: desfaz a aprovação (cobrança some, material volta) ---- */
        if ($acao === 'voltar_venda') {
            $idCob = (int)($_POST['cobranca_id'] ?? 0);
            $cobV = vn_fetch_one('SELECT * FROM vn_cobrancas WHERE id = ? LIMIT 1', [$idCob]);
            if (!$cobV) throw new Exception('Cobrança não encontrada.');
            $itensV = vn_fetch_all('SELECT * FROM vn_saida_cobrancas WHERE cobranca_id = ? ORDER BY id ASC', [$idCob]);
            if (!$itensV) throw new Exception('Esta cobrança não veio de uma venda, não há o que devolver.');
            $temPaga = vn_fetch_one('SELECT id FROM vn_cobranca_parcelas WHERE cobranca_id = ? AND pago = 1 LIMIT 1', [$idCob]);
            if ($temPaga) throw new Exception('Há parcela(s) já paga(s) nesta cobrança. Desfaça o pagamento antes de voltar para a venda.');

            foreach ($itensV as $iv) {
                vn_exec('UPDATE vn_saida_cobrancas SET cobranca_id = NULL WHERE id = ?', [(int)$iv['id']]);
            }
            vn_exec('DELETE FROM vn_cobranca_parcelas WHERE cobranca_id = ?', [$idCob]);
            vn_exec('DELETE FROM vn_cobrancas WHERE id = ?', [$idCob]);

            $ok = 'Cobrança desfeita: ' . count($itensV) . ' item(ns) de ' . (string)$cobV['cliente_nome'] . ' voltaram para a aba Vendas, aguardando aprovação de novo.';
            $abrirCli = substr((string)($itensV[0]['criado_em'] ?? ''), 0, 7) . '|' . (string)$itensV[0]['cliente_nome'];
        }

        if ($acao === 'editar_cobranca') {
            // Edita dados do cliente E a lista de parcelas (montada como no orçamento).
            $idCob = (int)($_POST['cobranca_id'] ?? 0);
            if ($idCob <= 0) throw new Exception('Cobrança inválida.');
            $cobAtual = vn_fetch_one('SELECT * FROM vn_cobrancas WHERE id = ?', [$idCob]);
            if (!$cobAtual) throw new Exception('Cobrança não encontrada.');

            $nome = vn_clean($_POST['cliente_nome'] ?? '');
            if ($nome === '') throw new Exception('Informe o nome do cliente.');
            $telefone = vn_clean($_POST['telefone'] ?? '');
            $chave    = vn_clean($_POST['chave_pix'] ?? '');
            $obs      = trim((string)($_POST['observacao'] ?? ''));
            $dia      = (int)($_POST['dia_pix'] ?? 0);
            if ($dia < 1 || $dia > 31) $dia = (int)$cobAtual['dia_pix'];

            $rows = cobr_parse_parcelas_post($_POST);
            if (!$rows) throw new Exception('A cobrança precisa de pelo menos uma parcela.');

            // Parcelas atuais por id (para saber quais foram removidas e o que está pago).
            $atuais = [];
            foreach (vn_fetch_all('SELECT * FROM vn_cobranca_parcelas WHERE cobranca_id = ?', [$idCob]) as $p) {
                $atuais[(int)$p['id']] = $p;
            }
            $mantidos = [];
            foreach ($rows as $r) { if ($r['id'] > 0) $mantidos[$r['id']] = true; }

            // Não permite remover parcela que já foi paga.
            foreach ($atuais as $pid => $p) {
                if (!isset($mantidos[$pid]) && (int)$p['pago'] === 1) {
                    throw new Exception('Não dá para remover a parcela ' . (int)$p['numero'] . ' porque já está paga. Desmarque o pagamento antes.');
                }
            }

            $total = 0.0;
            foreach ($rows as $r) $total += $r['valor'];

            // Remove as que saíram (todas não pagas, já validado acima).
            foreach ($atuais as $pid => $p) {
                if (!isset($mantidos[$pid])) {
                    vn_exec('DELETE FROM vn_cobranca_parcelas WHERE id = ?', [$pid]);
                }
            }
            // Atualiza existentes e cria novas, renumerando por ordem de vencimento.
            $n = 0;
            foreach ($rows as $r) {
                $n++;
                if ($r['id'] > 0 && isset($atuais[$r['id']])) {
                    vn_exec('UPDATE vn_cobranca_parcelas SET numero = ?, valor = ?, vencimento = ? WHERE id = ?', [$n, $r['valor'], $r['vencimento'], $r['id']]);
                } else {
                    vn_exec('INSERT INTO vn_cobranca_parcelas (cobranca_id, numero, valor, vencimento, pago) VALUES (?, ?, ?, ?, 0)', [$idCob, $n, $r['valor'], $r['vencimento']]);
                }
            }
            vn_exec(
                'UPDATE vn_cobrancas SET cliente_nome = ?, telefone = ?, chave_pix = ?, dia_pix = ?, observacao = ?, valor_total = ?, parcelas_total = ? WHERE id = ?',
                [$nome, $telefone, $chave, $dia, $obs, round($total, 2), count($rows), $idCob]
            );
            $ok = 'Cobrança de ' . $nome . ' atualizada (' . count($rows) . ' parcela(s), total ' . vn_money($total) . ').';
            $abrirId = $idCob;
        }
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

/* ----------------------------------------------------------------------
   Carga de dados: tudo vira UMA lista de "itens de cobrança"
   (cobrança em parcelas, mensalidade do mês ou venda de material a aprovar)
---------------------------------------------------------------------- */
$hoje      = vn_today_sp();
$mesAtual  = vn_mes_atual();
$fimSemana = date('Y-m-d', strtotime($hoje . ' +7 days'));
$inicioMes = $mesAtual . '-01';
$limPagas  = date('Y-m-d', strtotime($hoje . ' -60 days'));

$cobrancas = [];            // cobranças em parcelas
$parcelasPorCobranca = [];
$vendaPorCobranca = [];     // cobranca_id => qtd de itens de venda (botão "Voltar para venda")
$etqMap = []; $idPorNome = []; $telPorNome = [];
$matMeses = []; $matPend = 0.0;
$mensRows = [];
$mensCategorias = ['Portaria remota', 'Monitoramento', 'CFTV', 'Alarme', 'Manutenção', 'Interfonia'];
$catalogoPecas = []; $catalogoServicos = [];
$itens = [];
$kpi = ['atrasado' => 0.0, 'atrasado_qtd' => 0, 'semana' => 0.0, 'semana_qtd' => 0, 'material' => 0.0, 'material_qtd' => 0, 'recebido' => 0.0, 'recebido_qtd' => 0];

try {
    foreach ($clientesLista as $c) {
        $k = vn_norm_key((string)$c['nome']);
        if ($k === '') continue;
        $idPorNome[$k] = (int)$c['id'];
        $telPorNome[$k] = (string)($c['telefone'] ?? '');
    }
    vn_saida_cobrancas_auto_gerar();   // TODA saída de material vira venda para aprovar
    vn_mens_gerar_mes($mesAtual);
    $etqMap = vn_cliente_etiquetas_map();

    $cobrancas = vn_fetch_all('SELECT * FROM vn_cobrancas ORDER BY criado_em DESC, id DESC');
    foreach (vn_fetch_all('SELECT cobranca_id, COUNT(*) AS q FROM vn_saida_cobrancas WHERE cobranca_id IS NOT NULL GROUP BY cobranca_id') as $r) {
        $vendaPorCobranca[(int)$r['cobranca_id']] = (int)$r['q'];
    }
    foreach (vn_fetch_all('SELECT * FROM vn_cobranca_parcelas ORDER BY cobranca_id, numero') as $p) {
        $parcelasPorCobranca[(int)$p['cobranca_id']][] = $p;
    }

    // Material ainda não aprovado, agrupado por mês e cliente.
    foreach (vn_fetch_all('SELECT * FROM vn_saida_cobrancas WHERE cobranca_id IS NULL ORDER BY criado_em DESC, id DESC LIMIT 1000') as $c) {
        $nome = (string)$c['cliente_nome'];
        $mesItem = substr((string)($c['criado_em'] ?? ''), 0, 7);
        if (strlen($mesItem) !== 7) $mesItem = $mesAtual;
        if (!isset($matMeses[$mesItem][$nome])) {
            $matMeses[$mesItem][$nome] = [
                'cliente_id' => (int)($c['cliente_id'] ?? 0),
                'etiqueta'   => vn_etiqueta_resolver($etqMap, $idPorNome, $c['cliente_id'] ?? 0, $nome),
                'itens'      => [], 'pend' => 0.0, 'pendentes' => [],
            ];
        }
        $matMeses[$mesItem][$nome]['itens'][] = $c;
        $matMeses[$mesItem][$nome]['pendentes'][] = $c;
        $matMeses[$mesItem][$nome]['pend'] += (float)$c['valor_total'];
        $matPend += (float)$c['valor_total'];
    }

    // Mensalidades: tudo que está em aberto (de qualquer mês, para não esconder atraso) + o que foi pago nos últimos 60 dias.
    $mensRows = vn_fetch_all(
        'SELECT p.id AS pagamento_id, p.competencia, p.valor, p.vencimento, p.pago, p.pago_em,
                m.id AS mensalidade_id, m.ativo, m.cliente_id, m.cliente_nome, m.categoria, m.telefone, m.chave_pix, m.dia_vencimento, m.observacao
         FROM vn_mensalidade_pagamentos p
         JOIN vn_mensalidades m ON m.id = p.mensalidade_id
         WHERE (p.pago = 0 AND p.competencia <= ?) OR (p.pago = 1 AND p.pago_em >= ?)
         ORDER BY p.vencimento ASC, m.cliente_nome ASC', [$mesAtual, $limPagas]);

    foreach (vn_fetch_all("SELECT DISTINCT categoria FROM vn_mensalidades WHERE COALESCE(categoria, '') NOT IN ('', 'Mensalidade')") as $r) {
        if (!in_array((string)$r['categoria'], $mensCategorias, true)) $mensCategorias[] = (string)$r['categoria'];
    }
    sort($mensCategorias, SORT_NATURAL | SORT_FLAG_CASE);

    // Catálogo do modal "Cobrar peça / serviço" (mesmos itens da tela de saídas).
    foreach (vn_catalogo_orcamento_mobile_v6(true) as $it) {
        $tipo = (string)($it['tipo'] ?? 'material');
        if (vn_is_tipo_ferramenta($tipo)) continue;
        if ((string)($it['origem'] ?? 'mobile') !== 'mobile') continue;
        if (in_array($tipo, ['servico', 'mao_obra'], true)) { $catalogoServicos[] = $it; continue; }
        $catalogoPecas[] = $it;
    }
} catch (Throwable $e) {
    if (!$erro) $erro = $e->getMessage();
}

/* ---- Cobranças em parcelas: uma linha por cobrança (próxima parcela em aberto) ---- */
foreach ($cobrancas as $cob) {
    $cid = (int)$cob['id'];
    $ps = $parcelasPorCobranca[$cid] ?? [];
    $abertas = []; $atrasadas = []; $somaPagas = 0.0; $somaAberta = 0.0; $somaAtrasada = 0.0; $somaSemana = 0.0; $ultimoPago = '';
    foreach ($ps as $p) {
        $v = (float)$p['valor'];
        if ((int)$p['pago'] === 1) {
            $somaPagas += $v;
            $pe = (string)($p['pago_em'] ?? '');
            if ($pe > $ultimoPago) $ultimoPago = $pe;
            if ($pe !== '' && $pe >= $inicioMes) { $kpi['recebido'] += $v; $kpi['recebido_qtd']++; }
        } else {
            $abertas[] = $p; $somaAberta += $v;
            $venc = (string)($p['vencimento'] ?? '');
            if ($venc !== '' && $venc < $hoje) { $atrasadas[] = $p; $somaAtrasada += $v; }
            elseif ($venc !== '' && $venc <= $fimSemana) { $somaSemana += $v; }
        }
    }
    // Totais do resumo: dinheiro de todas as parcelas, contando cada cobrança uma vez.
    if ($atrasadas) { $kpi['atrasado'] += $somaAtrasada; $kpi['atrasado_qtd']++; }
    if ($somaSemana > 0) { $kpi['semana'] += $somaSemana; $kpi['semana_qtd']++; }
    $nome = (string)$cob['cliente_nome'];
    $origem = isset($vendaPorCobranca[$cid]) ? 'Venda de material' : 'Cobrança avulsa';
    $totalParc = (int)$cob['parcelas_total'];
    $it = [
        'key' => 'c' . $cid, 'tipo' => 'parcela', 'cliente' => $nome,
        'etq' => vn_etiqueta_resolver($etqMap, $idPorNome, 0, $nome),
        'cob' => $cob, 'parcelas' => $ps, 'somaPagas' => $somaPagas, 'somaAberta' => $somaAberta,
        'pago_em' => '', 'venc' => '', 'wpp' => '', 'pix' => '',
    ];
    if ($abertas) {
        $prox = $abertas[0];
        $venc = (string)($prox['vencimento'] ?? '');
        $it['prox'] = $prox;
        $it['estado'] = cb_estado_venc($venc, $hoje, $fimSemana);
        $it['valor'] = (float)$prox['valor'];
        $it['venc'] = $venc;
        $it['titulo'] = 'Parcela ' . (int)$prox['numero'] . '/' . $totalParc;
        $it['sub'] = vn_h($origem) . (count($abertas) > 1 ? ' · restam ' . vn_h(vn_money($somaAberta)) . ' em ' . count($abertas) . ' parcelas' : '');
        $it['wpp'] = vn_cobranca_whatsapp($cob, $prox);
        $it['pix'] = vn_cobranca_pix($cob, $prox);
        if (count($atrasadas) >= 2) {   // duas ou mais atrasadas: a linha cobra o total de uma vez
            $it['multi'] = true;
            $it['valor'] = $somaAtrasada;
            $it['venc'] = (string)$atrasadas[0]['vencimento'];
            $it['titulo'] = count($atrasadas) . ' parcelas atrasadas';
            $it['sub'] = vn_h($origem) . ' · parcelas ' . implode(', ', array_map(function ($p) { return (int)$p['numero']; }, $atrasadas)) . ' de ' . $totalParc
                . (count($abertas) > count($atrasadas) ? ' · restam ' . vn_h(vn_money($somaAberta - $somaAtrasada)) . ' a vencer' : '');
            $it['wpp'] = cb_whatsapp_varias($cob, $atrasadas);
            $it['pix'] = vn_pix_payload((string)($cob['chave_pix'] ?? ''), $somaAtrasada);
        }
    } elseif ($ps) {
        $it['estado'] = 'paga';
        $it['valor'] = (float)$cob['valor_total'];
        $it['pago_em'] = $ultimoPago;
        $it['titulo'] = 'Quitada em ' . $totalParc . 'x';
        $it['sub'] = vn_h($origem);
    } else {
        $it['estado'] = 'futura';
        $it['valor'] = (float)$cob['valor_total'];
        $it['titulo'] = 'Sem parcelas';
        $it['sub'] = 'abra e edite para criar as parcelas';
    }
    $itens[] = $it;
}

/* ---- Mensalidades: uma linha por mês de cada mensalidade ---- */
foreach ($mensRows as $r) {
    $nome = (string)$r['cliente_nome'];
    $pago = (int)$r['pago'] === 1;
    $venc = (string)($r['vencimento'] ?? '');
    $comp = (string)$r['competencia'];
    $v = (float)$r['valor'];
    if ($pago) {
        $estado = 'paga';
        $pe = (string)($r['pago_em'] ?? '');
        if ($pe !== '' && $pe >= $inicioMes) { $kpi['recebido'] += $v; $kpi['recebido_qtd']++; }
    } else {
        $estado = cb_estado_venc($venc, $hoje, $fimSemana);
        if ($estado === 'atrasada') { $kpi['atrasado'] += $v; $kpi['atrasado_qtd']++; }
        elseif ($venc !== '' && $venc <= $fimSemana) { $kpi['semana'] += $v; $kpi['semana_qtd']++; }
    }
    $itens[] = [
        'key' => 'm' . (int)$r['pagamento_id'], 'tipo' => 'mensalidade', 'estado' => $estado, 'cliente' => $nome,
        'etq' => vn_etiqueta_resolver($etqMap, $idPorNome, $r['cliente_id'] ?? 0, $nome),
        'valor' => $v, 'venc' => $venc, 'pago_em' => (string)($r['pago_em'] ?? ''),
        'titulo' => 'Mensalidade · ' . vn_mens_categoria($r),
        'sub' => 'mês ' . vn_h(substr($comp, 5, 2) . '/' . substr($comp, 0, 4)) . ((int)($r['ativo'] ?? 1) !== 1 ? ' · pausada' : ''),
        'wpp' => vn_mens_whatsapp($r, $r), 'pix' => vn_mens_pix($r, $r), 'raw' => $r,
    ];
}

/* ---- Material para aprovar: uma linha por cliente e mês ---- */
foreach ($matMeses as $mesItem => $lista) {
    foreach ($lista as $nomeCli => $d) {
        $kpi['material'] += $d['pend']; $kpi['material_qtd']++;
        $semValor = 0;
        foreach ($d['itens'] as $i) { if ((float)$i['valor_unitario'] <= 0) $semValor++; }
        $mesLabel = substr($mesItem, 5, 2) . '/' . substr($mesItem, 0, 4);
        $chave = $mesItem . '|' . $nomeCli;
        $itens[] = [
            'key' => 'v' . substr(md5($chave), 0, 10), 'tipo' => 'material', 'estado' => 'aprovar', 'cliente' => $nomeCli,
            'etq' => $d['etiqueta'], 'valor' => $d['pend'], 'venc' => '', 'pago_em' => '', 'mes' => $mesItem, 'mesLabel' => $mesLabel,
            'titulo' => 'Material · ' . count($d['itens']) . (count($d['itens']) === 1 ? ' peça' : ' peças'),
            'sub' => 'mês ' . vn_h($mesLabel) . ($semValor > 0 ? ' · <span class="warn">' . $semValor . ' sem valor</span>' : ''),
            'semValor' => $semValor, 'dados' => $d, 'chave' => $chave,
            'wpp' => vn_cobr_equip_whatsapp($nomeCli, $telPorNome[vn_norm_key($nomeCli)] ?? '', $d['pendentes']),
        ];
    }
}

/* ---- Seções por urgência ---- */
$secoes = ['atrasada' => [], 'semana' => [], 'futura' => [], 'aprovar' => [], 'paga' => []];
foreach ($itens as $it) {
    $s = $it['estado'] === 'hoje' ? 'semana' : $it['estado'];
    $secoes[$s][] = $it;
}
$cmpVenc = function ($a, $b) { return strcmp((string)$a['venc'], (string)$b['venc']) ?: strcasecmp($a['cliente'], $b['cliente']); };
foreach (['atrasada', 'semana', 'futura'] as $s) usort($secoes[$s], $cmpVenc);
usort($secoes['aprovar'], function ($a, $b) {
    $ka = $a['etq'] !== '' ? $a['etq'] : $a['cliente']; $kb = $b['etq'] !== '' ? $b['etq'] : $b['cliente'];
    return strcmp($b['mes'], $a['mes']) ?: strcasecmp($ka, $kb) ?: strcasecmp($a['cliente'], $b['cliente']);
});
usort($secoes['paga'], function ($a, $b) { return strcmp((string)$b['pago_em'], (string)$a['pago_em']) ?: strcasecmp($a['cliente'], $b['cliente']); });
$secoes['paga'] = array_slice($secoes['paga'], 0, 60);
$mostrados = [];
foreach ($secoes as $l) foreach ($l as $it) $mostrados[$it['key']] = true;

$etqLista = []; $semEtq = false;
foreach ($itens as $it) { if ($it['etq'] !== '') $etqLista[$it['etq']] = true; else $semEtq = true; }
$etqLista = array_keys($etqLista);
sort($etqLista, SORT_NATURAL | SORT_FLAG_CASE);

/* Qual janela abrir sozinha depois de uma ação (quando a página é enviada sem JS ou a ação cria algo novo). */
$abrirDlg = '';
if ($abrirId > 0) $abrirDlg = 'd-c' . $abrirId;
elseif ($abrirCli !== '') $abrirDlg = 'd-v' . substr(md5($abrirCli), 0, 10);
elseif ($abrirPeca) $abrirDlg = 'cb-peca-modal';

$secTitulo = [
    'atrasada' => ['warning', 'Atrasadas'],
    'semana'   => ['schedule', 'Hoje e próximos 7 dias'],
    'futura'   => ['event', 'Mais adiante'],
    'aprovar'  => ['inventory_2', 'Material para aprovar'],
    'paga'     => ['task_alt', 'Pagas recentemente'],
];

vn_ui_shell_start('Cobranças', 'cobrancas', [
    'css'        => ['assets/css/vn-cobrancas.css'],
    'head_extra' => '<style>' . vn_etiqueta_css() . '</style>',
]);
vn_alerts($ok, $erro);
?>
<div class="cb">

    <div class="cb-head">
        <div class="cb-head-text"><strong>Central de cobranças</strong><span>O que ainda falta receber, por urgência.</span></div>
        <div class="cb-head-acts">
            <details class="cb-menu">
                <summary class="vn-btn" aria-label="Mais opções"><?php echo cb_ico('more_horiz'); ?>Mais</summary>
                <div class="cb-menu-panel">
                    <button type="button" data-abrir="cb-dlg-etq"><?php echo cb_ico('label'); ?>Etiquetas dos clientes</button>
                    <button type="button" data-abrir="cb-dlg-pdf"><?php echo cb_ico('picture_as_pdf'); ?>PDF dos materiais</button>
                    <div class="cb-menu-sep"></div>
                    <a href="cobrancas-export.php?tipo=parcelas&amp;so=vencidas"><?php echo cb_ico('download'); ?>Parcelas vencidas (CSV)</a>
                    <a href="cobrancas-export.php?tipo=parcelas&amp;so=todas"><?php echo cb_ico('download'); ?>Todas as parcelas (CSV)</a>
                    <a href="cobrancas-export.php?tipo=mensalidades&amp;so=todas"><?php echo cb_ico('download'); ?>Mensalidades (CSV)</a>
                    <div class="cb-menu-sep"></div>
                    <a href="mensalidades.php"><?php echo cb_ico('event_repeat'); ?>Mensalidades: histórico e edição</a>
                    <a href="saidas-estoque.php"><?php echo cb_ico('inventory_2'); ?>Saídas de estoque</a>
                </div>
            </details>
            <button type="button" class="vn-btn vn-btn-primary cb-primary" data-abrir="cb-dlg-novo"><?php echo cb_ico('add'); ?>Nova cobrança</button>
        </div>
    </div>

    <!-- ===================== O que criar ===================== -->
    <?php echo cb_janela('cb-dlg-novo', 'Nova cobrança', 'O que você quer cobrar?',
        '<div class="cb-choose">'
        . '<button type="button" class="cb-choice" data-abrir="cb-dlg-parcelas"><span class="ico">' . cb_ico('payments') . '</span><span><strong>Cobrança em parcelas</strong><span class="d">Um valor combinado, dividido em quantas parcelas quiser, com PIX copia e cola.</span></span><span class="go">' . cb_ico('chevron_right') . '</span></button>'
        . '<button type="button" class="cb-choice" data-abrir="cb-dlg-mensal"><span class="ico">' . cb_ico('event_repeat') . '</span><span><strong>Mensalidade</strong><span class="d">Cobrança fixa todo mês, no dia combinado (ex.: portaria remota, monitoramento).</span></span><span class="go">' . cb_ico('chevron_right') . '</span></button>'
        . '<button type="button" class="cb-choice" data-add-peca data-cliente-id="0" data-cliente-nome=""><span class="ico">' . cb_ico('sell') . '</span><span><strong>Peça ou serviço</strong><span class="d">Material do estoque ou serviço avulso (mão de obra, visita). Entra em "Material para aprovar".</span></span><span class="go">' . cb_ico('chevron_right') . '</span></button>'
        . '</div>', '', ''); ?>

    <!-- ===================== Cobrança em parcelas ===================== -->
    <?php
    $optClientes = '';
    foreach ($clientesLista as $c) {
        $optClientes .= '<option value="' . (int)$c['id'] . '" data-telefone="' . vn_h((string)($c['telefone'] ?? '')) . '">' . vn_h((string)$c['nome']) . '</option>';
    }
    ob_start(); ?>
    <form method="post" class="cb-form" data-async data-pb-form id="cb-form-parcelas">
        <input type="hidden" name="acao" value="nova_cobranca">
        <div class="cb-f full"><label for="cb-p-cli">Cliente *</label>
            <?php if ($clientesLista): ?>
                <select id="cb-p-cli" name="cliente_id" required data-cliente-tel data-foco><option value="">Selecione um cliente…</option><?php echo $optClientes; ?></select>
            <?php else: ?>
                <select id="cb-p-cli" name="cliente_id" required disabled><option value="">Nenhum cliente cadastrado</option></select>
                <small>Cadastre clientes em <a href="clientes-admin.php">Clientes</a> para poder cobrar.</small>
            <?php endif; ?>
        </div>
        <div class="cb-f full"><span class="lbl">Valor e parcelas *</span>
            <div class="cb-pb" data-pb data-pb-auto data-hoje="<?php echo vn_h($hoje); ?>">
                <div class="cb-pb-gen">
                    <div class="cb-f"><label for="cb-p-total">Valor total (R$)</label><input id="cb-p-total" data-pb-gt inputmode="decimal" placeholder="0,00" autocomplete="off"></div>
                    <div class="cb-f"><label for="cb-p-qtd">Parcelas</label><input id="cb-p-qtd" data-pb-gq type="number" min="1" max="60" value="1" inputmode="numeric"></div>
                    <div class="cb-f"><label for="cb-p-data">1º vencimento</label><input id="cb-p-data" data-pb-gd type="date" value="<?php echo vn_h($hoje); ?>"></div>
                </div>
                <div class="cb-pb-rows" data-pb-rows></div>
                <p class="cb-pb-hint" data-pb-hint>As parcelas aparecem aqui assim que você informar o valor. Dá para ajustar cada uma.</p>
                <div class="cb-pb-foot"><button type="button" class="cb-btn sm" data-pb-add><?php echo cb_ico('add'); ?>Adicionar parcela</button>
                    <span class="cb-pb-total">Total · <span data-pb-count>0</span>x · <span data-pb-total>R$ 0,00</span></span></div>
            </div>
        </div>
        <details class="cb-more full"><summary>Telefone, chave PIX e observação</summary>
            <div class="cb-form">
                <div class="cb-f"><label for="cb-p-tel">WhatsApp / telefone</label><input id="cb-p-tel" name="telefone" inputmode="tel" placeholder="(11) 90000-0000"></div>
                <div class="cb-f"><label for="cb-p-pix">Chave PIX</label><input id="cb-p-pix" name="chave_pix" placeholder="CPF, e-mail, telefone ou aleatória"></div>
                <div class="cb-f full"><label for="cb-p-obs">Observação</label><textarea id="cb-p-obs" name="observacao" placeholder="Referente a quê, combinados, etc."></textarea></div>
            </div>
        </details>
        <div class="cb-f full"><button type="submit" class="cb-btn pri" style="min-height:48px">Registrar cobrança</button></div>
    </form>
    <?php $f = ob_get_clean(); echo cb_janela('cb-dlg-parcelas', 'Cobrança em parcelas', $orcPre ? 'Preenchida a partir do pedido ' . vn_h($orcPre['numero']) . '. Confira antes de registrar.' : 'Informe o valor e quantas parcelas.', $f); ?>

    <div id="cb-root">
        <div class="cb-kpis">
            <button type="button" class="cb-kpi<?php echo $kpi['atrasado'] > 0 ? ' is-danger' : ''; ?>" data-kpi="atrasadas" aria-pressed="false">
                <span class="l"><?php echo cb_ico('warning'); ?>Atrasado</span>
                <span class="v"><?php echo vn_h(vn_money($kpi['atrasado'])); ?></span>
                <span class="s"><?php echo (int)$kpi['atrasado_qtd']; ?> <?php echo $kpi['atrasado_qtd'] === 1 ? 'cobrança' : 'cobranças'; ?> em atraso</span>
            </button>
            <button type="button" class="cb-kpi is-warn" data-kpi="semana" aria-pressed="false">
                <span class="l"><?php echo cb_ico('schedule'); ?>Próximos 7 dias</span>
                <span class="v"><?php echo vn_h(vn_money($kpi['semana'])); ?></span>
                <span class="s"><?php echo (int)$kpi['semana_qtd']; ?> a vencer</span>
            </button>
            <button type="button" class="cb-kpi" data-kpi="material" aria-pressed="false">
                <span class="l"><?php echo cb_ico('inventory_2'); ?>Material a aprovar</span>
                <span class="v"><?php echo vn_h(vn_money($kpi['material'])); ?></span>
                <span class="s"><?php echo (int)$kpi['material_qtd']; ?> <?php echo $kpi['material_qtd'] === 1 ? 'venda' : 'vendas'; ?></span>
            </button>
            <button type="button" class="cb-kpi is-ok" data-kpi="pagas" aria-pressed="false">
                <span class="l"><?php echo cb_ico('check_circle'); ?>Recebido em <?php echo vn_h(substr($mesAtual, 5, 2) . '/' . substr($mesAtual, 0, 4)); ?></span>
                <span class="v"><?php echo vn_h(vn_money($kpi['recebido'])); ?></span>
                <span class="s"><?php echo (int)$kpi['recebido_qtd']; ?> <?php echo $kpi['recebido_qtd'] === 1 ? 'pagamento' : 'pagamentos'; ?></span>
            </button>
        </div>

        <div class="cb-tools" style="margin-top:16px">
            <label class="cb-search"><?php echo cb_ico('search'); ?><input type="search" id="cb-q" placeholder="Buscar cliente ou etiqueta" autocomplete="off" aria-label="Buscar cliente ou etiqueta"></label>
            <div class="cb-seg" role="group" aria-label="Situação">
                <button type="button" data-status="aberto" aria-pressed="true">Em aberto <span class="n"></span></button>
                <button type="button" data-status="atrasadas" aria-pressed="false">Atrasadas <span class="n"></span></button>
                <button type="button" data-status="semana" aria-pressed="false">Semana <span class="n"></span></button>
                <button type="button" data-status="pagas" aria-pressed="false">Pagas <span class="n"></span></button>
            </div>
            <select class="cb-select" id="cb-tipo" aria-label="Tipo de cobrança">
                <option value="">Todos os tipos</option>
                <option value="mensalidade">Mensalidades</option>
                <option value="parcela">Cobranças em parcelas</option>
                <option value="material">Material</option>
            </select>
            <?php if ($etqLista): ?>
            <select class="cb-select" id="cb-etq" aria-label="Etiqueta do cliente">
                <option value="">Todas as etiquetas</option>
                <?php foreach ($etqLista as $e): ?><option value="<?php echo vn_h($e); ?>"><?php echo vn_h($e); ?></option><?php endforeach; ?>
                <?php if ($semEtq): ?><option value="__sem__">Sem etiqueta</option><?php endif; ?>
            </select>
            <?php endif; ?>
        </div>

        <div style="margin-top:16px;display:flex;flex-direction:column;gap:18px">
        <?php if (!$itens): ?>
            <div class="cb-empty">
                <?php echo cb_ico('payments'); ?>
                <strong>Nenhuma cobrança ainda</strong>
                Crie uma cobrança em parcelas, cadastre uma mensalidade ou registre uma saída de estoque para o cliente: tudo aparece aqui.
                <div class="cb-empty-acts"><button type="button" class="vn-btn vn-btn-primary" data-abrir="cb-dlg-novo"><?php echo cb_ico('add'); ?>Nova cobrança</button></div>
            </div>
        <?php else: foreach ($secoes as $chaveSec => $lista): if (!$lista) continue; ?>
            <section class="cb-sec" data-sec="<?php echo vn_h($chaveSec); ?>">
                <h3 class="cb-sec-title"><?php echo cb_ico($secTitulo[$chaveSec][0]); ?><?php echo vn_h($secTitulo[$chaveSec][1]); ?> <span class="cb-count"><?php echo count($lista); ?></span><span class="cb-sum"></span></h3>
                <div class="cb-list">
                    <?php foreach ($lista as $it) echo cb_linha($it, $hoje); ?>
                </div>
            </section>
        <?php endforeach; endif; ?>
            <div class="cb-empty" id="cb-vazio-filtro" hidden>
                <?php echo cb_ico('search'); ?>
                <strong class="msg">Nenhuma cobrança encontrada.</strong>
                <div class="cb-empty-acts"><button type="button" class="vn-btn" data-limpar-filtros>Limpar filtros</button></div>
            </div>
        </div>

        <?php /* Detalhe de cada item (abre ao clicar na linha) */
        foreach ($itens as $it) {
            if (empty($mostrados[$it['key']])) continue;
            if ($it['tipo'] === 'parcela')          echo cb_janela_parcela($it, $hoje, $fimSemana, isset($vendaPorCobranca[(int)$it['cob']['id']]));
            elseif ($it['tipo'] === 'mensalidade')  echo cb_janela_mensalidade($it, $hoje);
            else                                    echo cb_janela_material($it);
        } ?>
    <!-- ===================== Mensalidade ===================== -->
    <?php ob_start(); ?>
    <form method="post" class="cb-form" data-async data-fecha id="cb-form-mensal">
        <input type="hidden" name="acao" value="nova_mensalidade">
        <div class="cb-f full"><label for="cb-m-cli">Cliente *</label>
            <?php if ($clientesLista): ?>
                <select id="cb-m-cli" name="cliente_id" required data-cliente-tel data-foco><option value="">Selecione um cliente…</option><?php echo $optClientes; ?></select>
            <?php else: ?>
                <input id="cb-m-cli" name="cliente_nome" required placeholder="Nome do cliente / condomínio">
            <?php endif; ?>
        </div>
        <div class="cb-f full"><label for="cb-m-cat">Serviço cobrado</label>
            <input id="cb-m-cat" name="categoria" list="cb-cat-lista" maxlength="80" placeholder="Ex.: Portaria remota, Monitoramento…">
            <datalist id="cb-cat-lista"><?php foreach ($mensCategorias as $cat): ?><option value="<?php echo vn_h($cat); ?>"></option><?php endforeach; ?></datalist>
        </div>
        <div class="cb-f"><label for="cb-m-valor">Valor mensal (R$) *</label><input id="cb-m-valor" name="valor_mensal" inputmode="decimal" required placeholder="0,00" autocomplete="off"></div>
        <div class="cb-f"><label for="cb-m-dia">Vence todo dia *</label><input id="cb-m-dia" name="dia_vencimento" type="number" min="1" max="31" value="10" required inputmode="numeric"></div>
        <details class="cb-more full"><summary>Telefone, chave PIX e observação</summary>
            <div class="cb-form">
                <div class="cb-f"><label for="cb-m-tel">WhatsApp / telefone</label><input id="cb-m-tel" name="telefone" inputmode="tel" placeholder="(11) 90000-0000"></div>
                <div class="cb-f"><label for="cb-m-pix">Chave PIX</label><input id="cb-m-pix" name="chave_pix" placeholder="CPF, e-mail, telefone ou aleatória"></div>
                <div class="cb-f full"><label for="cb-m-obs">Observação</label><textarea id="cb-m-obs" name="observacao" placeholder="Combinados, referência…"></textarea></div>
            </div>
        </details>
        <div class="cb-f full"><button type="submit" class="cb-btn pri" style="min-height:48px">Cadastrar mensalidade</button></div>
    </form>
    <?php $f = ob_get_clean(); echo cb_janela('cb-dlg-mensal', 'Nova mensalidade', 'Gera a cobrança de cada mês automaticamente.', $f); ?>

    <!-- ===================== Etiquetas ===================== -->
    <?php ob_start();
    if (!$clientesLista) { echo '<p class="cb-note">Cadastre clientes em <a href="clientes-admin.php">Clientes</a> para usar etiquetas.</p>'; }
    else { ?>
        <form method="post" class="cb-form" data-async id="cb-form-etq">
            <input type="hidden" name="acao" value="salvar_etiqueta">
            <div class="cb-f"><label for="cb-e-cli">Cliente *</label>
                <select id="cb-e-cli" name="etq_cliente_id" required><option value="">Selecione um cliente…</option>
                    <?php foreach ($clientesLista as $c): $e = $etqMap[(int)$c['id']] ?? ''; ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo vn_h((string)$c['nome']); ?><?php echo $e !== '' ? ' — ' . vn_h($e) : ''; ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="cb-f"><label for="cb-e-nome">Etiqueta (empresa ou grupo)</label>
                <input id="cb-e-nome" name="etq_nome" list="cb-etq-lista" maxlength="80" placeholder="Ex.: Lexcopany">
                <datalist id="cb-etq-lista"><?php foreach (array_unique(array_values($etqMap)) as $e): ?><option value="<?php echo vn_h($e); ?>"></option><?php endforeach; ?></datalist>
                <small>Deixe vazio para tirar a etiqueta do cliente.</small></div>
            <div class="cb-f full"><button type="submit" class="cb-btn pri" style="min-height:46px">Salvar etiqueta</button></div>
        </form>
        <?php if ($etqMap): ?>
            <div><h4 class="cb-h">Etiquetas aplicadas</h4>
            <?php
            $porEtiqueta = []; $nomePorId = [];
            foreach ($clientesLista as $c) $nomePorId[(int)$c['id']] = (string)$c['nome'];
            foreach ($etqMap as $cid => $e) $porEtiqueta[$e][] = $cid;
            ksort($porEtiqueta, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($porEtiqueta as $e => $ids): ?>
                <div class="cb-etq-grupo"><?php echo cb_tag((string)$e); ?>
                    <?php foreach ($ids as $cid): ?>
                        <form method="post" data-async><input type="hidden" name="acao" value="remover_etiqueta"><input type="hidden" name="etq_cliente_id" value="<?php echo (int)$cid; ?>">
                            <span class="nm"><?php echo vn_h($nomePorId[$cid] ?? ('Cliente #' . $cid)); ?></span>
                            <button type="submit" title="Tirar a etiqueta deste cliente" aria-label="Tirar a etiqueta de <?php echo vn_h($nomePorId[$cid] ?? ''); ?>">×</button></form>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?></div>
        <?php endif;
    }
    $f = ob_get_clean(); echo cb_janela('cb-dlg-etq', 'Etiquetas dos clientes', 'Junte condomínios da mesma empresa para ver e filtrar juntos.', $f); ?>

    <!-- ===================== PDF dos materiais ===================== -->
    <?php ob_start(); ?>
    <form method="get" action="vendas-pdf.php" target="_blank" class="cb-form">
        <div class="cb-f"><label for="cb-pdf-mes">Mês</label>
            <select id="cb-pdf-mes" name="mes"><option value="">Todos os meses</option>
                <?php foreach (array_keys($matMeses) as $m): ?><option value="<?php echo vn_h($m); ?>"><?php echo vn_h(substr($m, 5, 2) . '/' . substr($m, 0, 4)); ?></option><?php endforeach; ?>
            </select></div>
        <div class="cb-f"><label for="cb-pdf-st">Quais materiais</label>
            <select id="cb-pdf-st" name="status"><option value="todas">Todos (pendentes + aprovados)</option><option value="pendentes">Só aguardando aprovação</option><option value="aprovadas">Só vendas já aprovadas</option></select></div>
        <div class="cb-f full"><span class="lbl">O que aparece no PDF</span>
            <label style="display:flex;gap:10px;align-items:center;min-height:36px"><input type="checkbox" name="mostrar_valores" value="1" checked style="width:20px;min-height:20px"> Mostrar valores</label>
            <label style="display:flex;gap:10px;align-items:center;min-height:36px"><input type="checkbox" name="mostrar_unitario" value="1" checked style="width:20px;min-height:20px"> Mostrar valor unitário</label>
            <label style="display:flex;gap:10px;align-items:center;min-height:36px"><input type="checkbox" name="mostrar_data" value="1" checked style="width:20px;min-height:20px"> Mostrar a data de cada peça</label>
            <label style="display:flex;gap:10px;align-items:center;min-height:36px"><input type="checkbox" name="agrupar_cliente" value="1" checked style="width:20px;min-height:20px"> Separar por cliente</label></div>
        <div class="cb-f full"><button type="submit" class="cb-btn pri" style="min-height:48px"><?php echo cb_ico('picture_as_pdf'); ?>Gerar PDF</button></div>
    </form>
    <?php $f = ob_get_clean(); echo cb_janela('cb-dlg-pdf', 'PDF dos materiais', 'Escolha o que entra no documento.', $f); ?>

    <!-- ===================== Cobrar peça / serviço ===================== -->
    <dialog class="cb-sheet cb-pecamodal" id="cb-peca-modal" aria-labelledby="cb-peca-modal-t"><div class="cb-sheet-in">
        <form method="post" id="cb-form-peca" data-async data-fecha style="display:flex;flex-direction:column;min-height:0;flex:1">
            <input type="hidden" name="acao" value="adicionar_pecas">
            <input type="hidden" name="peca_cliente_nome" id="cb-peca-nome" value="">
            <div class="cb-sheet-head"><h3 id="cb-peca-modal-t">Cobrar peça ou serviço<small>Material do estoque dá baixa automática.</small></h3>
                <button type="button" class="cb-x" data-fechar aria-label="Fechar"><?php echo cb_ico('close'); ?></button></div>
            <div class="cb-sheet-body">
                <div class="cb-f"><label for="cb-peca-cli">Cobrar de qual cliente? *</label>
                    <?php if ($clientesLista): ?>
                        <select name="peca_cliente_id" id="cb-peca-cli" required><option value="">Selecione um cliente…</option>
                            <?php foreach ($clientesLista as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo vn_h((string)$c['nome']); ?><?php $e = $etqMap[(int)$c['id']] ?? ''; echo $e !== '' ? ' — ' . vn_h($e) : ''; ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input name="peca_cliente_nome" id="cb-peca-cli" placeholder="Nome do cliente / condomínio" required>
                    <?php endif; ?></div>
                <div class="cb-f"><label for="cb-peca-data">Data da venda</label><input type="date" name="peca_data" id="cb-peca-data" value="<?php echo vn_h($hoje); ?>" required>
                    <small>Hoje, ou o dia em que o material foi mesmo vendido. Dá para mudar depois.</small></div>
                <div class="cb-serv">
                    <div class="cb-h" style="margin-bottom:4px">Serviço (escreva na hora)</div>
                    <p>Ex.: mão de obra, visita técnica, configuração. Linhas vazias são ignoradas.</p>
                    <div id="cb-serv-rows"><div class="cb-serv-row" data-serv-row>
                        <input name="serv_nome[]" placeholder="Descrição do serviço" aria-label="Descrição do serviço">
                        <input name="serv_qtd[]" inputmode="decimal" value="1" aria-label="Quantidade">
                        <input name="serv_valor[]" inputmode="decimal" placeholder="Valor (R$)" aria-label="Valor do serviço">
                    </div></div>
                    <button type="button" class="cb-btn sm" id="cb-serv-add"><?php echo cb_ico('add'); ?>Outro serviço</button>
                </div>
                <label class="cb-search-in"><?php echo cb_ico('search'); ?><input type="search" id="cb-peca-busca" placeholder="Buscar peça ou serviço do catálogo" aria-label="Buscar no catálogo"></label>
                <?php echo cb_catalogo_lista($catalogoPecas, $catalogoServicos); ?>
            </div>
            <div class="cb-sheet-foot"><button type="submit" class="cb-btn pri grow" id="cb-peca-ok" disabled>Cobrar itens selecionados</button></div>
        </form>
    </div></dialog>
    <?php echo cb_janela_catalogo(); ?>
    </div>

    <div id="cb-flash" hidden data-ok="<?php echo vn_h((string)$ok); ?>" data-erro="<?php echo vn_h((string)$erro); ?>" data-abrir="<?php echo vn_h($abrirDlg); ?>" data-cat-id="<?php echo (int)$catalogoSalvoId; ?>" data-cat-novo="<?php echo $catalogoNovo ? '1' : '0'; ?>"></div>

</div>

<script>
window.VN_COB = <?php echo json_encode($orcPre ? ['orc' => ['cliente_id' => $orcPre['cliente_id'], 'telefone' => $orcPre['telefone'], 'valor' => $orcPre['valor'], 'obs' => $orcPre['obs'], 'numero' => $orcPre['numero']]] : new stdClass(), VN_JSON_SCRIPT); ?>;
</script>
<script defer src="<?php echo vn_h(vn_ui_asset('assets/js/vn-cobrancas.js')); ?>"></script>
<?php vn_ui_shell_end('cobrancas'); ?>
