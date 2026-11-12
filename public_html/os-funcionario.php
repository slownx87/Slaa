<?php
require_once __DIR__ . '/_vn_security.php'; // sessão/cookie/permissões antes do session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');
function vn_os_now_sp(): string { return date('Y-m-d H:i:s'); }
function vn_os_today_sp(): string { return date('Y-m-d'); }


if (!isset($_SESSION['cargo']) || strtolower(trim((string)$_SESSION['cargo'])) !== 'funcionario') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config.php";
if (is_file(__DIR__ . "/auth.php")) { require_once __DIR__ . "/auth.php"; }

$pagina_atual = basename($_SERVER['PHP_SELF']);

$success = "";
$error = "";

$cliente_id_sessao_raw = $_SESSION['cliente_id'] ?? null;
$listaClientes = [];
$enderecosPorCliente = [];
$clientesMap = [];

/* ============================= */
/* BANCO DE DADOS */
/* ============================= */

function vn_page_db()
{
    global $conn, $mysqli, $pdo, $conexao;

    if (function_exists('db')) {
        try {
            $d = db();
            if ($d instanceof PDO || $d instanceof mysqli) return $d;
        } catch (Throwable $e) {
            /* continua tentando conexões globais */
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($conn) && $conn instanceof mysqli) return $conn;
    if (isset($mysqli) && $mysqli instanceof mysqli) return $mysqli;
    if (isset($conexao) && $conexao instanceof mysqli) return $conexao;

    throw new Exception("Não encontrei a conexão com o banco. Verifique o arquivo config.php.");
}

function dbIsPdo()
{
    return vn_page_db() instanceof PDO;
}

function bindMysqliParams($stmt, $params)
{
    if (empty($params)) return;

    $types = "";
    $values = [];

    foreach ($params as $p) {
        if (is_int($p)) {
            $types .= "i";
        } elseif (is_float($p)) {
            $types .= "d";
        } else {
            $types .= "s";
        }

        $values[] = $p;
    }

    $bind = [];
    $bind[] = $types;

    foreach ($values as $k => $v) {
        $bind[] = &$values[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function dbQuery($sql, $params = [])
{
    $database = vn_page_db();

    if ($database instanceof PDO) {
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    $stmt = $database->prepare($sql);

    if (!$stmt) {
        throw new Exception(vn_sql_error_msg('prepare', (string)$database->error, (string)$sql));
    }

    bindMysqliParams($stmt, $params);

    if (!$stmt->execute()) {
        throw new Exception(vn_sql_error_msg('execute', (string)$stmt->error, (string)$sql));
    }

    return $stmt;
}

function dbFetch($sql, $params = [])
{
    $stmt = dbQuery($sql, $params);

    if (dbIsPdo()) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function dbFetchAll($sql, $params = [])
{
    $stmt = dbQuery($sql, $params);

    if (dbIsPdo()) {
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function dbInsert($table, $dados)
{
    if (empty($dados)) {
        throw new Exception("Nenhum dado informado para inserir.");
    }

    $cols = array_keys($dados);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colSql = '`' . implode('`, `', $cols) . '`';
    $params = array_values($dados);

    $sql = "INSERT INTO `$table` ($colSql) VALUES ($placeholders)";

    $database = vn_page_db();

    if ($database instanceof PDO) {
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        return (int)$database->lastInsertId();
    }

    $stmt = dbQuery($sql, $params);
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function databaseName()
{
    $database = vn_page_db();

    if ($database instanceof PDO) {
        $stmt = $database->query("SELECT DATABASE() AS dbname");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['dbname'] ?? '';
    }

    $result = $database->query("SELECT DATABASE() AS dbname");

    if (!$result) {
        throw new Exception("Erro ao identificar banco atual: " . $database->error);
    }

    $row = $result->fetch_assoc();
    return $row['dbname'] ?? '';
}

function tableExists($table)
{
    $dbName = databaseName();

    $row = dbFetch("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
        LIMIT 1
    ", [$dbName, $table]);

    return !empty($row);
}

function columnExists($table, $column)
{
    $dbName = databaseName();

    $row = dbFetch("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ", [$dbName, $table, $column]);

    return !empty($row);
}

/* ============================= */
/* HELPERS */
/* ============================= */

if (!function_exists('h')) { function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

function clean($v)
{
    $v = trim((string)$v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function extrairIdsClientesSessao($valor)
{
    $ids = [];

    if (is_array($valor)) {
        foreach ($valor as $item) {
            $ids = array_merge($ids, extrairIdsClientesSessao($item));
        }
    } else {
        $texto = trim((string)$valor);

        if ($texto !== '') {
            $partes = preg_split('/[^0-9]+/', $texto);

            foreach ($partes as $parte) {
                $parte = trim((string)$parte);

                if ($parte !== '' && ctype_digit($parte)) {
                    $ids[] = (int)$parte;
                }
            }
        }
    }

    $ids = array_filter($ids, static function ($id) {
        return (int)$id > 0;
    });

    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);

    return $ids;
}

function getUsuarioLogadoId()
{
    $possiveis = [
        $_SESSION['id'] ?? null,
        $_SESSION['admin_id'] ?? null,
        $_SESSION['usuario_id'] ?? null,
        $_SESSION['user_id'] ?? null,
    ];

    foreach ($possiveis as $id) {
        $id = (int)$id;

        if ($id > 0) {
            return $id;
        }
    }

    return null;
}

function getTecnicoSessaoId()
{
    $possiveis = [
        $_SESSION['id_tec'] ?? null,
        $_SESSION['tecnico_id'] ?? null,
        $_SESSION['id'] ?? null,
        $_SESSION['admin_id'] ?? null,
        $_SESSION['usuario_id'] ?? null,
    ];

    foreach ($possiveis as $id) {
        $id = (int)$id;

        if ($id > 0) {
            return $id;
        }
    }

    return null;
}

function getNomeUsuario()
{
    return clean($_SESSION['admin'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? 'Funcionário');
}

function nomeClienteLocal($cliente)
{
    return clean($cliente['nome'] ?? $cliente['razao_social'] ?? $cliente['fantasia'] ?? ('Cliente #' . (int)($cliente['id'] ?? 0)));
}

function montarEnderecoTexto($endereco)
{
    if (!$endereco || !is_array($endereco)) {
        return 'Endereço não localizado';
    }

    $logradouro = clean($endereco['endereco'] ?? $endereco['logradouro'] ?? $endereco['rua'] ?? '');
    $numero = clean($endereco['numero'] ?? '');
    $complemento = clean($endereco['complemento'] ?? '');
    $bairro = clean($endereco['bairro'] ?? '');
    $cidade = clean($endereco['cidade'] ?? '');
    $uf = clean($endereco['uf'] ?? '');
    $cep = clean($endereco['cep'] ?? '');
    $referencia = clean($endereco['referencia'] ?? '');

    $partes = [];

    if ($logradouro !== '') {
        $linha = $logradouro;

        if ($numero !== '') {
            $linha .= ', ' . $numero;
        }

        if ($complemento !== '') {
            $linha .= ' - ' . $complemento;
        }

        $partes[] = $linha;
    }

    if ($bairro !== '') $partes[] = $bairro;
    if ($cidade !== '') $partes[] = $cidade;
    if ($uf !== '') $partes[] = $uf;
    if ($cep !== '') $partes[] = 'CEP ' . $cep;
    if ($referencia !== '') $partes[] = 'Ref: ' . $referencia;

    return !empty($partes) ? implode(' - ', $partes) : 'Endereço não localizado';
}

function gerarCodigoOS()
{
    return 'OS-' . date('Ymd-His') . '-' . random_int(100, 999);
}

/* ============================= */
/* CLIENTES E ENDEREÇOS */
/* ============================= */

function listarClientesLocais($idsRestritos = [])
{
    if (!tableExists('clientes')) {
        throw new Exception("A tabela clientes não existe no banco.");
    }

    if (!columnExists('clientes', 'id')) {
        throw new Exception("A tabela clientes precisa ter a coluna id.");
    }

    $nomeCol = columnExists('clientes', 'nome') ? 'nome' : "NULL AS nome";
    $where = [];
    $params = [];

    if (columnExists('clientes', 'status')) {
        $where[] = "(status IS NULL OR status = '' OR LOWER(status) = 'ativo')";
    }

    if (!empty($idsRestritos)) {
        $where[] = "id IN (" . implode(',', array_fill(0, count($idsRestritos), '?')) . ")";
        $params = array_merge($params, $idsRestritos);
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    return dbFetchAll("
        SELECT id, {$nomeCol}
        FROM clientes
        {$whereSql}
        ORDER BY nome ASC, id ASC
    ", $params);
}

function buscarClienteLocal($clienteId)
{
    $clienteId = (int)$clienteId;

    if ($clienteId <= 0) {
        return null;
    }

    if (!tableExists('clientes') || !columnExists('clientes', 'id')) {
        return null;
    }

    $nomeCol = columnExists('clientes', 'nome') ? 'nome' : "NULL AS nome";

    return dbFetch("
        SELECT id, {$nomeCol}
        FROM clientes
        WHERE id = ?
        LIMIT 1
    ", [$clienteId]);
}

function buscarEnderecosDoCliente($clienteId)
{
    $clienteId = (int)$clienteId;

    if ($clienteId <= 0) {
        return [];
    }

    if (!tableExists('enderecos_clientes')) {
        return [];
    }

    if (!columnExists('enderecos_clientes', 'cliente_id')) {
        return [];
    }

    $cols = ['id', 'cliente_id'];

    foreach (['nome_endereco', 'cep', 'endereco', 'logradouro', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'referencia', 'status', 'principal'] as $col) {
        if (columnExists('enderecos_clientes', $col)) {
            $cols[] = $col;
        }
    }

    $colsSql = '`' . implode('`, `', array_unique($cols)) . '`';
    $whereStatus = "";

    if (columnExists('enderecos_clientes', 'status')) {
        $whereStatus = " AND (status IS NULL OR status = '' OR LOWER(status) = 'ativo') ";
    }

    $order = [];

    if (columnExists('enderecos_clientes', 'principal')) {
        $order[] = "principal DESC";
    }

    if (columnExists('enderecos_clientes', 'nome_endereco')) {
        $order[] = "nome_endereco ASC";
    }

    $order[] = "id ASC";

    $rows = dbFetchAll("
        SELECT {$colsSql}
        FROM enderecos_clientes
        WHERE cliente_id = ?
        {$whereStatus}
        ORDER BY " . implode(', ', $order) . "
    ", [$clienteId]);

    $lista = [];

    foreach ($rows as $row) {
        $texto = montarEnderecoTexto($row);

        if ($texto === 'Endereço não localizado') {
            continue;
        }

        $label = clean($row['nome_endereco'] ?? '');

        if ($label === '') {
            $label = !empty($row['principal']) ? 'Endereço principal' : 'Endereço de atendimento';
        }

        $lista[] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => $label,
            'valor' => $texto,
        ];
    }

    return $lista;
}

function carregarDadosTela()
{
    global $cliente_id_sessao_raw;

    $idsClientesSessao = extrairIdsClientesSessao($cliente_id_sessao_raw);

    // Se o funcionário tiver cliente_id na sessão, limita aos IDs da sessão.
    // Se não tiver, mostra todos os clientes locais.
    $clientes = listarClientesLocais($idsClientesSessao);

    $listaClientes = [];
    $clientesMap = [];
    $enderecosPorCliente = [];

    foreach ($clientes as $cliente) {
        $id = (int)($cliente['id'] ?? 0);

        if ($id <= 0) {
            continue;
        }

        $nome = nomeClienteLocal($cliente);
        $enderecos = buscarEnderecosDoCliente($id);

        $listaClientes[] = [
            'id' => $id,
            'nome' => $nome,
            'enderecos' => $enderecos,
        ];

        $clientesMap[$id] = [
            'id' => $id,
            'nome' => $nome,
        ];

        $enderecosPorCliente[$id] = $enderecos;
    }

    return [$listaClientes, $clientesMap, $enderecosPorCliente];
}

/* ============================= */
/* GRAVAR OS */
/* ============================= */

function montarDadosInsertOS($dados)
{
    $table = 'ordens_servico';

    if (!tableExists($table)) {
        throw new Exception("A tabela ordens_servico não existe no banco.");
    }

    $idTec = getTecnicoSessaoId();
    $nomeTecnico = getNomeUsuario();

    $insert = [];

    $mapa = [
        'codigo_os' => $dados['codigo_os'] ?? null,
        'cliente_id' => $dados['cliente_id'] ?? null,
        'endereco_id' => $dados['endereco_id'] ?? null,
        'nome_cliente' => $dados['nome_cliente'] ?? null,

        'titulo' => $dados['titulo'] ?? null,

        // CFTV (padrão) ou serralheria
        'linha_servico' => $dados['linha_servico'] ?? 'cftv',

        'problema_relatado_cliente' => $dados['problema'] ?? null,
        'problema_ordem' => $dados['problema'] ?? null,

        'equipamento_ordem' => $dados['equipamento'] ?? '',
        'garantia_ordem' => $dados['garantia'] ?? '',
        'recebimento_ordem' => 'Aberta pelo funcionário',
        'referencia_ordem' => $dados['referencia'] ?? '',

        'obs_pedido' => $dados['observacao'] ?? '',
        'obs_interno_pedido' => $dados['observacao'] ?? '',
        'observacao_cliente' => $dados['observacao'] ?? '',
        'observacao_interna' => $dados['observacao'] ?? '',

        'status' => 'aberta',
        'prioridade' => $dados['prioridade'] ?? 'normal',

        'data_abertura' => vn_os_now_sp(),
        'data_pedido' => vn_os_today_sp(),
        'criado_em' => vn_os_now_sp(),

        'criado_por' => getUsuarioLogadoId(),

        'id_tec' => $idTec,
        'tecnico_id' => $idTec,
        'tecnico_admin_id' => getUsuarioLogadoId(),
        'vendedor_pedido' => $nomeTecnico,
        'vendedor_pedido_id' => $idTec,

        'valor_servico' => 0.00,
        'valor_material' => 0.00,
        'valor_total' => 0.00,

        'laudo_ordem' => '',
        'servico_executado' => '',
    ];

    foreach ($mapa as $coluna => $valor) {
        if (columnExists($table, $coluna)) {
            $insert[$coluna] = $valor;
        }
    }

    if (!array_key_exists('cliente_id', $insert)) {
        throw new Exception("A tabela ordens_servico precisa ter a coluna cliente_id.");
    }

    if (
        !array_key_exists('problema_relatado_cliente', $insert) &&
        !array_key_exists('problema_ordem', $insert) &&
        !array_key_exists('titulo', $insert)
    ) {
        throw new Exception("A tabela ordens_servico precisa ter uma coluna para salvar o serviço.");
    }

    return $insert;
}

/* ============================= */
/* CARREGAR TELA */
/* ============================= */

try {
    [$listaClientes, $clientesMap, $enderecosPorCliente] = carregarDadosTela();

    if (empty($listaClientes)) {
        throw new Exception("Nenhum cliente ativo foi encontrado no banco.");
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ============================= */
/* PROCESSAR ENVIO */
/* ============================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    try {
        $clientFinal = (int)($_POST['client'] ?? 0);

        if ($clientFinal <= 0 || !isset($clientesMap[$clientFinal])) {
            throw new Exception("Selecione um cliente válido.");
        }

        $clienteSelecionado = buscarClienteLocal($clientFinal);

        if (!$clienteSelecionado) {
            throw new Exception("Cliente não encontrado.");
        }

        $nomeCliente = nomeClienteLocal($clienteSelecionado);

        $expectedService = clean($_POST['expectedService'] ?? '');
        $observation = trim((string)($_POST['observation'] ?? ''));
        $priority = (int)($_POST['priority'] ?? 0);
        $equipamento = clean($_POST['equipamento'] ?? '');
        $garantia = clean($_POST['garantia'] ?? '');

        if ($expectedService === '') {
            throw new Exception("Informe o problema ou serviço previsto.");
        }

        $prioridadeTexto = match ($priority) {
            10 => 'urgente',
            1  => 'alta',
            default => 'normal'
        };

        $enderecosCliente = $enderecosPorCliente[$clientFinal] ?? [];
        $enderecoId = (int)($_POST['endereco_id'] ?? 0);
        $referencia = '';

        foreach ($enderecosCliente as $endereco) {
            if ((int)$endereco['id'] === $enderecoId) {
                $referencia = (string)$endereco['valor'];
                break;
            }
        }

        if ($referencia === '' && !empty($enderecosCliente)) {
            $enderecoId = (int)$enderecosCliente[0]['id'];
            $referencia = (string)$enderecosCliente[0]['valor'];
        }

        if ($referencia === '') {
            $referencia = 'Endereço não informado';
            $enderecoId = 0;
        }

        $linhaServico = (($_POST['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv';
        ensureColumn('ordens_servico', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");

        $dadosOS = [
            'codigo_os' => gerarCodigoOS(),
            'cliente_id' => $clientFinal,
            'endereco_id' => $enderecoId > 0 ? $enderecoId : null,
            'nome_cliente' => $nomeCliente,
            'linha_servico' => $linhaServico,
            'titulo' => 'Ordem de Serviço',
            'problema' => $expectedService,
            'equipamento' => $equipamento,
            'garantia' => $garantia,
            'referencia' => $referencia,
            'observacao' => $observation,
            'prioridade' => $prioridadeTexto,
        ];

        $insert = montarDadosInsertOS($dadosOS);
        $idOS = dbInsert('ordens_servico', $insert);

        $success = "Ordem de Serviço criada com sucesso! ID: " . $idOS;

        $_POST = [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$clientesJs = [];
foreach ($listaClientes as $clienteItem) {
    $clientesJs[(string)$clienteItem['id']] = $clienteItem['enderecos'];
}
?>
<?php
require_once __DIR__ . '/_vn_ui.php';
ob_start();
?>
<style>
        :root {
            --bg: #050810;
            --card: #0f172a;
            --card2: #111c31;
            --line: rgba(59, 130, 246, .25);
            --line2: rgba(255,255,255,.08);
            --text: #ffffff;
            --muted: #94a3b8;
            --blue: #3b82f6;
            --blue2: #2563eb;
            --green: #4ade80;
            --yellow: #facc15;
            --red: #f87171;
            --sidebar: #07111f;
            --sidebar-text: #cbd5e1;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /*
           overflow-x:hidden no body (com o html sem overflow) faz o body virar
           um segundo container de rolagem: o overflow-y computa para auto e o
           celular fica com dois scrollers disputando o toque — dava para descer
           mas não para voltar. No html clipa a horizontal com um scroller só.
        */
        html { overflow-x: hidden; }

        body {
            font-family: "Inter", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
        }

        .topbar {
            height: 68px;
            background: rgba(10, 19, 34, .98);
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 22px;
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
        }

        .brand img {
            height: 40px;
            max-width: 140px;
            object-fit: contain;
        }

        .brand span {
            color: var(--primary-fg);
            letter-spacing: .2px;
        }

        .menu-btn {
            display: none;
            border: 1px solid var(--line);
            background: rgba(59,130,246,.14);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 13px;
            font-weight: 800;
        }

        .layout {
            display: flex;
            min-height: calc(100vh - 68px);
        }

        .sidebar {
            width: 265px;
            background: linear-gradient(180deg, #07111f 0%, #050810 100%);
            color: var(--sidebar-text);
            padding: 16px 14px;
            flex-shrink: 0;
            border-right: 1px solid var(--line);
        }

        .sidebar-title {
            color: var(--muted);
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin: 4px 8px 12px;
        }

        .side-btn {
            min-height: 48px;
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--sidebar-text);
            padding: 12px 13px;
            border-radius: 12px;
            margin-bottom: 8px;
            font-size: .95rem;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,.06);
            background: rgba(255,255,255,.025);
            transition: .18s ease;
        }

        .side-btn i {
            width: 22px;
            text-align: center;
            color: var(--primary-fg);
            font-size: 1rem;
            flex-shrink: 0;
        }

        .side-btn:hover {
            background: rgba(59,130,246,.14);
            border-color: rgba(59,130,246,.45);
            color: #fff;
            transform: translateX(2px);
        }

        .side-btn.active {
            background: var(--blue);
            border-color: var(--blue);
            color: #020617;
            box-shadow: 0 8px 22px rgba(59,130,246,.22);
        }

        .side-btn.active i {
            color: #020617;
        }

        .side-btn.logout {
            margin-top: 18px;
            color: #fecaca !important;
            border-top: 1px solid rgba(255,255,255,.10);
            padding-top: 14px !important;
            background: rgba(248,113,113,.07);
            border-color: rgba(248,113,113,.20);
        }

        .side-btn.logout i {
            color: var(--red);
        }

        .content {
            flex: 1;
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .page-title,
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            margin-bottom: 18px;
        }

        .page-title {
            padding: 20px;
        }

        .page-title h1 {
            color: var(--primary-fg);
            font-size: 1.55rem;
            margin-bottom: 8px;
        }

        .page-title p {
            color: var(--muted);
            line-height: 1.5;
        }

        .card {
            padding: 24px;
        }

        .card h2 {
            color: var(--primary-fg);
            font-size: 1.2rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .full-width {
            grid-column: span 2;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: .82rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 14px;
            background: #050810;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: white;
            outline: none;
            font-family: inherit;
            font-size: .95rem;
        }

        textarea {
            min-height: 115px;
            resize: vertical;
        }

        .readonly-box,
        .resume-box {
            width: 100%;
            padding: 14px;
            background: var(--card2);
            border: 1px solid var(--line2);
            border-radius: 12px;
            color: #cbd5e1;
            min-height: 52px;
            line-height: 1.45;
        }

        .resume-box {
            margin-top: 8px;
            font-size: .86rem;
            color: var(--muted);
        }

        .btn {
            min-height: 50px;
            padding: 14px 18px;
            background: var(--blue);
            border: none;
            border-radius: 12px;
            font-weight: 900;
            color: #020617;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            text-transform: uppercase;
        }

        .btn:hover {
            background: #60a5fa;
        }

        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-weight: 700;
            text-align: center;
        }

        .alert-success {
            background: rgba(74, 222, 128, 0.10);
            color: var(--green);
            border: 1px solid rgba(74, 222, 128, .35);
        }

        .alert-error {
            background: rgba(248, 113, 113, 0.10);
            color: var(--red);
            border: 1px solid rgba(248, 113, 113, .35);
        }

        @media (max-width: 780px) {
            .topbar {
                height: 64px;
                padding: 0 14px;
            }

            .brand img {
                height: 34px;
                max-width: 110px;
            }

            .brand span {
                font-size: .95rem;
            }

            .menu-btn {
                display: block;
            }

            .layout {
                display: block;
            }

            .sidebar {
                display: none;
                width: 100%;
                padding: 12px;
                border-right: 0;
                border-bottom: 1px solid var(--line);
                background: #07111f;
            }

            .sidebar.open {
                display: block;
            }

            .side-btn {
                min-height: 50px;
                margin-bottom: 7px;
                font-size: 1rem;
                padding: 13px 12px;
            }

            .side-btn:hover {
                transform: none;
            }

            .content {
                padding: 14px;
            }

            .page-title,
            .card {
                padding: 14px;
            }

            .page-title h1 {
                font-size: 1.22rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: span 1;
            }

            .btn {
                min-height: 52px;
            }
        }

        @media (max-width: 430px) {
            .brand span {
                display: none;
            }
        }
    </style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<?php
$vnHeadExtra = ob_get_clean();
$linhaTela = (($_POST['linha_servico'] ?? ($_GET['linha'] ?? '')) === 'serralheria') ? 'serralheria' : 'cftv';
$menuChave = (($_GET['linha'] ?? '') === 'serralheria') ? 'gerar-os-serralheria' : 'gerar-os';
vn_ui_shell_start($linhaTela === 'serralheria' ? 'Gerar OS de Serralheria' : 'Gerar OS', $menuChave, ['head_extra' => $vnHeadExtra, 'legacy' => 'admin']);
?>
<div class="content">
            <div class="page-title">
                <h1><?= $linhaTela === 'serralheria' ? 'Abrir Nova OS de Serralheria' : 'Abrir Nova Ordem de Serviço' ?></h1>
                <p><?= $linhaTela === 'serralheria' ? 'Preencha os dados da ordem de serviço de serralheria. Ela aparece separada das OS de CFTV.' : 'Preencha os dados abaixo para abrir uma nova ordem de serviço.' ?></p>
            </div>

            <div class="card">
                <h2><i class="fas fa-plus-circle"></i> Dados da solicitação</h2>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?= h($success) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="form-grid">
                    <div class="full-width">
                        <label><i class="fas fa-user"></i> Cliente</label>

                        <select name="client" id="clientSelect" required>
                            <option value="">Selecione o cliente</option>
                            <?php foreach ($listaClientes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['client'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                    <?= h($c['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="full-width">
                        <label><i class="fas fa-map-marker-alt"></i> Endereço de atendimento</label>
                        <select id="enderecoSelect" name="endereco_id"></select>
                        <div class="resume-box" id="clientAddress">Selecione um cliente para carregar o endereço</div>
                    </div>

<style>
.linha-os-op{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px}
.linha-os-op label{display:flex;align-items:center;justify-content:center;gap:8px;min-height:50px;margin:0;padding:8px 10px;border:1px solid var(--line,rgba(148,163,184,.4));border-radius:12px;cursor:pointer;font-weight:800;text-align:center}
.linha-os-op input{position:absolute;opacity:0;pointer-events:none}
.linha-os-op label:has(input:checked){border-color:#3b82f6;background:rgba(59,130,246,.16);box-shadow:0 0 0 2px rgba(59,130,246,.25)}
.linha-os-op label.serr:has(input:checked){border-color:#f59e0b;background:rgba(245,158,11,.18);box-shadow:0 0 0 2px rgba(245,158,11,.28)}
</style>
                    <div class="full-width">
                        <label><i class="fas fa-layer-group"></i> Tipo da OS</label>
                        <div class="linha-os-op" role="radiogroup" aria-label="Tipo da OS">
                            <label><input type="radio" name="linha_servico" value="cftv" <?= $linhaTela !== 'serralheria' ? 'checked' : '' ?>><i class="fas fa-video"></i> CFTV / segurança</label>
                            <label class="serr"><input type="radio" name="linha_servico" value="serralheria" <?= $linhaTela === 'serralheria' ? 'checked' : '' ?>><i class="fas fa-hammer"></i> Serralheria</label>
                        </div>
                    </div>

                    <div>
                        <label><i class="fas fa-exclamation-circle"></i> Prioridade</label>
                        <select name="priority">
                            <option value="0" <?= ((int)($_POST['priority'] ?? 0) === 0) ? 'selected' : '' ?>>Normal</option>
                            <option value="1" <?= ((int)($_POST['priority'] ?? 0) === 1) ? 'selected' : '' ?>>Encaixe</option>
                            <option value="10" <?= ((int)($_POST['priority'] ?? 0) === 10) ? 'selected' : '' ?>>Urgente</option>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-user-tie"></i> Técnico</label>
                        <input type="text" value="<?= h(getNomeUsuario()) ?>" disabled>
                    </div>

                    <div class="full-width">
                        <label><i class="fas fa-tools"></i> Problema / Serviço Previsto</label>
                        <input
                            type="text"
                            name="expectedService"
                            placeholder="Descreva o serviço..."
                            value="<?= h($_POST['expectedService'] ?? '') ?>"
                            required
                        />
                    </div>

                    <div>
                        <label><i class="fas fa-microchip"></i> Equipamento</label>
                        <input
                            type="text"
                            name="equipamento"
                            placeholder="Ex: Câmera, motor, central, facial..."
                            value="<?= h($_POST['equipamento'] ?? '') ?>"
                        />
                    </div>

                    <div>
                        <label><i class="fas fa-shield-alt"></i> Garantia</label>
                        <input
                            type="text"
                            name="garantia"
                            placeholder="Ex: 90 dias"
                            value="<?= h($_POST['garantia'] ?? '') ?>"
                        />
                    </div>

                    <div class="full-width">
                        <label><i class="fas fa-sticky-note"></i> Observações</label>
                        <textarea name="observation" placeholder="Notas internas da OS..."><?= h($_POST['observation'] ?? '') ?></textarea>
                    </div>

                    <div class="full-width">
                        <button type="submit" class="btn">Gerar Ordem de Serviço</button>
                    </div>
                </form>
            </div>
        </div>

    <script>
        const clientesEnderecos = <?= json_encode($clientesJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const clientSelect = document.getElementById('clientSelect');
        const enderecoSelect = document.getElementById('enderecoSelect');
        const clientAddress = document.getElementById('clientAddress');

        function preencherEnderecos(clienteId, enderecoSelecionado = '') {
            if (!enderecoSelect || !clientAddress) return;

            const lista = clientesEnderecos[String(clienteId)] || [];
            enderecoSelect.innerHTML = '';

            if (!clienteId) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Selecione o cliente primeiro';
                enderecoSelect.appendChild(option);
                clientAddress.textContent = 'Selecione um cliente para carregar o endereço';
                return;
            }

            if (!lista.length) {
                const option = document.createElement('option');
                option.value = '0';
                option.dataset.valor = '';
                option.textContent = 'Nenhum endereço encontrado';
                enderecoSelect.appendChild(option);
                clientAddress.textContent = 'Nenhum endereço encontrado para este cliente';
                return;
            }

            lista.forEach((item, index) => {
                const option = document.createElement('option');
                option.value = item.id || '0';
                option.dataset.valor = item.valor || '';
                option.textContent = (item.label ? item.label + ' - ' : '') + (item.valor || '');

                if (enderecoSelecionado && String(option.value) === String(enderecoSelecionado)) {
                    option.selected = true;
                } else if (!enderecoSelecionado && index === 0) {
                    option.selected = true;
                }

                enderecoSelect.appendChild(option);
            });

            clientAddress.textContent = enderecoSelect.options[enderecoSelect.selectedIndex]?.dataset?.valor || 'Endereço selecionado';
        }

        if (clientSelect) {
            preencherEnderecos(clientSelect.value, '<?= (int)($_POST['endereco_id'] ?? 0) ?>');

            clientSelect.addEventListener('change', function () {
                preencherEnderecos(this.value, '');
            });
        }

        if (enderecoSelect) {
            enderecoSelect.addEventListener('change', function () {
                clientAddress.textContent = this.options[this.selectedIndex]?.dataset?.valor || 'Endereço selecionado';
            });
        }
    </script>
<?php vn_ui_shell_end($menuChave); ?>
