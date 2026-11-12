<?php
/*
    VN System - A Pagar (contas a pagar): dados, regras e fotos.

    Usado por:
      - contas-pagar.php        a tela (lista, nova conta, pagar, fotos)
      - contas-pagar-foto.php   entrega a foto só para quem está logado

    Precisa que _vn_orcamento_bootstrap.php já esteja carregado (vn_exec, vn_fetch_*, vn_money_db...).

    Fotos ficam em uploads/contas_pagar/<id da conta>/ (fora do alcance direto do navegador: a pasta
    recebe um .htaccess "Require all denied" e as imagens só saem por contas-pagar-foto.php).
    Cada anexo tem um tipo: 'conta' (boleto, nota, foto da conta) ou 'comprovante' (prova do pagamento), e um
    formato: 'img' (foto JPG/PNG/WebP) ou 'pdf' (boleto/nota/comprovante em PDF).

    Contas parceladas: "Parcelado" cria, de uma vez, a entrada (opcional, em % ou em R$) e as parcelas do restante.
    Cada pagamento é UMA conta comum (paga, tem fotos, pode ser editada) ligada às outras por parcelamento_id e
    numerada junto com as outras: 1/5, 2/5, 3/5... (a entrada, se houver, é a 1/N).

    Contas recorrentes (aluguel, internet, contador...): a conta é criada com "Repetir" e passa a existir uma
    "repetição" (vn_contas_pagar_recorrentes). A primeira conta nasce na hora em que é cadastrada; cada uma das
    seguintes é criada CP_ANTECEDENCIA_DIAS (7) dias antes de vencer, sozinha (cp_gerar_recorrentes, chamada a cada
    vez que a tela abre), até acabar o número de vezes ou alguém tocar em "Parar de repetir". Cada ocorrência é
    uma conta normal (paga, tem fotos, pode ser editada).
*/

/* Erro com mensagem escrita para o usuário (aparece como está na tela). */
class CpErro extends Exception {}

const CP_FOTOS_MAX_POR_CONTA = 20;
const CP_FOTO_MAX_BYTES = 15 * 1024 * 1024;   // vale para foto e para PDF
const CP_ANTECEDENCIA_DIAS = 7;    // cada conta repetida é criada quantos dias antes de vencer
const CP_SUPER_USUARIO = 'slownx';  // este usuário enxerga TODAS as contas, inclusive as privadas dos outros

/* ------------------------------------------------------------------ quem está usando / quem vê o quê */
/*
   Cada conta tem um dono (quem cadastrou) e uma visibilidade:
     publico  = todos os administradores veem (como era antes);
     privado  = só o dono vê. Nem os outros administradores. Exceção: CP_SUPER_USUARIO vê tudo.
   Contas antigas, sem dono, ficam públicas.
*/
function cp_usuario(): array
{
    $login = strtolower(trim((string)($_SESSION['usuario'] ?? ($_SESSION['admin'] ?? ''))));
    return [
        'id'      => (int)($_SESSION['admin_id'] ?? ($_SESSION['id'] ?? 0)),
        'usuario' => $login,
        'nome'    => trim((string)($_SESSION['nome'] ?? ($_SESSION['usuario'] ?? ''))),
        'super'   => $login !== '' && $login === CP_SUPER_USUARIO,
    ];
}

function cp_visibilidades(): array
{
    return ['privado' => 'Privada (só eu vejo)', 'publico' => 'Pública (todos os administradores veem)'];
}

/* Trecho de SQL (e parâmetros) que deixa passar só as contas que quem está usando pode ver. $a = apelido da tabela. */
function cp_sql_visivel(string $a = 'c'): array
{
    $u = cp_usuario();
    if ($u['super']) return ['1 = 1', []];
    return ["($a.visibilidade <> 'privado' OR ($a.dono_id IS NOT NULL AND $a.dono_id = ?))", [$u['id']]];
}

function cp_pode_ver(array $conta): bool
{
    $u = cp_usuario();
    if ($u['super']) return true;
    if ((string)($conta['visibilidade'] ?? 'publico') !== 'privado') return true;
    return $u['id'] > 0 && (int)($conta['dono_id'] ?? 0) === $u['id'];
}

/* Só o dono (ou o usuário principal) muda a visibilidade de uma conta. */
function cp_pode_mudar_visibilidade(array $conta): bool
{
    $u = cp_usuario();
    if ($u['super']) return true;
    $dono = (int)($conta['dono_id'] ?? 0);
    return $dono === 0 ? $u['id'] > 0 : $dono === $u['id'];   // conta antiga (sem dono): qualquer administrador
}

/* Dono + visibilidade que uma conta nova recebe. */
function cp_dono_novo(?string $visibilidade): array
{
    $u = cp_usuario();
    $vis = isset(cp_visibilidades()[(string)$visibilidade]) ? (string)$visibilidade : 'privado';
    if ($u['id'] <= 0) $vis = 'publico';   // sem dono identificado não existe "privada": ninguém (além do principal) conseguiria ver
    return [
        'dono_id'      => $u['id'] > 0 ? $u['id'] : null,
        'dono_usuario' => $u['usuario'] !== '' ? mb_substr($u['usuario'], 0, 80) : null,
        'dono_nome'    => $u['nome'] !== '' ? mb_substr($u['nome'], 0, 120) : null,
        'visibilidade' => $vis,
    ];
}

function cp_frequencias(): array
{
    return ['semanal' => 'Toda semana', 'quinzenal' => 'A cada 15 dias', 'mensal' => 'Todo mês', 'anual' => 'Todo ano'];
}

function cp_formas(): array
{
    return [
        'pix' => 'PIX', 'boleto' => 'Boleto', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro',
        'cartao' => 'Cartão', 'debito' => 'Débito automático', 'outro' => 'Outro',
    ];
}

function cp_categorias(): array
{
    return ['Aluguel', 'Água', 'Luz', 'Internet / Telefone', 'Material / Fornecedor', 'Combustível', 'Manutenção',
        'Impostos', 'Funcionários', 'Contador', 'Software / Assinaturas', 'Outros'];
}

function cp_tipos_foto(): array
{
    return ['conta' => 'Conta / boleto', 'comprovante' => 'Comprovante de pagamento'];
}

/* ------------------------------------------------------------------ tabelas */
function cp_ensure_tables(): void
{
    static $feito = false;
    if ($feito) return;
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        descricao VARCHAR(200) NOT NULL,
        fornecedor VARCHAR(180) NULL,
        categoria VARCHAR(60) NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        vencimento DATE NOT NULL,
        codigo VARCHAR(512) NULL,
        observacao TEXT NULL,
        pago TINYINT(1) NOT NULL DEFAULT 0,
        pago_em DATE NULL,
        valor_pago DECIMAL(12,2) NULL,
        forma_pagamento VARCHAR(20) NULL,
        recorrente_id INT NULL,
        ocorrencia INT NULL,
        parcelamento_id INT NULL,
        parcela_num INT NULL,
        parcela_total INT NULL,
        parcela_ordem INT NULL,
        parcela_qtd INT NULL,
        dono_id INT NULL,
        dono_usuario VARCHAR(80) NULL,
        dono_nome VARCHAR(120) NULL,
        visibilidade VARCHAR(10) NOT NULL DEFAULT 'publico',
        criado_em DATETIME NOT NULL,
        INDEX (pago, vencimento),
        INDEX (pago_em),
        INDEX idx_recorrente (recorrente_id),
        INDEX idx_parcelamento (parcelamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar_recorrentes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        descricao VARCHAR(200) NOT NULL,
        fornecedor VARCHAR(180) NULL,
        categoria VARCHAR(60) NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        observacao TEXT NULL,
        frequencia VARCHAR(12) NOT NULL DEFAULT 'mensal',
        primeira DATE NOT NULL,
        proximo_n INT NOT NULL DEFAULT 0,
        total_vezes INT NULL,
        ativa TINYINT(1) NOT NULL DEFAULT 1,
        dono_id INT NULL,
        dono_usuario VARCHAR(80) NULL,
        dono_nome VARCHAR(120) NULL,
        visibilidade VARCHAR(10) NOT NULL DEFAULT 'publico',
        criado_em DATETIME NOT NULL,
        INDEX (ativa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Quem já usa o A Pagar tem a tabela antiga: acrescenta as colunas novas sem perder nada.
    vn_add_col_if_missing('vn_contas_pagar', 'recorrente_id', 'INT NULL, ADD INDEX idx_recorrente (recorrente_id)');
    vn_add_col_if_missing('vn_contas_pagar', 'ocorrencia', 'INT NULL');
    vn_add_col_if_missing('vn_contas_pagar', 'parcelamento_id', 'INT NULL, ADD INDEX idx_parcelamento (parcelamento_id)');
    vn_add_col_if_missing('vn_contas_pagar', 'parcela_num', 'INT NULL');
    vn_add_col_if_missing('vn_contas_pagar', 'parcela_total', 'INT NULL');
    vn_add_col_if_missing('vn_contas_pagar', 'parcela_ordem', 'INT NULL');
    vn_add_col_if_missing('vn_contas_pagar', 'parcela_qtd', 'INT NULL');
    // Dono e visibilidade (contas antigas ficam públicas, sem dono)
    foreach (['vn_contas_pagar', 'vn_contas_pagar_recorrentes'] as $tab) {
        vn_add_col_if_missing($tab, 'dono_id', 'INT NULL, ADD INDEX idx_dono (dono_id)');
        vn_add_col_if_missing($tab, 'dono_usuario', 'VARCHAR(80) NULL');
        vn_add_col_if_missing($tab, 'dono_nome', 'VARCHAR(120) NULL');
        vn_add_col_if_missing($tab, 'visibilidade', "VARCHAR(10) NOT NULL DEFAULT 'publico'");
    }
    // Parcelamentos criados antes da numeração "1/5": entrada vira 1, as parcelas seguem 2, 3... (ou 1, 2... sem entrada)
    vn_exec('UPDATE vn_contas_pagar c
             JOIN (SELECT parcelamento_id, MAX(CASE WHEN parcela_num = 0 THEN 1 ELSE 0 END) AS ent
                   FROM vn_contas_pagar WHERE parcelamento_id IS NOT NULL GROUP BY parcelamento_id) g ON g.parcelamento_id = c.parcelamento_id
             SET c.parcela_ordem = c.parcela_num + g.ent, c.parcela_qtd = c.parcela_total + g.ent
             WHERE c.parcela_ordem IS NULL AND c.parcela_num IS NOT NULL');
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar_fotos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conta_id INT NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'conta',
        caminho VARCHAR(255) NOT NULL,
        nome_original VARCHAR(190) NULL,
        formato VARCHAR(8) NOT NULL DEFAULT 'img',
        tamanho INT NULL,
        criado_em DATETIME NOT NULL,
        INDEX (conta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_add_col_if_missing('vn_contas_pagar_fotos', 'formato', "VARCHAR(8) NOT NULL DEFAULT 'img'");   // 'img' (foto) ou 'pdf'
    vn_add_col_if_missing('vn_contas_pagar_fotos', 'tamanho', 'INT NULL');
    $feito = true;
}

/* ------------------------------------------------------------------ situação / textos */
/* paga | atrasada | hoje | semana (vence em até 7 dias) | futura */
function cp_estado(array $c, string $hoje): string
{
    if ((int)($c['pago'] ?? 0) === 1) return 'paga';
    $v = (string)($c['vencimento'] ?? '');
    if ($v === '') return 'futura';
    if ($v < $hoje) return 'atrasada';
    if ($v === $hoje) return 'hoje';
    if ($v <= date('Y-m-d', strtotime($hoje . ' +7 days'))) return 'semana';
    return 'futura';
}

/* "Venceu há 3 dias", "Vence hoje", "Vence amanhã", "Vence em 5 dias", "Vence 15/11". */
function cp_quando(string $venc, string $hoje): string
{
    if ($venc === '') return 'Sem vencimento';
    try {
        $d = (int)(new DateTime($hoje))->diff(new DateTime($venc))->format('%r%a');
    } catch (Throwable $e) {
        return 'Vence ' . vn_date_br($venc);
    }
    if ($d < 0) return 'Venceu há ' . abs($d) . (abs($d) === 1 ? ' dia' : ' dias');
    if ($d === 0) return 'Vence hoje';
    if ($d === 1) return 'Vence amanhã';
    if ($d <= 7) return 'Vence em ' . $d . ' dias';
    return 'Vence ' . substr(vn_date_br($venc), 0, 5);
}

/* ------------------------------------------------------------------ leitura */
/* Totais para os cartões do topo e as contagens das abas. Não depende da busca. */
function cp_resumo(string $hoje): array
{
    $fim7 = date('Y-m-d', strtotime($hoje . ' +7 days'));
    $mesIni = substr($hoje, 0, 8) . '01';
    $mesFim = date('Y-m-t', strtotime($mesIni));
    [$visSql, $visPar] = cp_sql_visivel('c');
    $r = vn_fetch_one(
        "SELECT
            COALESCE(SUM(CASE WHEN pago = 0 THEN 1 ELSE 0 END), 0) AS q_abertas,
            COALESCE(SUM(CASE WHEN pago = 0 THEN valor ELSE 0 END), 0) AS v_abertas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento < ? THEN 1 ELSE 0 END), 0) AS q_atrasadas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento < ? THEN valor ELSE 0 END), 0) AS v_atrasadas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento >= ? AND vencimento <= ? THEN 1 ELSE 0 END), 0) AS q_semana,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento >= ? AND vencimento <= ? THEN valor ELSE 0 END), 0) AS v_semana,
            COALESCE(SUM(CASE WHEN pago = 1 THEN 1 ELSE 0 END), 0) AS q_pagas,
            COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN COALESCE(valor_pago, valor) ELSE 0 END), 0) AS v_pago_mes,
            COUNT(*) AS q_todas
         FROM vn_contas_pagar c WHERE $visSql",
        array_merge([$hoje, $hoje, $hoje, $fim7, $hoje, $fim7, $mesIni, $mesFim], $visPar)
    ) ?: [];
    foreach (['q_abertas', 'q_atrasadas', 'q_semana', 'q_pagas', 'q_todas'] as $k) $r[$k] = (int)($r[$k] ?? 0);
    foreach (['v_abertas', 'v_atrasadas', 'v_semana', 'v_pago_mes'] as $k) $r[$k] = (float)($r[$k] ?? 0);
    return $r;
}

/* $ver: abertas | atrasadas | semana | pagas | todas. $q: texto de busca. $de: todas | minhas | publicas | privadas. */
function cp_listar(string $ver, string $q, string $hoje, string $de = 'todas'): array
{
    $fim7 = date('Y-m-d', strtotime($hoje . ' +7 days'));
    [$visSql, $visPar] = cp_sql_visivel('c');
    $where = [$visSql];
    $params = $visPar;
    switch ($de) {
        case 'minhas':   $where[] = 'c.dono_id = ?'; $params[] = cp_usuario()['id']; break;
        case 'publicas': $where[] = "c.visibilidade <> 'privado'"; break;
        case 'privadas': $where[] = "c.visibilidade = 'privado'"; break;
    }
    $ordem = 'c.pago ASC, c.vencimento ASC, c.id ASC';
    switch ($ver) {
        case 'atrasadas': $where[] = 'c.pago = 0 AND c.vencimento < ?'; $params[] = $hoje; break;
        case 'semana':    $where[] = 'c.pago = 0 AND c.vencimento >= ? AND c.vencimento <= ?'; $params[] = $hoje; $params[] = $fim7; break;
        case 'pagas':     $where[] = 'c.pago = 1'; $ordem = 'c.pago_em DESC, c.id DESC'; break;
        case 'todas':     break;
        default:          $where[] = 'c.pago = 0'; break;
    }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $where[] = '(c.descricao LIKE ? OR c.fornecedor LIKE ? OR c.categoria LIKE ? OR c.observacao LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT c.*, r.frequencia AS rec_freq, r.total_vezes AS rec_total, r.ativa AS rec_ativa
            FROM vn_contas_pagar c LEFT JOIN vn_contas_pagar_recorrentes r ON r.id = c.recorrente_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $ordem . ' LIMIT 400';
    return vn_fetch_all($sql, $params);
}

/* A conta, só se quem está usando pode vê-la (privada de outro usuário = "não encontrada"). */
function cp_conta(int $id): ?array
{
    if ($id <= 0) return null;
    $c = vn_fetch_one('SELECT * FROM vn_contas_pagar WHERE id = ?', [$id]);
    return ($c && cp_pode_ver($c)) ? $c : null;
}

/* Fotos das contas informadas, agrupadas por conta: [conta_id => [foto, ...]]. */
function cp_fotos_das_contas(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (vn_fetch_all("SELECT id, conta_id, tipo, nome_original, formato, tamanho FROM vn_contas_pagar_fotos WHERE conta_id IN ($marcas) ORDER BY id", $ids) as $f) {
        $out[(int)$f['conta_id']][] = [
            'id' => (int)$f['id'], 'tipo' => (string)$f['tipo'], 'nome' => (string)($f['nome_original'] ?? ''),
            'formato' => ((string)($f['formato'] ?? 'img')) === 'pdf' ? 'pdf' : 'img', 'tamanho' => (int)($f['tamanho'] ?? 0),
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ gravação */
/* Lê e valida o formulário de conta. Lança CpErro com a mensagem para o usuário. */
function cp_validar_conta(array $post): array
{
    $descricao = trim(preg_replace('/\s+/', ' ', (string)($post['descricao'] ?? '')));
    if ($descricao === '') throw new CpErro('Informe o que é a conta (ex.: Aluguel, Conta de luz).');
    if (mb_strlen($descricao) > 200) throw new CpErro('A descrição passou de 200 letras.');

    $valor = vn_money_db($post['valor'] ?? '');
    if ($valor <= 0) throw new CpErro('Informe o valor da conta.');
    if ($valor > 9999999.99) throw new CpErro('Valor muito alto. Confira se digitou certo.');

    $venc = vn_date_db_or_null($post['vencimento'] ?? '');
    if (!$venc) throw new CpErro('Informe a data de vencimento.');

    $limpa = static function ($v, int $max): ?string {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    return [
        'descricao'  => $descricao,
        'fornecedor' => $limpa(preg_replace('/\s+/', ' ', (string)($post['fornecedor'] ?? '')), 180),
        'categoria'  => $limpa(preg_replace('/\s+/', ' ', (string)($post['categoria'] ?? '')), 60),
        'valor'      => $valor,
        'vencimento' => $venc,
        'codigo'     => $limpa(preg_replace('/\s+/', '', (string)($post['codigo'] ?? '')), 512),
        'observacao' => $limpa($post['observacao'] ?? '', 2000),
        // null = o formulário não mandou (ou mandou algo inválido): numa conta nova vira "privada", numa existente não muda
        'visibilidade' => isset(cp_visibilidades()[(string)($post['visibilidade'] ?? '')]) ? (string)$post['visibilidade'] : null,
    ];
}

/*
   Cria (id = 0) ou atualiza a conta. Devolve o id.
   $aplicarProximas: numa conta que se repete, também muda a descrição/valor/fornecedor/categoria/observação
   das próximas repetições ainda não pagas (e do modelo, para as que ainda vão aparecer). As datas não mudam.
   Numa parcela, muda só descrição/fornecedor/categoria/observação das OUTRAS parcelas não pagas (valores e datas ficam).
*/
function cp_salvar_conta(int $id, array $d, bool $aplicarProximas = false): int
{
    if ($id > 0) {
        $atual = cp_conta($id);
        if (!$atual) throw new CpErro('Conta não encontrada.');
        vn_exec('UPDATE vn_contas_pagar SET descricao = ?, fornecedor = ?, categoria = ?, valor = ?, vencimento = ?, codigo = ?, observacao = ? WHERE id = ?',
            [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['vencimento'], $d['codigo'], $d['observacao'], $id]);
        // mudar público/privado: só o dono (ou o usuário principal); vale para toda a repetição / parcelamento da conta
        if (!empty($d['visibilidade']) && $d['visibilidade'] !== (string)($atual['visibilidade'] ?? 'publico') && cp_pode_mudar_visibilidade($atual)) {
            vn_exec('UPDATE vn_contas_pagar SET visibilidade = ? WHERE id = ?', [$d['visibilidade'], $id]);
            if ((int)($atual['recorrente_id'] ?? 0) > 0) {
                vn_exec('UPDATE vn_contas_pagar SET visibilidade = ? WHERE recorrente_id = ?', [$d['visibilidade'], (int)$atual['recorrente_id']]);
                vn_exec('UPDATE vn_contas_pagar_recorrentes SET visibilidade = ? WHERE id = ?', [$d['visibilidade'], (int)$atual['recorrente_id']]);
            }
            if ((int)($atual['parcelamento_id'] ?? 0) > 0) {
                vn_exec('UPDATE vn_contas_pagar SET visibilidade = ? WHERE parcelamento_id = ?', [$d['visibilidade'], (int)$atual['parcelamento_id']]);
            }
        }
        if ($aplicarProximas && (int)($atual['recorrente_id'] ?? 0) > 0) {
            $rec = (int)$atual['recorrente_id'];
            vn_exec('UPDATE vn_contas_pagar_recorrentes SET descricao = ?, fornecedor = ?, categoria = ?, valor = ?, observacao = ? WHERE id = ?',
                [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['observacao'], $rec]);
            vn_exec('UPDATE vn_contas_pagar SET descricao = ?, fornecedor = ?, categoria = ?, valor = ?, observacao = ?
                     WHERE recorrente_id = ? AND pago = 0 AND ocorrencia > ?',
                [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['observacao'], $rec, (int)($atual['ocorrencia'] ?? 0)]);
        }
        if ($aplicarProximas && (int)($atual['parcelamento_id'] ?? 0) > 0) {
            vn_exec('UPDATE vn_contas_pagar SET descricao = ?, fornecedor = ?, categoria = ?, observacao = ? WHERE parcelamento_id = ? AND pago = 0 AND id <> ?',
                [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['observacao'], (int)$atual['parcelamento_id'], $id]);
        }
        return $id;
    }
    $dono = cp_dono_novo($d['visibilidade'] ?? null);
    vn_exec('INSERT INTO vn_contas_pagar (descricao, fornecedor, categoria, valor, vencimento, codigo, observacao, dono_id, dono_usuario, dono_nome, visibilidade, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['vencimento'], $d['codigo'], $d['observacao'],
         $dono['dono_id'], $dono['dono_usuario'], $dono['dono_nome'], $dono['visibilidade'], date('Y-m-d H:i:s')]);
    return vn_last_id();
}

function cp_marcar_paga(int $id, array $post): void
{
    $c = cp_conta($id);
    if (!$c) throw new CpErro('Conta não encontrada.');
    $data = vn_date_db_or_null($post['pago_em'] ?? '');
    if (!$data) throw new CpErro('Informe a data em que pagou.');
    $valor = vn_money_db($post['valor_pago'] ?? '');
    if ($valor <= 0) $valor = (float)$c['valor'];
    if ($valor > 9999999.99) throw new CpErro('Valor muito alto. Confira se digitou certo.');
    $forma = (string)($post['forma_pagamento'] ?? '');
    if (!isset(cp_formas()[$forma])) $forma = null;
    vn_exec('UPDATE vn_contas_pagar SET pago = 1, pago_em = ?, valor_pago = ?, forma_pagamento = ? WHERE id = ?', [$data, $valor, $forma, $id]);
}

function cp_desfazer_pagamento(int $id): void
{
    if (!cp_conta($id)) throw new CpErro('Conta não encontrada.');
    vn_exec('UPDATE vn_contas_pagar SET pago = 0, pago_em = NULL, valor_pago = NULL, forma_pagamento = NULL WHERE id = ?', [$id]);
}

/* Exclui a conta e as fotos dela. $pararRepeticao: se ela se repete, para a repetição (remove as futuras sem pagamento e sem foto). */
function cp_excluir_conta(int $id, bool $pararRepeticao = false): void
{
    $c = cp_conta($id);
    if (!$c) throw new CpErro('Conta não encontrada.');
    if ($pararRepeticao && (int)($c['recorrente_id'] ?? 0) > 0) cp_parar_recorrente((int)$c['recorrente_id'], (string)$c['vencimento']);
    foreach (vn_fetch_all('SELECT id FROM vn_contas_pagar_fotos WHERE conta_id = ?', [$id]) as $f) {
        cp_excluir_foto((int)$f['id']);
    }
    vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [$id]);
    @rmdir(cp_fotos_dir($id));   // só remove se ficou vazia
}

/*
   Impede fazer duas vezes a mesma coisa (cadastrar a conta, registrar o pagamento, anexar as fotos) quando o envio
   é repetido: toque duplo no botão, falha de rede em que o navegador reenvia, etc.
   Cada vez que a janela abre ela recebe um código único; o 2º envio com o mesmo código é recusado.
   Devolve o código aceito ('' se o navegador não mandou um válido: então não há como proteger por código).
   Guarda os últimos 30 códigos na sessão (a sessão do PHP já serializa os pedidos do mesmo usuário, então
   dois envios simultâneos não passam juntos).
*/
function cp_token_usar(string $token, string $mensagem): string
{
    if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) return '';
    $usados = (array)($_SESSION['cp_tokens'] ?? []);
    if (in_array($token, $usados, true)) throw new CpErro($mensagem);
    $usados[] = $token;
    $_SESSION['cp_tokens'] = array_slice($usados, -30);
    return $token;
}

/* Devolve o código quando a operação NÃO chegou a acontecer (deu erro), para a pessoa poder tentar de novo. */
function cp_token_liberar(string $token): void
{
    $_SESSION['cp_tokens'] = array_values(array_diff((array)($_SESSION['cp_tokens'] ?? []), [$token]));
}

/*
   Segunda barreira, para quando não veio código: já existe uma conta com o mesmo nome e vencimento criada nos
   últimos 30 segundos? Devolve o id dela (null se não). Pega o reenvio mesmo de um navegador/arquivo antigo.
*/
function cp_conta_igual_recente(array $d): ?int
{
    $u = cp_usuario();
    $r = vn_fetch_one('SELECT id FROM vn_contas_pagar WHERE descricao = ? AND vencimento = ? AND criado_em >= ? AND dono_id <=> ? LIMIT 1',
        [$d['descricao'], $d['vencimento'], date('Y-m-d H:i:s', time() - 30), $u['id'] > 0 ? $u['id'] : null]);
    return $r ? (int)$r['id'] : null;
}

/* ------------------------------------------------------------------ contas parceladas */
function cp_intervalos(): array
{
    return ['mensal' => 'Todo mês', 'quinzenal' => 'A cada 15 dias', 'semanal' => 'Toda semana'];
}

/* "2/5": posição da conta entre todos os pagamentos do parcelamento (null se a conta não é parcelada). */
function cp_numero_parcela(?int $ordem, ?int $qtd): ?string
{
    return ($ordem === null || $qtd === null || $qtd < 1) ? null : $ordem . '/' . $qtd;
}

/* "Parcela 2/5" ou "Entrada 1/5" (null se a conta não é parcelada). $num: 0 = entrada. */
function cp_rotulo_parcela(?int $num, ?int $ordem, ?int $qtd): ?string
{
    $n = cp_numero_parcela($ordem, $qtd);
    if ($n === null) return null;
    return ($num === 0 ? 'Entrada ' : 'Parcela ') . $n;
}

/*
   Lê "Forma de pagamento" do formulário. Devolve null (à vista) ou o plano:
     ['entrada' => R$, 'n' => parcelas do restante, 'freq' => ..., 'itens' => [['num' => 0..n, 'valor' => R$, 'venc' => data], ...]]
   Entrada em porcentagem ('pct') ou em reais ('val'). O restante é dividido em n parcelas (os centavos que sobram
   ficam na última, então a soma fecha sempre no valor total). Lança CpErro com a mensagem para o usuário.
   $d = cp_validar_conta(): valor = total; vencimento = data da entrada (ou da 1ª parcela, se não houver entrada).
*/
function cp_validar_parcelamento(array $post, array $d): ?array
{
    if ((string)($post['condicao'] ?? 'avista') !== 'parcelado') return null;

    $n = trim((string)($post['parcelas'] ?? ''));
    if ($n === '' || !ctype_digit($n) || (int)$n < 1 || (int)$n > 120) throw new CpErro('Em "número de parcelas", digite de 1 a 120.');
    $n = (int)$n;

    $totalCent = (int)round($d['valor'] * 100);
    $tipo = (string)($post['entrada_tipo'] ?? '');
    $entradaCent = 0;
    if ($tipo === 'pct') {
        $pct = (float)str_replace(',', '.', trim((string)($post['entrada_valor'] ?? '')));
        if ($pct <= 0 || $pct >= 100) throw new CpErro('A entrada em porcentagem deve ser maior que 0% e menor que 100%.');
        $entradaCent = (int)round($totalCent * $pct / 100);
    } elseif ($tipo === 'val') {
        $entradaCent = (int)round(vn_money_db($post['entrada_valor'] ?? '') * 100);
        if ($entradaCent <= 0) throw new CpErro('Informe o valor da entrada.');
    } elseif ($tipo !== '') {
        throw new CpErro('Escolha se a entrada é em porcentagem ou em valor.');
    }
    if ($tipo !== '' && ($entradaCent <= 0 || $entradaCent >= $totalCent)) throw new CpErro('A entrada precisa ser maior que zero e menor que o valor total.');
    if ($entradaCent === 0 && $n === 1) return null;   // 1 parcela sem entrada = conta à vista

    $freq = (string)($post['intervalo'] ?? 'mensal');
    if (!isset(cp_intervalos()[$freq])) $freq = 'mensal';

    // datas: entrada no vencimento escolhido; 1ª parcela depois (padrão: um intervalo depois), ou no próprio vencimento se não há entrada
    $primeira = $d['vencimento'];
    if ($entradaCent > 0) {
        $primeira = vn_date_db_or_null($post['venc_parcela'] ?? '') ?: cp_data_ocorrencia($d['vencimento'], $freq, 1);
        if ($primeira < $d['vencimento']) throw new CpErro('O vencimento da 1ª parcela não pode ser antes da entrada.');
    }

    $restoCent = $totalCent - $entradaCent;
    $baseCent = intdiv($restoCent, $n);
    $itens = [];
    if ($entradaCent > 0) $itens[] = ['num' => 0, 'valor' => (float)($entradaCent / 100), 'venc' => $d['vencimento']];
    for ($k = 1; $k <= $n; $k++) {
        $cent = $k < $n ? $baseCent : $restoCent - $baseCent * ($n - 1);
        $itens[] = ['num' => $k, 'valor' => (float)($cent / 100), 'venc' => cp_data_ocorrencia($primeira, $freq, $k - 1)];
    }
    foreach ($itens as $it) if ($it['valor'] <= 0) throw new CpErro('Com tantas parcelas o valor de cada uma ficaria zerado. Diminua o número de parcelas.');
    return ['entrada' => (float)($entradaCent / 100), 'n' => $n, 'freq' => $freq, 'itens' => $itens];
}

/* Cria a entrada e as parcelas como contas comuns. Devolve o id da primeira (onde vão as fotos e o código do boleto). */
function cp_criar_parcelamento(array $d, array $plano): int
{
    $criadas = [];
    $grupo = null;
    $qtd = count($plano['itens']);                       // todos os pagamentos: entrada (se houver) + parcelas
    $dono = cp_dono_novo($d['visibilidade'] ?? null);
    $deslocamento = $plano['entrada'] > 0 ? 1 : 0;      // com entrada: entrada = 1/N, parcela 1 = 2/N...
    try {
        foreach ($plano['itens'] as $it) {
            $ordem = $it['num'] + $deslocamento;
            vn_exec('INSERT INTO vn_contas_pagar (descricao, fornecedor, categoria, valor, vencimento, codigo, observacao, parcelamento_id, parcela_num, parcela_total, parcela_ordem, parcela_qtd, dono_id, dono_usuario, dono_nome, visibilidade, criado_em)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$d['descricao'], $d['fornecedor'], $d['categoria'], $it['valor'], $it['venc'], $grupo === null ? $d['codigo'] : null, $d['observacao'],
                 $grupo, $it['num'], $plano['n'], $ordem, $qtd, $dono['dono_id'], $dono['dono_usuario'], $dono['dono_nome'], $dono['visibilidade'], date('Y-m-d H:i:s')]);
            $id = vn_last_id();
            $criadas[] = $id;
            if ($grupo === null) {                       // o grupo é o id da primeira conta
                $grupo = $id;
                vn_exec('UPDATE vn_contas_pagar SET parcelamento_id = ? WHERE id = ?', [$grupo, $id]);
            }
        }
    } catch (Throwable $e) {
        foreach ($criadas as $id) vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [$id]);   // não deixa parcelamento pela metade
        throw $e;
    }
    return $criadas[0];
}

/* Por parcelamento: quantas contas, quantas pagas e o total. [parcelamento_id => [...]] */
function cp_resumo_parcelamentos(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (vn_fetch_all("SELECT parcelamento_id, COUNT(*) AS q, SUM(pago) AS pagas, SUM(valor) AS total FROM vn_contas_pagar WHERE parcelamento_id IN ($marcas) GROUP BY parcelamento_id", $ids) as $r) {
        $out[(int)$r['parcelamento_id']] = ['q' => (int)$r['q'], 'pagas' => (int)$r['pagas'], 'total' => (float)$r['total']];
    }
    return $out;
}

/* Cancela o parcelamento: remove as parcelas NÃO pagas e sem foto. As pagas (e as com foto) ficam. Devolve quantas removeu. */
function cp_cancelar_parcelamento(int $grupo): int
{
    if ($grupo <= 0) throw new CpErro('Essa conta não é parcelada.');
    $n = 0;
    foreach (vn_fetch_all(
        'SELECT c.id FROM vn_contas_pagar c WHERE c.parcelamento_id = ? AND c.pago = 0
           AND NOT EXISTS (SELECT 1 FROM vn_contas_pagar_fotos f WHERE f.conta_id = c.id)', [$grupo]) as $c) {
        vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [(int)$c['id']]);
        $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ contas recorrentes */
/*
   Lê "Repetir" do formulário. Devolve null (não repete) ou ['freq' => ..., 'vezes' => int|null].
   vezes = null: sem data final. Lança CpErro se a combinação não faz sentido.
*/
function cp_validar_repeticao(array $post, string $primeiroVenc, string $hoje): ?array
{
    $freq = (string)($post['repetir'] ?? '');
    if ($freq === '') return null;
    if (!isset(cp_frequencias()[$freq])) throw new CpErro('Escolha como a conta se repete.');
    $vezes = trim((string)($post['vezes'] ?? ''));
    $n = null;
    if ($vezes !== '') {
        if (!ctype_digit($vezes) || (int)$vezes < 1 || (int)$vezes > 120) throw new CpErro('Em "quantas vezes", digite um número de 1 a 120 (ou deixe vazio para repetir sem data final).');
        $n = (int)$vezes;
        if ($n === 1) return null;   // 1 vez só = conta comum
    }
    // data inicial no passado: no máximo um período atrás (senão apareceriam várias contas atrasadas de uma vez)
    $dias = ['semanal' => 7, 'quinzenal' => 15, 'mensal' => 31, 'anual' => 366][$freq];
    if ($primeiroVenc < date('Y-m-d', strtotime($hoje . ' -' . $dias . ' days'))) {
        throw new CpErro('Para repetir, o primeiro vencimento não pode ser de mais de ' . $dias . ' dias atrás. Use a data da próxima conta a vencer.');
    }
    return ['freq' => $freq, 'vezes' => $n];
}

/* Data da ocorrência de número $n (0 = a primeira). Mês curto: dia 31 vira o último dia do mês. */
function cp_data_ocorrencia(string $primeira, string $freq, int $n): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $primeira);
    if (!$d) throw new CpErro('Data inicial da repetição inválida.');
    if ($freq === 'semanal') return $d->modify('+' . (7 * $n) . ' days')->format('Y-m-d');
    if ($freq === 'quinzenal') return $d->modify('+' . (14 * $n) . ' days')->format('Y-m-d');
    $dia = (int)$d->format('j');
    $ano = (int)$d->format('Y');
    $mes = (int)$d->format('n') + ($freq === 'anual' ? 12 * $n : $n);
    $ano += intdiv($mes - 1, 12);
    $mes = (($mes - 1) % 12) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, min($dia, (int)date('t', mktime(0, 0, 0, $mes, 1, $ano))));
}

/* "Todo mês", "Todo mês · 3 de 12" para mostrar na lista. */
function cp_rotulo_repeticao(string $freq, ?int $total, ?int $ocorrencia): string
{
    $t = cp_frequencias()[$freq] ?? 'Repete';
    if ($total !== null && $ocorrencia !== null) $t .= ' · ' . ($ocorrencia + 1) . ' de ' . $total;
    return $t;
}

/*
   Cria a repetição e já gera a primeira conta (mesmo que seja daqui a muito tempo, para poder anexar foto).
   Devolve o id da primeira conta.
*/
function cp_criar_recorrente(array $d, string $freq, ?int $vezes, string $hoje): int
{
    $dono = cp_dono_novo($d['visibilidade'] ?? null);
    vn_exec('INSERT INTO vn_contas_pagar_recorrentes (descricao, fornecedor, categoria, valor, observacao, frequencia, primeira, proximo_n, total_vezes, ativa, dono_id, dono_usuario, dono_nome, visibilidade, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?, ?, ?, ?, ?)',
        [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['observacao'], $freq, $d['vencimento'], $vezes,
         $dono['dono_id'], $dono['dono_usuario'], $dono['dono_nome'], $dono['visibilidade'], date('Y-m-d H:i:s')]);
    $recId = vn_last_id();
    cp_gerar_recorrentes($hoje, $recId, true);
    // o código do boleto vale só para a primeira conta (os próximos boletos têm outro código)
    $primeira = vn_fetch_one('SELECT id FROM vn_contas_pagar WHERE recorrente_id = ? AND ocorrencia = 0', [$recId]);
    if (!$primeira) throw new CpErro('Não foi possível criar a conta repetida.');
    if ($d['codigo'] !== null) vn_exec('UPDATE vn_contas_pagar SET codigo = ? WHERE id = ?', [$d['codigo'], (int)$primeira['id']]);
    return (int)$primeira['id'];
}

/*
   Cria as contas das repetições que chegaram a CP_ANTECEDENCIA_DIAS (7) dias do vencimento. É só a data que conta:
   pagar ou excluir a anterior não adianta nem atrasa a próxima. A primeira (ocorrência 0) é criada na hora em que a
   pessoa cadastra ($forcarPrimeira). Pode rodar quantas vezes quiser: cada vez só cria o que falta (o contador
   proximo_n da repetição é "reservado" antes de criar, então duas abas abertas ao mesmo tempo não duplicam, e uma
   ocorrência que já existe não é criada de novo). $so: só essa repetição. Devolve quantas contas criou.
*/
function cp_gerar_recorrentes(string $hoje, ?int $so = null, bool $forcarPrimeira = false): int
{
    $limite = date('Y-m-d', strtotime($hoje . ' +' . CP_ANTECEDENCIA_DIAS . ' days'));
    $criadas = 0;
    $lista = $so ? vn_fetch_all('SELECT * FROM vn_contas_pagar_recorrentes WHERE id = ? AND ativa = 1', [$so])
                 : vn_fetch_all('SELECT * FROM vn_contas_pagar_recorrentes WHERE ativa = 1');
    foreach ($lista as $r) {
        $rid = (int)$r['id'];
        $n = (int)$r['proximo_n'];
        $total = $r['total_vezes'] !== null ? (int)$r['total_vezes'] : null;
        for ($guarda = 0; $guarda < 130; $guarda++) {
            if ($total !== null && $n >= $total) {
                vn_exec('UPDATE vn_contas_pagar_recorrentes SET ativa = 0 WHERE id = ?', [$rid]);   // acabou o número de vezes
                break;
            }
            $venc = cp_data_ocorrencia((string)$r['primeira'], (string)$r['frequencia'], $n);
            if ($venc > $limite && !($forcarPrimeira && $n === 0)) break;     // ainda não chegou a hora (7 dias antes)
            if (vn_exec('UPDATE vn_contas_pagar_recorrentes SET proximo_n = ? WHERE id = ? AND proximo_n = ?', [$n + 1, $rid, $n]) !== 1) break;   // outra aba já fez
            try {
                $ja = vn_fetch_one('SELECT id FROM vn_contas_pagar WHERE recorrente_id = ? AND ocorrencia = ?', [$rid, $n]);
                if (!$ja) {
                    vn_exec('INSERT INTO vn_contas_pagar (descricao, fornecedor, categoria, valor, vencimento, observacao, recorrente_id, ocorrencia, dono_id, dono_usuario, dono_nome, visibilidade, criado_em)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$r['descricao'], $r['fornecedor'], $r['categoria'], $r['valor'], $venc, $r['observacao'], $rid, $n,
                         $r['dono_id'], $r['dono_usuario'], $r['dono_nome'], (string)($r['visibilidade'] ?? 'publico'), date('Y-m-d H:i:s')]);
                    $criadas++;
                }
            } catch (Throwable $e) {
                vn_exec('UPDATE vn_contas_pagar_recorrentes SET proximo_n = ? WHERE id = ? AND proximo_n = ?', [$n, $rid, $n + 1]);   // devolve a vez
                throw $e;
            }
            $n++;
        }
    }
    return $criadas;
}

/*
   Ajuste para repetições criadas antes da regra "7 dias antes de vencer" (quando o sistema criava vários meses de uma vez
   ou a seguinte logo ao pagar): remove as contas futuras que ainda não chegaram a 7 dias do vencimento, MAS só as que
   ninguém mexeu (sem pagamento, sem foto, sem código de boleto, com os mesmos dados do modelo). A primeira conta de cada
   repetição (ocorrência 0), que a pessoa cadastrou, nunca é removida. Depois acerta o contador para elas voltarem a ser
   criadas na data certa. Devolve quantas removeu.
*/
function cp_enxugar_recorrentes(string $hoje): int
{
    $limite = date('Y-m-d', strtotime($hoje . ' +' . CP_ANTECEDENCIA_DIAS . ' days'));
    $removidas = 0;
    foreach (vn_fetch_all('SELECT * FROM vn_contas_pagar_recorrentes WHERE ativa = 1') as $r) {
        $rid = (int)$r['id'];
        $dele = vn_fetch_all(
            'SELECT c.id, c.ocorrencia FROM vn_contas_pagar c
             WHERE c.recorrente_id = ? AND c.pago = 0 AND c.ocorrencia >= 1 AND c.vencimento > ?
               AND c.codigo IS NULL AND c.descricao <=> ? AND c.fornecedor <=> ? AND c.categoria <=> ? AND c.valor = ? AND c.observacao <=> ?
               AND NOT EXISTS (SELECT 1 FROM vn_contas_pagar_fotos f WHERE f.conta_id = c.id)',
            [$rid, $limite, $r['descricao'], $r['fornecedor'], $r['categoria'], $r['valor'], $r['observacao']]);
        if (!$dele) continue;
        $menor = PHP_INT_MAX;
        foreach ($dele as $c) { vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [(int)$c['id']]); $removidas++; $menor = min($menor, (int)$c['ocorrencia']); }
        vn_exec('UPDATE vn_contas_pagar_recorrentes SET proximo_n = ? WHERE id = ? AND proximo_n > ?', [$menor, $rid, $menor]);   // volta a criar a partir da 1ª removida, na data certa
    }
    return $removidas;
}

/*
   Roda $fn uma única vez na vida do sistema (guarda uma marca no banco). Devolve o resultado de $fn, ou null se já tinha rodado.
   A marca é gravada ANTES de rodar, então duas telas abrindo juntas não repetem.
*/
function cp_ajuste_unico(string $chave, callable $fn)
{
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar_ajustes (
        chave VARCHAR(60) NOT NULL PRIMARY KEY,
        feito_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (vn_exec('INSERT IGNORE INTO vn_contas_pagar_ajustes (chave, feito_em) VALUES (?, ?)', [$chave, date('Y-m-d H:i:s')]) !== 1) return null;
    return $fn();
}

/* Repetições ativas, para a lista "Contas fixas": modelo + próxima conta ainda não paga. */
function cp_recorrentes_ativas(): array
{
    [$visSql, $visPar] = cp_sql_visivel('r');
    return vn_fetch_all(
        "SELECT r.*, (SELECT MIN(c.vencimento) FROM vn_contas_pagar c WHERE c.recorrente_id = r.id AND c.pago = 0) AS proxima
         FROM vn_contas_pagar_recorrentes r WHERE r.ativa = 1 AND $visSql ORDER BY r.descricao", $visPar
    );
}

/* Quando a próxima conta ainda não criada de uma repetição vence e em que dia ela vai aparecer (7 dias antes). null se já criou todas. */
function cp_proxima_a_criar(array $r): ?array
{
    $n = (int)$r['proximo_n'];
    if ($r['total_vezes'] !== null && $n >= (int)$r['total_vezes']) return null;
    $venc = cp_data_ocorrencia((string)$r['primeira'], (string)$r['frequencia'], $n);
    return ['vence' => $venc, 'aparece' => date('Y-m-d', strtotime($venc . ' -' . CP_ANTECEDENCIA_DIAS . ' days'))];
}

/*
   Para de repetir: nenhuma conta nova é criada. As contas ainda NÃO pagas, sem foto e com vencimento depois de
   $depoisDe (as "do futuro" que já tinham aparecido sozinhas) são removidas. Pagas, atrasadas e com foto ficam.
   Devolve quantas contas futuras foram removidas.
*/
function cp_parar_recorrente(int $recId, string $depoisDe): int
{
    $r = vn_fetch_one('SELECT id, visibilidade, dono_id FROM vn_contas_pagar_recorrentes WHERE id = ?', [$recId]);
    if (!$r || !cp_pode_ver($r)) throw new CpErro('Essa repetição não existe mais.');
    vn_exec('UPDATE vn_contas_pagar_recorrentes SET ativa = 0 WHERE id = ?', [$recId]);
    $removidas = 0;
    foreach (vn_fetch_all(
        'SELECT c.id FROM vn_contas_pagar c
         WHERE c.recorrente_id = ? AND c.pago = 0 AND c.vencimento > ?
           AND NOT EXISTS (SELECT 1 FROM vn_contas_pagar_fotos f WHERE f.conta_id = c.id)',
        [$recId, $depoisDe]) as $c) {
        vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [(int)$c['id']]);
        $removidas++;
    }
    return $removidas;
}

/* ------------------------------------------------------------------ relatório financeiro */
/* Dados do A Pagar para orcamentos-dashboard.php (Relatórios Financeiros). */

/* Primeiro e último dia de um mês 'AAAA-MM'. */
function cp_rel_limites(string $mes): array
{
    $ini = $mes . '-01';
    return [$ini, date('Y-m-t', strtotime($ini))];
}

/* Quanto foi pago no mês (pela data do pagamento): ['total' => R$, 'qtd' => n]. */
function cp_rel_pago_no_mes(string $mes): array
{
    [$ini, $fim] = cp_rel_limites($mes);
    [$visSql, $visPar] = cp_sql_visivel('c');
    $r = vn_fetch_one("SELECT COALESCE(SUM(COALESCE(valor_pago, valor)), 0) AS t, COUNT(*) AS q FROM vn_contas_pagar c WHERE c.pago = 1 AND c.pago_em >= ? AND c.pago_em <= ? AND $visSql", array_merge([$ini, $fim], $visPar)) ?: [];
    return ['total' => (float)($r['t'] ?? 0), 'qtd' => (int)($r['q'] ?? 0)];
}

/* Pago no mês por categoria, do maior para o menor: [['categoria' => ..., 'total' => R$, 'qtd' => n], ...]. */
function cp_rel_pago_por_categoria(string $mes): array
{
    [$ini, $fim] = cp_rel_limites($mes);
    [$visSql, $visPar] = cp_sql_visivel('c');
    $out = [];
    foreach (vn_fetch_all("SELECT COALESCE(NULLIF(TRIM(c.categoria), ''), 'Sem categoria') AS cat, SUM(COALESCE(c.valor_pago, c.valor)) AS t, COUNT(*) AS q
                           FROM vn_contas_pagar c WHERE c.pago = 1 AND c.pago_em >= ? AND c.pago_em <= ? AND $visSql GROUP BY cat ORDER BY t DESC", array_merge([$ini, $fim], $visPar)) as $r) {
        $out[] = ['categoria' => (string)$r['cat'], 'total' => (float)$r['t'], 'qtd' => (int)$r['q']];
    }
    return $out;
}

/* Pago em cada mês entre os dois meses (inclusive): ['AAAA-MM' => R$]. */
function cp_rel_pago_por_mes(string $mesIni, string $mesFim): array
{
    [$ini] = cp_rel_limites($mesIni);
    [, $fim] = cp_rel_limites($mesFim);
    [$visSql, $visPar] = cp_sql_visivel('c');
    $out = [];
    foreach (vn_fetch_all("SELECT DATE_FORMAT(c.pago_em, '%Y-%m') AS m, SUM(COALESCE(c.valor_pago, c.valor)) AS t FROM vn_contas_pagar c
                           WHERE c.pago = 1 AND c.pago_em >= ? AND c.pago_em <= ? AND $visSql GROUP BY m", array_merge([$ini, $fim], $visPar)) as $r) {
        $out[(string)$r['m']] = (float)$r['t'];
    }
    return $out;
}

/* As próximas contas a pagar (atrasadas primeiro), com a quantidade de fotos de cada uma. */
function cp_rel_proximas(int $limite = 8): array
{
    $limite = max(1, min(50, $limite));
    [$visSql, $visPar] = cp_sql_visivel('c');
    return vn_fetch_all("SELECT c.id, c.descricao, c.fornecedor, c.categoria, c.valor, c.vencimento, c.pago, c.parcela_num, c.parcela_ordem, c.parcela_qtd,
                                (SELECT COUNT(*) FROM vn_contas_pagar_fotos f WHERE f.conta_id = c.id) AS fotos
                         FROM vn_contas_pagar c WHERE c.pago = 0 AND $visSql ORDER BY c.vencimento ASC, c.id ASC LIMIT $limite", $visPar);
}

/* ------------------------------------------------------------------ fotos */
function cp_fotos_base(): string
{
    return __DIR__ . '/uploads/contas_pagar';
}

function cp_fotos_dir(int $contaId): string
{
    return cp_fotos_base() . '/' . $contaId;
}

/* Cria a pasta base com a trava que impede abrir os arquivos direto pelo endereço. */
function cp_fotos_preparar_base(): void
{
    $base = cp_fotos_base();
    if (!is_dir($base) && !@mkdir($base, 0775, true)) throw new CpErro('Não foi possível criar a pasta das fotos no servidor.');
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Fotos de contas a pagar: só saem por contas-pagar-foto.php (exige login).\nOptions -Indexes\nRequire all denied\n", LOCK_EX);
    }
}

/* Caminho no disco da miniatura que acompanha uma foto (th_<nome>.jpg, na mesma pasta). */
function cp_miniatura_caminho(string $arquivo): string
{
    return dirname($arquivo) . '/th_' . pathinfo($arquivo, PATHINFO_FILENAME) . '.jpg';
}

/* Gera a miniatura (lado maior = $max px) respeitando a rotação do celular. Falha em silêncio. */
function cp_miniatura_criar(string $origem, string $destino, int $max = 360): bool
{
    try {
        if (!function_exists('imagecreatetruecolor')) return false;
        $info = @getimagesize($origem);
        if (!$info) return false;
        [$w, $h] = $info;
        $mime = (string)($info['mime'] ?? '');
        if ($w <= 0 || $h <= 0 || ($w * $h) > 40000000) return false;
        @ini_set('memory_limit', '512M');
        $img = false;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($origem);
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($origem);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($origem);
        if (!$img) return false;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($origem);
            $graus = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($graus !== 0 && ($rot = imagerotate($img, $graus, 0))) { imagedestroy($img); $img = $rot; }
        }
        $w = imagesx($img); $h = imagesy($img);
        $esc = min(1.0, $max / max($w, $h));
        $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
        $mini = imagecreatetruecolor($nw, $nh);
        imagefill($mini, 0, 0, imagecolorallocate($mini, 255, 255, 255));   // PNG/WebP com transparência vira fundo branco
        imagecopyresampled($mini, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $ok = imagejpeg($mini, $destino, 78);
        imagedestroy($mini);
        return (bool)$ok;
    } catch (Throwable $e) {
        error_log('VN System [contas a pagar - miniatura]: ' . $e->getMessage());
        return false;
    }
}

/*
   Guarda os anexos enviados ($files = $_FILES['fotos'], com vários arquivos): fotos (JPG, PNG, WebP) e PDF.
   Confere o CONTEÚDO, não só o nome: foto precisa ser imagem de verdade; PDF precisa começar com "%PDF-".
   Devolve ['salvas' => n, 'fotos' => n, 'pdfs' => n, 'falhas' => [mensagens]].
*/
function cp_salvar_fotos(int $contaId, string $tipo, array $files): array
{
    require_once __DIR__ . '/_vn_media.php';
    if (!isset(cp_tipos_foto()[$tipo])) $tipo = 'conta';
    $res = ['salvas' => 0, 'fotos' => 0, 'pdfs' => 0, 'falhas' => []];
    if (empty($files['name'])) return $res;

    // um arquivo só (name = texto) vira lista de um
    if (!is_array($files['name'])) {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) $files[$k] = [$files[$k] ?? null];
    }

    $existentes = (int)(vn_fetch_one('SELECT COUNT(*) AS q FROM vn_contas_pagar_fotos WHERE conta_id = ?', [$contaId])['q'] ?? 0);
    $extPorMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $dir = cp_fotos_dir($contaId);

    foreach ($files['name'] as $i => $nomeArq) {
        $erro = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) continue;
        $nome = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$nomeArq));
        $rotulo = $nome !== '' ? $nome : 'arquivo';

        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) { $res['falhas'][] = "$rotulo: passou do tamanho que o servidor aceita."; continue; }
        if ($erro !== UPLOAD_ERR_OK) { $res['falhas'][] = "$rotulo: o envio falhou, tente de novo."; continue; }
        if ($existentes + $res['salvas'] >= CP_FOTOS_MAX_POR_CONTA) { $res['falhas'][] = 'Limite de ' . CP_FOTOS_MAX_POR_CONTA . ' anexos por conta.'; break; }

        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { $res['falhas'][] = "$rotulo: arquivo inválido."; continue; }
        $tam = (int)($files['size'][$i] ?? 0);
        if ($tam <= 0 || $tam > CP_FOTO_MAX_BYTES) { $res['falhas'][] = "$rotulo: maior que 15 MB."; continue; }

        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
        $ehPdf = $mime === 'application/pdf' || $mime === 'application/x-pdf';
        if ($ehPdf) {
            $ini = (string)@file_get_contents($tmp, false, null, 0, 1024);      // o "%PDF-" pode vir depois de poucos bytes de lixo
            if (strpos($ini, '%PDF-') === false) { $res['falhas'][] = "$rotulo: o PDF está corrompido."; continue; }
            $mime = 'application/pdf';
        } elseif (!isset($extPorMime[$mime]) || !@getimagesize($tmp)) {
            $res['falhas'][] = "$rotulo: só aceita foto (JPG, PNG ou WebP) ou PDF."; continue;
        }

        cp_fotos_preparar_base();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $res['falhas'][] = "$rotulo: não foi possível criar a pasta da conta."; continue; }

        $arquivo = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extPorMime[$mime];
        $destino = $dir . '/' . $arquivo;
        if (!@move_uploaded_file($tmp, $destino)) { $res['falhas'][] = "$rotulo: não foi possível salvar."; continue; }
        if (!$ehPdf) {
            vn_image_optimize($destino);                         // fotos de celular pesam 3-12 MB
            cp_miniatura_criar($destino, cp_miniatura_caminho($destino));
        }
        vn_exec('INSERT INTO vn_contas_pagar_fotos (conta_id, tipo, caminho, nome_original, formato, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$contaId, $tipo, 'uploads/contas_pagar/' . $contaId . '/' . $arquivo, mb_substr($nome, 0, 190), $ehPdf ? 'pdf' : 'img', (int)@filesize($destino), date('Y-m-d H:i:s')]);
        $res['salvas']++;
        $res[$ehPdf ? 'pdfs' : 'fotos']++;
    }
    return $res;
}

/* O anexo (linha da tabela), só se a conta dele pode ser vista por quem está usando. Para a tela que entrega o arquivo. */
function cp_foto_permitida(int $fotoId): ?array
{
    $f = $fotoId > 0 ? vn_fetch_one('SELECT * FROM vn_contas_pagar_fotos WHERE id = ?', [$fotoId]) : null;
    if (!$f || !cp_conta((int)$f['conta_id'])) return null;
    return $f;
}

/* Caminho seguro no disco de uma foto (ou da miniatura). null se não existir ou estiver fora da pasta das fotos. */
function cp_foto_arquivo(array $foto, bool $miniatura = false): ?string
{
    $arq = __DIR__ . '/' . ltrim((string)($foto['caminho'] ?? ''), '/');
    if ($miniatura) {
        $mini = cp_miniatura_caminho($arq);
        if (is_file($mini)) $arq = $mini;
    }
    $real = realpath($arq);
    $base = realpath(cp_fotos_base());
    if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) return null;
    return $real;
}

/* Apaga o anexo (arquivo, miniatura e registro). Devolve o id da conta a que ela pertencia (0 se não achou). */
function cp_excluir_foto(int $fotoId): int
{
    $f = cp_foto_permitida($fotoId);
    if (!$f) return 0;
    $principal = cp_foto_arquivo($f, false);
    if ($principal) {
        @unlink($principal);
        $mini = cp_miniatura_caminho($principal);
        if (is_file($mini)) @unlink($mini);
    }
    vn_exec('DELETE FROM vn_contas_pagar_fotos WHERE id = ?', [$fotoId]);
    return (int)$f['conta_id'];
}
