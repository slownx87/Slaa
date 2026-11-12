<?php
require_once __DIR__ . '/_vn_security.php'; // sessão/cookie/permissões antes do session_start()
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['cargo']) || strtolower(trim((string)$_SESSION['cargo'])) !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/auth.php')) { require_once __DIR__ . '/auth.php'; }

$pagina_atual = basename($_SERVER['PHP_SELF']);
$success = '';
$error = '';

function vn_page_db() {
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
    throw new Exception('Não encontrei a conexão com o banco.');
}

function dbIsPdo() { return vn_page_db() instanceof PDO; }
if (!function_exists('h')) { if (!function_exists('h')) { function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } } }
function clean($v) { return preg_replace('/\s+/', ' ', trim((string)$v)); }

function bindMysqliParams($stmt, $params) {
    if (!$params) return;
    $types = '';
    $values = [];
    foreach ($params as $p) {
        if (is_int($p) || $p === null) $types .= 'i';
        elseif (is_float($p)) $types .= 'd';
        else $types .= 's';
        $values[] = $p;
    }
    $bind = [$types];
    foreach ($values as $k => $v) $bind[] = &$values[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function dbQuery($sql, $params = []) {
    $database = vn_page_db();
    if ($database instanceof PDO) {
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    $stmt = $database->prepare($sql);
    if (!$stmt) throw new Exception(vn_sql_error_msg('prepare', (string)$database->error, (string)$sql));
    bindMysqliParams($stmt, $params);
    if (!$stmt->execute()) throw new Exception(vn_sql_error_msg('execute', (string)$stmt->error, (string)$sql));
    return $stmt;
}

function dbFetch($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    if (dbIsPdo()) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function dbFetchAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    if (dbIsPdo()) return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function dbLastId() { $database = vn_page_db(); return $database instanceof PDO ? (int)$database->lastInsertId() : (int)$database->insert_id; }
function dbBegin() { $d = vn_page_db(); $d instanceof PDO ? $d->beginTransaction() : $d->begin_transaction(); }
function dbCommit() { $d = vn_page_db(); $d instanceof PDO ? ($d->inTransaction() ? $d->commit() : null) : $d->commit(); }
function dbRollback() { $d = vn_page_db(); $d instanceof PDO ? ($d->inTransaction() ? $d->rollBack() : null) : $d->rollback(); }

function databaseName() {
    if (dbIsPdo()) return (string)(vn_page_db()->query('SELECT DATABASE()')->fetchColumn() ?: '');
    $res = vn_page_db()->query('SELECT DATABASE() AS dbname');
    $row = $res ? $res->fetch_assoc() : [];
    return (string)($row['dbname'] ?? '');
}

function tableExists($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $row = dbFetch('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1', [databaseName(), $table]);
    return $cache[$table] = !empty($row);
}

function columnExists($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $row = dbFetch('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', [databaseName(), $table, $column]);
    return $cache[$key] = !empty($row);
}

function firstCol($table, $cols) {
    foreach ($cols as $col) if (columnExists($table, $col)) return $col;
    return null;
}

function setIfCol(&$data, $table, $cols, $value) {
    $col = firstCol($table, $cols);
    if ($col) $data[$col] = $value;
}

function firstValue($row, $keys, $default = '') {
    foreach ($keys as $key) {
        if (isset($row[$key]) && clean($row[$key]) !== '') return $row[$key];
    }
    return $default;
}

function insertDynamic($table, $data) {
    if (!$data) throw new Exception('Nenhum dado para inserir.');
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    dbQuery($sql, array_values($data));
    return dbLastId();
}

function updateDynamic($table, $id, $data) {
    if (!$data) return;
    $sets = [];
    foreach (array_keys($data) as $col) $sets[] = '`' . $col . '` = ?';
    $params = array_values($data);
    $params[] = (int)$id;
    dbQuery('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1', $params);
}

function excluirCliente($clienteId) {
    $clienteId = (int)$clienteId;
    if ($clienteId <= 0) throw new Exception('Cliente inválido para excluir.');
    if (!tableExists('clientes') || !columnExists('clientes', 'id')) throw new Exception('Tabela clientes inválida.');

    if (tableExists('enderecos_clientes')) {
        $clienteCol = firstCol('enderecos_clientes', ['cliente_id', 'id_cliente']);
        if ($clienteCol) dbQuery("DELETE FROM enderecos_clientes WHERE `{$clienteCol}` = ?", [$clienteId]);
    }

    dbQuery('DELETE FROM clientes WHERE id = ? LIMIT 1', [$clienteId]);
}

function montarEnderecoTexto($row) {
    $partes = [];
    $rua = clean(firstValue($row, ['endereco', 'logradouro', 'rua']));
    $numero = clean($row['numero'] ?? '');
    $complemento = clean($row['complemento'] ?? '');
    $bairro = clean($row['bairro'] ?? '');
    $cidade = clean($row['cidade'] ?? '');
    $uf = clean($row['uf'] ?? '');
    $cep = clean($row['cep'] ?? '');
    if ($rua !== '') $partes[] = $rua . ($numero !== '' ? ', ' . $numero : '');
    if ($complemento !== '') $partes[] = $complemento;
    if ($bairro !== '') $partes[] = $bairro;
    if ($cidade !== '' || $uf !== '') $partes[] = trim($cidade . ($uf !== '' ? ' - ' . $uf : ''));
    if ($cep !== '') $partes[] = 'CEP ' . $cep;
    return implode(' - ', $partes);
}

function clienteDataFromPost() {
    $nome = clean($_POST['nome'] ?? '');
    if ($nome === '') throw new Exception('Informe o nome do cliente/condomínio.');
    $data = [];
    setIfCol($data, 'clientes', ['nome', 'nome_cliente', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente', 'cliente'], $nome);
    setIfCol($data, 'clientes', ['cnpj', 'cnpj_cliente', 'documento', 'cpf_cnpj'], clean($_POST['cnpj'] ?? ''));
    setIfCol($data, 'clientes', ['email', 'email_cliente', 'cliente_email'], clean($_POST['email'] ?? ''));
    setIfCol($data, 'clientes', ['status'], 'ativo');
    return $data;
}

function enderecoDataFromPost($clienteId) {
    if (!tableExists('enderecos_clientes')) throw new Exception('Tabela enderecos_clientes não encontrada.');
    $clienteCol = firstCol('enderecos_clientes', ['cliente_id', 'id_cliente']);
    if (!$clienteCol) throw new Exception('Tabela enderecos_clientes sem coluna cliente_id.');
    $rua = clean($_POST['logradouro'] ?? '');
    $cep = clean($_POST['cep'] ?? '');
    if ($rua === '' && $cep === '') throw new Exception('Informe o CEP ou a rua do endereço.');
    $data = [$clienteCol => (int)$clienteId];
    setIfCol($data, 'enderecos_clientes', ['nome_endereco', 'titulo', 'apelido'], 'Endereço principal');
    setIfCol($data, 'enderecos_clientes', ['cep', 'cep_cliente'], $cep);
    setIfCol($data, 'enderecos_clientes', ['endereco', 'endereco_cliente', 'logradouro', 'logradouro_cliente', 'rua', 'rua_cliente'], $rua);
    setIfCol($data, 'enderecos_clientes', ['numero', 'numero_cliente'], clean($_POST['numero'] ?? ''));
    setIfCol($data, 'enderecos_clientes', ['complemento', 'complemento_cliente'], clean($_POST['complemento'] ?? ''));
    setIfCol($data, 'enderecos_clientes', ['bairro', 'bairro_cliente'], clean($_POST['bairro'] ?? ''));
    setIfCol($data, 'enderecos_clientes', ['cidade', 'cidade_cliente'], clean($_POST['cidade'] ?? ''));
    setIfCol($data, 'enderecos_clientes', ['uf', 'uf_cliente', 'estado'], clean($_POST['uf'] ?? ''));
    setIfCol($data, 'enderecos_clientes', ['status'], 'ativo');
    setIfCol($data, 'enderecos_clientes', ['principal'], 1);
    return $data;
}

function carregarClientes() {
    if (!tableExists('clientes') || !columnExists('clientes', 'id')) return [];
    $nome = firstCol('clientes', ['nome', 'nome_cliente', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente', 'cliente']);
    $cnpj = firstCol('clientes', ['cnpj', 'cnpj_cliente', 'documento', 'cpf_cnpj']);
    $email = firstCol('clientes', ['email', 'email_cliente', 'cliente_email']);
    $cols = ['id'];
    if ($nome) $cols[] = $nome . ' AS nome'; else $cols[] = "CONCAT('Cliente #', id) AS nome";
    if ($cnpj) $cols[] = $cnpj . ' AS cnpj'; else $cols[] = "'' AS cnpj";
    if ($email) $cols[] = $email . ' AS email'; else $cols[] = "'' AS email";
    $where = columnExists('clientes', 'status') ? "WHERE (status IS NULL OR status = '' OR LOWER(status) = 'ativo')" : '';
    $clientes = dbFetchAll('SELECT ' . implode(', ', $cols) . " FROM clientes {$where} ORDER BY nome ASC, id ASC LIMIT 1000");

    if (!tableExists('enderecos_clientes')) return $clientes;
    $clienteCol = firstCol('enderecos_clientes', ['cliente_id', 'id_cliente']);
    if (!$clienteCol) return $clientes;
    $endCols = ['id', $clienteCol . ' AS cliente_id'];
    foreach (['cep','endereco','logradouro','rua','numero','complemento','bairro','cidade','uf'] as $col) {
        if (columnExists('enderecos_clientes', $col)) $endCols[] = $col;
        else $endCols[] = "'' AS {$col}";
    }
    $enderecos = dbFetchAll('SELECT ' . implode(', ', $endCols) . ' FROM enderecos_clientes ORDER BY id ASC LIMIT 3000');
    $porCliente = [];
    foreach ($enderecos as $end) if (!isset($porCliente[(int)$end['cliente_id']])) $porCliente[(int)$end['cliente_id']] = $end;
    foreach ($clientes as &$cliente) {
        $end = $porCliente[(int)$cliente['id']] ?? [];
        $cliente['endereco_id'] = (int)($end['id'] ?? 0);
        $cliente['cep'] = $end['cep'] ?? '';
        $cliente['logradouro'] = firstValue($end, ['endereco', 'logradouro', 'rua']);
        $cliente['numero'] = $end['numero'] ?? '';
        $cliente['complemento'] = $end['complemento'] ?? '';
        $cliente['bairro'] = $end['bairro'] ?? '';
        $cliente['cidade'] = $end['cidade'] ?? '';
        $cliente['uf'] = $end['uf'] ?? '';
        $cliente['endereco_texto'] = montarEnderecoTexto($end);
    }
    unset($cliente);
    return $clientes;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string)($_POST['acao'] ?? 'salvar');
        if ($acao === 'salvar') {
            dbBegin();
            try {
                $clienteId = (int)($_POST['cliente_id'] ?? 0);
                if ($clienteId > 0) {
                    updateDynamic('clientes', $clienteId, clienteDataFromPost());
                } else {
                    $clienteId = insertDynamic('clientes', clienteDataFromPost());
                }

                $enderecoId = (int)($_POST['endereco_id'] ?? 0);
                if ($enderecoId > 0) updateDynamic('enderecos_clientes', $enderecoId, enderecoDataFromPost($clienteId));
                else insertDynamic('enderecos_clientes', enderecoDataFromPost($clienteId));

                dbCommit();
                $success = 'Cliente e endereço salvos com sucesso.';
                $_POST = [];
            } catch (Throwable $e) {
                dbRollback();
                throw $e;
            }
        } elseif ($acao === 'excluir') {
            dbBegin();
            try {
                excluirCliente((int)($_POST['cliente_id'] ?? 0));
                dbCommit();
                $success = 'Cliente excluído com sucesso.';
                $_POST = [];
            } catch (Throwable $e) {
                dbRollback();
                throw $e;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$clientes = [];
try { $clientes = carregarClientes(); } catch (Throwable $e) { if (!$error) $error = $e->getMessage(); }
$clientesJson = json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php
require_once __DIR__ . '/_vn_ui.php';
ob_start();
?>
<style>
        :root{--bg:#050810;--card:#0f172a;--card2:#111c31;--line:rgba(59,130,246,.25);--line2:rgba(255,255,255,.08);--text:#fff;--muted:#94a3b8;--blue:#3b82f6;--green:#4ade80;--red:#f87171;--sidebar-text:#cbd5e1}*{margin:0;padding:0;box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}a{text-decoration:none}.topbar{height:68px;background:rgba(10,19,34,.98);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 22px;position:sticky;top:0;z-index:20}.brand{display:flex;align-items:center;gap:12px;font-weight:800}.brand img{height:40px;max-width:140px;object-fit:contain}.brand span{color:var(--primary-fg)}.menu-btn{display:none;border:1px solid var(--line);background:rgba(59,130,246,.14);color:var(--text);border-radius:10px;padding:10px 13px;font-weight:800}.layout{display:flex;min-height:calc(100vh - 68px)}.sidebar{width:265px;background:linear-gradient(180deg,#07111f 0%,#050810 100%);padding:16px 14px;flex-shrink:0;border-right:1px solid var(--line)}.sidebar-title{color:var(--muted);font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin:4px 8px 12px}.side-btn{min-height:48px;display:flex;align-items:center;gap:11px;color:var(--sidebar-text);padding:12px 13px;border-radius:12px;margin-bottom:8px;font-size:.95rem;font-weight:700;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.025);transition:.18s ease}.side-btn i{width:22px;text-align:center;color:var(--primary-fg)}.side-btn:hover{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.45);color:#fff;transform:translateX(2px)}.side-btn.active{background:var(--blue);border-color:var(--blue);color:#020617;box-shadow:0 8px 22px rgba(59,130,246,.22)}.side-btn.active i{color:#020617}.side-btn.logout{margin-top:18px;color:#fecaca!important;background:rgba(248,113,113,.07);border-color:rgba(248,113,113,.20)}.side-btn.logout i{color:var(--red)}.content{flex:1;padding:24px;max-width:1200px;margin:0 auto;width:100%}.page-title,.card{background:var(--card);border:1px solid var(--line);border-radius:16px;margin-bottom:18px}.page-title{padding:20px}.page-title h1{color:var(--primary-fg);font-size:1.55rem;margin-bottom:8px}.page-title p{color:var(--muted);line-height:1.5}.card{padding:24px}.card h2{color:var(--primary-fg);font-size:1.2rem;margin-bottom:18px;display:flex;align-items:center;gap:10px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.full-width{grid-column:span 2}label{display:block;margin-bottom:8px;color:var(--muted);font-size:.82rem;font-weight:900;text-transform:uppercase}input,select{width:100%;padding:14px;background:#050810;border:1px solid var(--line);border-radius:12px;color:white;outline:none;font-family:inherit;font-size:.95rem}.cep-grid{display:grid;grid-template-columns:160px 1fr 130px;gap:12px}.btn{min-height:50px;padding:14px 18px;background:var(--blue);border:none;border-radius:12px;font-weight:900;color:#020617;cursor:pointer;width:100%;font-size:1rem;text-transform:uppercase}.btn:hover{background:#60a5fa}.btn-soft{background:rgba(59,130,246,.16);border:1px solid var(--line);color:#bfdbfe}.alert{padding:15px;border-radius:12px;margin-bottom:18px;font-weight:700;text-align:center}.alert-success{background:rgba(74,222,128,.10);color:var(--green);border:1px solid rgba(74,222,128,.35)}.alert-error{background:rgba(248,113,113,.10);color:var(--red);border:1px solid rgba(248,113,113,.35)}.clients{display:grid;gap:10px}.client-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:14px;border:1px solid var(--line2);border-radius:12px;background:var(--card2)}.client-row strong{display:block;margin-bottom:5px}.client-row small{color:var(--muted);line-height:1.45}.edit-btn{width:auto;min-height:42px;padding:10px 14px}.hint{display:block;color:var(--muted);font-size:.8rem;margin-top:7px;font-weight:700}@media(max-width:780px){.topbar{height:64px;padding:0 14px}.brand img{height:34px;max-width:110px}.menu-btn{display:block}.layout{display:block}.sidebar{display:none;width:100%;padding:12px;border-right:0;border-bottom:1px solid var(--line);background:#07111f}.sidebar.open{display:block}.content{padding:14px}.page-title,.card{padding:14px}.form-grid,.cep-grid{grid-template-columns:1fr}.full-width{grid-column:span 1}.client-row{grid-template-columns:1fr}.edit-btn{width:100%}}@media(max-width:430px){.brand span{display:none}}
    </style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php
$vnHeadExtra = ob_get_clean();
vn_ui_shell_start('Clientes', 'clientes', ['head_extra' => $vnHeadExtra, 'legacy' => 'admin']);
?>
<div class="content">
            <div class="page-title"><h1>Clientes e Condomínios</h1><p>Cadastre e altere nome, CNPJ, e-mail e endereço. O CNPJ e o CEP podem preencher os dados automaticamente.</p></div>
            <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
            <section class="card">
                <h2><i class="fas fa-building-circle-check"></i> Cadastro / Alteração</h2>
                <form method="POST" class="form-grid" id="clienteForm">
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="cliente_id" id="clienteId" value="<?= h($_POST['cliente_id'] ?? '') ?>">
                    <input type="hidden" name="endereco_id" id="enderecoId" value="<?= h($_POST['endereco_id'] ?? '') ?>">
                    <div><label>CNPJ</label><input name="cnpj" id="cnpjInput" maxlength="18" placeholder="00.000.000/0000-00" value="<?= h($_POST['cnpj'] ?? '') ?>"><small class="hint" id="cnpjStatus">Digite o CNPJ para puxar o nome.</small></div>
                    <div><label>Nome do cliente / condomínio</label><input name="nome" id="nomeInput" required value="<?= h($_POST['nome'] ?? '') ?>"></div>
                    <div class="full-width"><label>E-mail</label><input type="email" name="email" id="emailInput" value="<?= h($_POST['email'] ?? '') ?>"></div>
                    <div class="full-width"><label>Endereço por CEP</label><div class="cep-grid"><input name="cep" id="cepInput" maxlength="9" placeholder="CEP" value="<?= h($_POST['cep'] ?? '') ?>"><input name="logradouro" id="logradouroInput" placeholder="Rua / logradouro" value="<?= h($_POST['logradouro'] ?? '') ?>"><input name="numero" id="numeroInput" placeholder="Número" value="<?= h($_POST['numero'] ?? '') ?>"></div><div class="cep-grid" style="margin-top:12px"><input name="bairro" id="bairroInput" placeholder="Bairro" value="<?= h($_POST['bairro'] ?? '') ?>"><input name="cidade" id="cidadeInput" placeholder="Cidade" value="<?= h($_POST['cidade'] ?? '') ?>"><input name="uf" id="ufInput" maxlength="2" placeholder="UF" value="<?= h($_POST['uf'] ?? '') ?>"></div><input name="complemento" id="complementoInput" placeholder="Complemento / referência" style="margin-top:12px" value="<?= h($_POST['complemento'] ?? '') ?>"><small class="hint" id="cepStatus">Digite o CEP para preencher o endereço.</small></div>
                    <div><button type="button" class="btn btn-soft" id="novoBtn">Novo cadastro</button></div>
                    <div><button type="submit" class="btn">Salvar Cliente e Endereço</button></div>
                </form>
            </section>
            <section class="card"><h2><i class="fas fa-list"></i> Clientes cadastrados</h2>
                <div class="busca-clientes" style="position:relative;margin:0 0 12px">
                    <input type="search" id="buscaClientes" placeholder="Pesquisar cliente por nome, CNPJ, e-mail ou endereço…" autocomplete="off" aria-label="Pesquisar cliente" style="width:100%;min-height:48px;padding:10px 14px 10px 44px;border-radius:12px;font-size:16px;box-sizing:border-box">
                    <i class="fas fa-search" aria-hidden="true" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none"></i>
                    <small id="buscaClientesN" style="display:block;margin-top:6px;opacity:.75" aria-live="polite"></small>
                </div>
                <div class="clients" id="clientesLista"><?php foreach ($clientes as $cliente): ?><div class="client-row"><div><strong><?= h($cliente['nome'] ?? ('Cliente #' . $cliente['id'])) ?></strong><small><?= h($cliente['cnpj'] ?? '') ?><?= !empty($cliente['email']) ? ' · ' . h($cliente['email']) : '' ?><br><?= h($cliente['endereco_texto'] ?? 'Sem endereço') ?></small></div><div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end"><button class="btn btn-soft edit-btn" type="button" data-edit="<?= (int)$cliente['id'] ?>">Editar</button><form method="POST" data-confirm="Excluir este cliente e os endereços vinculados?" data-danger><input type="hidden" name="acao" value="excluir"><input type="hidden" name="cliente_id" value="<?= (int)$cliente['id'] ?>"><button class="btn edit-btn" type="submit" style="background:var(--danger-solid);color:#fff">Excluir</button></form></div></div><?php endforeach; ?></div></section>
        </div>

    <script>
    (function () {
        var campo = document.getElementById('buscaClientes'), lista = document.getElementById('clientesLista'), n = document.getElementById('buscaClientesN');
        if (!campo || !lista) return;
        function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
        function filtrar() {
            var termos = norm(campo.value).split(/\s+/).filter(Boolean), linhas = lista.querySelectorAll('.client-row'), ok = 0;
            var digitos = campo.value.replace(/\D+/g, '');
            linhas.forEach(function (l) {
                var txt = norm(l.textContent), so = (l.textContent || '').replace(/\D+/g, '');
                var vis = !termos.length || termos.every(function (t) { return txt.indexOf(t) !== -1; }) || (digitos.length >= 3 && so.indexOf(digitos) !== -1);
                l.hidden = !vis; l.style.display = vis ? '' : 'none';
                if (vis) ok++;
            });
            n.textContent = termos.length ? (ok === 0 ? 'Nenhum cliente encontrado.' : (ok === 1 ? '1 cliente encontrado' : ok + ' clientes encontrados')) : (linhas.length + (linhas.length === 1 ? ' cliente' : ' clientes'));
        }
        campo.addEventListener('input', filtrar);
        filtrar();
    })();
    </script>
    <script>
        const clientes = <?= $clientesJson ?: '[]' ?>;
        const byId = Object.fromEntries(clientes.map(c => [String(c.id), c]));
        const fields = {
            cliente_id: document.getElementById('clienteId'), endereco_id: document.getElementById('enderecoId'), nome: document.getElementById('nomeInput'), cnpj: document.getElementById('cnpjInput'), email: document.getElementById('emailInput'), cep: document.getElementById('cepInput'), logradouro: document.getElementById('logradouroInput'), numero: document.getElementById('numeroInput'), complemento: document.getElementById('complementoInput'), bairro: document.getElementById('bairroInput'), cidade: document.getElementById('cidadeInput'), uf: document.getElementById('ufInput')
        };
        const cnpjStatus = document.getElementById('cnpjStatus');
        const cepStatus = document.getElementById('cepStatus');
        function setVal(name, val){ if(fields[name]) fields[name].value = val || ''; }
        function editar(id){ const c = byId[String(id)]; if(!c) return; Object.entries({cliente_id:c.id,endereco_id:c.endereco_id,nome:c.nome,cnpj:c.cnpj,email:c.email,cep:c.cep,logradouro:c.logradouro,numero:c.numero,complemento:c.complemento,bairro:c.bairro,cidade:c.cidade,uf:c.uf}).forEach(([k,v])=>setVal(k,v)); window.scrollTo({top:0,behavior:'smooth'}); }
        document.querySelectorAll('[data-edit]').forEach(btn => btn.addEventListener('click', () => editar(btn.dataset.edit)));
        document.getElementById('novoBtn')?.addEventListener('click', () => { Object.keys(fields).forEach(k => setVal(k,'')); fields.nome?.focus(); });
        function maskCnpj(v){ const n=String(v||'').replace(/\D/g,'').slice(0,14); if(n.length>12)return n.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2}).*/,'$1.$2.$3/$4-$5'); if(n.length>8)return n.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4}).*/,'$1.$2.$3/$4'); if(n.length>5)return n.replace(/^(\d{2})(\d{3})(\d{0,3}).*/,'$1.$2.$3'); if(n.length>2)return n.replace(/^(\d{2})(\d{0,3}).*/,'$1.$2'); return n; }
        function maskCep(v){ const n=String(v||'').replace(/\D/g,'').slice(0,8); return n.length>5?n.slice(0,5)+'-'+n.slice(5):n; }
        async function buscarCnpj(){ const cnpj=(fields.cnpj?.value||'').replace(/\D/g,''); if(cnpj.length!==14){ if(cnpjStatus)cnpjStatus.textContent='Digite um CNPJ com 14 números.'; return; } if(cnpjStatus)cnpjStatus.textContent='Buscando CNPJ...'; try{ const resp=await fetch('https://brasilapi.com.br/api/cnpj/v1/'+cnpj); if(!resp.ok){ if(cnpjStatus)cnpjStatus.textContent='CNPJ não encontrado.'; return; } const data=await resp.json(); setVal('nome', data.razao_social || data.nome_fantasia || fields.nome?.value || ''); if(data.cep)setVal('cep', maskCep(data.cep)); if(data.logradouro)setVal('logradouro', data.logradouro); if(data.numero)setVal('numero', data.numero); if(data.complemento)setVal('complemento', data.complemento); if(data.bairro)setVal('bairro', data.bairro); if(data.municipio)setVal('cidade', data.municipio); if(data.uf)setVal('uf', data.uf); if(cnpjStatus)cnpjStatus.textContent='Dados preenchidos pelo CNPJ. Você pode alterar antes de salvar.'; }catch(e){ if(cnpjStatus)cnpjStatus.textContent='Não foi possível consultar o CNPJ agora.'; } }
        async function buscarCep(){ const cep=(fields.cep?.value||'').replace(/\D/g,''); if(cep.length!==8){ if(cepStatus)cepStatus.textContent='Digite um CEP com 8 números.'; return; } if(cepStatus)cepStatus.textContent='Buscando CEP...'; try{ const resp=await fetch('https://viacep.com.br/ws/'+cep+'/json/'); const data=await resp.json(); if(data.erro){ if(cepStatus)cepStatus.textContent='CEP não encontrado.'; return; } setVal('logradouro', data.logradouro); setVal('bairro', data.bairro); setVal('cidade', data.localidade); setVal('uf', data.uf); if(cepStatus)cepStatus.textContent='Endereço preenchido pelo CEP. Complete número e complemento.'; }catch(e){ if(cepStatus)cepStatus.textContent='Não foi possível consultar o CEP agora.'; } }
        fields.cnpj?.addEventListener('input', function(){ this.value=maskCnpj(this.value); }); fields.cnpj?.addEventListener('blur', buscarCnpj);
        fields.cep?.addEventListener('input', function(){ this.value=maskCep(this.value); }); fields.cep?.addEventListener('blur', buscarCep);
    </script>
<?php vn_ui_shell_end('clientes'); ?>
