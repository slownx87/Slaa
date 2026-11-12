<?php
require_once __DIR__ . '/_vn_security.php'; // sessão/cookie/permissões antes do session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');
function vn_os_now_sp(): string { return date('Y-m-d H:i:s'); }
function vn_os_today_sp(): string { return date('Y-m-d'); }


if (!isset($_SESSION['cargo']) || strtolower(trim((string)$_SESSION['cargo'])) !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . '/_vn_media.php';
if (is_file(__DIR__ . "/auth.php")) { require_once __DIR__ . "/auth.php"; }

$pagina_atual = basename($_SERVER['PHP_SELF']);

$success = "";
$error = "";

$listaClientes = [];
$listaTecnicos = [];
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

function dbBeginTransaction()
{
    $database = vn_page_db();

    if ($database instanceof PDO) {
        $database->beginTransaction();
        return;
    }

    $database->begin_transaction();
}

function dbCommitTransaction()
{
    $database = vn_page_db();

    if ($database instanceof PDO) {
        if ($database->inTransaction()) $database->commit();
        return;
    }

    $database->commit();
}

function dbRollbackTransaction()
{
    $database = vn_page_db();

    if ($database instanceof PDO) {
        if ($database->inTransaction()) $database->rollBack();
        return;
    }

    $database->rollback();
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

/*
   Garante que a coluna existe antes de gravar. Sem isso, bancos antigos (que não
   têm tipo_servico em ordens_servico) perdiam silenciosamente o tipo escolhido
   — a OS era criada, mas a Preventiva não aparecia separada para o técnico.
*/
function ensureColumn($table, $column, $definition)
{
    if (!tableExists($table)) return false;
    if (columnExists($table, $column)) return true;

    try {
        dbQuery("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (Throwable $e) {
        // Se outra requisição criou ao mesmo tempo, tudo bem.
        if (stripos($e->getMessage(), 'Duplicate column') === false) return false;
    }

    return columnExists($table, $column);
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

function listarClientesLocais()
{
    if (!tableExists('clientes')) {
        throw new Exception("A tabela clientes não existe no banco.");
    }

    if (!columnExists('clientes', 'id')) {
        throw new Exception("A tabela clientes precisa ter a coluna id.");
    }

    $nomeCol = columnExists('clientes', 'nome') ? 'nome' : "NULL AS nome";
    $statusWhere = "";

    if (columnExists('clientes', 'status')) {
        $statusWhere = " WHERE (status IS NULL OR status = '' OR LOWER(status) = 'ativo') ";
    }

    return dbFetchAll("
        SELECT id, {$nomeCol}
        FROM clientes
        {$statusWhere}
        ORDER BY nome ASC, id ASC
    ");
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

function primeiroCampoExistente($table, array $cols)
{
    foreach ($cols as $col) {
        if (columnExists($table, $col)) {
            return $col;
        }
    }

    return null;
}

function setCampoSeExiste(array &$dados, $table, array $cols, $valor)
{
    $col = primeiroCampoExistente($table, $cols);

    if ($col) {
        $dados[$col] = $valor;
    }
}

function inserirClienteLocal($nome, $cnpj = '', $email = '')
{
    if (!tableExists('clientes') || !columnExists('clientes', 'id')) {
        throw new Exception("A tabela clientes precisa existir e ter a coluna id para cadastrar cliente novo.");
    }

    $nome = clean($nome);

    if ($nome === '') {
        throw new Exception("Informe o nome do novo cliente.");
    }

    $dados = [];
    setCampoSeExiste($dados, 'clientes', ['nome', 'nome_cliente', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente', 'cliente'], $nome);
    setCampoSeExiste($dados, 'clientes', ['cnpj', 'cnpj_cliente', 'documento', 'cpf_cnpj'], clean($cnpj));
    setCampoSeExiste($dados, 'clientes', ['email', 'email_cliente', 'cliente_email'], clean($email));
    setCampoSeExiste($dados, 'clientes', ['status'], 'ativo');
    setCampoSeExiste($dados, 'clientes', ['criado_em', 'created_at'], vn_os_now_sp());

    if (empty($dados)) {
        throw new Exception("A tabela clientes não possui coluna compatível para salvar o nome.");
    }

    return dbInsert('clientes', $dados);
}

function montarEnderecoPost(array $post)
{
    $logradouro = clean($post['cep_logradouro'] ?? '');
    $numero = clean($post['cep_numero'] ?? '');
    $complemento = clean($post['cep_complemento'] ?? '');
    $bairro = clean($post['cep_bairro'] ?? '');
    $cidade = clean($post['cep_cidade'] ?? '');
    $uf = clean($post['cep_uf'] ?? '');
    $cep = clean($post['cep'] ?? '');

    $partes = [];
    if ($logradouro !== '') $partes[] = $logradouro . ($numero !== '' ? ', ' . $numero : '');
    if ($complemento !== '') $partes[] = $complemento;
    if ($bairro !== '') $partes[] = $bairro;
    if ($cidade !== '' || $uf !== '') $partes[] = trim($cidade . ($uf !== '' ? ' - ' . $uf : ''));
    if ($cep !== '') $partes[] = 'CEP ' . $cep;

    return implode(' - ', array_filter($partes));
}

function inserirEnderecoClienteLocal($clienteId, array $post, $obrigatorio = false)
{
    $clienteId = (int)$clienteId;
    if ($clienteId <= 0 || !tableExists('enderecos_clientes')) {
        if ($obrigatorio) {
            throw new Exception("Não foi possível cadastrar o endereço: tabela enderecos_clientes não encontrada.");
        }

        return 0;
    }

    $clienteCol = primeiroCampoExistente('enderecos_clientes', ['cliente_id', 'id_cliente']);
    if (!$clienteCol) {
        if ($obrigatorio) {
            throw new Exception("Não foi possível cadastrar o endereço: falta coluna cliente_id em enderecos_clientes.");
        }

        return 0;
    }

    $logradouro = clean($post['cep_logradouro'] ?? '');
    $cep = clean($post['cep'] ?? '');

    if ($logradouro === '' && $cep === '') {
        if ($obrigatorio) {
            throw new Exception("Informe o CEP ou a rua para cadastrar o endereço junto com o cliente.");
        }

        return 0;
    }

    $dados = [$clienteCol => $clienteId];
    setCampoSeExiste($dados, 'enderecos_clientes', ['nome_endereco', 'titulo', 'apelido'], 'Endereço principal');
    setCampoSeExiste($dados, 'enderecos_clientes', ['cep', 'cep_cliente'], $cep);
    setCampoSeExiste($dados, 'enderecos_clientes', ['endereco', 'endereco_cliente', 'logradouro', 'logradouro_cliente', 'rua', 'rua_cliente'], $logradouro);
    setCampoSeExiste($dados, 'enderecos_clientes', ['numero', 'numero_cliente'], clean($post['cep_numero'] ?? ''));
    setCampoSeExiste($dados, 'enderecos_clientes', ['complemento', 'complemento_cliente'], clean($post['cep_complemento'] ?? ''));
    setCampoSeExiste($dados, 'enderecos_clientes', ['bairro', 'bairro_cliente'], clean($post['cep_bairro'] ?? ''));
    setCampoSeExiste($dados, 'enderecos_clientes', ['cidade', 'cidade_cliente'], clean($post['cep_cidade'] ?? ''));
    setCampoSeExiste($dados, 'enderecos_clientes', ['uf', 'uf_cliente', 'estado'], clean($post['cep_uf'] ?? ''));
    setCampoSeExiste($dados, 'enderecos_clientes', ['status'], 'ativo');
    setCampoSeExiste($dados, 'enderecos_clientes', ['principal'], 1);

    return dbInsert('enderecos_clientes', $dados);
}

function listarTecnicosLocais()
{
    $tecnicos = [];
    $vistos = [];

    $adicionar = function ($id, $nome, $adminId = 0) use (&$tecnicos, &$vistos) {
        $id = (int)$id;
        $adminId = (int)$adminId;
        $nome = clean($nome);

        if ($id <= 0 || $nome === '') {
            return;
        }

        if (isset($vistos[$id])) {
            foreach ($tecnicos as &$tecnico) {
                if ((int)$tecnico['id'] === $id && (int)$tecnico['admin_id'] <= 0 && $adminId > 0) {
                    $tecnico['admin_id'] = $adminId;
                    break;
                }
            }
            unset($tecnico);
            return;
        }

        $vistos[$id] = true;
        $tecnicos[] = ['id' => $id, 'admin_id' => $adminId, 'nome' => $nome];
    };

    if (tableExists('admins') && columnExists('admins', 'id')) {
        $cols = ['id'];
        foreach (['id_tec', 'nome', 'usuario', 'cargo', 'status'] as $col) {
            if (columnExists('admins', $col)) $cols[] = $col;
        }

        $where = [];
        if (columnExists('admins', 'cargo')) $where[] = "(cargo IS NULL OR cargo = '' OR LOWER(TRIM(cargo)) IN ('admin','funcionario','colaborador','tecnico','técnico'))";
        if (columnExists('admins', 'status')) $where[] = "(status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s'))";
        $order = columnExists('admins', 'nome') ? 'nome' : (columnExists('admins', 'usuario') ? 'usuario' : 'id');
        $sql = 'SELECT `' . implode('`, `', array_unique($cols)) . '` FROM admins ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY `$order` ASC, id ASC";

        foreach (dbFetchAll($sql) as $row) {
            $id = (int)($row['id_tec'] ?? 0);
            $adminId = (int)($row['id'] ?? 0);
            if ($id <= 0) $id = (int)($row['id'] ?? 0);
            $adicionar($id, $row['nome'] ?? ($row['usuario'] ?? ('Técnico #' . $id)), $adminId);
        }
    }

    if (tableExists('tecnicos') && columnExists('tecnicos', 'id')) {
        $cols = ['id'];
        foreach (['nome', 'usuario', 'nome_tecnico', 'status'] as $col) {
            if (columnExists('tecnicos', $col)) $cols[] = $col;
        }

        $where = columnExists('tecnicos', 'status') ? "WHERE (status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s'))" : '';
        $order = columnExists('tecnicos', 'nome') ? 'nome' : 'id';

        foreach (dbFetchAll('SELECT `' . implode('`, `', array_unique($cols)) . "` FROM tecnicos {$where} ORDER BY `$order` ASC, id ASC") as $row) {
            $id = (int)($row['id'] ?? 0);
            $adicionar($id, $row['nome'] ?? ($row['nome_tecnico'] ?? ($row['usuario'] ?? ('Técnico #' . $id))));
        }
    }

    return $tecnicos;
}

function carregarDadosTela()
{
    $clientes = listarClientesLocais();

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

/*
   Fotos/vídeos de orientação da OS (o técnico vê ao abrir a OS no celular).
   Mesmas validações do upload da Área de OS: extensão + conteúdo real + tamanho,
   e a pasta recebe .htaccess para nunca executar nada que for enviado.
   Retorna quantos arquivos foram salvos.
*/
function vn_salvar_fotos_ajuda_os(int $ordemId): int
{
    if ($ordemId <= 0 || empty($_FILES['fotos_ajuda']) || !is_array($_FILES['fotos_ajuda']['name'] ?? null)) {
        return 0;
    }

    dbQuery("CREATE TABLE IF NOT EXISTS os_ajuda_arquivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ordem_id INT NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'foto',
        nome_original VARCHAR(255) NULL,
        caminho_arquivo VARCHAR(255) NOT NULL,
        criado_por INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ordem_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $permitidos = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm', 'm4v', '3gp'];
    $mimesOk = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'mp4' => ['video/mp4', 'application/mp4'], 'mov' => ['video/quicktime'], 'webm' => ['video/webm'],
        'm4v' => ['video/x-m4v', 'video/mp4'], '3gp' => ['video/3gpp', 'video/3gpp2'],
    ];

    $baseDir = __DIR__ . '/uploads/os_ajuda/' . $ordemId;
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) return 0;

    $htaccess = $baseDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|asp|aspx|jsp)$\">\nRequire all denied\n</FilesMatch>\n", LOCK_EX);
    }

    $salvos = 0;
    $total = count($_FILES['fotos_ajuda']['name']);
    for ($i = 0; $i < $total && $i < 20; $i++) {
        if ((int)($_FILES['fotos_ajuda']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $tmp = (string)($_FILES['fotos_ajuda']['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $tamanho = (int)($_FILES['fotos_ajuda']['size'][$i] ?? 0);
        if ($tamanho <= 0 || $tamanho > 120 * 1024 * 1024) continue;

        $original = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$_FILES['fotos_ajuda']['name'][$i]));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $permitidos, true)) continue;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if ($mime !== '' && isset($mimesOk[$ext]) && !in_array($mime, $mimesOk[$ext], true)) continue;
            }
        }

        $novoNome = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $baseDir . '/' . $novoNome)) continue;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { vn_image_optimize($baseDir . '/' . $novoNome); }

        $tipo = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'foto' : 'video';
        dbInsert('os_ajuda_arquivos', [
            'ordem_id' => $ordemId,
            'tipo' => $tipo,
            'nome_original' => $original,
            'caminho_arquivo' => 'uploads/os_ajuda/' . $ordemId . '/' . $novoNome,
            'criado_por' => getUsuarioLogadoId(),
        ]);
        $salvos++;
    }

    return $salvos;
}

function montarDadosInsertOS($dados)
{
    $table = 'ordens_servico';

    if (!tableExists($table)) {
        throw new Exception("A tabela ordens_servico não existe no banco.");
    }

    $insert = [];

    $mapa = [
        'codigo_os' => $dados['codigo_os'] ?? null,
        'cliente_id' => $dados['cliente_id'] ?? null,
        'endereco_id' => $dados['endereco_id'] ?? null,
        'nome_cliente' => $dados['nome_cliente'] ?? null,
        'tecnico_id' => $dados['tecnico_id'] ?? null,
        'tecnico_admin_id' => $dados['tecnico_admin_id'] ?? null,
        'id_tec' => $dados['tecnico_id'] ?? null,
        'vendedor_pedido_id' => $dados['tecnico_id'] ?? null,
        'funcionario_id' => $dados['tecnico_id'] ?? null,
        'tecnico_nome' => $dados['tecnico_nome'] ?? '',
        'nome_tecnico' => $dados['tecnico_nome'] ?? '',
        'vendedor_pedido' => $dados['tecnico_nome'] ?? '',
        'funcionario_nome' => $dados['tecnico_nome'] ?? '',

        'titulo' => $dados['titulo'] ?? null,

        // Corretiva, Preventiva, Instalação, Vistoria... é o que a Área de OS mostra como tipo.
        'tipo_servico' => $dados['tipo_servico'] ?? null,

        // CFTV (padrão) ou serralheria: separa as OS nas listas da Área de OS e em OS Concluídas.
        'linha_servico' => $dados['linha_servico'] ?? 'cftv',

        'problema_relatado_cliente' => $dados['problema'] ?? null,
        'problema_ordem' => $dados['problema'] ?? null,

        'equipamento_ordem' => $dados['equipamento'] ?? '',
        'garantia_ordem' => $dados['garantia'] ?? '',
        'recebimento_ordem' => 'Aberta pelo sistema',
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

        // Data/hora combinada com o cliente: é o que ordena e filtra a Área de OS do técnico.
        // Sem isso, toda OS aparecia no horário em que foi criada.
        'data_agendamento' => $dados['data_agendamento'] ?? null,
        'data_agendada' => $dados['data_agendamento'] ?? null,

        'criado_por' => getUsuarioLogadoId(),

        'valor_servico' => 0.00,
        'valor_material' => 0.00,
        'valor_total' => 0.00,

        'laudo_ordem' => '',
        'servico_executado' => '',
    ];

    foreach ($mapa as $coluna => $valor) {
        if (columnExists($table, $coluna)) {
            if ($coluna === 'tecnico_id' && $valor && tableExists('tecnicos') && columnExists('tecnicos', 'id')) {
                if (!dbFetch('SELECT id FROM tecnicos WHERE id = ? LIMIT 1', [(int)$valor])) {
                    continue;
                }
            }

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
    $listaTecnicos = listarTecnicosLocais();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ============================= */
/* PROCESSAR ENVIO */
/* ============================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    try {
        $clientRaw = (string)($_POST['client'] ?? '');

        if ($clientRaw === 'new') {
            throw new Exception("Cadastre o cliente na página Clientes antes de gerar a OS.");
        }

            $clientFinal = (int)$clientRaw;

            if ($clientFinal <= 0 || !isset($clientesMap[$clientFinal])) {
                throw new Exception("Selecione um cliente válido ou cadastre um novo.");
            }

            $clienteSelecionado = buscarClienteLocal($clientFinal);

            if (!$clienteSelecionado) {
                throw new Exception("Cliente não encontrado.");
            }

            $nomeCliente = nomeClienteLocal($clienteSelecionado);
            $novoEnderecoId = 0;

            $expectedService = clean($_POST['expectedService'] ?? '');
            $observation = trim((string)($_POST['observation'] ?? ''));
            $priority = (int)($_POST['priority'] ?? 0);
            $tecnicoId = (int)($_POST['tecnico_id'] ?? 0);
            $tecnicoAdminId = 0;
            $tecnicoNome = '';
            $equipamento = clean($_POST['equipamento'] ?? '');
            $garantia = clean($_POST['garantia'] ?? '');

            if ($expectedService === '') {
                throw new Exception("Informe o problema ou serviço previsto.");
            }

            $prioridadeTexto = match ($priority) {
                99 => 'emergencia',
                10 => 'urgente',
                1  => 'alta',
                default => 'normal'
            };

            foreach ($listaTecnicos as $tecnico) {
                if ((int)$tecnico['id'] === $tecnicoId) {
                    $tecnicoNome = (string)$tecnico['nome'];
                    $tecnicoAdminId = (int)($tecnico['admin_id'] ?? 0);
                    break;
                }
            }

            if ($tecnicoId > 0 && $tecnicoNome === '') {
                throw new Exception("Técnico selecionado não encontrado.");
            }

            $enderecosCliente = $enderecosPorCliente[$clientFinal] ?? [];
            $enderecoId = $novoEnderecoId > 0 ? $novoEnderecoId : (int)($_POST['endereco_id'] ?? 0);
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

            // Agendamento (opcional): vazio = a OS entra para hoje, como era antes.
            $agendamento = trim((string)($_POST['data_agendamento'] ?? ''));
            if ($agendamento !== '') {
                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $agendamento) ?: DateTime::createFromFormat('Y-m-d H:i:s', $agendamento);
                if (!$dt) throw new Exception('Data de agendamento inválida.');
                $agendamento = $dt->format('Y-m-d H:i:s');
            } else {
                $agendamento = vn_os_now_sp();
            }

            // Tipo de serviço (corretiva/preventiva/instalação/vistoria).
            $tiposValidos = ['Corretiva', 'Preventiva', 'Instalação', 'Vistoria', 'Orçamento'];
            $tipoServico = trim((string)($_POST['tipo_servico'] ?? 'Corretiva'));
            if (!in_array($tipoServico, $tiposValidos, true)) $tipoServico = 'Corretiva';

            // Tipo da OS: CFTV ou serralheria
            $linhaServico = (($_POST['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv';

            // Bancos antigos não têm estas colunas: cria na hora para não perder o dado.
            ensureColumn('ordens_servico', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");
            ensureColumn('ordens_servico', 'tipo_servico', 'VARCHAR(120) NULL');
            ensureColumn('ordens_servico', 'data_agendamento', 'DATETIME NULL');

            $dadosOS = [
                'codigo_os' => gerarCodigoOS(),
                'cliente_id' => $clientFinal,
                'endereco_id' => $enderecoId > 0 ? $enderecoId : null,
                'data_agendamento' => $agendamento,
                'tipo_servico' => $tipoServico,
                'linha_servico' => $linhaServico,
                'nome_cliente' => $nomeCliente,
                'tecnico_id' => $tecnicoId > 0 ? $tecnicoId : null,
                'tecnico_admin_id' => $tecnicoAdminId > 0 ? $tecnicoAdminId : null,
                'tecnico_nome' => $tecnicoNome,
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

            $success = ($linhaServico === 'serralheria' ? "OS de Serralheria criada com sucesso! ID: " : "Ordem de Serviço criada com sucesso! ID: ") . $idOS;

            // Fotos/vídeos de orientação que o técnico vê ao abrir a OS.
            $enviadas = vn_salvar_fotos_ajuda_os((int)$idOS);
            if ($enviadas > 0) {
                $success .= " ({$enviadas} foto(s) de ajuda anexada(s))";
            }

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

        .hidden {
            display: none !important;
        }

        .cep-grid {
            display: grid;
            grid-template-columns: 160px 1fr 130px;
            gap: 12px;
        }

        .field-hint {
            color: var(--muted);
            display: block;
            font-size: .78rem;
            font-weight: 700;
            margin-top: 7px;
        }

        .inline-actions {
            display: grid;
            grid-template-columns: 1fr 230px;
            gap: 12px;
            align-items: end;
        }

        .btn-secondary {
            background: rgba(59,130,246,.16);
            border: 1px solid var(--line);
            color: #bfdbfe;
        }

        .btn-secondary:hover {
            background: rgba(59,130,246,.26);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(2, 6, 23, .76);
            padding: 18px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-card {
            width: min(820px, 96vw);
            max-height: 92vh;
            overflow: auto;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 24px 80px rgba(0,0,0,.45);
            padding: 22px;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .modal-head h3 {
            color: var(--primary-fg);
            font-size: 1.16rem;
        }

        .modal-close {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: #050810;
            color: var(--text);
            cursor: pointer;
            font-size: 1.25rem;
            font-weight: 900;
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

            .cep-grid {
                grid-template-columns: 1fr;
            }

            .inline-actions {
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
// ?linha=serralheria (menu "Gerar OS de Serralheria") já abre com Serralheria marcada
$linhaTela = (($_POST['linha_servico'] ?? ($_GET['linha'] ?? '')) === 'serralheria') ? 'serralheria' : 'cftv';
$menuChave = (($_GET['linha'] ?? '') === 'serralheria') ? 'gerar-os-serralheria' : 'gerar-os';
vn_ui_shell_start($linhaTela === 'serralheria' ? 'Gerar OS de Serralheria' : 'Gerar OS', $menuChave, ['head_extra' => $vnHeadExtra, 'legacy' => 'admin', 'js' => ['assets/js/vn-camera.js']]);
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

                <form method="POST" class="form-grid" enctype="multipart/form-data">
                    <div class="full-width">
                        <label><i class="fas fa-user"></i> Cliente</label>

                        <div class="inline-actions">
                            <select name="client" id="clientSelect" required>
                                <option value="">Selecione o cliente</option>
                                <?php foreach ($listaClientes as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['client'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= h($c['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <a class="btn btn-secondary" href="clientes-admin.php">Clientes</a>
                        </div>
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
                        <label><i class="fas fa-screwdriver-wrench"></i> Tipo de serviço</label>
                        <select name="tipo_servico">
                            <?php
                            $tipoSel = (string)($_POST['tipo_servico'] ?? 'Corretiva');
                            foreach ([
                                'Corretiva'   => 'Corretiva (conserto / chamado)',
                                'Preventiva'  => 'Preventiva (manutenção programada)',
                                'Instalação'  => 'Instalação',
                                'Vistoria'    => 'Vistoria / inspeção',
                                'Orçamento'   => 'Orçamento / levantamento',
                            ] as $val => $rotulo): ?>
                                <option value="<?= h($val) ?>" <?= $tipoSel === $val ? 'selected' : '' ?>><?= h($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-calendar-day"></i> Agendar para</label>
                        <input type="datetime-local" name="data_agendamento" value="<?= h((string)($_POST['data_agendamento'] ?? date('Y-m-d\TH:i'))) ?>">
                        <div class="resume-box">Data e hora combinadas com o cliente. É por aqui que a OS entra na agenda e na rota do técnico.</div>
                    </div>

                    <div>
                        <label><i class="fas fa-exclamation-circle"></i> Prioridade</label>
                        <select name="priority">
                            <option value="0" <?= ((int)($_POST['priority'] ?? 0) === 0) ? 'selected' : '' ?>>Normal</option>
                            <option value="1" <?= ((int)($_POST['priority'] ?? 0) === 1) ? 'selected' : '' ?>>Encaixe</option>
                            <option value="10" <?= ((int)($_POST['priority'] ?? 0) === 10) ? 'selected' : '' ?>>Urgente</option>
                            <option value="99" <?= ((int)($_POST['priority'] ?? 0) === 99) ? 'selected' : '' ?>>Emergencia</option>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-user-tie"></i> Técnico</label>
                        <select name="tecnico_id" id="tecnicoSelect">
                            <option value="0">A definir</option>
                            <?php foreach ($listaTecnicos as $tecnico): ?>
                                <option value="<?= (int)$tecnico['id'] ?>" <?= ((int)($_POST['tecnico_id'] ?? 0) === (int)$tecnico['id']) ? 'selected' : '' ?>>
                                    <?= h($tecnico['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                        <label><i class="fas fa-images"></i> Fotos de ajuda para o técnico</label>
                        <input type="file" name="fotos_ajuda[]" accept="image/*,video/*" multiple data-vn-camera>
                        <div class="resume-box">Opcional. Fotos ou vídeos de orientação (local do equipamento, modelo da peça, caminho até o quadro...). O técnico vê essas imagens ao abrir a OS no celular.</div>
                    </div>

                    <div class="full-width">
                        <button type="submit" class="btn" name="acao" value="criar_os">Gerar Ordem de Serviço</button>
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
