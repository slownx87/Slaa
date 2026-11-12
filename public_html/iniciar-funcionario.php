<?php
/* Sessão, cookie e tempo de login centralizados em _vn_security.php. */
require_once __DIR__ . '/_vn_security.php';
vn_session_start();
vn_session_refresh_cookie();

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_vn_ui.php';
require_once __DIR__ . '/_vn_media.php';

if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}

if (!isset($_SESSION['cargo']) || !in_array($_SESSION['cargo'], ['admin', 'funcionario'])) {
    header("Location: login.php");
    exit;
}

$mysqli = null;

if (isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
} elseif (isset($conexao) && $conexao instanceof mysqli) {
    $mysqli = $conexao;
} elseif (defined('DB_HOST')) {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} else {
    die("Erro: conexão com banco não encontrada no config.php");
}

if ($mysqli->connect_errno) {
    die("Erro ao conectar no banco: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
@$mysqli->query("SET time_zone = '-03:00'");

$usuarioId = (int)($_SESSION['id'] ?? $_SESSION['admin_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0);
$usuarioNome = $_SESSION['usuario'] ?? $_SESSION['admin'] ?? $_SESSION['nome'] ?? 'VN System';
$cargo = strtolower(trim((string)($_SESSION['cargo'] ?? '')));
$idTec = (int)($_SESSION['id_tec'] ?? $_SESSION['tecnico_id'] ?? $_SESSION['vendedor_pedido_id'] ?? 0);
if ($idTec <= 0 && $usuarioId > 0) {
    $idTec = $usuarioId;
}


function vn_os_now_sp(): string { return date('Y-m-d H:i:s'); }
function vn_os_today_sp(): string { return date('Y-m-d'); }
function vn_os_time_sp(): string { return date('H:i:s'); }
function vn_os_csrf_token(): string {
    if (empty($_SESSION['vn_os_csrf'])) {
        $_SESSION['vn_os_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['vn_os_csrf'];
}
function vn_os_check_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['vn_os_csrf'] ?? ''), $token)) {
        jsonResponse(['ok' => false, 'msg' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
    }
}
function vn_os_public_token_link_pdf(int $id): string {
    return vn_os_token($id); // _vn_security.php
}
function vn_os_public_base_url_link_pdf(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '' || $scriptDir === '.') $scriptDir = '';
    return $scheme . '://' . $host . $scriptDir;
}

/*
   Modelos de PDF salvos em pdf_templates/*.json (os mesmos do os-modelos-pdf.php).
   Servem para o "Compartilhar link" deixar escolher com qual layout o cliente
   vai abrir a OS, em vez de cair sempre no primeiro modelo da pasta.
*/
function vn_os_modelos_pdf_link(): array {
    $dir = __DIR__ . '/pdf_templates';
    if (!is_dir($dir)) return [];

    $modelos = [];
    foreach ((array)glob($dir . '/*.json') as $arquivo) {
        $id = basename((string)$arquivo, '.json');
        if (!preg_match('/^[a-z0-9_-]{1,48}$/i', $id)) continue;

        $dados = json_decode((string)@file_get_contents($arquivo), true);
        $nome = is_array($dados) ? trim((string)($dados['nome'] ?? '')) : '';
        if ($nome === '') $nome = $id;

        $modelos[] = ['id' => $id, 'nome' => $nome];
    }

    usort($modelos, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $modelos;
}

if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function canAccessVisit(mysqli $db, int $visitaId, string $cargo, int $idTec, int $usuarioId): array {
    $stmt = $db->prepare("SELECT * FROM os_visitas WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $visitaId);
    $stmt->execute();
    $res = $stmt->get_result();
    $visita = $res->fetch_assoc();

    if (!$visita) {
        jsonResponse(['ok' => false, 'msg' => 'Visita não encontrada.'], 404);
    }

    if ($cargo === 'admin') {
        return $visita;
    }

    $ordemLocalId = (int)($visita['ordem_id_local'] ?? 0);

    if ($ordemLocalId > 0 && tableExists($db, 'ordens_servico') && columnExists($db, 'ordens_servico', 'id')) {
        $perms = [];
        $params = [$ordemLocalId];
        $types = 'i';

        foreach (['id_tec', 'tecnico_id', 'tecnico_admin_id', 'vendedor_pedido_id', 'funcionario_id', 'criado_por'] as $colTecPerm) {
            if (columnExists($db, 'ordens_servico', $colTecPerm)) {
                $perms[] = "`$colTecPerm` IN (?, ?)";
                $params[] = $idTec;
                $params[] = $usuarioId;
                $types .= 'ii';
            }
        }

        if ($perms) {
            $sql = "SELECT id FROM ordens_servico WHERE id = ? AND (" . implode(' OR ', $perms) . ") LIMIT 1";
            $check = $db->prepare($sql);
            if ($check) {
                $check->bind_param($types, ...$params);
                $check->execute();
                if ($check->get_result()->fetch_assoc()) {
                    return $visita;
                }
            }
        }
    }

    $funcionarioId = (int)($visita['funcionario_id'] ?? 0);

    if (($ordemLocalId <= 0) && ($funcionarioId === $idTec || $funcionarioId === $usuarioId)) {
        return $visita;
    }

    jsonResponse(['ok' => false, 'msg' => 'Sem permissão para acessar esta OS.'], 403);
}

function addHistory(mysqli $db, int $visitaId, int $usuarioId, string $usuarioNome, string $acao, string $descricao = ''): void {
    $stmt = $db->prepare("
        INSERT INTO os_visita_historico 
        (visita_id, usuario_id, usuario_nome, acao, descricao) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisss", $visitaId, $usuarioId, $usuarioNome, $acao, $descricao);
    $stmt->execute();
}

function uploadBase64Image(string $base64, string $dir, string $prefix): ?string {
    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64, $m)) {
        return null;
    }

    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';

    $data = substr($base64, strpos($base64, ',') + 1);
    $data = base64_decode($data, true);

    if (!$data || strlen($data) < 500 || strlen($data) > 8 * 1024 * 1024) {
        return null;
    }

    if (!@getimagesizefromstring($data)) {
        return null;
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = preg_replace('/[^a-z0-9_\-]/i', '_', $prefix) . '_' . vn_os_now_sp();
    $name = str_replace([' ', ':'], ['_', ''], $name) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $path = rtrim($dir, '/') . '/' . $name;

    if (file_put_contents($path, $data, LOCK_EX) === false) {
        return null;
    }

    return $path;
}


function databaseName(mysqli $db): string {
    $res = $db->query("SELECT DATABASE() AS dbname");
    if (!$res) return '';
    $row = $res->fetch_assoc();
    return (string)($row['dbname'] ?? '');
}

function tableExists(mysqli $db, string $table): bool {
    static $cache = [];
    $key = $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $dbName = databaseName($db);
    if ($dbName === '') return $cache[$key] = false;
    $stmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1");
    $stmt->bind_param("ss", $dbName, $table);
    $stmt->execute();
    return $cache[$key] = (bool)$stmt->get_result()->fetch_assoc();
}

function columnExists(mysqli $db, string $table, string $column): bool {
    // Cache global (e não static) para poder ser atualizado quando criamos a coluna.
    global $VN_OS_COL_CACHE;
    if (!is_array($VN_OS_COL_CACHE ?? null)) $VN_OS_COL_CACHE = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $VN_OS_COL_CACHE)) return $VN_OS_COL_CACHE[$key];
    $dbName = databaseName($db);
    if ($dbName === '') return $VN_OS_COL_CACHE[$key] = false;
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param("sss", $dbName, $table, $column);
    $stmt->execute();
    return $VN_OS_COL_CACHE[$key] = (bool)$stmt->get_result()->fetch_assoc();
}

/*
   Cria a coluna se ela ainda não existir no banco.
   Bancos antigos não têm tipo_servico: sem isso a tela quebrava e a troca de tipo
   era descartada em silêncio.
*/
function ensureColumnVN(mysqli $db, string $table, string $column, string $definition): bool {
    global $VN_OS_COL_CACHE;
    if (!tableExists($db, $table)) return false;
    if (columnExists($db, $table, $column)) return true;

    try {
        $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate column') === false) return false;
    }

    $dbName = databaseName($db);
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param("sss", $dbName, $table, $column);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_assoc();

    if (!is_array($VN_OS_COL_CACHE ?? null)) $VN_OS_COL_CACHE = [];
    $VN_OS_COL_CACHE[$table . '.' . $column] = $existe;

    return $existe;
}

/* Garante as colunas que a tela usa, para não quebrar em bancos antigos. */
function garantirColunasOSVN(mysqli $db): void {
    static $feito = false;
    if ($feito) return;
    $feito = true;

    foreach ([
        ['ordens_servico', 'tipo_servico', 'VARCHAR(120) NULL'],
        ['ordens_servico', 'data_agendamento', 'DATETIME NULL'],
        ['os_visitas', 'tipo_servico', 'VARCHAR(120) NULL'],
        ['os_visitas', 'tag_servico', 'VARCHAR(120) NULL'],
        ['ordens_servico', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'"],   // OS de CFTV ou de serralheria
        ['os_visitas', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'"],
    ] as [$tabela, $coluna, $def]) {
        ensureColumnVN($db, $tabela, $coluna, $def);
    }
}

function firstValue(array $row, array $cols, string $default = ''): string {
    foreach ($cols as $c) {
        if (isset($row[$c]) && trim((string)$row[$c]) !== '') {
            return trim((string)$row[$c]);
        }
    }
    return $default;
}

function normalizeOsStatus(string $status): string {
    $s = mb_strtolower(trim($status), 'UTF-8');
    $s = str_replace(['ã','á','à','â','é','ê','í','ó','ô','õ','ú','ç'], ['a','a','a','a','e','e','i','o','o','o','u','c'], $s);

    if ($s === '') return 'pendente';
    if (str_contains($s, 'rota')) return 'em_rota';
    if (str_contains($s, 'andamento') || str_contains($s, 'execucao') || str_contains($s, 'iniciada')) return 'em_andamento';
    if (str_contains($s, 'final') || str_contains($s, 'conclu') || str_contains($s, 'atendido') || str_contains($s, 'aprovado') || str_contains($s, 'encerr')) return 'concluida';
    if (str_contains($s, 'cancel') || str_contains($s, 'nao realizada') || str_contains($s, 'não realizada')) return 'nao_realizada';
    return 'pendente';
}

function safeDateValue(string $value): ?string {
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return null;
    $ts = strtotime($value);
    if (!$ts) return null;
    return date('Y-m-d H:i:s', $ts);
}

function syncStatusToOrdem(mysqli $db, array $visita, string $statusVisita): void {
    if (!tableExists($db, 'ordens_servico')) return;

    $ordemId = (int)($visita['ordem_id_local'] ?? 0);
    if ($ordemId <= 0 || !columnExists($db, 'ordens_servico', 'id')) return;

    // Grava na tabela principal para aparecer para os outros usuários do site também.
    $statusPedido = match ($statusVisita) {
        'concluida' => 'Atendido',
        'nao_realizada' => 'Cancelado',
        'em_rota', 'em_andamento' => 'Em Andamento',
        default => 'Em Aberto',
    };

    $statusLocal = match ($statusVisita) {
        'concluida' => 'finalizada',
        'nao_realizada' => 'cancelada',
        'em_rota', 'em_andamento' => 'em_andamento',
        default => 'aberta',
    };

    $statusSituacao = match ($statusVisita) {
        'concluida' => 'Concluída',
        'nao_realizada' => 'Não realizada',
        'em_rota', 'em_andamento' => 'Em andamento',
        default => 'Aberta',
    };

    $updates = [];
    $params = [];
    $types = '';
    $agoraSp = vn_os_now_sp();
    $hojeSp = vn_os_today_sp();
    $horaSp = vn_os_time_sp();

    if (columnExists($db, 'ordens_servico', 'status_pedido')) {
        $updates[] = '`status_pedido` = ?';
        $params[] = $statusPedido;
        $types .= 's';
    }

    if (columnExists($db, 'ordens_servico', 'status')) {
        $updates[] = '`status` = ?';
        $params[] = $statusLocal;
        $types .= 's';
    }

    if (columnExists($db, 'ordens_servico', 'status_visita')) {
        $updates[] = '`status_visita` = ?';
        $params[] = $statusVisita;
        $types .= 's';
    }

    if (columnExists($db, 'ordens_servico', 'situacao')) {
        $updates[] = '`situacao` = ?';
        $params[] = $statusSituacao;
        $types .= 's';
    }

    if (in_array($statusVisita, ['concluida', 'nao_realizada'], true)) {
        foreach (['data_finalizacao', 'finalizado_em', 'data_conclusao', 'data_encerramento'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $updates[] = "`$col` = ?";
                $params[] = $agoraSp;
                $types .= 's';
            }
        }

        if (columnExists($db, 'ordens_servico', 'data_realizacao')) {
            $updates[] = '`data_realizacao` = ?';
            $params[] = $hojeSp;
            $types .= 's';
        }

        if (columnExists($db, 'ordens_servico', 'data_realizacao_hora')) {
            $updates[] = '`data_realizacao_hora` = ?';
            $params[] = $horaSp;
            $types .= 's';
        }
    } else {
        // Se a OS voltou para aberta/em andamento, limpa horários de finalização antigos para não bugar os horários.
        foreach (['data_finalizacao', 'finalizado_em', 'data_conclusao', 'data_encerramento', 'data_realizacao'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $updates[] = "`$col` = NULL";
            }
        }

        if (columnExists($db, 'ordens_servico', 'data_realizacao_hora')) {
            $updates[] = '`data_realizacao_hora` = NULL';
        }
    }

    foreach (['atualizado_em', 'updated_at', 'data_atualizacao'] as $col) {
        if (columnExists($db, 'ordens_servico', $col)) {
            $updates[] = "`$col` = ?";
                $params[] = $agoraSp;
                $types .= 's';
        }
    }

    if (!$updates) return;

    $params[] = $ordemId;
    $types .= 'i';

    $sql = 'UPDATE ordens_servico SET ' . implode(', ', $updates) . ' WHERE id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
}

function syncLocalCamposToOrdem(mysqli $db, array $visita, array $dados): void {
    if (!tableExists($db, 'ordens_servico')) return;
    $agoraSp = vn_os_now_sp();

    $ordemId = (int)($visita['ordem_id_local'] ?? 0);
    if ($ordemId <= 0 || !columnExists($db, 'ordens_servico', 'id')) return;

    $comentario = trim((string)($dados['comentario'] ?? ''));
    $pendencias = trim((string)($dados['pendencias'] ?? ''));

    $updates = [];
    $params = [];
    $types = '';

    if ($comentario !== '') {
        foreach (['observacao_interna', 'obs_interno_pedido'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $updates[] = "`$col` = ?";
                $params[] = $comentario;
                $types .= 's';
                break;
            }
        }
    }

    foreach (['pendencias', 'pendencia', 'observacao_pendencia'] as $col) {
        if (columnExists($db, 'ordens_servico', $col)) {
            $updates[] = "`$col` = ?";
            $params[] = $pendencias;
            $types .= 's';
            break;
        }
    }

    foreach (['atualizado_em', 'updated_at', 'data_atualizacao'] as $col) {
        if (columnExists($db, 'ordens_servico', $col)) {
            $updates[] = "`$col` = ?";
                $params[] = $agoraSp;
                $types .= 's';
        }
    }

    if (!$updates) return;

    $params[] = $ordemId;
    $types .= 'i';

    $sql = 'UPDATE ordens_servico SET ' . implode(', ', $updates) . ' WHERE id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
}


function resetarDadosTecnicoNaoRealizada(mysqli $db, array $visita, int $visitaId): void {
    if ($visitaId <= 0) return;

    // Limpa somente o que foi preenchido durante o atendimento técnico.
    // Mantém dados originais da OS: cliente, endereço, descrição/problema, equipamento, garantia e técnico responsável.
    $updatesVisita = [];

    $limparTexto = ['comentario', 'pendencias', 'produtos_materiais'];
    foreach ($limparTexto as $col) {
        if (columnExists($db, 'os_visitas', $col)) {
            $updatesVisita[] = "`$col` = ''";
        }
    }

    $limparNull = [
        'iniciou_rota_em', 'checkin_em', 'checkout_em',
        'checkin_lat', 'checkin_lng', 'checkout_lat', 'checkout_lng',
        'visita_concluida', 'confirmado_zelador', 'restou_pendencia'
    ];
    foreach ($limparNull as $col) {
        if (columnExists($db, 'os_visitas', $col)) {
            $updatesVisita[] = "`$col` = NULL";
        }
    }

    if (columnExists($db, 'os_visitas', 'duracao_segundos')) {
        $updatesVisita[] = '`duracao_segundos` = 0';
    }

    if (columnExists($db, 'os_visitas', 'status_visita')) {
        $updatesVisita[] = "`status_visita` = 'pendente'";
    }

    if (columnExists($db, 'os_visitas', 'atualizado_em')) {
        $updatesVisita[] = '`atualizado_em` = CURRENT_TIMESTAMP';
    }

    if (!empty($updatesVisita)) {
        $sql = 'UPDATE os_visitas SET ' . implode(', ', $updatesVisita) . ' WHERE id = ? LIMIT 1';
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $visitaId);
            $stmt->execute();
        }
    }

    // Remove anexos, fotos/vídeos, produtos, respostas e assinaturas vinculadas ao atendimento.
    foreach (['os_visita_arquivos', 'os_visita_assinaturas', 'os_visita_produtos', 'os_formulario_respostas'] as $tbl) {
        if (tableExists($db, $tbl) && columnExists($db, $tbl, 'visita_id')) {
            $del = $db->prepare("DELETE FROM `$tbl` WHERE visita_id = ?");
            if ($del) {
                $del->bind_param('i', $visitaId);
                $del->execute();
            }
        }
    }

    // Limpa também os campos técnicos espelhados na tabela principal de OS, sem apagar dados da abertura.
    $ordemId = (int)($visita['ordem_id_local'] ?? 0);
    if ($ordemId > 0 && tableExists($db, 'ordens_servico') && columnExists($db, 'ordens_servico', 'id')) {
        $updatesOs = [];

        foreach ([
            'recebimento_ordem',
            'obs_interno_pedido',
            'observacao_interna',
            'laudo_ordem',
            'servico_executado',
            'assinante_nome',
            'assinante_cpf',
            'assinatura_base64',
            'fotos_json',
            'fotos_atendimento',
            'fotos_base64',
            'videos_json',
            'videos_atendimento',
            'pendencias',
            'pendencia',
            'observacao_pendencia'
        ] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $updatesOs[] = "`$col` = ''";
            }
        }

        foreach (['data_inicio', 'inicio_atendimento', 'checkin_em', 'checkout_em', 'data_finalizacao', 'finalizado_em', 'data_conclusao', 'data_encerramento', 'data_realizacao'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $updatesOs[] = "`$col` = NULL";
            }
        }

        if (columnExists($db, 'ordens_servico', 'data_realizacao_hora')) {
            $updatesOs[] = "`data_realizacao_hora` = NULL";
        }

        if (columnExists($db, 'ordens_servico', 'duracao_segundos')) {
            $updatesOs[] = '`duracao_segundos` = 0';
        }

        if (columnExists($db, 'ordens_servico', 'atualizado_em')) {
            $updatesOs[] = '`atualizado_em` = CURRENT_TIMESTAMP';
        }

        if (!empty($updatesOs)) {
            $sqlOs = 'UPDATE ordens_servico SET ' . implode(', ', $updatesOs) . ' WHERE id = ? LIMIT 1';
            $stmtOs = $db->prepare($sqlOs);
            if ($stmtOs) {
                $stmtOs->bind_param('i', $ordemId);
                $stmtOs->execute();
            }
        }
    }
}


function vnClean($v): string {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function lowerSafeVN($v): string {
    $v = vnClean($v);

    if (function_exists('mb_strtolower')) {
        $v = mb_strtolower($v, 'UTF-8');
    } else {
        $v = strtolower($v);
    }

    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c'
    ];

    return strtr($v, $map);
}

function extrairIdsClientesSessaoVN($valor): array {
    $ids = [];

    if (is_array($valor)) {
        foreach ($valor as $item) {
            $ids = array_merge($ids, extrairIdsClientesSessaoVN($item));
        }
    } else {
        $texto = trim((string)$valor);
        if ($texto !== '') {
            foreach (preg_split('/[^0-9]+/', $texto) as $parte) {
                $parte = trim((string)$parte);
                if ($parte !== '' && ctype_digit($parte) && (int)$parte > 0) {
                    $ids[] = (int)$parte;
                }
            }
        }
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $ids;
}

function nomeClienteLocalVN(array $cliente): string {
    return vnClean(firstValue($cliente, ['nome', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente'], 'Cliente #' . (int)($cliente['id'] ?? 0)));
}

function listarClientesNovaOS(mysqli $db, $clienteIdSessaoRaw, string $cargo): array {
    if (!tableExists($db, 'clientes') || !columnExists($db, 'clientes', 'id')) {
        return [];
    }

    $cols = ['id'];
    foreach (['nome', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente', 'status'] as $col) {
        if (columnExists($db, 'clientes', $col)) {
            $cols[] = $col;
        }
    }

    $where = [];
    $params = [];
    $types = '';

    if (columnExists($db, 'clientes', 'status')) {
        $where[] = "(status IS NULL OR status = '' OR LOWER(status) = 'ativo')";
    }

    $idsRestritos = extrairIdsClientesSessaoVN($clienteIdSessaoRaw);
    if ($cargo !== 'admin' && !empty($idsRestritos)) {
        $where[] = "id IN (" . implode(',', array_fill(0, count($idsRestritos), '?')) . ")";
        foreach ($idsRestritos as $id) {
            $params[] = (int)$id;
            $types .= 'i';
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $orderCol = columnExists($db, 'clientes', 'nome') ? 'nome' : 'id';
    $sql = "SELECT `" . implode('`, `', array_unique($cols)) . "` FROM clientes {$whereSql} ORDER BY `{$orderCol}` ASC, id ASC";

    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;
        $out[] = ['id' => $id, 'nome' => nomeClienteLocalVN($r)];
    }
    return $out;
}

function buscarClienteNomeNovaOS(mysqli $db, int $clienteId): string {
    if ($clienteId <= 0 || !tableExists($db, 'clientes') || !columnExists($db, 'clientes', 'id')) {
        return '';
    }

    $cols = ['id'];
    foreach (['nome', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente'] as $col) {
        if (columnExists($db, 'clientes', $col)) $cols[] = $col;
    }

    $sql = "SELECT `" . implode('`, `', array_unique($cols)) . "` FROM clientes WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) return '';
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? nomeClienteLocalVN($row) : '';
}

function montarEnderecoTextoVN(array $endereco): string {
    $logradouro = vnClean(firstValue($endereco, ['endereco', 'endereco_cliente', 'logradouro', 'logradouro_cliente', 'rua', 'rua_cliente', 'referencia_ordem', 'local_atendimento'], ''));
    $numero = vnClean(firstValue($endereco, ['numero', 'numero_cliente'], ''));
    $complemento = vnClean(firstValue($endereco, ['complemento', 'complemento_cliente'], ''));
    $bairro = vnClean(firstValue($endereco, ['bairro', 'bairro_cliente'], ''));
    $cidade = vnClean(firstValue($endereco, ['cidade', 'cidade_cliente'], ''));
    $uf = vnClean(firstValue($endereco, ['uf', 'uf_cliente'], ''));
    $cep = vnClean(firstValue($endereco, ['cep', 'cep_cliente'], ''));
    $referencia = vnClean(firstValue($endereco, ['referencia', 'referencia_cliente'], ''));

    $partes = [];
    if ($logradouro !== '') {
        $linha = $logradouro;
        if ($numero !== '') $linha .= ', ' . $numero;
        if ($complemento !== '') $linha .= ' - ' . $complemento;
        $partes[] = $linha;
    }
    if ($bairro !== '') $partes[] = $bairro;
    if ($cidade !== '') $partes[] = $cidade;
    if ($uf !== '') $partes[] = $uf;
    if ($cep !== '') $partes[] = 'CEP ' . $cep;
    if ($referencia !== '') $partes[] = 'Ref: ' . $referencia;

    return $partes ? implode(' - ', $partes) : 'Endereço não informado';
}

function buscarEnderecosClienteNovaOS(mysqli $db, int $clienteId): array {
    if ($clienteId <= 0 || !tableExists($db, 'enderecos_clientes') || !columnExists($db, 'enderecos_clientes', 'cliente_id')) {
        return [];
    }

    $cols = [];
    foreach (['id', 'cliente_id', 'nome_endereco', 'cep', 'cep_cliente', 'endereco', 'endereco_cliente', 'logradouro', 'logradouro_cliente', 'rua', 'rua_cliente', 'numero', 'numero_cliente', 'complemento', 'complemento_cliente', 'bairro', 'bairro_cliente', 'cidade', 'cidade_cliente', 'uf', 'uf_cliente', 'referencia', 'referencia_cliente', 'status', 'principal'] as $col) {
        if (columnExists($db, 'enderecos_clientes', $col)) {
            $cols[] = $col;
        }
    }

    if (!$cols) return [];

    $whereStatus = '';
    if (columnExists($db, 'enderecos_clientes', 'status')) {
        $whereStatus = " AND (status IS NULL OR status = '' OR LOWER(status) IN ('ativo','1','sim','s')) ";
    }

    $order = [];
    if (columnExists($db, 'enderecos_clientes', 'principal')) $order[] = 'principal DESC';
    if (columnExists($db, 'enderecos_clientes', 'nome_endereco')) $order[] = 'nome_endereco ASC';
    if (columnExists($db, 'enderecos_clientes', 'id')) $order[] = 'id ASC';
    $orderSql = $order ? ('ORDER BY ' . implode(', ', $order)) : '';

    $sql = "SELECT `" . implode('`, `', array_unique($cols)) . "` FROM enderecos_clientes WHERE cliente_id = ? {$whereStatus} {$orderSql}";
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lista = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $label = vnClean(firstValue($row, ['nome_endereco'], ''));
        if ($label === '') $label = !empty($row['principal']) ? 'Endereço principal' : 'Endereço de atendimento';
        $lista[] = [
            'id' => $id,
            'label' => $label,
            'valor' => montarEnderecoTextoVN($row),
        ];
    }
    return $lista;
}


function listarTodosEnderecosBancoVN(mysqli $db): array {
    if (!tableExists($db, 'enderecos_clientes') || !columnExists($db, 'enderecos_clientes', 'cliente_id')) {
        return listarEnderecosFallbackBancoVN($db);
    }

    $enderecoCols = [];
    foreach (['id', 'cliente_id', 'nome_endereco', 'cep', 'cep_cliente', 'endereco', 'endereco_cliente', 'logradouro', 'logradouro_cliente', 'rua', 'rua_cliente', 'numero', 'numero_cliente', 'complemento', 'complemento_cliente', 'bairro', 'bairro_cliente', 'cidade', 'cidade_cliente', 'uf', 'uf_cliente', 'referencia', 'referencia_cliente', 'status', 'principal'] as $col) {
        if (columnExists($db, 'enderecos_clientes', $col)) {
            $enderecoCols[] = "e.`{$col}`";
        }
    }

    if (empty($enderecoCols)) {
        return [];
    }

    $joinClientes = '';
    $nomeExpr = "CONCAT('Cliente #', e.`cliente_id`)";

    if (tableExists($db, 'clientes') && columnExists($db, 'clientes', 'id')) {
        $nomes = [];
        foreach (['nome', 'razao_social', 'razao_cliente', 'fantasia', 'fantasia_cliente'] as $col) {
            if (columnExists($db, 'clientes', $col)) {
                $nomes[] = "NULLIF(TRIM(c.`{$col}`), '')";
            }
        }

        if (!empty($nomes)) {
            $nomeExpr = 'COALESCE(' . implode(', ', $nomes) . ", CONCAT('Cliente #', e.`cliente_id`))";
        }

        $joinClientes = ' LEFT JOIN clientes c ON c.id = e.cliente_id ';
    }

    $where = ' WHERE 1=1 ';
    if (columnExists($db, 'enderecos_clientes', 'status')) {
        $where .= " AND (e.`status` IS NULL OR e.`status` = '' OR LOWER(e.`status`) IN ('ativo','1','sim','s')) ";
    }

    $order = [];
    if ($joinClientes && columnExists($db, 'clientes', 'nome')) $order[] = 'c.`nome` ASC';
    if (columnExists($db, 'enderecos_clientes', 'nome_endereco')) $order[] = 'e.`nome_endereco` ASC';
    if (columnExists($db, 'enderecos_clientes', 'principal')) $order[] = 'e.`principal` DESC';
    if (columnExists($db, 'enderecos_clientes', 'id')) $order[] = 'e.`id` ASC';
    $orderSql = $order ? (' ORDER BY ' . implode(', ', $order)) : '';

    $sql = "SELECT " . implode(', ', array_unique($enderecoCols)) . ", {$nomeExpr} AS cliente_nome_db FROM enderecos_clientes e {$joinClientes} {$where} {$orderSql}";
    $res = $db->query($sql);
    if (!$res) {
        return listarEnderecosFallbackBancoVN($db);
    }

    $lista = [];
    $vistos = [];

    while ($row = $res->fetch_assoc()) {
        $valor = montarEnderecoTextoVN($row);
        $valorLimpo = vnClean($valor);

        if ($valorLimpo === '' || lowerSafeVN($valorLimpo) === 'endereço não informado' || lowerSafeVN($valorLimpo) === 'endereco nao informado') {
            continue;
        }

        $chave = lowerSafeVN(preg_replace('/\s+/', ' ', $valorLimpo));
        if (isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;

        $label = vnClean(firstValue($row, ['nome_endereco'], ''));
        if ($label === '') {
            $label = !empty($row['principal']) ? 'Endereço principal' : 'Endereço de atendimento';
        }

        $lista[] = [
            'id' => (int)($row['id'] ?? 0),
            'cliente_id' => (int)($row['cliente_id'] ?? 0),
            'cliente' => vnClean($row['cliente_nome_db'] ?? 'Cliente'),
            'label' => $label,
            'valor' => $valorLimpo,
        ];
    }

    if (empty($lista)) {
        return listarEnderecosFallbackBancoVN($db);
    }

    return $lista;
}


function tecnicoIdExisteEmTecnicosVN(mysqli $db, int $idTec): bool {
    if ($idTec <= 0) return false;
    if (!tableExists($db, 'tecnicos') || !columnExists($db, 'tecnicos', 'id')) return false;
    $stmt = $db->prepare("SELECT id FROM tecnicos WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $idTec);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function listarEnderecosFallbackBancoVN(mysqli $db): array {
    $lista = [];
    $vistos = [];

    $add = function(array $row, string $cliente = 'Cliente', string $label = 'Endereço') use (&$lista, &$vistos) {
        $valor = montarEnderecoTextoVN($row);
        $valorLimpo = vnClean($valor);
        $lower = lowerSafeVN($valorLimpo);
        if ($valorLimpo === '' || $lower === 'endereço não informado' || $lower === 'endereco nao informado') return;
        $chave = lowerSafeVN(preg_replace('/\s+/', ' ', $valorLimpo));
        if (isset($vistos[$chave])) return;
        $vistos[$chave] = true;
        $lista[] = [
            'id' => (int)($row['id'] ?? 0),
            'cliente_id' => (int)($row['cliente_id'] ?? ($row['id'] ?? 0)),
            'cliente' => vnClean($cliente ?: 'Cliente'),
            'label' => vnClean($label ?: 'Endereço'),
            'valor' => $valorLimpo,
        ];
    };

    if (tableExists($db, 'clientes') && columnExists($db, 'clientes', 'id')) {
        $cols = ['id'];
        foreach (['nome','razao_social','razao_cliente','fantasia','fantasia_cliente','endereco','endereco_cliente','logradouro','logradouro_cliente','rua','rua_cliente','numero','numero_cliente','complemento','complemento_cliente','bairro','bairro_cliente','cidade','cidade_cliente','uf','uf_cliente','cep','cep_cliente','referencia','referencia_cliente'] as $col) {
            if (columnExists($db, 'clientes', $col)) $cols[] = $col;
        }
        if (count($cols) > 1) {
            $sql = "SELECT `" . implode('`, `', array_unique($cols)) . "` FROM clientes ORDER BY id DESC LIMIT 2000";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $add($row, nomeClienteLocalVN($row), 'Cadastro do cliente');
                }
            }
        }
    }

    if (tableExists($db, 'ordens_servico')) {
        $cols = ['id'];
        foreach (['cliente_id','nome_cliente','referencia_ordem','endereco','endereco_cliente','local_atendimento','bairro','cidade','uf','cep'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) $cols[] = $col;
        }
        if (count($cols) > 1) {
            $sql = "SELECT `" . implode('`, `', array_unique($cols)) . "` FROM ordens_servico ORDER BY id DESC LIMIT 2000";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['referencia_ordem']) && empty($row['endereco'])) $row['endereco'] = $row['referencia_ordem'];
                    if (!empty($row['local_atendimento']) && empty($row['endereco'])) $row['endereco'] = $row['local_atendimento'];
                    $add($row, $row['nome_cliente'] ?? 'Cliente', 'Endereço usado em OS');
                }
            }
        }
    }

    return $lista;
}

function gerarCodigoOSVN(): string {
    return 'OS-' . date('Ymd-His') . '-' . random_int(100, 999);
}

function inserirOrdemServicoLocalVN(mysqli $db, array $dados, int $idTec, int $usuarioId, string $usuarioNome): int {
    if (!tableExists($db, 'ordens_servico')) {
        return 0;
    }

    $codigo = $dados['codigo_os'] ?? gerarCodigoOSVN();
    $problema = $dados['problema'] ?? '';
    $observacao = $dados['observacao'] ?? '';

    /*
       Tipo e data vinham fixos: toda OS aberta pelo celular nascia sem tipo
       (nunca aparecia em Preventivas) e agendada para o instante da criação.
       Agora quem abre a OS escolhe, e só cai no padrão se não vier nada.
    */
    $tipoServico = trim((string)($dados['tipo_servico'] ?? '')) ?: 'Corretiva';
    $dataAgendada = trim((string)($dados['data_agendada'] ?? '')) ?: vn_os_now_sp();

    ensureColumnVN($db, 'ordens_servico', 'tipo_servico', 'VARCHAR(120) NULL');
    ensureColumnVN($db, 'ordens_servico', 'linha_servico', "VARCHAR(20) NOT NULL DEFAULT 'cftv'");
    $linhaServico = (($dados['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv';

    $map = [
        'codigo_os' => $codigo,
        'cliente_id' => $dados['cliente_id'] ?? null,
        'endereco_id' => $dados['endereco_id'] ?? null,
        'nome_cliente' => $dados['nome_cliente'] ?? null,
        'titulo' => 'Ordem de Serviço',
        'tipo_servico' => $tipoServico,
        'linha_servico' => $linhaServico,
        'problema_relatado_cliente' => $problema,
        'problema_ordem' => $problema,
        'servico' => $problema,
        'equipamento_ordem' => $dados['equipamento'] ?? '',
        'garantia_ordem' => $dados['garantia'] ?? '',
        'recebimento_ordem' => 'Aberta pelo funcionário',
        'referencia_ordem' => $dados['referencia'] ?? '',
        'obs_pedido' => $observacao,
        'observacao_cliente' => $observacao,
        'obs_interno_pedido' => $observacao,
        'observacao_interna' => $observacao,
        'status' => 'aberta',
        'status_pedido' => 'Em Aberto',
        'prioridade' => $dados['prioridade'] ?? 'normal',
        'data_agendamento' => $dataAgendada,
        'data_agendada' => $dataAgendada,
        'data_abertura' => vn_os_now_sp(),
        'data_pedido' => vn_os_today_sp(),
        'criado_em' => vn_os_now_sp(),
        'criado_por' => $usuarioId,
        'id_tec' => $idTec,
        'tecnico_id' => $idTec,
        'tecnico_admin_id' => $usuarioId,
        'funcionario_id' => $idTec,
        'vendedor_pedido' => $usuarioNome,
        'vendedor_pedido_id' => $idTec,
        'valor_servico' => 0.00,
        'valor_material' => 0.00,
        'valor_total' => 0.00,
        'laudo_ordem' => '',
        'servico_executado' => '',
    ];

    if (isset($map['tecnico_id']) && !tecnicoIdExisteEmTecnicosVN($db, (int)$map['tecnico_id'])) {
        unset($map['tecnico_id']);
    }

    if ((int)$idTec <= 0) {
        foreach (['id_tec', 'funcionario_id', 'vendedor_pedido_id'] as $colSemTecnico) {
            if (isset($map[$colSemTecnico])) unset($map[$colSemTecnico]);
        }
    }

    $insert = [];
    foreach ($map as $col => $val) {
        if (columnExists($db, 'ordens_servico', $col)) {
            $insert[$col] = $val;
        }
    }

    if (!$insert) return 0;

    $cols = array_keys($insert);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO ordens_servico (`" . implode('`, `', $cols) . "`) VALUES ({$placeholders})";

    $params = array_values($insert);
    $types = '';
    foreach ($params as $p) {
        if (is_int($p)) $types .= 'i';
        elseif (is_float($p)) $types .= 'd';
        else $types .= 's';
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Erro ao preparar criação da OS: ' . $db->error);
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception('Erro ao criar OS: ' . $stmt->error);
    }

    return (int)$stmt->insert_id;
}

function syncOrdensServicoParaVisitas(mysqli $db, string $cargo, int $idTec, int $usuarioId, string $usuarioNome): void {
    if (!tableExists($db, 'ordens_servico') || !tableExists($db, 'os_visitas')) return;

    // Bancos antigos podem não ter as colunas que o sync grava.
    garantirColunasOSVN($db);

    $whereParts = [];
    $params = [];
    $types = '';

    if ($cargo !== 'admin') {
        foreach (['id_tec', 'tecnico_id', 'vendedor_pedido_id', 'tecnico_admin_id', 'funcionario_id', 'criado_por'] as $col) {
            if (columnExists($db, 'ordens_servico', $col)) {
                $whereParts[] = "`$col` IN (?, ?)";
                $params[] = $idTec;
                $params[] = $usuarioId;
                $types .= 'ii';
            }
        }
    }

    $orderCol = columnExists($db, 'ordens_servico', 'id') ? 'id' : (columnExists($db, 'ordens_servico', 'criado_em') ? 'criado_em' : '1');
    $whereSql = $whereParts ? ('WHERE ' . implode(' OR ', $whereParts)) : '';
    $sql = "SELECT * FROM ordens_servico $whereSql ORDER BY `$orderCol` DESC LIMIT 300";
    if ($orderCol === '1') $sql = "SELECT * FROM ordens_servico $whereSql LIMIT 300";

    $stmt = $db->prepare($sql);
    if (!$stmt) return;
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $os) {
        $ordemLocalId = (int)($os['id'] ?? 0);
        $ordemApi = firstValue($os, ['id_pedido', 'id_ordem', 'codigo_os', 'numero_os'], $ordemLocalId > 0 ? (string)$ordemLocalId : '');
        if ($ordemLocalId <= 0 && $ordemApi === '') continue;

        $numeroOs = firstValue($os, ['codigo_os', 'numero_os', 'id_pedido', 'id_ordem'], $ordemLocalId > 0 ? 'OS-' . $ordemLocalId : 'OS');
        $clienteId = firstValue($os, ['cliente_id', 'id_cliente'], '');
        $clienteNome = firstValue($os, ['nome_cliente', 'cliente_nome', 'razao_cliente', 'fantasia_cliente', 'cliente'], $clienteId !== '' ? 'Cliente #' . $clienteId : 'Cliente');
        $funcId = (int)firstValue($os, ['id_tec', 'tecnico_id', 'vendedor_pedido_id', 'funcionario_id', 'tecnico_admin_id', 'criado_por'], (string)($idTec ?: $usuarioId));
        $funcNome = firstValue($os, ['vendedor_pedido', 'tecnico_nome', 'nome_tecnico', 'funcionario_nome'], $usuarioNome);
        $tipoServico = firstValue($os, ['tipo_servico', 'servico', 'categoria', 'equipamento_ordem'], 'Ordem de Serviço');
        $tag = firstValue($os, ['prioridade', 'tag_servico', 'status_pedido', 'status'], $tipoServico);
        $endereco = firstValue($os, ['referencia_ordem', 'endereco', 'endereco_cliente', 'local_atendimento'], 'Endereço não informado');
        // Usa a mesma lógica da página antiga: a coluna principal é `status` (aberta, em_andamento, finalizada, cancelada).
        // status_pedido fica só como fallback, para não transformar finalizada em aberta quando as colunas estiverem divergentes.
        $statusRaw = firstValue($os, ['status', 'status_visita', 'situacao', 'status_pedido'], 'aberta');
        $status = normalizeOsStatus($statusRaw);
        $data = safeDateValue(firstValue($os, ['data_agendamento', 'data_agendada', 'data_abertura', 'data_pedido', 'data_cad_pedido', 'data_entrega', 'criado_em'], ''));
        $descricao = firstValue($os, ['problema_relatado_cliente', 'problema_ordem', 'titulo', 'servico_executado', 'obs_pedido', 'observacao_cliente', 'observacao_interna'], '');
        $linhaOs = firstValue($os, ['linha_servico'], 'cftv') === 'serralheria' ? 'serralheria' : 'cftv';

        $exist = null;
        if ($ordemLocalId > 0) {
            $st = $db->prepare("SELECT id FROM os_visitas WHERE ordem_id_local = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $ordemLocalId);
                $st->execute();
                $exist = $st->get_result()->fetch_assoc();
            }
        }
        if (!$exist && $ordemApi !== '') {
            $st = $db->prepare("SELECT id FROM os_visitas WHERE ordem_id_api = ? LIMIT 1");
            if ($st) {
                $st->bind_param('s', $ordemApi);
                $st->execute();
                $exist = $st->get_result()->fetch_assoc();
            }
        }

        if ($exist) {
            $vid = (int)$exist['id'];

            $extraFinalizacao = '';
            $extraParams = [];
            $extraTypes = '';
            if (in_array($status, ['concluida', 'nao_realizada'], true)) {
                $agoraSp = vn_os_now_sp();
                $extraFinalizacao = ", checkout_em = COALESCE(checkout_em, ?), duracao_segundos = CASE WHEN checkin_em IS NOT NULL AND (duracao_segundos IS NULL OR duracao_segundos = 0) THEN TIMESTAMPDIFF(SECOND, checkin_em, ?) ELSE duracao_segundos END";
                $extraParams = [$agoraSp, $agoraSp];
                $extraTypes = 'ss';
            }

            $up = $db->prepare("UPDATE os_visitas SET ordem_id_api=?, numero_os=?, cliente_id=?, cliente_nome=?, funcionario_id=?, funcionario_nome=?, titulo='Ordem de Serviço', tipo_servico=?, tag_servico=?, endereco=?, status_visita=?, data_agendada=COALESCE(?, data_agendada), descricao=?, linha_servico=? {$extraFinalizacao} WHERE id=? LIMIT 1");
            if ($up) {
                $paramsUp = [$ordemApi, $numeroOs, $clienteId, $clienteNome, $funcId, $funcNome, $tipoServico, $tag, $endereco, $status, $data, $descricao, $linhaOs];
                $typesUp = 'ssssissssssss';
                if ($extraParams) {
                    $paramsUp = array_merge($paramsUp, $extraParams);
                    $typesUp .= $extraTypes;
                }
                $paramsUp[] = $vid;
                $typesUp .= 'i';
                $up->bind_param($typesUp, ...$paramsUp);
                $up->execute();
            }
        } else {
            $in = $db->prepare("INSERT INTO os_visitas (ordem_id_local, ordem_id_api, numero_os, cliente_id, cliente_nome, funcionario_id, funcionario_nome, titulo, tipo_servico, tag_servico, endereco, status_visita, data_agendada, descricao, linha_servico) VALUES (?, ?, ?, ?, ?, ?, ?, 'Ordem de Serviço', ?, ?, ?, ?, ?, ?, ?)");
            if ($in) {
                $in->bind_param('issssissssssss', $ordemLocalId, $ordemApi, $numeroOs, $clienteId, $clienteNome, $funcId, $funcNome, $tipoServico, $tag, $endereco, $status, $data, $descricao, $linhaOs);
                $in->execute();
            }
        }
    }
}


/* =====================================================
   PDF OS - MODELO VN SYSTEM
===================================================== */
$empresa_pdf = [
    'nome' => 'VN System',
    'cnpj' => '58.181.863/0001-92',
    'telefone' => '(11) 96919-0724',
    'endereco' => 'Rua Ana Maria, 46 - São Bernardo do Campo/SP - CEP 09669-060',
    'logo' => 'vn.png',
];

function pdf_limpar_texto_vn($v) {
    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
    $v = preg_replace('/[ \t]+/', ' ', $v);
    return trim($v);
}

function pdf_nome_arquivo_vn($v) {
    $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$v);
    $v = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $v);
    $v = trim($v, '-');
    return $v !== '' ? $v : 'ordem-servico';
}

function pdf_formatar_data_hora_vn($valor, string $fallback = 'Não informado'): string {
    $valor = trim((string)$valor);

    if (
        $valor === '' ||
        $valor === '0000-00-00' ||
        $valor === '0000-00-00 00:00:00' ||
        strtolower($valor) === 'null'
    ) {
        return $fallback;
    }

    try {
        /*
           Corrige datas do PDF usando sempre fuso de São Paulo.
           Se vier com T/Z da API, converte corretamente para America/Sao_Paulo.
        */
        $tz = new DateTimeZone('America/Sao_Paulo');

        if (preg_match('/Z$|[+-]\d{2}:\d{2}$/', $valor)) {
            $dt = new DateTime($valor);
            $dt->setTimezone($tz);
        } else {
            $valor = str_replace('T', ' ', $valor);
            $dt = new DateTime($valor, $tz);
        }

        $hora = $dt->format('H:i');

        if ($hora === '00:00' && strlen($valor) <= 10) {
            return $dt->format('d/m/Y');
        }

        return $dt->format('d/m/Y') . ' às ' . $dt->format('H:i');
    } catch (Throwable $e) {
        return $valor !== '' ? $valor : $fallback;
    }
}

function pdf_primeira_data_valida_vn(array $row, array $cols, string $fallback = 'Não informado'): string {
    foreach ($cols as $col) {
        if (isset($row[$col]) && trim((string)$row[$col]) !== '') {
            $formatada = pdf_formatar_data_hora_vn((string)$row[$col], '');
            if ($formatada !== '') {
                return $formatada;
            }
        }
    }

    return $fallback;
}

function pdf_assinatura_para_vertical_vn(string $dataUri): string {
    $dataUri = trim($dataUri);

    if ($dataUri === '') {
        return '';
    }

    if (!preg_match('/^data:image\/[^;]+;base64,/i', $dataUri)) {
        return $dataUri;
    }

    if (!function_exists('imagecreatefromstring')) {
        return $dataUri;
    }

    $raw = base64_decode(preg_replace('/^data:image\/[^;]+;base64,/i', '', $dataUri), true);

    if (!$raw) {
        return $dataUri;
    }

    $img = @imagecreatefromstring($raw);

    if (!$img) {
        return $dataUri;
    }

    $w = imagesx($img);
    $h = imagesy($img);

    /*
       As assinaturas da OS são feitas com o celular deitado.
       Para o PDF, a imagem precisa ficar deitada/horizontal dentro da linha de assinatura.
       Versões antigas salvavam a assinatura em pé; por isso, se a imagem vier mais alta que larga,
       ela é girada somente na geração do PDF/link público, sem estragar o arquivo original salvo.
    */
    if ($h > $w) {
        $bg = imagecolorallocate($img, 255, 255, 255);
        $rot = imagerotate($img, 90, $bg);

        if ($rot) {
            imagedestroy($img);
            $img = $rot;
        }
    }

    $nw = imagesx($img);
    $nh = imagesy($img);

    $canvas = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $nw, $nh);

    ob_start();
    imagejpeg($canvas, null, 90);
    $jpeg = ob_get_clean();

    imagedestroy($canvas);
    imagedestroy($img);

    return $jpeg ? 'data:image/jpeg;base64,' . base64_encode($jpeg) : $dataUri;
}

function pdf_img_para_datauri_jpeg_vn($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    $possiveis = [];
    if (preg_match('~^(/|[A-Za-z]:[\\\\/])~', $path)) $possiveis[] = $path;
    else {
        $possiveis[] = __DIR__ . '/' . ltrim($path, '/');
        $possiveis[] = getcwd() . '/' . ltrim($path, '/');
    }
    $arquivo = '';
    foreach ($possiveis as $p) { if (is_file($p)) { $arquivo = $p; break; } }
    if ($arquivo === '') return '';
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    if (!function_exists('imagecreatetruecolor')) {
        if (in_array($ext, ['jpg','jpeg'], true)) {
            $bin = @file_get_contents($arquivo);
            return $bin ? 'data:image/jpeg;base64,' . base64_encode($bin) : '';
        }
        return '';
    }
    $img = null;
    if (in_array($ext, ['jpg','jpeg'], true) && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($arquivo);
    elseif ($ext === 'png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($arquivo);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($arquivo);
    elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($arquivo);
    if (!$img) return '';
    $w = imagesx($img); $h = imagesy($img);
    $canvas = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    ob_start(); imagejpeg($canvas, null, 90); $jpeg = ob_get_clean();
    imagedestroy($canvas); imagedestroy($img);
    return $jpeg ? 'data:image/jpeg;base64,' . base64_encode($jpeg) : '';
}

class VNSimplePDFMobile {
    public float $w = 595.28;
    public float $h = 841.89;
    public array $objects = [];
    public array $pages = [];
    public array $current = [];
    public int $imgSeq = 1;
    public function __construct() { $this->addPage(); }
    public function reserveObject(): int { $id = count($this->objects) + 1; $this->objects[$id] = ''; return $id; }
    public function addObject(string $body): int { $id = count($this->objects) + 1; $this->objects[$id] = $body; return $id; }
    public function setObject(int $id, string $body): void { $this->objects[$id] = $body; }
    public function addPage(): void { if (!empty($this->current)) $this->pages[] = $this->current; $this->current = ['content'=>'','xobjects'=>[]]; }
    public function finishPages(): void { if (!empty($this->current)) { $this->pages[] = $this->current; $this->current = []; } }
    public function y(float $top): float { return $this->h - $top; }
    public function escText($text): string { $text = pdf_limpar_texto_vn($text); if (function_exists('iconv')) { $c=@iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',$text); if ($c!==false) $text=$c; } return str_replace(["\\","(",")"],["\\\\","\\(","\\)"],$text); }
    public function cmd(string $cmd): void { $this->current['content'] .= $cmd . "\n"; }
    public function colorStroke(array $rgb): void { $this->cmd(sprintf('%.3f %.3f %.3f RG', $rgb[0]/255, $rgb[1]/255, $rgb[2]/255)); }
    public function colorFill(array $rgb): void { $this->cmd(sprintf('%.3f %.3f %.3f rg', $rgb[0]/255, $rgb[1]/255, $rgb[2]/255)); }
    public function rect(float $x,float $yTop,float $w,float $h,array $stroke=[191,219,254],?array $fill=null): void { if($fill)$this->colorFill($fill); $this->colorStroke($stroke); $this->cmd(sprintf('%.2f %.2f %.2f %.2f re %s',$x,$this->y($yTop+$h),$w,$h,$fill?'B':'S')); }
    public function line(float $x1,float $y1Top,float $x2,float $y2Top,array $rgb=[29,78,216]): void { $this->colorStroke($rgb); $this->cmd(sprintf('%.2f %.2f m %.2f %.2f l S',$x1,$this->y($y1Top),$x2,$this->y($y2Top))); }
    public function text(string $text,float $x,float $yTop,int $size=9,array $rgb=[30,58,138],bool $bold=false): void { $font=$bold?'/F2':'/F1'; $this->colorFill($rgb); $this->cmd(sprintf('BT %s %d Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',$font,$size,$x,$this->y($yTop),$this->escText($text))); }
    public function wrapText(string $text,float $maxWidth,int $size=9): array { $text=pdf_limpar_texto_vn($text); if($text==='') return ['Não informado']; $chars=max(12,(int)floor($maxWidth/($size*.48))); $out=[]; foreach(explode("\n",$text) as $p){ foreach(explode("\n",wordwrap($p,$chars,"\n",true)) as $l)$out[]=$l; } return $out?:['Não informado']; }
    public function multiText(string $text,float $x,float $yTop,float $maxWidth,int $size=9,array $rgb=[30,58,138],bool $bold=false): float { $lines=$this->wrapText($text,$maxWidth,$size); $lh=$size*.55+4; foreach($lines as $i=>$line)$this->text($line,$x,$yTop+($i*$lh),$size,$rgb,$bold); return count($lines)*$lh; }
    public function imageJpeg(string $dataUri,float $x,float $yTop,float $boxW,float $boxH): bool {
        $bin = '';
        if (preg_match('/^data:image\/jpe?g;base64,/i', $dataUri)) {
            $bin = base64_decode(preg_replace('/^data:image\/jpe?g;base64,/i', '', $dataUri), true);
        } elseif (preg_match('/^data:image\/(png|webp|gif);base64,/i', $dataUri)) {
            $raw = base64_decode(preg_replace('/^data:image\/[^;]+;base64,/i', '', $dataUri), true);
            if ($raw && function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring($raw);
                if ($img) {
                    $w = imagesx($img); $h = imagesy($img);
                    $canvas = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
                    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
                    ob_start(); imagejpeg($canvas, null, 90); $bin = ob_get_clean();
                    imagedestroy($canvas); imagedestroy($img);
                }
            }
        }
        if(!$bin) return false;
        $info=@getimagesizefromstring($bin); if(!$info) return false;
        $iw=(float)$info[0]; $ih=(float)$info[1]; $scale=min($boxW/$iw,$boxH/$ih);
        $drawW=$iw*$scale; $drawH=$ih*$scale; $drawX=$x+(($boxW-$drawW)/2); $drawYTop=$yTop+(($boxH-$drawH)/2);
        $obj=$this->addObject("<< /Type /XObject /Subtype /Image /Width {$info[0]} /Height {$info[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($bin)." >>
stream
".$bin."
endstream");
        $name='Im'.$this->imgSeq++; $this->current['xobjects'][$name]=$obj;
        $this->cmd('q'); $this->cmd(sprintf('%.2f 0 0 %.2f %.2f %.2f cm /%s Do',$drawW,$drawH,$drawX,$this->y($drawYTop+$drawH),$name));
        $this->cmd('Q'); return true;
    }
    public function output(): string { $this->finishPages(); $font=$this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'); $bold=$this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'); $pagesId=$this->reserveObject(); $pageIds=[]; foreach($this->pages as $page){ $content=$page['content']; $cid=$this->addObject('<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream'); $xObj=''; if(!empty($page['xobjects'])){ $items=[]; foreach($page['xobjects'] as $name=>$oid)$items[]="/{$name} {$oid} 0 R"; $xObj='/XObject << '.implode(' ',$items).' >>'; } $res="<< /Font << /F1 {$font} 0 R /F2 {$bold} 0 R >> {$xObj} >>"; $pid=$this->addObject("<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$this->w} {$this->h}] /Resources {$res} /Contents {$cid} 0 R >>"); $pageIds[]="{$pid} 0 R"; } $this->setObject($pagesId,'<< /Type /Pages /Kids ['.implode(' ',$pageIds).'] /Count '.count($pageIds).' >>'); $catalog=$this->addObject("<< /Type /Catalog /Pages {$pagesId} 0 R >>"); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs=[0]; foreach($this->objects as $id=>$body){ $offs[$id]=strlen($pdf); $pdf.="{$id} 0 obj\n{$body}\nendobj\n"; } $xref=strlen($pdf); $pdf.='xref\n0 '.(count($this->objects)+1)."\n0000000000 65535 f \n"; for($i=1;$i<=count($this->objects);$i++) $pdf.=sprintf("%010d 00000 n \n",$offs[$i]); return $pdf."trailer\n<< /Size ".(count($this->objects)+1)." /Root {$catalog} 0 R >>\nstartxref\n{$xref}\n%%EOF"; }
}

/* PDF da OS: layout novo, com a marca da área (CFTV ou Serralheria). Se algo falhar, o layout clássico logo abaixo continua valendo. */
function gerar_pdf_visita_vn(array $payload, array $empresa_pdf): string {
    if (is_file(__DIR__ . '/_vn_pdf_docs.php')) {
        try {
            require_once __DIR__ . '/_vn_pdf_docs.php';
            return vn_pdf_os_v6($payload, null, $GLOBALS['mysqli'] ?? null);
        } catch (Throwable $e) {
            error_log('PDF da OS (layout novo) falhou, usando o clássico: ' . $e->getMessage());
        }
    }
    return gerar_pdf_visita_vn_classico($payload, $empresa_pdf);
}

function gerar_pdf_visita_vn_classico(array $payload, array $empresa_pdf): string {
    $pdf = new VNSimplePDFMobile();
    $azul=[29,78,216]; $azul2=[37,99,235]; $azulClaro=[239,246,255]; $texto=[30,58,138]; $linha=[191,219,254];
    $m=36; $pageW=$pdf->w; $pageH=$pdf->h; $y=28;
    $logoData = pdf_img_para_datauri_jpeg_vn($empresa_pdf['logo'] ?? 'vn.png');
    $emitido = date('d/m/Y H:i:s');
    $novaPagina = function() use (&$pdf,&$y,$m,$pageW,$linha,$azul2,$empresa_pdf,$emitido){ $pdf->line($m,820,$pageW-$m,820,$linha); $pdf->text(($empresa_pdf['nome']??'VN System').' - '.($empresa_pdf['telefone']??''),$m,828,7,$azul2); $pdf->text('Gerado em '.$emitido,$pageW-160,828,7,$azul2); $pdf->addPage(); $y=28; };
    $need = function($extra) use (&$y,$pageH,$novaPagina){ if($y+$extra>$pageH-38) $novaPagina(); };
    $section = function($title) use (&$pdf,&$y,$m,$pageW,$azul2,$need){ $need(28); $pdf->rect($m,$y,$pageW-$m*2,18,$azul2,$azul2); $pdf->text(strtoupper($title),$m+8,$y+12,9,[255,255,255],true); $y+=24; };
    $box = function($label,$value,$x,$yy,$w,$h) use (&$pdf,$azulClaro,$linha,$azul2,$texto){ $pdf->rect($x,$yy,$w,$h,$linha,$azulClaro); $pdf->text(strtoupper($label),$x+6,$yy+10,7,$azul2,true); $pdf->multiText((string)$value,$x+6,$yy+22,$w-12,8.5,$texto); };
    $textBlock = function($title,$value) use (&$pdf,&$y,$m,$pageW,$linha,$azul2,$texto,$need){ $lines=$pdf->wrapText($value?:'Não informado',$pageW-$m*2-16,9); $h=max(42,24+count($lines)*12); $need($h+8); $pdf->rect($m,$y,$pageW-$m*2,$h,$linha,null); $pdf->text(strtoupper($title),$m+8,$y+12,7.5,$azul2,true); $pdf->multiText($value?:'Não informado',$m+8,$y+26,$pageW-$m*2-16,9,$texto); $y+=$h+8; };

    $pdf->rect($m,$y,$pageW-$m*2,8,$azul,$azul); $y+=12;
    $pdf->rect($m,$y,$pageW-$m*2,88,$azul2,null);
    $pdf->rect($m+8,$y+12,64,54,$linha,$azulClaro);
    if($logoData) $pdf->imageJpeg($logoData,$m+12,$y+16,56,46); else $pdf->text('VN',$m+27,$y+45,22,$azul,true);
    $pdf->text(strtoupper($empresa_pdf['nome']??'VN System'),$m+84,$y+18,16,$azul,true);
    $pdf->text('Ordem de Serviço Técnica',$m+84,$y+34,10,$texto,true);
    $pdf->text('CNPJ: '.($empresa_pdf['cnpj']??''),$m+84,$y+49,9,$texto);
    $pdf->text('Telefone: '.($empresa_pdf['telefone']??''),$m+84,$y+62,9,$texto);
    $pdf->multiText('Endereço: '.($empresa_pdf['endereco']??''),$m+84,$y+75,230,8.5,$texto);
    $pdf->rect($pageW-$m-128,$y+12,118,62,$linha,$azulClaro);
    $pdf->text('EMITIDO EM',$pageW-$m-120,$y+31,7,$azul2,true);
    $pdf->text($emitido,$pageW-$m-120,$y+49,8,$texto);
    $y+=102;

    $section('1. Dados da Ordem');
    $box('Abertura',$payload['abertura']??'Não informado',$m,$y,118,46);
    $box('Status',$payload['status']??'Não informado',$m+124,$y,118,46);
    $box('Check-in',$payload['checkin']??'Não informado',$m+248,$y,118,46);
    $box('Check-out',$payload['checkout']??'Não informado',$m+372,$y,$pageW-$m*2-372,46); $y+=56;
    $section('2. Cliente e Local de Atendimento');
    $box('Cliente',$payload['cliente']??'Não informado',$m,$y,250,46);
    $box('Técnico',$payload['tecnico']??'Não informado',$m+260,$y,$pageW-$m*2-260,46); $y+=56;
    $textBlock('Endereço do atendimento',$payload['enderecoCliente']??'Não informado');
    $section('3. Serviço solicitado');
    $textBlock('Problema / serviço previsto',$payload['problema']??'Não informado');
    $textBlock('Anotação do técnico',$payload['comentario']??'Não informado');
    $textBlock('Pendências',$payload['pendencias']??'Não informado');

    $section('4. Fotos do Atendimento');
    $fotos = $payload['fotos'] ?? [];
    if (!is_array($fotos) || empty($fotos)) $textBlock('Fotos','Sem fotos anexadas.');
    else {
        foreach($fotos as $i=>$foto){
            $data = is_array($foto) ? ($foto['data'] ?? '') : '';
            $titulo = is_array($foto) ? ($foto['nome'] ?? ('Foto '.($i+1))) : ('Foto '.($i+1));
            $need(190); $pdf->rect($m,$y,$pageW-$m*2,180,$linha,null); $pdf->text(strtoupper($titulo),$m+8,$y+14,8,$azul2,true);
            if(!$pdf->imageJpeg($data,$m+8,$y+22,$pageW-$m*2-16,148)) $pdf->text('Arquivo não compatível para inserir no PDF.',$m+8,$y+45,9,$texto);
            $y+=190;
        }
    }
    $videos = $payload['videos'] ?? [];
    if (is_array($videos) && !empty($videos)) {
        $section('4.1 Vídeos anexados');
        $textoVideos = [];
        foreach ($videos as $i => $video) {
            $nomeVideo = is_array($video) ? ($video['nome'] ?? ('Vídeo '.($i+1))) : ('Vídeo '.($i+1));
            $arquivoVideo = is_array($video) ? ($video['arquivo'] ?? '') : '';
            $textoVideos[] = ($i+1) . '. ' . $nomeVideo . ($arquivoVideo ? ' - ' . $arquivoVideo : '');
        }
        $textBlock('Vídeos salvos na OS', implode("\n", $textoVideos));
    }
    $section('5. Assinaturas');
    $need(120); $sigW=($pageW-$m*2-20)/2;
    $pdf->rect($m,$y,$sigW,112,$linha,null); $pdf->text('ASSINATURA DO CLIENTE',$m+8,$y+14,7.5,$azul2,true); $pdf->imageJpeg($payload['assinaturaCliente']??'',$m+8,$y+20,$sigW-16,52); $pdf->line($m+12,$y+78,$m+$sigW-12,$y+78,$azul); $pdf->multiText($payload['nomeClienteAss']??'Responsável pelo local',$m+12,$y+91,$sigW-24,8.5,$texto,true);
    $x2=$m+$sigW+20; $pdf->rect($x2,$y,$sigW,112,$linha,null); $pdf->text('ASSINATURA DO TÉCNICO',$x2+8,$y+14,7.5,$azul2,true); $pdf->imageJpeg($payload['assinaturaTecnico']??'',$x2+8,$y+20,$sigW-16,52); $pdf->line($x2+12,$y+78,$x2+$sigW-12,$y+78,$azul); $pdf->multiText($payload['tecnico']??'Técnico VN System',$x2+12,$y+91,$sigW-24,8.5,$texto,true);
    $pdf->line($m,820,$pageW-$m,820,$linha); $pdf->text(($empresa_pdf['nome']??'VN System').' - '.($empresa_pdf['telefone']??''),$m,828,7,$azul2); $pdf->text('Gerado em '.$emitido,$pageW-160,828,7,$azul2);
    return $pdf->output();
}

function consultar_visita_pdf_vn(mysqli $db, int $visitaId, string $cargo, int $idTec, int $usuarioId): array {
    $visita = null;
    $stmt = $db->prepare('SELECT * FROM os_visitas WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $visitaId); $stmt->execute(); $visita = $stmt->get_result()->fetch_assoc();
    if (!$visita) throw new Exception('OS não encontrada.');
    if ($cargo !== 'admin') {
        $permitido = false;
        if ((int)($visita['funcionario_id'] ?? 0) === $idTec || (int)($visita['funcionario_id'] ?? 0) === $usuarioId) $permitido = true;
        $ordemId = (int)($visita['ordem_id_local'] ?? 0);
        if (!$permitido && $ordemId > 0 && tableExists($db,'ordens_servico')) {
            $perms=[]; $params=[$ordemId]; $types='i';
            foreach(['id_tec','tecnico_id','tecnico_admin_id','vendedor_pedido_id','funcionario_id','criado_por'] as $col){ if(columnExists($db,'ordens_servico',$col)){ $perms[]="`$col` IN (?, ?)"; $params[]=$idTec; $params[]=$usuarioId; $types.='ii'; } }
            if($perms){ $sql='SELECT id FROM ordens_servico WHERE id = ? AND ('.implode(' OR ',$perms).') LIMIT 1'; $ch=$db->prepare($sql); $ch->bind_param($types,...$params); $ch->execute(); $permitido=(bool)$ch->get_result()->fetch_assoc(); }
        }
        if (!$permitido) throw new Exception('Sem permissão para gerar PDF desta OS.');
    }
    $arquivos=[]; $res=$db->prepare('SELECT * FROM os_visita_arquivos WHERE visita_id = ? ORDER BY id ASC'); $res->bind_param('i',$visitaId); $res->execute(); $r=$res->get_result(); while($a=$r->fetch_assoc()) $arquivos[]=$a;
    $ass=[]; $res=$db->prepare('SELECT * FROM os_visita_assinaturas WHERE visita_id = ? ORDER BY id ASC'); $res->bind_param('i',$visitaId); $res->execute(); $r=$res->get_result(); while($a=$r->fetch_assoc()) $ass[$a['tipo_assinante']]=$a;
    return [$visita,$arquivos,$ass];
}

if (isset($_GET['baixar_pdf_os'])) {
    try {
        $idPdf = (int)($_GET['baixar_pdf_os'] ?? 0);
        if ($idPdf <= 0) throw new Exception('ID inválido para PDF.');
        [$visitaPdf, $arquivosPdf, $assinaturasPdf] = consultar_visita_pdf_vn($mysqli, $idPdf, $cargo, $idTec, $usuarioId);
        $fotos = [];
        $videos = [];
        foreach ($arquivosPdf as $a) {
            if (($a['tipo'] ?? '') === 'foto') {
                $data = pdf_img_para_datauri_jpeg_vn($a['caminho_arquivo'] ?? '');
                if ($data) $fotos[] = ['nome' => $a['nome_original'] ?? 'Foto', 'data' => $data];
            } elseif (($a['tipo'] ?? '') === 'video') {
                $videos[] = [
                    'nome' => $a['nome_original'] ?? 'Vídeo',
                    'arquivo' => $a['caminho_arquivo'] ?? ''
                ];
            }
        }
        $payload = [
            'numeroOS' => $visitaPdf['numero_os'] ?: ('Atendimento-' . $idPdf),
            'cliente' => $visitaPdf['cliente_nome'] ?? 'Não informado',
            'tecnico' => $visitaPdf['funcionario_nome'] ?? 'Não informado',
            'status' => statusLabelPhpVN($visitaPdf['status_visita'] ?? ''),
            'abertura' => pdf_primeira_data_valida_vn($visitaPdf, ['data_abertura', 'criado_em', 'data_agendada']),
            'checkin' => pdf_formatar_data_hora_vn($visitaPdf['checkin_em'] ?? ''),
            'checkout' => pdf_formatar_data_hora_vn($visitaPdf['checkout_em'] ?? ''),
            'enderecoCliente' => $visitaPdf['endereco'] ?? 'Não informado',
            'problema' => $visitaPdf['descricao'] ?? 'Não informado',
            'comentario' => $visitaPdf['comentario'] ?? 'Não informado',
            'pendencias' => $visitaPdf['pendencias'] ?? 'Não informado',
            'fotos' => $fotos,
            'videos' => $videos,
            'assinaturaCliente' => pdf_assinatura_para_vertical_vn($assinaturasPdf['cliente']['assinatura_base64'] ?? ''),
            'assinaturaTecnico' => pdf_assinatura_para_vertical_vn($assinaturasPdf['tecnico']['assinatura_base64'] ?? ''),
            'nomeClienteAss' => $assinaturasPdf['cliente']['nome_assinante'] ?? '',
            'linha' => (($visitaPdf['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv',
            'tipoServico' => $visitaPdf['tipo_servico'] ?? '',
        ];
        $pdfBin = gerar_pdf_visita_vn($payload, $empresa_pdf);
        $nomeBase = pdf_nome_arquivo_vn($payload['numeroOS']);
        $nome = (stripos($nomeBase, 'OS-') === 0 ? '' : 'OS-') . $nomeBase . '.pdf';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdfBin;
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao gerar PDF: ' . $e->getMessage();
        exit;
    }
}

function statusLabelPhpVN(string $s): string {
    return match ($s) {
        'pendente' => 'Pendente',
        'em_rota' => 'Em rota',
        'em_andamento' => 'Em andamento',
        'concluida' => 'Concluída',
        'nao_realizada' => 'Não realizada',
        'cancelada' => 'Cancelada',
        default => $s ?: 'Não informado',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    vn_os_check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_theme') {
        $tema = $_POST['tema'] ?? 'escuro';
        if (!in_array($tema, ['claro', 'escuro'])) {
            $tema = 'escuro';
        }

        if ($usuarioId > 0 && tableExists($mysqli, 'admins') && columnExists($mysqli, 'admins', 'tema_os')) {
            $stmt = $mysqli->prepare("UPDATE admins SET tema_os = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param("si", $tema, $usuarioId);
            $stmt->execute();
        }

        $_SESSION['tema_os'] = $tema;

        jsonResponse(['ok' => true, 'tema' => $tema]);
    }

    if ($action === 'nova_visita') {
        $clienteId = (int)($_POST['client'] ?? $_POST['cliente_id'] ?? 0);
        $enderecoId = (int)($_POST['endereco_id'] ?? 0);

        $clienteNome = trim($_POST['cliente_nome'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        /*
           O tipo é o que separa Corretiva de Preventiva nas abas. Antes vinha o
           equipamento digitado aqui, então nada era reconhecido como preventiva.
        */
        $tiposValidos = ['Corretiva', 'Preventiva', 'Instalação', 'Vistoria', 'Orçamento'];
        $tipoServico = trim($_POST['tipo_servico'] ?? '');
        if (!in_array($tipoServico, $tiposValidos, true)) $tipoServico = 'Corretiva';

        $descricao = trim($_POST['expectedService'] ?? $_POST['descricao'] ?? '');
        $observacao = trim((string)($_POST['observation'] ?? $_POST['observacao'] ?? ''));
        $equipamento = vnClean($_POST['equipamento'] ?? '');
        $garantia = vnClean($_POST['garantia'] ?? '');
        $priority = (int)($_POST['priority'] ?? 0);
        $linhaServico = (($_POST['linha_servico'] ?? '') === 'serralheria') ? 'serralheria' : 'cftv';   // OS de CFTV ou de serralheria

        if ($clienteId > 0) {
            $nomeLocal = buscarClienteNomeNovaOS($mysqli, $clienteId);
            if ($nomeLocal !== '') {
                $clienteNome = $nomeLocal;
            }

            $enderecosCliente = buscarEnderecosClienteNovaOS($mysqli, $clienteId);
            if ($enderecoId > 0) {
                foreach ($enderecosCliente as $end) {
                    if ((int)$end['id'] === $enderecoId) {
                        $endereco = (string)$end['valor'];
                        break;
                    }
                }
            }

            if ($endereco === '' && !empty($enderecosCliente)) {
                $enderecoId = (int)$enderecosCliente[0]['id'];
                $endereco = (string)$enderecosCliente[0]['valor'];
            }
        }

        if ($clienteNome === '') {
            jsonResponse(['ok' => false, 'msg' => 'Selecione ou informe o cliente.'], 422);
        }

        if ($descricao === '') {
            jsonResponse(['ok' => false, 'msg' => 'Informe o problema ou serviço previsto.'], 422);
        }

        if ($endereco === '') {
            $endereco = 'Endereço não informado';
        }

        $prioridadeTexto = match ($priority) {
            10 => 'urgente',
            1 => 'alta',
            default => 'normal'
        };

        $numeroOs = gerarCodigoOSVN();
        $status = 'pendente';

        // Data/hora combinada com o cliente. Vazio = agora, como era antes.
        $dataAgendada = trim((string)($_POST['data_agendada'] ?? ''));
        if ($dataAgendada !== '') {
            $dtAg = DateTime::createFromFormat('Y-m-d\TH:i', $dataAgendada)
                ?: DateTime::createFromFormat('Y-m-d H:i:s', $dataAgendada);
            if (!$dtAg) {
                jsonResponse(['ok' => false, 'msg' => 'Data de agendamento inválida.'], 422);
            }
            $dataAgendada = $dtAg->format('Y-m-d H:i:s');
        } else {
            $dataAgendada = vn_os_now_sp();
        }

        $ordemLocalId = 0;

        try {
            $ordemLocalId = inserirOrdemServicoLocalVN($mysqli, [
                'codigo_os' => $numeroOs,
                'cliente_id' => $clienteId > 0 ? $clienteId : null,
                'endereco_id' => $enderecoId > 0 ? $enderecoId : null,
                'nome_cliente' => $clienteNome,
                'tipo_servico' => $tipoServico,
                'linha_servico' => $linhaServico,
                'data_agendada' => $dataAgendada,
                'problema' => $descricao,
                'equipamento' => $equipamento,
                'garantia' => $garantia,
                'referencia' => $endereco,
                'observacao' => $observacao,
                'prioridade' => $prioridadeTexto,
            ], $idTec, $usuarioId, $usuarioNome);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'msg' => $e->getMessage()], 500);
        }

        $stmt = $mysqli->prepare("
            INSERT INTO os_visitas 
            (ordem_id_local, ordem_id_api, numero_os, cliente_id, cliente_nome, funcionario_id, funcionario_nome, titulo, tipo_servico, tag_servico, endereco, status_visita, data_agendada, descricao, observacao_interna, linha_servico)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $ordemApi = $ordemLocalId > 0 ? (string)$ordemLocalId : $numeroOs;
        $clienteIdStr = $clienteId > 0 ? (string)$clienteId : '';
        $titulo = 'Ordem de Serviço';
        $tag = $prioridadeTexto;

        $stmt->bind_param(
            "issssissssssssss",
            $ordemLocalId,
            $ordemApi,
            $numeroOs,
            $clienteIdStr,
            $clienteNome,
            $idTec,
            $usuarioNome,
            $titulo,
            $tipoServico,
            $tag,
            $endereco,
            $status,
            $dataAgendada,
            $descricao,
            $observacao,
            $linhaServico
        );

        $stmt->execute();
        $visitaId = $mysqli->insert_id;

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'criou_visita', 'OS criada manualmente.');

        jsonResponse(['ok' => true, 'msg' => 'OS criada com sucesso.', 'visita_id' => $visitaId, 'status_visita' => 'pendente']);
    }

    $visitaId = (int)($_POST['visita_id'] ?? 0);
    if ($visitaId <= 0) {
        jsonResponse(['ok' => false, 'msg' => 'ID da visita inválido.'], 422);
    }

    $visita = canAccessVisit($mysqli, $visitaId, $cargo, $idTec, $usuarioId);

    $acoesQueExigemCheckin = ['salvar_campos', 'upload_arquivo', 'excluir_arquivo', 'salvar_assinatura', 'checkout'];
    if (in_array($action, $acoesQueExigemCheckin, true) && empty($visita['checkin_em'])) {
        jsonResponse(['ok' => false, 'msg' => 'Primeiro faça o check-in desta OS.'], 422);
    }

    /* Alterar o tipo da OS (Corretiva/Preventiva/...) direto no detalhe. */
    if ($action === 'alterar_tipo_os') {
        $tiposValidos = ['Corretiva', 'Preventiva', 'Instalacao', 'Instalação', 'Vistoria', 'Orcamento', 'Orçamento'];
        $novoTipo = trim((string)($_POST['tipo_os'] ?? ''));

        if ($novoTipo === '' || !in_array($novoTipo, $tiposValidos, true)) {
            jsonResponse(['ok' => false, 'msg' => 'Tipo de serviço inválido.'], 422);
        }

        // Cria as colunas se o banco for antigo, senão a alteração seria descartada.
        garantirColunasOSVN($mysqli);

        if (columnExists($mysqli, 'os_visitas', 'tipo_servico')) {
            $st = $mysqli->prepare('UPDATE os_visitas SET tipo_servico = ? WHERE id = ? LIMIT 1');
            $st->bind_param('si', $novoTipo, $visitaId);
            $st->execute();
        }

        // Grava também na ordem, senão a sincronização devolveria o valor antigo.
        $ordemId = (int)($visita['ordem_id_local'] ?? 0);
        if ($ordemId > 0 && tableExists($mysqli, 'ordens_servico') && columnExists($mysqli, 'ordens_servico', 'tipo_servico')) {
            $st = $mysqli->prepare('UPDATE ordens_servico SET tipo_servico = ? WHERE id = ? LIMIT 1');
            $st->bind_param('si', $novoTipo, $ordemId);
            $st->execute();
        }

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'alterou_tipo', 'Tipo alterado para ' . $novoTipo . '.');

        jsonResponse(['ok' => true, 'msg' => 'Tipo alterado.', 'tipo' => $novoTipo, 'visita_id' => $visitaId]);
    }

    if ($action === 'em_rota') {
        $agoraSp = vn_os_now_sp();
        $stmt = $mysqli->prepare("
            UPDATE os_visitas 
            SET status_visita = 'em_rota',
                iniciou_rota_em = IFNULL(iniciou_rota_em, ?)
            WHERE id = ?
        ");
        $stmt->bind_param("si", $agoraSp, $visitaId);
        $stmt->execute();

        syncStatusToOrdem($mysqli, $visita, 'em_rota');
        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'em_rota', 'Funcionário informou que está a caminho.');

        jsonResponse(['ok' => true, 'msg' => 'Marcado como em rota.', 'visita_id' => $visitaId, 'status_visita' => 'em_rota']);
    }

    if ($action === 'checkin') {
        $agoraSp = vn_os_now_sp();
        $latPost = $_POST['lat'] ?? '';
        $lat = $latPost !== '' ? $latPost : null;
        $lngPost = $_POST['lng'] ?? '';
        $lng = $lngPost !== '' ? $lngPost : null;

        $stmt = $mysqli->prepare("
            UPDATE os_visitas 
            SET status_visita = 'em_andamento',
                checkin_em = ?,
                checkin_lat = ?,
                checkin_lng = ?,
                checkout_em = NULL,
                checkout_lat = NULL,
                checkout_lng = NULL,
                duracao_segundos = 0
            WHERE id = ?
        ");
        $stmt->bind_param("sssi", $agoraSp, $lat, $lng, $visitaId);
        $stmt->execute();

        syncStatusToOrdem($mysqli, $visita, 'em_andamento');
        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'checkin', 'Check-in realizado.');

        jsonResponse(['ok' => true, 'msg' => 'Check-in realizado.', 'visita_id' => $visitaId, 'status_visita' => 'em_andamento']);
    }

    if ($action === 'checkout') {
        $statusFinal = $_POST['status_final'] ?? 'concluida';
        if (!in_array($statusFinal, ['concluida', 'nao_realizada'])) {
            $statusFinal = 'concluida';
        }

        if (empty($visita['checkin_em'])) {
            jsonResponse(['ok' => false, 'msg' => 'Faça o check-in antes de dar check-out.'], 422);
        }

        // Quando marcar como NÃO REALIZADA, limpa tudo que foi preenchido pelo técnico
        // e mantém somente os dados originais da OS: cliente, endereço, problema, equipamento etc.
        if ($statusFinal === 'nao_realizada') {
            resetarDadosTecnicoNaoRealizada($mysqli, $visita, $visitaId);
            syncStatusToOrdem($mysqli, $visita, 'pendente');
            addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'nao_realizada_reset', 'OS não realizada: dados técnicos limpos e OS voltou para aberta.');

            jsonResponse([
                'ok' => true,
                'msg' => 'OS não realizada. Dados técnicos limpos e OS voltou para aberta.',
                'visita_id' => $visitaId,
                'status_visita' => 'pendente'
            ]);
        }

        $agoraSp = vn_os_now_sp();
        $latPost = $_POST['lat'] ?? '';
        $lat = $latPost !== '' ? $latPost : null;
        $lngPost = $_POST['lng'] ?? '';
        $lng = $lngPost !== '' ? $lngPost : null;

        $stmt = $mysqli->prepare("
            UPDATE os_visitas
            SET status_visita = ?,
                checkout_em = ?,
                checkout_lat = ?,
                checkout_lng = ?,
                duracao_segundos = TIMESTAMPDIFF(SECOND, checkin_em, ?)
            WHERE id = ?
              AND checkin_em IS NOT NULL
            LIMIT 1
        ");
        $stmt->bind_param("sssssi", $statusFinal, $agoraSp, $lat, $lng, $agoraSp, $visitaId);
        $stmt->execute();

        if ($stmt->affected_rows < 1) {
            jsonResponse(['ok' => false, 'msg' => 'Não foi possível registrar o check-out desta OS.'], 409);
        }

        syncStatusToOrdem($mysqli, $visita, $statusFinal);
        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'checkout', 'Check-out realizado.');

        jsonResponse(['ok' => true, 'msg' => 'Check-out realizado.', 'visita_id' => $visitaId, 'status_visita' => $statusFinal]);
    }


    if ($action === 'excluir_os') {
        $ordemId = (int)($visita['ordem_id_local'] ?? 0);

        foreach (['os_visita_arquivos', 'os_visita_assinaturas', 'os_visita_historico'] as $tbl) {
            if (tableExists($mysqli, $tbl) && columnExists($mysqli, $tbl, 'visita_id')) {
                $del = $mysqli->prepare("DELETE FROM `$tbl` WHERE visita_id = ?");
                if ($del) {
                    $del->bind_param('i', $visitaId);
                    $del->execute();
                }
            }
        }

        $delVisita = $mysqli->prepare("DELETE FROM os_visitas WHERE id = ? LIMIT 1");
        $delVisita->bind_param('i', $visitaId);
        $delVisita->execute();

        if ($ordemId > 0 && tableExists($mysqli, 'ordens_servico') && columnExists($mysqli, 'ordens_servico', 'id')) {
            $delOs = $mysqli->prepare("DELETE FROM ordens_servico WHERE id = ? LIMIT 1");
            if ($delOs) {
                $delOs->bind_param('i', $ordemId);
                $delOs->execute();
            }
        }


        jsonResponse(['ok' => true, 'msg' => 'OS excluída com sucesso.', 'deleted' => true]);
    }

    if ($action === 'salvar_campos') {
        $comentario = trim($_POST['comentario'] ?? '');
        $pendencias = trim($_POST['pendencias'] ?? '');
        $visitaConcluida = $_POST['visita_concluida'] ?? null;
        $confirmadoZelador = $_POST['confirmado_zelador'] ?? null;
        $restouPendencia = $_POST['restou_pendencia'] ?? null;

        foreach (['visitaConcluida', 'confirmadoZelador', 'restouPendencia'] as $var) {
            if (!in_array($$var, ['sim', 'nao', null], true)) {
                $$var = null;
            }
        }

        if ($restouPendencia !== 'sim') {
            $pendencias = '';
        }

        $stmt = $mysqli->prepare("
            UPDATE os_visitas SET
                comentario = ?,
                pendencias = ?,
                visita_concluida = ?,
                confirmado_zelador = ?,
                restou_pendencia = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "sssssi",
            $comentario,
            $pendencias,
            $visitaConcluida,
            $confirmadoZelador,
            $restouPendencia,
            $visitaId
        );
        $stmt->execute();

        syncLocalCamposToOrdem($mysqli, $visita, [
            'comentario' => $comentario,
            'pendencias' => $pendencias,
        ]);

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'salvou_formulario', 'Campos da visita atualizados.');

        jsonResponse(['ok' => true, 'msg' => 'Informações salvas.', 'visita_id' => $visitaId]);
    }

    if ($action === 'upload_arquivo') {
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['ok' => false, 'msg' => 'Arquivo não enviado.'], 422);
        }

        $file = $_FILES['arquivo'];
        $original = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$file['name']));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        $permitidos = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4', 'mov', 'webm', 'm4v', '3gp', 'avi', 'mkv'];
        if (!in_array($ext, $permitidos, true)) {
            jsonResponse(['ok' => false, 'msg' => 'Tipo de arquivo não permitido.'], 422);
        }

        $tamanho = (int)($file['size'] ?? 0);
        if ($tamanho <= 0 || $tamanho > 120 * 1024 * 1024) {
            jsonResponse(['ok' => false, 'msg' => 'Arquivo vazio ou maior que 120 MB.'], 422);
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            }
        }
        $mimesPermitidos = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf', 'application/x-pdf'],
            'mp4' => ['video/mp4', 'application/mp4'],
            'mov' => ['video/quicktime'],
            'webm' => ['video/webm'],
            'm4v' => ['video/x-m4v', 'video/mp4'],
            '3gp' => ['video/3gpp', 'video/3gpp2'],
            'avi' => ['video/x-msvideo'],
            'mkv' => ['video/x-matroska'],
        ];
        if ($mime !== '' && isset($mimesPermitidos[$ext]) && !in_array($mime, $mimesPermitidos[$ext], true)) {
            jsonResponse(['ok' => false, 'msg' => 'Conteúdo do arquivo não confere com a extensão.'], 422);
        }

        $baseDir = __DIR__ . '/uploads/os_visitas/' . $visitaId;
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
            jsonResponse(['ok' => false, 'msg' => 'Não foi possível criar a pasta do arquivo.'], 500);
        }

        $htaccess = $baseDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes
<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|asp|aspx|jsp)$\">
Require all denied
</FilesMatch>
", LOCK_EX);
        }

        $novoNome = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destino = $baseDir . '/' . $novoNome;
        $webPath = 'uploads/os_visitas/' . $visitaId . '/' . $novoNome;

        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            jsonResponse(['ok' => false, 'msg' => 'Falha ao salvar arquivo enviado.'], 500);
        }
        // Foto do celular pesa vários MB: reduz para 1920 px / ~80% de qualidade (só troca se ficar menor).
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { vn_image_optimize($destino); }

        $tipo = 'arquivo';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $tipo = 'foto';
        } elseif (in_array($ext, ['mp4', 'mov', 'webm', 'm4v', '3gp', 'avi', 'mkv'])) {
            $tipo = 'video';
        }

        $stmt = $mysqli->prepare("
            INSERT INTO os_visita_arquivos
            (visita_id, tipo, nome_original, caminho_arquivo, criado_por)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssi", $visitaId, $tipo, $original, $webPath, $usuarioId);
        $stmt->execute();

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'adicionou_arquivo', $original);

        jsonResponse(['ok' => true, 'msg' => 'Arquivo salvo.', 'visita_id' => $visitaId]);
    }

    if ($action === 'excluir_arquivo') {
        $arquivoId = (int)($_POST['arquivo_id'] ?? 0);
        if ($arquivoId <= 0) {
            jsonResponse(['ok' => false, 'msg' => 'Arquivo inválido.'], 422);
        }

        // Só apaga se o arquivo for mesmo desta visita (evita apagar anexo de outra OS).
        $st = $mysqli->prepare('SELECT * FROM os_visita_arquivos WHERE id = ? AND visita_id = ? LIMIT 1');
        $st->bind_param('ii', $arquivoId, $visitaId);
        $st->execute();
        $arq = $st->get_result()->fetch_assoc();
        if (!$arq) {
            jsonResponse(['ok' => false, 'msg' => 'Arquivo não encontrado nesta OS.'], 404);
        }

        $del = $mysqli->prepare('DELETE FROM os_visita_arquivos WHERE id = ? AND visita_id = ? LIMIT 1');
        $del->bind_param('ii', $arquivoId, $visitaId);
        $del->execute();

        // Remove do disco, mas só dentro da pasta de uploads desta visita.
        $rel = (string)($arq['caminho_arquivo'] ?? '');
        if ($rel !== '' && strpos($rel, '..') === false) {
            $baseVisita = realpath(__DIR__ . '/uploads/os_visitas/' . $visitaId);
            $alvo = realpath(__DIR__ . '/' . $rel);
            if ($baseVisita && $alvo && strpos($alvo, $baseVisita) === 0 && is_file($alvo)) {
                @unlink($alvo);
            }
        }

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'excluiu_arquivo', (string)($arq['nome_original'] ?? ''));

        jsonResponse(['ok' => true, 'msg' => 'Arquivo excluído.', 'visita_id' => $visitaId]);
    }

    if ($action === 'salvar_assinatura') {
        $tipo = $_POST['tipo'] ?? '';
        $nome = trim($_POST['nome_assinante'] ?? '');
        $cpf = trim($_POST['cpf_assinante'] ?? '');
        $assinatura = $_POST['assinatura_base64'] ?? '';

        if (!in_array($tipo, ['tecnico', 'cliente'])) {
            jsonResponse(['ok' => false, 'msg' => 'Tipo de assinatura inválido.'], 422);
        }

        if ($assinatura === '') {
            jsonResponse(['ok' => false, 'msg' => 'Assinatura vazia.'], 422);
        }

        $dir = __DIR__ . '/uploads/os_visitas/' . $visitaId . '/assinaturas';
        $pathAbs = uploadBase64Image($assinatura, $dir, 'assinatura_' . $tipo);

        $webPath = null;
        if ($pathAbs) {
            $webPath = str_replace(__DIR__ . '/', '', $pathAbs);
        }

        $stmt = $mysqli->prepare("
            SELECT id FROM os_visita_assinaturas 
            WHERE visita_id = ? AND tipo_assinante = ?
            LIMIT 1
        ");
        $stmt->bind_param("is", $visitaId, $tipo);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();

        if ($existing) {
            $assinadoSp = vn_os_now_sp();
            $assinaturaId = (int)$existing['id'];
            $stmt = $mysqli->prepare("
                UPDATE os_visita_assinaturas SET
                    nome_assinante = ?,
                    cpf_assinante = ?,
                    assinatura_base64 = ?,
                    caminho_imagem = ?,
                    assinado_em = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssssi", $nome, $cpf, $assinatura, $webPath, $assinadoSp, $assinaturaId);
            $stmt->execute();
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO os_visita_assinaturas
                (visita_id, tipo_assinante, nome_assinante, cpf_assinante, assinatura_base64, caminho_imagem)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssss", $visitaId, $tipo, $nome, $cpf, $assinatura, $webPath);
            $stmt->execute();
        }

        addHistory($mysqli, $visitaId, $usuarioId, $usuarioNome, 'assinatura_' . $tipo, 'Assinatura registrada.');

        jsonResponse(['ok' => true, 'msg' => 'Assinatura salva.', 'visita_id' => $visitaId]);
    }

    jsonResponse(['ok' => false, 'msg' => 'Ação inválida.'], 400);
}

$temaAtual = $_SESSION['tema_os'] ?? 'escuro';

if ($usuarioId > 0 && tableExists($mysqli, 'admins') && columnExists($mysqli, 'admins', 'tema_os')) {
    $stmt = $mysqli->prepare("SELECT tema_os FROM admins WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();

    if (!empty($u['tema_os'])) {
        $temaAtual = $u['tema_os'];
    }
}

if (!in_array($temaAtual, ['claro', 'escuro'], true)) {
    $temaAtual = 'escuro';
}
$temaHtml = ($temaAtual === 'claro') ? 'light' : 'dark'; // valor usado por vn-ui (data-theme)

syncOrdensServicoParaVisitas($mysqli, $cargo, $idTec, $usuarioId, $usuarioNome);

/* =====================================================
   FILTRO POR DATA DA OS
   Usa data_agendada da tabela os_visitas.
   Se não tiver data_agendada, usa criado_em como fallback.
===================================================== */

$dataInicioFiltro = trim((string)($_GET['data_inicio'] ?? ''));
$dataFimFiltro = trim((string)($_GET['data_fim'] ?? ''));

if ($dataInicioFiltro !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicioFiltro)) {
    $dataInicioFiltro = '';
}

if ($dataFimFiltro !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFimFiltro)) {
    $dataFimFiltro = '';
}

/*
   Padrão da tela:
   Sem parâmetro de data, mostra automaticamente as OS de HOJE.
   Para ver outro dia/período, usa o seletor de calendário no topo.
*/
$semFiltroDataNaUrl = !isset($_GET['data_inicio']) && !isset($_GET['data_fim']);

if ($semFiltroDataNaUrl) {
    $dataInicioFiltro = date('Y-m-d');
    $dataFimFiltro = date('Y-m-d');
}

$filtroHojeAtivo = ($dataInicioFiltro === date('Y-m-d') && $dataFimFiltro === date('Y-m-d'));

$where = "";
$params = [];
$types = "";

if ($cargo !== 'admin') {
    // Mantém a regra da página antiga: funcionário só vê OS vinculada ao seu técnico.
    // Para evitar visita antiga sincronizada errada, quando houver ordem_id_local a permissão é conferida na ordens_servico.
    $visitLocalPerm = "funcionario_id IN (?, ?)";
    $params[] = $idTec;
    $params[] = $usuarioId;
    $types .= "ii";

    $ordemPerms = [];
    foreach (['id_tec', 'tecnico_id', 'tecnico_admin_id', 'vendedor_pedido_id', 'funcionario_id', 'criado_por'] as $colTecPerm) {
        if (tableExists($mysqli, 'ordens_servico') && columnExists($mysqli, 'ordens_servico', $colTecPerm)) {
            $ordemPerms[] = "os.`$colTecPerm` IN (?, ?)";
            $params[] = $idTec;
            $params[] = $usuarioId;
            $types .= "ii";
        }
    }

    if ($ordemPerms) {
        $where = "WHERE (((ordem_id_local IS NULL OR ordem_id_local = 0) AND {$visitLocalPerm}) OR EXISTS (SELECT 1 FROM ordens_servico os WHERE os.id = os_visitas.ordem_id_local AND (" . implode(' OR ', $ordemPerms) . ")))";
    } else {
        $where = "WHERE {$visitLocalPerm}";
    }
}

/*
   Filtro por data.
   Funciona para admin e funcionário.
   Exemplo:
   ?data_inicio=2026-05-01&data_fim=2026-05-31
*/
$dateWhere = '';

if ($dataInicioFiltro !== '' && $dataFimFiltro !== '') {
    $dateWhere = "DATE(COALESCE(data_agendada, criado_em)) BETWEEN ? AND ?";
    $params[] = $dataInicioFiltro;
    $params[] = $dataFimFiltro;
    $types .= "ss";
} elseif ($dataInicioFiltro !== '') {
    $dateWhere = "DATE(COALESCE(data_agendada, criado_em)) >= ?";
    $params[] = $dataInicioFiltro;
    $types .= "s";
} elseif ($dataFimFiltro !== '') {
    $dateWhere = "DATE(COALESCE(data_agendada, criado_em)) <= ?";
    $params[] = $dataFimFiltro;
    $types .= "s";
}

if ($dateWhere !== '') {
    if ($where === '') {
        $where = "WHERE " . $dateWhere;
    } else {
        $where .= " AND " . $dateWhere;
    }
}

$sql = "
    SELECT *
    FROM os_visitas
    $where
    ORDER BY
        /*
           Prioridade: olha SO a tag de prioridade, nunca a descricao ou o tipo de servico.
           Antes um servico com camera de alta resolucao no texto subia como urgente.
           0 = urgente/emergencia | 1 = encaixe (alta) | 2 = normal.
        */
        CASE
            WHEN LOWER(COALESCE(tag_servico,'')) LIKE '%urgent%' THEN 0
            WHEN LOWER(COALESCE(tag_servico,'')) LIKE '%emerg%' THEN 0
            WHEN LOWER(COALESCE(tag_servico,'')) LIKE '%critic%' THEN 0
            WHEN LOWER(COALESCE(tag_servico,'')) LIKE '%encaixe%' THEN 1
            WHEN LOWER(COALESCE(tag_servico,'')) = 'alta' THEN 1
            WHEN LOWER(COALESCE(tag_servico,'')) LIKE '%prioridade alta%' THEN 1
            ELSE 2
        END,
        CASE status_visita
            WHEN 'em_andamento' THEN 1
            WHEN 'em_rota' THEN 2
            WHEN 'pendente' THEN 3
            WHEN 'concluida' THEN 4
            WHEN 'nao_realizada' THEN 5
            ELSE 9
        END,
        COALESCE(data_agendada, criado_em) DESC
    LIMIT 1000
";

$stmt = $mysqli->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$visitas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$ids = array_column($visitas, 'id');
$arquivosPorVisita = [];
$ajudaPorOrdem = [];
$assinaturasPorVisita = [];

if ($ids) {
    $safeIds = implode(',', array_map('intval', $ids));

    $res = $mysqli->query("SELECT * FROM os_visita_arquivos WHERE visita_id IN ($safeIds) ORDER BY id DESC");
    while ($r = $res->fetch_assoc()) {
        $arquivosPorVisita[$r['visita_id']][] = $r;
    }

    $res = $mysqli->query("SELECT * FROM os_visita_assinaturas WHERE visita_id IN ($safeIds) ORDER BY id DESC");
    while ($r = $res->fetch_assoc()) {
        $assinaturasPorVisita[$r['visita_id']][$r['tipo_assinante']] = $r;
    }

    /* Fotos de orientacao anexadas pelo admin ao gerar a OS (ligadas a ordens_servico). */
    if (tableExists($mysqli, 'os_ajuda_arquivos')) {
        $ordemIds = array_filter(array_map('intval', array_column($visitas, 'ordem_id_local')));
        if ($ordemIds) {
            $safeOrdens = implode(',', $ordemIds);
            $res = $mysqli->query("SELECT * FROM os_ajuda_arquivos WHERE ordem_id IN ($safeOrdens) ORDER BY id ASC");
            while ($r = $res->fetch_assoc()) {
                $ajudaPorOrdem[(int)$r['ordem_id']][] = $r;
            }
        }
    }
}

$visitasJson = [];

foreach ($visitas as $v) {
    $id = (int)$v['id'];
    $v['_arquivos'] = $arquivosPorVisita[$id] ?? [];
    $v['_assinaturas'] = $assinaturasPorVisita[$id] ?? [];
    $v['_ajuda'] = $ajudaPorOrdem[(int)($v['ordem_id_local'] ?? 0)] ?? [];
    $v['_public_token'] = $id > 0 ? vn_os_public_token_link_pdf($id) : '';
    $visitasJson[] = $v;
}

/*
   Resumo do calendário:
   - admin vê todas as OS no calendário
   - funcionário vê somente as OS vinculadas ao técnico/usuário dele
   - usado apenas para pintar os dias, não altera OS
*/
$calendarioResumoOS = [];

try {
    $whereCal = "";
    $paramsCal = [];
    $typesCal = "";

    if ($cargo !== 'admin') {
        $whereCal = "WHERE funcionario_id IN (?, ?)";
        $paramsCal[] = $idTec;
        $paramsCal[] = $usuarioId;
        $typesCal .= "ii";
    }

    if (tableExists($mysqli, 'os_visitas')) {
        $sqlCal = "
            SELECT
                DATE(COALESCE(data_agendada, criado_em)) AS dia,
                COALESCE(status_visita, 'pendente') AS status,
                COUNT(*) AS total
            FROM os_visitas
            $whereCal
            GROUP BY DATE(COALESCE(data_agendada, criado_em)), COALESCE(status_visita, 'pendente')
        ";

        $stmtCal = $mysqli->prepare($sqlCal);

        if ($stmtCal) {
            if ($paramsCal) $stmtCal->bind_param($typesCal, ...$paramsCal);
            $stmtCal->execute();
            $resCal = $stmtCal->get_result();

            while ($rCal = $resCal->fetch_assoc()) {
                $dia = (string)($rCal['dia'] ?? '');
                if ($dia === '') continue;

                if (!isset($calendarioResumoOS[$dia])) {
                    $calendarioResumoOS[$dia] = [
                        'total' => 0,
                        'status' => [],
                    ];
                }

                $statusCal = normalizeOsStatus((string)($rCal['status'] ?? 'pendente'));
                $totalCal = (int)($rCal['total'] ?? 0);

                $calendarioResumoOS[$dia]['total'] += $totalCal;
                $calendarioResumoOS[$dia]['status'][$statusCal] =
                    ($calendarioResumoOS[$dia]['status'][$statusCal] ?? 0) + $totalCal;
            }
        }
    }
} catch (Throwable $e) {
    $calendarioResumoOS = [];
}


$listaClientesNovaOS = [];
$clientesEnderecosNovaOS = [];
try {
    $listaClientesNovaOS = listarClientesNovaOS($mysqli, $_SESSION['cliente_id'] ?? null, $cargo);
    foreach ($listaClientesNovaOS as $cli) {
        $clientesEnderecosNovaOS[(string)$cli['id']] = buscarEnderecosClienteNovaOS($mysqli, (int)$cli['id']);
    }
} catch (Throwable $e) {
    $listaClientesNovaOS = [];
    $clientesEnderecosNovaOS = [];
}

$todosEnderecosBanco = [];
try {
    $todosEnderecosBanco = listarTodosEnderecosBancoVN($mysqli);
} catch (Throwable $e) {
    $todosEnderecosBanco = [];
}

$logoSrc = 'vn.png';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= h($temaHtml) ?>">
<head>
    <?php echo vn_ui_head_tags(['title' => 'Ordens de Serviço', 'noindex' => true, 'icons' => false, 'theme_default' => $temaHtml, 'css' => ['assets/css/os-app-funcionario.css'], 'js' => ['assets/js/vn-download.js']]); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="route-app">
<header class="site-topbar">
    <div class="brand">
        <span>VN System Funcionário</span>
    </div>

    <button class="menu-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')">
        Menu
    </button>
</header>

<div class="site-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">Menu</div>

        <a href="os-funcionario.php" class="side-btn">
            <i class="fas fa-file-circle-plus"></i>
            <span>Gerar OS</span>
        </a>

        <button class="side-btn active" type="button">
            <i class="fas fa-clipboard-list"></i>
            <span>Ordens</span>
        </button>

        <button class="side-btn" type="button" onclick="openNovaOS()">
            <i class="fas fa-plus-circle"></i>
            <span>Nova OS</span>
        </button>

        <button class="side-btn" type="button" onclick="toggleTheme()">
            <i class="fas fa-circle-half-stroke"></i>
            <span>Claro / Escuro</span>
        </button>

        <button class="side-btn" type="button" onclick="reiniciarPagina()">
            <i class="fas fa-rotate-right"></i>
            <span>Reiniciar página</span>
        </button>

        <a href="painel-funcionario.php" class="side-btn">
            <i class="fas fa-arrow-left"></i>
            <span>Voltar</span>
        </a>

        <a href="logout.php" class="side-btn logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Sair</span>
        </a>
    </aside>

    <main class="content">
        <div class="toolbar">
            <input type="hidden" id="dataInicioFiltro" value="<?= h($dataInicioFiltro) ?>">
            <input type="hidden" id="dataFimFiltro" value="<?= h($dataFimFiltro) ?>">

            <section class="app-route-actions">
                <button class="app-route-btn primary" type="button" onclick="bestRoute()">Melhor Rota</button>
                <button class="app-route-btn secondary" type="button" onclick="openFullRoute()">Ver no Google Maps</button>
            </section>

            <section class="app-date-top">
                <div class="app-avatar">
                    <?= h(strtoupper(substr((string)$usuarioNome, 0, 1))) ?>
                </div>

                <button class="app-date-pill" type="button" onclick="abrirCalendarioFiltro('dia')">
                    <span id="tituloFiltroDataTopo">Hoje</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </section>

            <section class="app-search-card">
                <div class="app-search-box">
                    <i class="fas fa-search"></i>
                    <input id="search" placeholder="Buscar visitas" oninput="renderList()">
                </div>

                <button class="app-filter-btn" type="button" onclick="abrirCalendarioFiltro('periodo')">
                    Filtros
                </button>
            </section>

            <div id="subtituloFiltroDataTopo" class="app-date-subtitle" style="display:none;">OS agendadas para hoje</div>

            <div class="calendar-filter-panel" id="calendarFilterPanel" style="display:none;">
                <div class="calendar-filter-head">
                    <div>
                        <strong id="tituloPainelCalendario">Escolher data</strong>
                        <span id="subtituloPainelCalendario">Selecione um dia no calendário</span>
                    </div>

                    <button type="button" onclick="fecharCalendarioFiltro()">×</button>
                </div>

                <div class="calendar-mode-tabs">
                    <button type="button" id="btnModoDia" onclick="trocarModoCalendarioFiltro('dia')">Dia único</button>
                    <button type="button" id="btnModoPeriodo" onclick="trocarModoCalendarioFiltro('periodo')">Período</button>
                </div>

                <div class="range-picked" id="rangePickedText">Selecione uma data.</div>

                <div class="filter-calendar-box">
                    <div class="filter-calendar-nav">
                        <button type="button" onclick="mudarMesFiltro(-1)">‹</button>
                        <strong id="filterCalendarTitle">Mês Ano</strong>
                        <button type="button" onclick="mudarMesFiltro(1)">›</button>
                    </div>

                    <div class="filter-calendar-week">
                        <span>Dom</span>
                        <span>Seg</span>
                        <span>Ter</span>
                        <span>Qua</span>
                        <span>Qui</span>
                        <span>Sex</span>
                        <span>Sáb</span>
                    </div>

                    <div class="filter-calendar-days" id="filterCalendarDays"></div>
                </div>

                <div class="calendar-filter-actions">
                    <button type="button" class="btn" onclick="limparSelecaoCalendario()">Limpar seleção</button>
                    <button type="button" class="btn btn-primary" onclick="aplicarSelecaoCalendario()">Aplicar data</button>
                </div>
            </div>


            <div class="tabs">
                <button class="tab active" data-tab="pendentes" onclick="setTab('pendentes')">Pendentes</button>
                <button class="tab" data-tab="preventivas" onclick="setTab('preventivas')">Preventivas</button>
                <button class="tab" data-tab="concluidas" onclick="setTab('concluidas')">Concluídas</button>
                <button class="tab" data-tab="nao_realizadas" onclick="setTab('nao_realizadas')">Não realizadas</button>
                <button class="tab" data-tab="todos" onclick="setTab('todos')">Todas</button>
            </div>

            <style>
                .linhas-os{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0}
                .linha-chip{display:inline-flex;align-items:center;gap:7px;min-height:40px;padding:0 14px;border-radius:999px;border:1px solid rgba(148,163,184,.45);background:transparent;color:inherit;font:inherit;font-weight:800;font-size:.85rem;cursor:pointer}
                .linha-chip .n{display:inline-block;min-width:22px;padding:1px 7px;border-radius:999px;background:rgba(148,163,184,.25);font-size:.72rem;text-align:center}
                .linha-chip.active{border-color:#3b82f6;background:rgba(59,130,246,.16);box-shadow:0 0 0 2px rgba(59,130,246,.25)}
                .linha-chip.serr.active{border-color:#f59e0b;background:rgba(245,158,11,.18);box-shadow:0 0 0 2px rgba(245,158,11,.28)}
                .tipo-badge.serralheria{background:rgba(245,158,11,.2);color:#b45309;border-color:rgba(245,158,11,.5)}
            </style>
            <div class="linhas-os" role="group" aria-label="Tipo da OS">
                <button type="button" class="linha-chip active" data-linha="" onclick="setLinha('')">Todas as áreas <span class="n" data-linha-n="">0</span></button>
                <button type="button" class="linha-chip" data-linha="cftv" onclick="setLinha('cftv')"><i class="fas fa-video"></i> CFTV <span class="n" data-linha-n="cftv">0</span></button>
                <button type="button" class="linha-chip serr" data-linha="serralheria" onclick="setLinha('serralheria')"><i class="fas fa-hammer"></i> Serralheria <span class="n" data-linha-n="serralheria">0</span></button>
            </div>
        </div>

        <div class="os-shell">
            <section class="os-panel list-panel">
                <div class="route-actions">
                    <button class="btn btn-primary" onclick="bestRoute()">Melhor Rota</button>
                    <button class="btn btn-soft" onclick="openMapsSelected()">Abrir Maps</button>
                </div>

                <div class="route-line">
                    <div class="route-item">
                        <div class="route-icon"><i class="fas fa-house"></i></div>
                        <div>
                            <div class="route-title">Partida</div>
                            <div class="route-sub">Primeiro Check-in</div>
                        </div>
                    </div>
                </div>

                <div id="visitList" class="visit-list"></div>

                <div class="route-line arrival">
                    <div class="route-item">
                        <div class="route-icon"><i class="far fa-flag"></i></div>
                        <div>
                            <div class="route-title">Chegada</div>
                            <div class="route-sub">Último Check-out</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="os-panel detail-panel">
                <div id="detail" class="detail-scroll">
                    <div class="empty">Selecione uma ordem de serviço.</div>
                </div>
            </section>
        </div>
    </main>
</div>

<nav class="mobile-bottom">
    <button class="active" id="mb-os" onclick="backToList(); setMobileActive('os')"><i class="fas fa-house"></i></button>
    <button id="mb-map" onclick="openAddressList(); setMobileActive('map')"><i class="fas fa-location-dot"></i></button>
    <button class="bottom-plus" id="mb-plus" onclick="openNovaOS(); setMobileActive('plus')">+</button>
    <button id="mb-refresh" onclick="reiniciarPagina(); setMobileActive('refresh')"><i class="fas fa-rotate-right"></i></button>
    <button id="mb-theme" onclick="toggleTheme(); setMobileActive('theme')"><i class="fas fa-circle-half-stroke"></i></button>
</nav>


<div class="signature-landscape" id="signatureFullscreen">
    <div class="signature-landscape-head">
        <div>
            <strong id="signatureFullTitle">Assinatura</strong>
            <small id="signatureFullSubtitle">Assine com o celular deitado. A tela ficará inteira para assinatura.</small>
            <input type="text" id="signatureNomeCliente" class="signature-nome-input" placeholder="Nome de quem está assinando" hidden>
        </div>
        <button class="btn btn-danger" onclick="closeSignatureFullscreen(false)"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="signature-landscape-body">
        <canvas id="signatureCanvasFull"></canvas>
    </div>
    <div class="signature-landscape-actions">
        <button class="btn btn-danger" onclick="clearSignatureFull()">Limpar</button>
        <button class="btn" onclick="closeSignatureFullscreen(false)">Cancelar</button>
        <button class="btn btn-primary" onclick="saveSignatureFull()">Salvar</button>
    </div>
</div>

<div class="toast" id="toast"></div>

<div class="modal" id="modal">
    <div class="modal-card">
        <div class="modal-head">
            <button class="close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
            <h2 id="modalTitle">Modal</h2>
            <span></span>
        </div>
        <div class="modal-progress"><span></span></div>
        <div class="modal-search">
            <div class="modal-search-wrap">
                <i class="fas fa-search"></i>
                <input class="input" placeholder="Buscar campo">
            </div>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>

function aplicarTemaVN(tema, salvar = true) {
    // aceita os nomes antigos (claro/escuro) e os do sistema (light/dark)
    const claro = (tema === 'claro' || tema === 'light');
    const nome = claro ? 'claro' : 'escuro';

    if (window.VN && VN.theme) {
        if (salvar) { VN.theme.set(claro ? 'light' : 'dark'); } else { VN.theme.apply(claro ? 'light' : 'dark'); }
    } else {
        document.documentElement.setAttribute('data-theme', claro ? 'light' : 'dark');
    }

    aplicarClassesTemaVN(claro);
}

function aplicarClassesTemaVN(claro) {
    if (document.body) {
        document.body.classList.remove('theme-claro', 'theme-escuro');
        document.body.classList.add('theme-' + (claro ? 'claro' : 'escuro'));
    }

    const metaTheme = document.querySelector('meta[name="theme-color"]');
    if (metaTheme) {
        metaTheme.setAttribute('content', claro ? '#f5f6fb' : '#050810');
    }
}

// quando o tema muda por outro caminho (outra aba, botão do sistema), mantém as classes do app em dia
// (só classes e cor da barra — NÃO chama VN.theme.apply de novo, senão entra em loop)
window.addEventListener('vn:themechange', function (e) {
    aplicarClassesTemaVN(e.detail && e.detail.theme === 'light');
});

(function () {
    aplicarTemaVN(document.documentElement.getAttribute('data-theme') || 'dark', false);
})();

function trocarLogoVN(img) {
    const tentativas = ['vn.png', './vn.png', '../vn.png', '/vn.png'];
    const atual = Number(img.dataset.try || '0');
    if (atual < tentativas.length) {
        img.dataset.try = String(atual + 1);
        img.src = tentativas[atual] + '?v=' + Date.now();
        return;
    }
    img.style.display = 'none';
    const fallback = img.parentElement ? img.parentElement.querySelector('.brand-fallback') : null;
    if (fallback) fallback.style.display = 'grid';
}

const VN_USUARIO_NOME = <?= json_encode($usuarioNome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const VN_OS_CSRF_TOKEN = <?= json_encode(vn_os_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const VN_PUBLIC_PDF_BASE = <?= json_encode(vn_os_public_base_url_link_pdf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const VN_MODELOS_PDF = <?= json_encode(vn_os_modelos_pdf_link(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const visitas = <?= json_encode($visitasJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const clientesNovaOS = <?= json_encode($listaClientesNovaOS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const clientesEnderecosNovaOS = <?= json_encode($clientesEnderecosNovaOS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const todosEnderecosBanco = <?= json_encode($todosEnderecosBanco, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const calendarioResumoOS = <?= json_encode($calendarioResumoOS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentBancoEnderecos = [];
const urlParams = new URLSearchParams(location.search);
// Faltava 'preventivas' aqui: uma ação na aba Preventivas recarregava a
// página com ?tab=preventivas e essa validação jogava de volta pra Pendentes.
let currentTab = urlParams.get('tab') || sessionStorage.getItem('vn_current_tab') || 'pendentes';
if (!['pendentes', 'concluidas', 'nao_realizadas', 'preventivas', 'todos'].includes(currentTab)) currentTab = 'pendentes';
/* Tipo da OS: '' = todas | cftv | serralheria (o link "OS de Serralheria" do menu abre com ?linha=serralheria) */
let currentLinha = urlParams.get('linha') || sessionStorage.getItem('vn_current_linha') || '';
if (!['', 'cftv', 'serralheria'].includes(currentLinha)) currentLinha = '';
const urlSelectedId = Number(urlParams.get('os') || 0);
const savedSelectedId = Number(sessionStorage.getItem('vn_selected_os') || 0);
const preferredSelectedId = visitas.some(v => Number(v.id) === urlSelectedId) ? urlSelectedId : savedSelectedId;
let selectedId = visitas.some(v => Number(v.id) === preferredSelectedId)
    ? preferredSelectedId
    : (visitas.length ? Number(visitas[0].id) : null);
let signaturePad = null;
let actionRunning = false;

function toast(msg) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2600);
}

function esc(v) {
    if (v === null || v === undefined || v === '') return '-';
    return String(v).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
}


function normalizaTextoUrgenteVN(v) {
    return String(v || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
}

/*
   Nível de prioridade da OS: 'urgente' | 'alta' | 'normal'.
   Olha SOMENTE os campos de prioridade/tag — nunca a descrição ou o tipo de serviço,
   senão "câmera de alta resolução" (serviço normal de CFTV) virava URGENTE.
*/
function nivelPrioridadeOS(v) {
    const texto = normalizaTextoUrgenteVN([
        v.prioridade,
        v.tag_servico,
        v.status_pedido
    ].join(' | '));

    if (
        texto.includes('urgente') ||
        texto.includes('urgencia') ||
        texto.includes('emergencia') ||
        texto.includes('emergencial') ||
        texto.includes('critic')
    ) return 'urgente';

    if (
        texto.includes('encaixe') ||
        texto.includes('prioridade alta') ||
        texto.includes('alta prioridade') ||
        /(^|\| *)alta( *\||$)/.test(texto)
    ) return 'alta';

    return 'normal';
}

function isUrgenteOS(v) {
    return nivelPrioridadeOS(v) === 'urgente';
}

/* Selo de prioridade (vazio quando é normal). 'alta' = o "Encaixe" do cadastro. */
function seloPrioridadeOS(v) {
    const n = nivelPrioridadeOS(v);
    if (n === 'urgente') return '<span class="urgent-badge"><i class="fas fa-triangle-exclamation"></i> URGENTE</span>';
    if (n === 'alta') return '<span class="urgent-badge alta"><i class="fas fa-arrow-up"></i> ENCAIXE</span>';
    return '';
}

/* É manutenção preventiva (programada)? */
function isPreventivaOS(v) {
    const texto = normalizaTextoUrgenteVN([v.tipo_servico, v.tag_servico, v.titulo].join(' | '));
    return texto.includes('preventiv');
}

/* Selo do tipo de serviço: hoje só a preventiva ganha destaque próprio. */
function seloTipoOS(v) {
    let selo = '';
    if (isPreventivaOS(v)) {
        selo += '<span class="tipo-badge preventiva"><i class="fas fa-calendar-check"></i> PREVENTIVA</span>';
    }
    if (String(v.linha_servico || '') === 'serralheria') {
        selo += '<span class="tipo-badge serralheria"><i class="fas fa-hammer"></i> SERRALHERIA</span>';
    }
    return selo;
}

function linhaDaOS(v) { return String(v.linha_servico || '') === 'serralheria' ? 'serralheria' : 'cftv'; }

function setLinha(l) {
    currentLinha = ['cftv', 'serralheria'].includes(l) ? l : '';
    try { sessionStorage.setItem('vn_current_linha', currentLinha); } catch (e) {}
    renderList();
}

function atualizarChipsLinha() {
    const cont = { '': visitas.length, cftv: 0, serralheria: 0 };
    visitas.forEach(v => { cont[linhaDaOS(v)]++; });
    document.querySelectorAll('.linha-chip').forEach(b => {
        b.classList.toggle('active', (b.dataset.linha || '') === currentLinha);
        const n = b.querySelector('[data-linha-n]');
        if (n) n.textContent = cont[b.dataset.linha || ''];
    });
}

function prioridadeOrdenacaoOS(v) {
    const n = nivelPrioridadeOS(v);
    return n === 'urgente' ? 0 : (n === 'alta' ? 1 : 2);
}

function pesoStatusOS(s) {
    if (s === 'em_andamento') return 1;
    if (s === 'em_rota') return 2;
    if (s === 'pendente') return 3;
    if (s === 'concluida') return 4;
    if (s === 'nao_realizada' || s === 'cancelada') return 5;
    return 9;
}

function statusLabel(s) {
    return {
        pendente: 'Pendente', em_rota: 'Em rota', em_andamento: 'Em andamento',
        concluida: 'Concluída', nao_realizada: 'Não realizada', cancelada: 'Cancelada'
    }[s] || s;
}

function fmtData(dt) {
    if (!dt) return '-';
    const d = new Date(String(dt).replace(' ', 'T'));
    if (isNaN(d)) return dt;
    return d.toLocaleDateString('pt-BR') + ' - ' + d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'}).replace(':', 'h');
}

function fmtDia(dt) {
    if (!dt) return 'Hoje';
    const d = new Date(String(dt).replace(' ', 'T'));
    if (isNaN(d)) return 'Hoje';
    return d.toLocaleDateString('pt-BR', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
}

function fmtHora(dt) {
    if (!dt) return '--';
    const d = new Date(String(dt).replace(' ', 'T'));
    if (isNaN(d)) return '--';
    return d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'}).replace(':', 'h');
}

function fmtDuracao(seg) {
    seg = Number(seg || 0);
    if (seg <= 0) return '--';
    const h = Math.floor(seg / 3600);
    const m = Math.floor((seg % 3600) / 60);
    return h > 0 ? `${h}h ${m}min` : `${m}min`;
}

function visitaById(id) {
    return visitas.find(v => Number(v.id) === Number(id));
}

function persistView(showDetail = false) {
    if (selectedId) sessionStorage.setItem('vn_selected_os', String(selectedId));
    sessionStorage.setItem('vn_current_tab', currentTab);
    sessionStorage.setItem('vn_show_detail', showDetail ? '1' : '0');

    const url = new URL(location.href);
    if (selectedId) url.searchParams.set('os', String(selectedId));
    url.searchParams.set('tab', currentTab);
    if (showDetail) url.searchParams.set('detail', '1');
    else url.searchParams.delete('detail');
    history.replaceState(null, '', url.toString());
}

/*
   Salvar qualquer coisa recarrega a página inteira. A OS e a aba já voltavam,
   mas a rolagem não: quem estava no fim da OS era jogado de volta ao topo.
   Guarda a posição antes de recarregar e devolve depois de renderizar.
*/
function vnMarcarRecarga() {
    try {
        const y = window.scrollY || window.pageYOffset || 0;
        sessionStorage.setItem('vn_scroll_y', String(y));
        sessionStorage.setItem('vn_scroll_flag', '1');
    } catch (e) { /* sessionStorage bloqueado: segue sem restaurar */ }
}

function vnRestaurarScroll() {
    let y = 0;
    try {
        if (sessionStorage.getItem('vn_scroll_flag') !== '1') return;
        y = Number(sessionStorage.getItem('vn_scroll_y') || 0);
        sessionStorage.removeItem('vn_scroll_flag');
    } catch (e) { return; }

    if (!(y > 0)) return;

    // Dois frames: o primeiro fecha o layout, o segundo já tem a altura final.
    requestAnimationFrame(() => requestAnimationFrame(() => {
        window.scrollTo(0, y);
    }));
}

function lockMessage(v) {
    if (!v) return 'Selecione uma OS.';
    if (!v.checkin_em) return 'Primeiro faça o check-in desta OS.';
    return '';
}

function canEditVisit(v) {
    const msg = lockMessage(v);
    if (msg) {
        toast(msg);
        return false;
    }
    return true;
}


function reiniciarPagina() {
    sessionStorage.setItem('vn_show_detail', '0');
    sessionStorage.setItem('vn_current_tab', currentTab || 'pendentes');
    const url = new URL(location.href);
    url.searchParams.delete('os');
    url.searchParams.delete('detail');
    url.searchParams.set('tab', currentTab || 'pendentes');
    url.searchParams.set('_t', String(Date.now()));
    location.href = url.toString();
}

function hojeISODate() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function aplicarFiltroDatas() {
    const inicio = document.getElementById('dataInicioFiltro')?.value || '';
    const fim = document.getElementById('dataFimFiltro')?.value || '';

    const url = new URL(location.href);

    if (inicio) url.searchParams.set('data_inicio', inicio);
    else url.searchParams.delete('data_inicio');

    if (fim) url.searchParams.set('data_fim', fim);
    else url.searchParams.delete('data_fim');

    url.searchParams.delete('os');
    url.searchParams.delete('detail');
    url.searchParams.set('tab', currentTab || 'pendentes');

    location.href = url.toString();
}

function filtrarHoje() {
    const hoje = hojeISODate();

    const inicio = document.getElementById('dataInicioFiltro');
    const fim = document.getElementById('dataFimFiltro');

    if (inicio) inicio.value = hoje;
    if (fim) fim.value = hoje;

    aplicarFiltroDatas();
}

function limparFiltroDatas() {
    const url = new URL(location.href);
    url.searchParams.delete('data_inicio');
    url.searchParams.delete('data_fim');
    url.searchParams.delete('os');
    url.searchParams.delete('detail');
    url.searchParams.set('tab', currentTab || 'pendentes');
    location.href = url.toString();
}

function dataKeyVisita(v) {
    const raw = v.data_agendada || v.criado_em || '';
    if (!raw) return '';
    const d = new Date(String(raw).replace(' ', 'T'));
    if (isNaN(d)) return String(raw).slice(0, 10);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function dataTituloVisita(v) {
    const raw = v.data_agendada || v.criado_em || '';
    if (!raw) return 'Sem data';
    const d = new Date(String(raw).replace(' ', 'T'));
    if (isNaN(d)) return 'Sem data';
    return d.toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric'
    });
}

function dataObjVisita(v) {
    const raw = v.data_agendada || v.criado_em || '';
    if (!raw) return null;

    const d = new Date(String(raw).replace(' ', 'T'));
    return isNaN(d) ? null : d;
}

function dataDiaNumeroVisita(v) {
    const d = dataObjVisita(v);
    return d ? String(d.getDate()).padStart(2, '0') : '--';
}

function dataMesCurtoVisita(v) {
    const d = dataObjVisita(v);
    if (!d) return 'S/D';
    return d.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '');
}

function dataSubtituloVisita(v, total) {
    const d = dataObjVisita(v);
    const totalTxt = total === 1 ? '1 OS nesse dia' : `${total} OS nesse dia`;

    if (!d) return totalTxt;

    const hoje = new Date();
    const ontem = new Date();
    ontem.setDate(hoje.getDate() - 1);

    const key = dataKeyVisita(v);
    const hojeKey = hojeISODate();
    const ontemKey =
        ontem.getFullYear() + '-' +
        String(ontem.getMonth() + 1).padStart(2, '0') + '-' +
        String(ontem.getDate()).padStart(2, '0');

    let relativo = '';

    if (key === hojeKey) relativo = 'Hoje';
    else if (key === ontemKey) relativo = 'Ontem';

    return relativo ? `${relativo} · ${totalTxt}` : totalTxt;
}

function contarStatusDoDia(items) {
    const total = items.length;

    const pendentes = items.filter(v => ['pendente', 'em_rota'].includes(v.status_visita)).length;
    const andamento = items.filter(v => v.status_visita === 'em_andamento').length;
    const concluidas = items.filter(v => v.status_visita === 'concluida').length;
    const canceladas = items.filter(v => ['nao_realizada', 'cancelada'].includes(v.status_visita)).length;

    return { total, pendentes, andamento, concluidas, canceladas };
}

function resumoFiltroDatas(total, dataInicio, dataFim) {
    const fmt = (d) => d ? d.split('-').reverse().join('/') : '';

    if (!dataInicio && !dataFim) {
        return '';
    }

    let texto = '';

    if (dataInicio && dataFim && dataInicio === dataFim) {
        texto = `OS do dia <strong>${esc(fmt(dataInicio))}</strong>`;
    } else if (dataInicio && dataFim) {
        texto = `Período <strong>${esc(fmt(dataInicio))}</strong> até <strong>${esc(fmt(dataFim))}</strong>`;
    } else if (dataInicio) {
        texto = `A partir de <strong>${esc(fmt(dataInicio))}</strong>`;
    } else {
        texto = `Até <strong>${esc(fmt(dataFim))}</strong>`;
    }

    return `
        <div class="date-filter-badge">
            <div><i class="fas fa-calendar-days"></i> ${texto}</div>
            <span>${total} ${total === 1 ? 'OS encontrada' : 'OS encontradas'}</span>
        </div>
    `;
}


function setMobileActive(name) {
    document.querySelectorAll('.mobile-bottom button').forEach(b => b.classList.remove('active'));
    const el = document.getElementById('mb-' + name);
    if (el) el.classList.add('active');
}

function setTab(tab) {
    /*
       "Todas" tem que ser literalmente todas as OS do sistema. O problema:
       um filtro de data (o botão "Hoje", ou uma data escolhida no calendário)
       recarrega a página com data_inicio/data_fim na URL, e o SERVIDOR já
       busca só aquele período — sobra pouca coisa em "visitas" pro JS filtrar.
       Trocar de aba sozinho não reverte isso, porque só troca localmente.
       Então "Todas" força um recarregamento sem data_inicio/data_fim, pra
       buscar o total de novo, sempre que um filtro de data estiver ativo.
    */
    if (tab === 'todos') {
        const inicioAtivo = document.getElementById('dataInicioFiltro')?.value || '';
        const fimAtivo = document.getElementById('dataFimFiltro')?.value || '';
        if (inicioAtivo || fimAtivo) {
            const url = new URL(location.href);
            url.searchParams.delete('data_inicio');
            url.searchParams.delete('data_fim');
            url.searchParams.delete('os');
            url.searchParams.delete('detail');
            url.searchParams.set('tab', 'todos');
            location.href = url.toString();
            return;
        }
    }

    currentTab = tab;

    const temaAtualVN = document.documentElement.getAttribute('data-theme') || 'escuro';
    aplicarTemaVN(temaAtualVN, false);

    document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    persistView(document.body.classList.contains('show-detail'));
    renderList();
}


let filtroCalendarioMes = new Date();
let filtroCalendarioModo = 'dia';
let filtroDataInicioSelecionada = '';
let filtroDataFimSelecionada = '';

const mesesFiltroVN = [
    'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
    'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'
];

function parseISODateVN(valor) {
    if (!valor) return null;
    const partes = String(valor).split('-').map(Number);
    if (partes.length !== 3 || !partes[0] || !partes[1] || !partes[2]) return null;
    return new Date(partes[0], partes[1] - 1, partes[2]);
}

function formatISODateVN(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function formatDataBRVN(valor) {
    const d = parseISODateVN(valor);
    return d ? d.toLocaleDateString('pt-BR') : '';
}

function tituloPeriodoTopo() {
    const inicio = document.getElementById('dataInicioFiltro')?.value || '';
    const fim = document.getElementById('dataFimFiltro')?.value || '';
    const hoje = hojeISODate();

    const titulo = document.getElementById('tituloFiltroDataTopo');
    const subtitulo = document.getElementById('subtituloFiltroDataTopo');

    if (!titulo || !subtitulo) return;

    if (inicio === hoje && fim === hoje) {
        titulo.innerText = 'Hoje';
        subtitulo.innerText = 'OS agendadas para hoje';
        return;
    }

    if (inicio && fim && inicio === fim) {
        const d = parseISODateVN(inicio);
        titulo.innerText = d ? d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long' }) : 'Data selecionada';
        subtitulo.innerText = 'OS do dia ' + formatDataBRVN(inicio);
        return;
    }

    if (inicio && fim) {
        titulo.innerText = 'Período selecionado';
        subtitulo.innerText = 'OS de ' + formatDataBRVN(inicio) + ' até ' + formatDataBRVN(fim);
        return;
    }

    if (inicio) {
        titulo.innerText = 'A partir de ' + formatDataBRVN(inicio);
        subtitulo.innerText = 'OS desde a data selecionada';
        return;
    }

    if (fim) {
        titulo.innerText = 'Até ' + formatDataBRVN(fim);
        subtitulo.innerText = 'OS até a data selecionada';
        return;
    }
}

function abrirCalendarioFiltro(modo) {
    filtroCalendarioModo = modo || 'dia';

    const inicioAtual = document.getElementById('dataInicioFiltro')?.value || hojeISODate();
    const fimAtual = document.getElementById('dataFimFiltro')?.value || inicioAtual;

    filtroDataInicioSelecionada = inicioAtual;
    filtroDataFimSelecionada = filtroCalendarioModo === 'dia' ? inicioAtual : fimAtual;

    const base = parseISODateVN(inicioAtual) || new Date();
    filtroCalendarioMes = new Date(base.getFullYear(), base.getMonth(), 1);

    const painel = document.getElementById('calendarFilterPanel');

    if (painel) {
        if (painel.parentElement !== document.body) {
            document.body.appendChild(painel);
        }

        painel.classList.add('is-open');
        painel.style.display = 'block';
        painel.style.zIndex = '2147483647';
    }

    trocarModoCalendarioFiltro(filtroCalendarioModo);
}

function fecharCalendarioFiltro() {
    const painel = document.getElementById('calendarFilterPanel');

    if (painel) {
        painel.classList.remove('is-open');
        painel.style.display = 'none';
    }
}

function trocarModoCalendarioFiltro(modo) {
    filtroCalendarioModo = modo || 'dia';

    const btnDia = document.getElementById('btnModoDia');
    const btnPeriodo = document.getElementById('btnModoPeriodo');
    const titulo = document.getElementById('tituloPainelCalendario');
    const sub = document.getElementById('subtituloPainelCalendario');

    if (btnDia) btnDia.classList.toggle('active', filtroCalendarioModo === 'dia');
    if (btnPeriodo) btnPeriodo.classList.toggle('active', filtroCalendarioModo === 'periodo');

    if (titulo) titulo.innerText = filtroCalendarioModo === 'dia' ? 'Escolher dia' : 'Escolher período';
    if (sub) sub.innerText = filtroCalendarioModo === 'dia'
        ? 'Clique em um dia para ver as OS daquela data'
        : 'Clique na data inicial e depois na data final';

    if (filtroCalendarioModo === 'dia') {
        filtroDataFimSelecionada = filtroDataInicioSelecionada;
    }

    atualizarTextoRange();
    renderCalendarioFiltro();
}

function mudarMesFiltro(direcao) {
    filtroCalendarioMes.setMonth(filtroCalendarioMes.getMonth() + direcao);
    renderCalendarioFiltro();
}

function atualizarTextoRange() {
    const el = document.getElementById('rangePickedText');
    if (!el) return;

    if (filtroCalendarioModo === 'dia') {
        el.innerText = filtroDataInicioSelecionada
            ? 'Dia selecionado: ' + formatDataBRVN(filtroDataInicioSelecionada)
            : 'Selecione um dia.';
        return;
    }

    if (filtroDataInicioSelecionada && filtroDataFimSelecionada) {
        el.innerText = 'Período: ' + formatDataBRVN(filtroDataInicioSelecionada) + ' até ' + formatDataBRVN(filtroDataFimSelecionada);
    } else if (filtroDataInicioSelecionada) {
        el.innerText = 'Data inicial: ' + formatDataBRVN(filtroDataInicioSelecionada) + '. Agora escolha a data final.';
    } else {
        el.innerText = 'Selecione a data inicial.';
    }
}

function selecionarDiaFiltro(key) {
    if (filtroCalendarioModo === 'dia') {
        filtroDataInicioSelecionada = key;
        filtroDataFimSelecionada = key;
        atualizarTextoRange();
        renderCalendarioFiltro();
        return;
    }

    if (!filtroDataInicioSelecionada || (filtroDataInicioSelecionada && filtroDataFimSelecionada)) {
        filtroDataInicioSelecionada = key;
        filtroDataFimSelecionada = '';
    } else {
        if (key < filtroDataInicioSelecionada) {
            filtroDataFimSelecionada = filtroDataInicioSelecionada;
            filtroDataInicioSelecionada = key;
        } else {
            filtroDataFimSelecionada = key;
        }
    }

    atualizarTextoRange();
    renderCalendarioFiltro();
}


function statusCalendarioPorDia(key) {
    const resumo = calendarioResumoOS && calendarioResumoOS[key] ? calendarioResumoOS[key] : null;

    if (!resumo || !Number(resumo.total || 0)) {
        return { total: 0, classe: '', multi: false, cor: '', bg: '', texto: '' };
    }

    const status = resumo.status || {};
    const keys = Object.keys(status).filter(k => Number(status[k] || 0) > 0);

    let classe = 'cal-yellow';
    let cor = '#f59e0b';
    let bg = 'rgba(245,158,11,.24)';
    let texto = 'Abertas/Pendentes';

    if (status.nao_realizada || status.cancelada) {
        classe = 'cal-red';
        cor = '#ef4444';
        bg = 'rgba(239,68,68,.24)';
        texto = 'Canceladas / Não realizadas';
    } else if (status.concluida) {
        classe = 'cal-green';
        cor = '#22c55e';
        bg = 'rgba(34,197,94,.24)';
        texto = 'Finalizadas';
    } else if (status.em_andamento || status.em_rota) {
        classe = 'cal-blue';
        cor = '#3b82f6';
        bg = 'rgba(59,130,246,.24)';
        texto = 'Em rota / Em andamento';
    }

    return {
        total: Number(resumo.total || 0),
        classe,
        multi: keys.length > 1,
        cor,
        bg,
        texto
    };
}


function renderCalendarioFiltro() {
    const title = document.getElementById('filterCalendarTitle');
    const container = document.getElementById('filterCalendarDays');

    if (!title || !container) return;

    const ano = filtroCalendarioMes.getFullYear();
    const mes = filtroCalendarioMes.getMonth();

    title.innerText = mesesFiltroVN[mes] + ' ' + ano;
    container.innerHTML = '';

    const primeiro = new Date(ano, mes, 1);
    const inicio = new Date(ano, mes, 1 - primeiro.getDay());
    const hoje = hojeISODate();

    for (let i = 0; i < 42; i++) {
        const d = new Date(inicio);
        d.setDate(inicio.getDate() + i);

        const key = formatISODateVN(d);
        const resumoDia = statusCalendarioPorDia(key);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'filter-day';

        btn.innerHTML = '<span class="day-num">' + d.getDate() + '</span>' +
            (resumoDia.total > 0 ? '<span class="cal-count">' + resumoDia.total + '</span>' : '');

        if (d.getMonth() !== mes) btn.classList.add('out');
        if (key === hoje) btn.classList.add('today');

        if (resumoDia.total > 0) {
            btn.classList.add('has-os', resumoDia.classe);
            btn.title = resumoDia.total + ' OS neste dia - ' + resumoDia.texto;

            btn.style.setProperty('background', resumoDia.bg, 'important');
            btn.style.setProperty('border-color', resumoDia.cor, 'important');
            btn.style.setProperty('color', resumoDia.cor, 'important');
            btn.style.setProperty('box-shadow', '0 0 0 2px ' + resumoDia.bg, 'important');

            if (resumoDia.multi) btn.classList.add('multi-status');
        }

        if (key === filtroDataInicioSelecionada || key === filtroDataFimSelecionada) {
            btn.classList.add('selected');
        }

        if (
            filtroCalendarioModo === 'periodo' &&
            filtroDataInicioSelecionada &&
            filtroDataFimSelecionada &&
            key > filtroDataInicioSelecionada &&
            key < filtroDataFimSelecionada
        ) {
            btn.classList.add('in-range');
        }

        btn.onclick = function () {
            selecionarDiaFiltro(key);
        };

        container.appendChild(btn);
    }
}

function limparSelecaoCalendario() {
    filtroDataInicioSelecionada = '';
    filtroDataFimSelecionada = '';
    atualizarTextoRange();
    renderCalendarioFiltro();
}

function aplicarSelecaoCalendario() {
    const inicio = filtroDataInicioSelecionada || hojeISODate();
    const fim = filtroCalendarioModo === 'dia'
        ? inicio
        : (filtroDataFimSelecionada || filtroDataInicioSelecionada || inicio);

    const hiddenInicio = document.getElementById('dataInicioFiltro');
    const hiddenFim = document.getElementById('dataFimFiltro');

    if (hiddenInicio) hiddenInicio.value = inicio;
    if (hiddenFim) hiddenFim.value = fim;

    aplicarFiltroDatas();
}

document.addEventListener('DOMContentLoaded', function () {
    tituloPeriodoTopo();
});


function filterVisitas() {
    const q = document.getElementById('search').value.toLowerCase().trim();
    const dataInicio = document.getElementById('dataInicioFiltro')?.value || '';
    const dataFim = document.getElementById('dataFimFiltro')?.value || '';

    return visitas.filter(v => {
        if (currentLinha && linhaDaOS(v) !== currentLinha) return false;
        const s = v.status_visita;
        const dataOs = dataKeyVisita(v);
        let tabOk = true;

        if (currentTab === 'pendentes') {
            tabOk = ['pendente', 'em_rota', 'em_andamento'].includes(s);
            /*
               Pendentes é "o que falta fazer hoje", não "todo pendente que já
               existiu". O topo já mostra "Hoje" por padrão — sem isso, o rótulo
               mentia: a lista continuava trazendo pendente de qualquer data.
               Só ignora esse recorte se o usuário escolheu outra data no filtro.
            */
            if (tabOk && !dataInicio && !dataFim) tabOk = dataOs === hojeISODate();
        }
        else if (currentTab === 'concluidas') tabOk = s === 'concluida';
        else if (currentTab === 'nao_realizadas') tabOk = ['nao_realizada', 'cancelada'].includes(s);
        else if (currentTab === 'preventivas') tabOk = isPreventivaOS(v);

        let dataOk = true;

        if (dataInicio && dataOs && dataOs < dataInicio) dataOk = false;
        if (dataFim && dataOs && dataOs > dataFim) dataOk = false;
        if ((dataInicio || dataFim) && !dataOs) dataOk = false;

        const text = [
            v.numero_os,
            v.cliente_nome,
            v.endereco,
            v.tipo_servico,
            v.tag_servico,
            v.descricao,
            v.comentario,
            fmtData(v.data_agendada)
        ].join(' ').toLowerCase();

        return tabOk && dataOk && (!q || text.includes(q));
    }).sort((a, b) => {
        const pa = prioridadeOrdenacaoOS(a);
        const pb = prioridadeOrdenacaoOS(b);

        if (pa !== pb) return pa - pb;

        const psA = pesoStatusOS(String(a.status_visita || ''));
        const psB = pesoStatusOS(String(b.status_visita || ''));

        if (psA !== psB) return psA - psB;

        const da = new Date(String(a.data_agendada || a.criado_em || '').replace(' ', 'T')).getTime() || 0;
        const db = new Date(String(b.data_agendada || b.criado_em || '').replace(' ', 'T')).getTime() || 0;

        return db - da;
    });
}

function renderList() {
    tituloPeriodoTopo();
    atualizarChipsLinha();

    const list = document.getElementById('visitList');
    const data = filterVisitas();

    if (!data.length) {
        list.innerHTML = `
            <section class="route-timeline">
                <div class="route-node start">
                    <div class="route-icon"><i class="fas fa-house"></i></div>
                    <div>
                        <strong>Partida</strong>
                        <span>Nenhuma OS encontrada</span>
                    </div>
                </div>

                <div class="route-empty-card">
                    <i class="fas fa-calendar-xmark"></i>
                    <strong>Sem visitas para esta data</strong>
                    <span>Escolha outro dia ou período em Filtros.</span>
                </div>

                <div class="route-node end">
                    <div class="route-icon"><i class="far fa-flag"></i></div>
                    <div>
                        <strong>Chegada</strong>
                        <span>Último Check-out</span>
                    </div>
                </div>
            </section>
        `;
        return;
    }

    let html = `
        <section class="route-timeline">
            <div class="route-node start">
                <div class="route-icon"><i class="fas fa-house"></i></div>
                <div>
                    <strong>Partida</strong>
                    <span>Primeiro Check-in <i class="fas fa-chevron-down"></i></span>
                </div>
            </div>
    `;

    data.forEach(v => {
        const isActive = Number(v.id) === Number(selectedId);
        const status = esc(v.status_visita || 'pendente');
        const hora = esc(fmtHora(v.data_agendada || v.criado_em));
        const cliente = esc(v.cliente_nome || 'Cliente');
        const endereco = esc(v.endereco || 'Endereço não informado');
        const tipo = esc(v.tag_servico || v.tipo_servico || 'serviço');
        const problema = esc(v.descricao || v.tipo_servico || 'Não informado');

        html += `
            <article class="route-visit-card ${isActive ? 'active' : ''} ${nivelPrioridadeOS(v) === 'urgente' ? 'urgente' : (nivelPrioridadeOS(v) === 'alta' ? 'prioridade-alta' : '')} ${isPreventivaOS(v) ? 'preventiva' : ''}" onclick="selectVisit(${Number(v.id)})">
                <button class="route-play" type="button" onclick="event.stopPropagation(); selectVisit(${Number(v.id)})">
                    <i class="fas fa-play"></i>
                </button>

                <div class="route-card-body">
                    <div class="route-card-top">
                        <span class="route-status ${status}">${statusLabel(v.status_visita)}</span>
                        ${seloPrioridadeOS(v)}${seloTipoOS(v)}
                        <span class="route-time">${hora}</span>
                    </div>

                    <h2>${cliente}</h2>
                    <p class="route-address">${endereco}</p>

                    <div class="route-tags">
                        <span><i class="fas fa-circle"></i> CLIENTE</span>
                        <em>${tipo}</em>
                    </div>

                    <div class="route-problem">${problema}</div>
                </div>

                <button class="route-map" type="button" onclick="event.stopPropagation(); openMapForVisit(${Number(v.id)})">
                    <i class="far fa-map"></i>
                </button>
            </article>
        `;
    });

    html += `
            <div class="route-node end">
                <div class="route-icon"><i class="far fa-flag"></i></div>
                <div>
                    <strong>Chegada</strong>
                    <span>Último Check-out <i class="fas fa-chevron-up"></i></span>
                </div>
            </div>
        </section>
    `;

    list.innerHTML = html;
}

function selectVisit(id) {
    selectedId = Number(id);
    renderList();
    renderDetail();
    document.body.classList.add('show-detail');
    setMobileActive('os');
    persistView(true);
}

function backToList() {
    document.body.classList.remove('show-detail');
    setMobileActive('os');
    persistView(false);
}

function formProgress(v) {
    const ok = v.visita_concluida && v.confirmado_zelador && v.restou_pendencia;
    return ok ? 1 : 0;
}

function renderDetail() {
    const v = visitaById(selectedId);
    const detail = document.getElementById('detail');
    if (!v) {
        detail.innerHTML = `<div class="empty">Selecione uma ordem de serviço.</div>`;
        return;
    }

    const fotosCount = (v._arquivos || []).length;
    const assTec = v._assinaturas && v._assinaturas.tecnico;
    const assCli = v._assinaturas && v._assinaturas.cliente;
    const formOk = formProgress(v);
    const canEdit = !!v.checkin_em;
    const lockedClass = canEdit ? '' : ' is-disabled';

    detail.innerHTML = `
        <div class="mobile-detail-actions mobile-only">
            <button class="round-icon" onclick="backToList()"><i class="fas fa-arrow-left"></i></button>
            <div style="display:flex;gap:10px">
                <button class="round-icon" onclick="openComentario()"><i class="far fa-pen-to-square"></i></button>
                <button class="round-icon" onclick="toggleTheme()"><i class="fas fa-circle-half-stroke"></i></button>
            </div>
        </div>

        <div class="detail-header">
            <div>
                <button class="btn desktop-back" onclick="backToList()"><i class="fas fa-arrow-left"></i> Voltar</button>
                <div class="mobile-os-line"><strong>Detalhes do atendimento</strong> <span class="status ${esc(v.status_visita)}">${statusLabel(v.status_visita)}</span>
                        ${seloPrioridadeOS(v)}${seloTipoOS(v)}</div>
                <div class="detail-title">Ordem de Serviço</div>
                <div class="os-number">Atendimento</div>
                <div style="margin-top:10px" class="desktop-only-status"><span class="status ${esc(v.status_visita)}">${statusLabel(v.status_visita)}</span>
                        ${seloPrioridadeOS(v)}${seloTipoOS(v)}</div>
            </div>
            <div style="text-align:right" class="desktop-date-box">
                <button class="btn btn-soft" onclick="reiniciarPagina()"><i class="fas fa-rotate-right"></i> Reiniciar</button>
            </div>
        </div>

        <div class="section">
            <h3 class="desktop-client-label">Cliente</h3>
            <div class="mobile-client-title">${esc(v.cliente_nome || 'Cliente')}</div>
            <p style="font-size:1.35rem;font-weight:900;margin:0 0 10px" class="desktop-client-title">${esc(v.cliente_nome)}</p>
            <div class="mobile-address">${esc(v.endereco || 'Endereço não informado')}</div>
            <p class="address desktop-address">${esc(v.endereco || 'Endereço não informado')}</p>
            <div class="tipo-os-box">
                <small>Tipo de serviço</small>
                <select id="tipoOSRapido" onchange="salvarTipoOS(this.value)">
                    ${['Corretiva','Preventiva','Instalação','Vistoria','Orçamento'].map(function(t){
                        const atual = String(v.tipo_servico || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
                        const alvo2 = t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
                        return `<option value="${t}" ${atual.indexOf(alvo2) > -1 ? 'selected' : ''}>${t}</option>`;
                    }).join('')}
                </select>
            </div>
            <div class="problem-box"><small>Problema / serviço previsto</small><p>${esc(v.descricao || v.tipo_servico || 'Não informado')}</p></div>
            ${(v._ajuda && v._ajuda.length) ? `
            <div class="ajuda-box">
                <div class="ajuda-head"><i class="fas fa-circle-info"></i> Fotos de ajuda <span class="ajuda-count">${v._ajuda.length}</span></div>
                <div class="ajuda-grid">
                    ${v._ajuda.map(a => {
                        const p = esc(a.caminho_arquivo);
                        if (a.tipo === 'foto') return `<a class="ajuda-item" href="${p}" target="_blank"><img src="${p}" alt="" loading="lazy"></a>`;
                        return `<a class="ajuda-item is-video" href="${p}" target="_blank"><i class="fas fa-circle-play"></i><span>${esc(a.nome_original || 'Vídeo')}</span></a>`;
                    }).join('')}
                </div>
                <small class="ajuda-nota">Enviadas pelo escritório para orientar o atendimento.</small>
            </div>` : ''}
            <div class="mobile-map-actions mobile-only">
                <button class="btn btn-soft mobile-btn-wide" onclick="openMapsSelected()"><i class="fas fa-map-location-dot"></i> Abrir no app Maps</button>
                <button class="btn btn-soft mobile-btn-wide" onclick="doAction('em_rota')"><i class="fas fa-route"></i> Estou a caminho</button>
            </div>
            <div class="action-grid mobile-hide">
                <button class="btn btn-soft" onclick="openMapsSelected()"><i class="fas fa-map-location-dot"></i> Abrir no app Maps</button>
                <button class="btn btn-primary" onclick="doAction('em_rota')"><i class="fas fa-route"></i> Estou a caminho</button>
                <button class="btn" onclick="openComentario()"><i class="far fa-comment"></i> Anotação</button>
            </div>
        </div>

        <div class="section">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:10px" class="mobile-only">
                <i class="fas fa-clipboard-check" style="color:var(--muted);font-size:1.4rem"></i>
                <div><strong>Atendimento</strong><br><span style="color:var(--muted)">${statusLabel(v.status_visita)}</span></div>
            </div>
            <div class="tags mobile-only" style="margin-bottom:18px"><span class="tag">${esc(v.tag_servico || v.tipo_servico || 'serviço')}</span></div>

            <div class="menu-list mobile-only">
                <div class="menu-item${lockedClass}" onclick="openFormulario()"><div class="menu-left"><i class="fas fa-list"></i> Formulários</div><span>${formOk}/1 <i class="fas fa-chevron-right"></i></span></div>
                <div class="menu-item${lockedClass}" onclick="openFotos()"><div class="menu-left"><i class="far fa-camera"></i> Fotos e vídeos</div><span>${fotosCount} <i class="fas fa-chevron-right"></i></span></div>
                <div class="menu-item${lockedClass}" onclick="openComentario()"><div class="menu-left"><i class="far fa-comment"></i> Anotação</div><span>${v.comentario ? 'OK' : ''} <i class="fas fa-chevron-right"></i></span></div>
                <div class="menu-item${lockedClass}" onclick="openAssinatura()"><div class="menu-left"><i class="fas fa-signature"></i> Assinatura</div><span>${assTec && assCli ? 'OK' : ''} <i class="fas fa-chevron-right"></i></span></div>
                <div class="menu-item" onclick="baixarPdfOS()"><div class="menu-left"><i class="fas fa-file-pdf"></i> Baixar PDF</div><span><i class="fas fa-chevron-right"></i></span></div>
                <div class="menu-item" onclick="compartilharOSPublica()"><div class="menu-left"><i class="fas fa-share-nodes"></i> Compartilhar link</div><span><i class="fas fa-chevron-right"></i></span></div>
            </div>

            <div class="field-grid">
                <div class="field"><small>Check-in</small><strong>${fmtHora(v.checkin_em)}</strong></div>
                <div class="field"><small>Check-out</small><strong>${fmtHora(v.checkout_em)}</strong></div>
                <div class="field"><small>Duração</small><strong>${fmtDuracao(v.duracao_segundos)}</strong></div>
                <div class="field"><small>Funcionário</small><strong>${esc(v.funcionario_nome || 'Não informado')}</strong></div>
                <div class="field"><small>Serviço</small><strong>${esc(v.tipo_servico || '-')}</strong></div>
                <div class="field"><small>Status</small><strong>${statusLabel(v.status_visita)}</strong></div>
            </div>

            <div class="action-grid">
                <button class="btn btn-primary ${v.checkin_em ? 'is-disabled' : ''}" onclick="${v.checkin_em ? "toast('Check-in já foi realizado nesta OS.')" : "doAction('checkin')"}">Check-in</button>
                <button class="btn btn-primary ${!v.checkin_em ? 'is-disabled' : ''}" onclick="${!v.checkin_em ? "toast('Primeiro faça o check-in desta OS.')" : "doAction('checkout')"}">Check-out</button>
                <button class="btn btn-danger ${!v.checkin_em ? 'is-disabled' : ''}" onclick="${!v.checkin_em ? "toast('Primeiro faça o check-in desta OS.')" : "doAction('checkout','nao_realizada')"}">Não realizada</button>
            </div>
        </div>

        <div class="section desktop-only-block">
            <h3>Descrição</h3>
            <p>${esc(v.descricao || 'Não informado')}</p>
        </div>

        <div class="section desktop-only-block">
            <h3>Itens da OS</h3>
            <div class="menu-list">
                <div class="menu-item${lockedClass}" onclick="openFormulario()"><div class="menu-left"><i class="fas fa-list"></i> Formulários</div><span>${formOk}/1 ›</span></div>
                <div class="menu-item${lockedClass}" onclick="openFotos()"><div class="menu-left"><i class="far fa-images"></i> Fotos e vídeos</div><span>${fotosCount} ›</span></div>
                <div class="menu-item${lockedClass}" onclick="openComentario()"><div class="menu-left"><i class="far fa-comment"></i> Anotação</div><span>${v.comentario ? 'Preenchido' : 'Pendente'} ›</span></div>
                <div class="menu-item${lockedClass}" onclick="openAssinatura()"><div class="menu-left"><i class="fas fa-signature"></i> Assinatura</div><span>${assTec && assCli ? 'Completa' : 'Pendente'} ›</span></div>
                <div class="menu-item" onclick="baixarPdfOS()"><div class="menu-left"><i class="fas fa-file-pdf"></i> Baixar PDF</div><span>›</span></div>
                <div class="menu-item" onclick="compartilharOSPublica()"><div class="menu-left"><i class="fas fa-share-nodes"></i> Compartilhar link</div><span>›</span></div>
            </div>
        </div>
    `;
}

function ajax(data, withFile = false) {
    let opts = { method: 'POST' };
    if (withFile) {
        data.append('ajax', '1');
        data.append('csrf_token', VN_OS_CSRF_TOKEN);
        opts.body = data;
    } else {
        data.ajax = '1';
        data.csrf_token = VN_OS_CSRF_TOKEN;
        opts.headers = {'Content-Type': 'application/x-www-form-urlencoded'};
        opts.body = new URLSearchParams(data);
    }
    return fetch(location.href, opts).then(r => r.json());
}

function reloadOk(res) {
    actionRunning = false;
    if (!res.ok) {
        toast(res.msg || 'Erro ao salvar.');
        return;
    }

    if (res.deleted) {
        sessionStorage.setItem('vn_show_detail', '0');
        sessionStorage.removeItem('vn_selected_os');
        toast(res.msg || 'OS excluída.');
        setTimeout(() => {
            const url = new URL(location.href);
            url.searchParams.delete('os');
            url.searchParams.delete('detail');
            url.searchParams.set('tab', currentTab || 'pendentes');
            url.searchParams.set('_t', String(Date.now()));
            location.href = url.toString();
        }, 450);
        return;
    }

    if (res.visita_id) selectedId = Number(res.visita_id);
    if (res.status_visita === 'concluida') currentTab = 'concluidas';
    if (res.status_visita === 'nao_realizada') currentTab = 'nao_realizadas';
    if (res.status_visita === 'pendente' || res.status_visita === 'em_andamento' || res.status_visita === 'em_rota') currentTab = 'pendentes';
    persistView(true);
    document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === currentTab));

    toast(res.msg || 'Salvo com sucesso.');
    vnMarcarRecarga();

    setTimeout(() => {
        const url = new URL(location.href);
        if (selectedId) url.searchParams.set('os', String(selectedId));
        url.searchParams.set('tab', currentTab);
        url.searchParams.set('detail', '1');
        url.searchParams.set('_t', String(Date.now()));
        location.href = url.toString();
    }, 450);
}

function doAction(action, statusFinal = 'concluida') {
    if (!selectedId) return toast('Selecione uma OS.');
    if (actionRunning) return toast('Aguarde a ação terminar.');

    const v = visitaById(selectedId);
    if (!v) return toast('OS não encontrada na tela.');

    if (action === 'checkin' && v.checkin_em) {
        return toast('Check-in já foi realizado nesta OS.');
    }

    if (action === 'checkout') {
        if (!v.checkin_em) return toast('Primeiro faça o check-in desta OS.');
    }

    actionRunning = true;
    persistView(true);

    const run = (lat = '', lng = '') => {
        ajax({ action, visita_id: selectedId, status_final: statusFinal, lat, lng })
            .then(reloadOk)
            .catch(() => { actionRunning = false; toast('Erro de conexão.'); });
    };
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            p => run(p.coords.latitude, p.coords.longitude),
            () => run('', ''),
            {enableHighAccuracy: true, timeout: 8000}
        );
    } else run('', '');
}

function enderecoMapa(v) {
    const endereco = String(v?.endereco || '').replace(/\s+/g, ' ').trim();
    if (endereco) return endereco + ', Brasil';
    return String(v?.cliente_nome || '').trim();
}

function montarGoogleMapsWebUrl(enderecos) {
    const lista = (Array.isArray(enderecos) ? enderecos : [enderecos])
        .map(e => String(e || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean);

    if (!lista.length) return '';

    const url = new URL('https://www.google.com/maps/dir/');
    url.searchParams.set('api', '1');
    url.searchParams.set('travelmode', 'driving');
    url.searchParams.set('destination', lista[lista.length - 1]);

    if (lista.length > 1) {
        url.searchParams.set('waypoints', lista.slice(0, -1).join('|'));
    }

    return url.toString();
}

function montarGoogleMapsAndroidIntent(webUrl) {
    if (!webUrl) return '';

    const semProtocolo = webUrl.replace(/^https?:\/\//i, '');
    return `intent://${semProtocolo}#Intent;scheme=https;package=com.google.android.apps.maps;end`;
}

function montarGoogleMapsIosUrl(enderecos) {
    const lista = (Array.isArray(enderecos) ? enderecos : [enderecos])
        .map(e => String(e || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean);

    if (!lista.length) return '';

    const destino = encodeURIComponent(lista[lista.length - 1]);
    return `comgooglemaps://?daddr=${destino}&directionsmode=driving`;
}

function montarGoogleNavigationAndroid(endereco) {
    const destino = String(endereco || '').replace(/\s+/g, ' ').trim();
    if (!destino) return '';
    return `google.navigation:q=${encodeURIComponent(destino)}&mode=d`;
}

function abrirUrlMapa(enderecos, webUrl = '') {
    const lista = (Array.isArray(enderecos) ? enderecos : [enderecos])
        .map(e => String(e || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean);

    if (!lista.length) return toast('Endereço não informado.');

    if (!webUrl) {
        webUrl = montarGoogleMapsWebUrl(lista);
    }

    const ua = navigator.userAgent || '';
    const isAndroid = /Android/i.test(ua);
    const isIOS = /iPhone|iPad|iPod/i.test(ua);

    if (isAndroid) {
        // OS única: abre direto o aplicativo Google Maps em modo navegação.
        if (lista.length === 1) {
            window.location.href = montarGoogleNavigationAndroid(lista[0]);
            return;
        }

        // Melhor rota com várias OS: abre o app Google Maps com destino e paradas.
        window.location.href = montarGoogleMapsAndroidIntent(webUrl);
        return;
    }

    if (isIOS) {
        window.location.href = montarGoogleMapsIosUrl(lista) || `maps://?q=${encodeURIComponent(lista[0])}`;
        return;
    }

    // Computador/navegador comum.
    window.open(webUrl, '_blank', 'noopener');
}

function openMapForVisit(id) {
    const v = visitaById(id || selectedId);
    if (!v) return toast('Selecione uma OS.');

    const destinoTxt = enderecoMapa(v).trim();
    if (!destinoTxt || destinoTxt === 'Brasil') {
        return toast('Endereço não informado nesta OS.');
    }

    const webUrl = montarGoogleMapsWebUrl([destinoTxt]);
    abrirUrlMapa([destinoTxt], webUrl);
}

function openMapsSelected() {
    if (selectedId) return openMapForVisit(selectedId);

    const lista = filterVisitas();
    if (lista.length) return openMapForVisit(lista[0].id);

    return toast('Selecione uma OS.');
}

function openMapForAddressText(endereco) {
    const destino = String(endereco || '').replace(/\s+/g, ' ').trim();
    if (!destino) return toast('Endereço não informado.');
    abrirUrlMapa([destino.endsWith('Brasil') ? destino : destino + ', Brasil']);
}

function openMapBancoEndereco(index) {
    const item = currentBancoEnderecos[Number(index)];
    if (!item) return toast('Endereço não encontrado.');

    const endereco = String(item.endereco || item.valor || '').replace(/\s+/g, ' ').trim();

    if (!endereco) return toast('Endereço não informado.');

    openMapForAddressText(endereco);
}

function openFullRoute() {
    const data = filterVisitas();

    if (!data.length) {
        alert('Nenhuma OS para abrir no mapa.');
        return;
    }

    const addresses = data.map(v => (v.endereco || '').trim()).filter(Boolean).slice(0, 8);

    if (!addresses.length) {
        alert('Nenhum endereço encontrado nas OS.');
        return;
    }

    const destination = addresses[addresses.length - 1];
    const waypoints = addresses.slice(0, -1).join('|');
    let url = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' + encodeURIComponent(destination);

    if (waypoints) {
        url += '&waypoints=' + encodeURIComponent(waypoints);
    }

    window.open(url, '_blank');
}

function bestRoute() {
    const statusAbertos = ['pendente', 'aberta', 'aberto', 'em_aberto'];
    const vistos = new Set();

    const enderecos = visitas
        .filter(v => statusAbertos.includes(String(v.status_visita || '').toLowerCase()))
        .map(v => enderecoMapa(v).trim())
        .filter(endereco => endereco && endereco !== 'Brasil')
        .filter(endereco => {
            const chave = endereco.toLowerCase();
            if (vistos.has(chave)) return false;
            vistos.add(chave);
            return true;
        })
        .slice(0, 10);

    if (!enderecos.length) {
        return toast('Nenhuma OS aberta com endereço para rota.');
    }

    const webUrl = montarGoogleMapsWebUrl(enderecos);
    abrirUrlMapa(enderecos, webUrl);
}


function openAddressList() {
    const vistos = new Set();

    const normalizarEnderecoItem = (item) => {
        item = item || {};

        const endereco = String(
            item.valor ||
            item.endereco ||
            item.endereco_cliente ||
            item.logradouro ||
            item.logradouro_cliente ||
            item.rua ||
            item.rua_cliente ||
            item.local ||
            item.local_atendimento ||
            item.referencia_ordem ||
            ''
        ).replace(/\s+/g, ' ').trim();

        return {
            cliente: String(item.cliente || item.cliente_nome || item.nome_cliente || item.cliente_nome_db || 'Cliente').trim(),
            label: String(item.label || item.nome_endereco || item.tipo || 'Endereço').trim(),
            endereco
        };
    };

    const lista = (Array.isArray(todosEnderecosBanco) ? todosEnderecosBanco : [])
        .map(normalizarEnderecoItem)
        .filter(item => {
            const e = item.endereco.toLowerCase();
            return item.endereco && e !== 'endereço não informado' && e !== 'endereco nao informado';
        })
        .filter(item => {
            const chave = item.endereco.toLowerCase();
            if (vistos.has(chave)) return false;
            vistos.add(chave);
            return true;
        });

    if (!lista.length) {
        visitas.forEach(v => {
            const endereco = String(enderecoMapa(v) || v.endereco || '').replace(/\s+/g, ' ').trim();
            const e = endereco.toLowerCase();

            if (!endereco || e === 'brasil' || e === 'endereço não informado' || e === 'endereco nao informado') return;

            const chave = endereco.toLowerCase();
            if (vistos.has(chave)) return;

            vistos.add(chave);
            lista.push({
                cliente: String(v.cliente_nome || 'Cliente'),
                label: 'Endereço da OS',
                endereco
            });
        });
    }

    if (!lista.length) {
        return openModal('Endereços cadastrados', `
            <div class="address-empty">
                <strong style="display:block;color:var(--text);margin-bottom:6px">Nenhum endereço encontrado</strong>
                Verifique se a tabela <b>enderecos_clientes</b> possui cliente_id e endereço preenchido,
                ou se existe endereço nas OS.
            </div>
        `, 35);
    }

    currentBancoEnderecos = lista;

    const html = `
        <div class="address-list-wrap">
            <div class="address-toolbar">
                <div class="address-count-line">
                    <strong>${lista.length} endereço${lista.length > 1 ? 's' : ''}</strong>
                    <small>cadastrados</small>
                </div>
                <input class="address-search" id="buscaEnderecoBanco" type="search"
                       placeholder="Buscar por cliente ou rua..."
                       oninput="filtrarEnderecosBanco(this.value)">
            </div>

            <div id="listaEnderecosBanco" class="address-list-wrap"></div>
        </div>
    `;

    openModal('Endereços cadastrados', html, 35);
    renderEnderecosBanco(lista.map((_, i) => i));
}

/* Desenha só os índices recebidos, para a busca não precisar remontar o modal. */
function renderEnderecosBanco(indices) {
    const alvo = document.getElementById('listaEnderecosBanco');
    if (!alvo) return;

    if (!indices.length) {
        alvo.innerHTML = '<div class="address-empty">Nenhum endereço bate com a busca.</div>';
        return;
    }

    alvo.innerHTML = indices.map(index => {
        const item = currentBancoEnderecos[index];
        const inicial = String(item.cliente || '?').trim().charAt(0).toUpperCase() || '?';

        return `
            <div class="address-list-card">
                <div class="address-list-head">
                    <div class="address-avatar">${esc(inicial)}</div>
                    <div>
                        <strong>${esc(item.cliente)}</strong>
                        <small>${esc(item.label)}</small>
                    </div>
                </div>

                <p class="address-list-text">${esc(item.endereco)}</p>

                <div class="address-actions">
                    <button class="btn btn-soft" type="button" onclick="openMapBancoEndereco(${index})">
                        <i class="fas fa-map-location-dot"></i> Ver no mapa
                    </button>
                    <button class="btn" type="button" onclick="copiarEnderecoBanco(${index})">
                        <i class="far fa-copy"></i> Copiar
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function filtrarEnderecosBanco(termo) {
    const busca = String(termo || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

    const indices = currentBancoEnderecos
        .map((item, index) => {
            const alvo = `${item.cliente} ${item.label} ${item.endereco}`
                .toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            return alvo.includes(busca) ? index : -1;
        })
        .filter(i => i >= 0);

    renderEnderecosBanco(indices);
}

function copiarEnderecoBanco(index) {
    const item = currentBancoEnderecos[Number(index)];
    if (!item) return toast('Endereço não encontrado.');

    const texto = String(item.endereco || '').trim();
    if (!texto) return toast('Endereço não informado.');

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto)
            .then(() => toast('Endereço copiado.'))
            .catch(() => toast('Não foi possível copiar.'));
        return;
    }

    // Navegador antigo / contexto sem HTTPS: cai no textarea temporário.
    const area = document.createElement('textarea');
    area.value = texto;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); toast('Endereço copiado.'); }
    catch (e) { toast('Não foi possível copiar.'); }
    document.body.removeChild(area);
}

function openModal(title, html, progress = 55) {
    document.getElementById('modalTitle').innerHTML = title;
    document.querySelector('.modal-progress span').style.width = progress + '%';
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
    document.getElementById('modalBody').innerHTML = '';
}

function preencherEnderecosNovaOS(clienteId, enderecoSelecionado = '') {
    const enderecoSelect = document.getElementById('novaEnderecoId');
    const enderecoBox = document.getElementById('novaEnderecoResumo');
    if (!enderecoSelect || !enderecoBox) return;

    const lista = clientesEnderecosNovaOS[String(clienteId)] || [];
    enderecoSelect.innerHTML = '';

    if (!clienteId) {
        enderecoSelect.innerHTML = '<option value="">Selecione o cliente primeiro</option>';
        enderecoBox.textContent = 'Selecione um cliente para carregar o endereço';
        return;
    }

    if (!lista.length) {
        enderecoSelect.innerHTML = '<option value="0">Nenhum endereço encontrado</option>';
        enderecoBox.textContent = 'Nenhum endereço encontrado para este cliente';
        return;
    }

    lista.forEach((item, index) => {
        const option = document.createElement('option');
        option.value = item.id || '0';
        option.dataset.valor = item.valor || '';
        option.textContent = (item.label ? item.label + ' - ' : '') + (item.valor || 'Endereço');
        if (enderecoSelecionado && String(option.value) === String(enderecoSelecionado)) option.selected = true;
        else if (!enderecoSelecionado && index === 0) option.selected = true;
        enderecoSelect.appendChild(option);
    });

    enderecoBox.textContent = enderecoSelect.options[enderecoSelect.selectedIndex]?.dataset?.valor || 'Endereço selecionado';
}

/* Valor inicial do campo "Agendar para": agora, no formato do datetime-local. */
function agoraInputLocalVN() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function openNovaOS() {
    const options = clientesNovaOS.length
        ? clientesNovaOS.map(c => `<option value="${Number(c.id)}">${esc(c.nome)}</option>`).join('')
        : '<option value="">Nenhum cliente cadastrado</option>';

    openModal('Abrir Nova Ordem de Serviço', `
        <div class="nova-os-form">
            <div>
                <label><i class="fas fa-user"></i> Cliente</label>
                <select class="input" id="novaClienteId" onchange="preencherEnderecosNovaOS(this.value)">
                    <option value="">Selecione o cliente</option>
                    ${options}
                </select>
            </div>

            <div>
                <label><i class="fas fa-map-marker-alt"></i> Endereço de atendimento</label>
                <select class="input" id="novaEnderecoId" onchange="document.getElementById('novaEnderecoResumo').textContent = this.options[this.selectedIndex]?.dataset?.valor || 'Endereço selecionado'"></select>
                <div class="resume-box" id="novaEnderecoResumo">Selecione um cliente para carregar o endereço</div>
            </div>

            <div>
                <label><i class="fas fa-layer-group"></i> Tipo da OS</label>
                <select class="input" id="novaLinhaServico">
                    <option value="cftv" ${currentLinha !== 'serralheria' ? 'selected' : ''}>CFTV / segurança eletrônica</option>
                    <option value="serralheria" ${currentLinha === 'serralheria' ? 'selected' : ''}>Serralheria</option>
                </select>
            </div>

            <div class="two-cols">
                <div>
                    <label><i class="fas fa-screwdriver-wrench"></i> Tipo de serviço</label>
                    <select class="input" id="novaTipoServico">
                        <option value="Corretiva">Corretiva (conserto / chamado)</option>
                        <option value="Preventiva">Preventiva (programada)</option>
                        <option value="Instalação">Instalação</option>
                        <option value="Vistoria">Vistoria / inspeção</option>
                        <option value="Orçamento">Orçamento / levantamento</option>
                    </select>
                </div>
                <div>
                    <label><i class="fas fa-calendar-day"></i> Agendar para</label>
                    <input class="input" type="datetime-local" id="novaDataAgendada" value="${agoraInputLocalVN()}">
                </div>
            </div>

            <div class="two-cols">
                <div>
                    <label><i class="fas fa-exclamation-circle"></i> Prioridade</label>
                    <select class="input" id="novaPrioridade">
                        <option value="0">Normal</option>
                        <option value="1">Encaixe</option>
                        <option value="10">Urgente</option>
                    </select>
                </div>
                <div>
                    <label><i class="fas fa-user-tie"></i> Técnico</label>
                    <input class="input" value="<?= h($usuarioNome) ?>" disabled>
                </div>
            </div>

            <div>
                <label><i class="fas fa-tools"></i> Problema / Serviço previsto</label>
                <input class="input" id="novaDescricao" placeholder="Descreva o serviço ou problema...">
            </div>

            <div class="two-cols">
                <div>
                    <label><i class="fas fa-microchip"></i> Equipamento</label>
                    <input class="input" id="novaEquipamento" placeholder="Ex: câmera, motor, central, facial...">
                </div>
                <div>
                    <label><i class="fas fa-shield-alt"></i> Garantia</label>
                    <input class="input" id="novaGarantia" placeholder="Ex: 90 dias">
                </div>
            </div>

            <div>
                <label><i class="fas fa-sticky-note"></i> Observações</label>
                <textarea class="input" id="novaObservacao" placeholder="Notas internas da OS..."></textarea>
            </div>

            <button class="btn btn-primary btn-block mobile-fixed-action" onclick="salvarNovaOS()">Gerar Ordem de Serviço</button>
        </div>
    `, 20);

    setTimeout(() => preencherEnderecosNovaOS(document.getElementById('novaClienteId')?.value || ''), 80);
}

function salvarNovaOS() {
    const clienteId = document.getElementById('novaClienteId')?.value || '';
    const clienteSelect = document.getElementById('novaClienteId');
    const clienteNome = clienteSelect?.options[clienteSelect.selectedIndex]?.textContent || '';

    ajax({
        action: 'nova_visita',
        client: clienteId,
        endereco_id: document.getElementById('novaEnderecoId')?.value || '0',
        cliente_nome: clienteNome,
        tipo_servico: document.getElementById('novaTipoServico')?.value || 'Corretiva',
        linha_servico: document.getElementById('novaLinhaServico')?.value || 'cftv',
        expectedService: document.getElementById('novaDescricao')?.value || '',
        equipamento: document.getElementById('novaEquipamento')?.value || '',
        garantia: document.getElementById('novaGarantia')?.value || '',
        observation: document.getElementById('novaObservacao')?.value || '',
        priority: document.getElementById('novaPrioridade')?.value || '0',
        data_agendada: document.getElementById('novaDataAgendada')?.value || ''
    }).then(reloadOk);
}

function openExcluirOS() {
    if (!visitas.length) return toast('Nenhuma OS para excluir.');
    const atual = selectedId || (visitas[0] ? Number(visitas[0].id) : 0);
    const lista = visitas.map(v => `
        <label class="delete-option">
            <input type="radio" name="delete_os_id" value="${Number(v.id)}" ${Number(v.id) === Number(atual) ? 'checked' : ''}>
            <span>
                <strong>${esc(v.cliente_nome || 'Cliente')}</strong>
                <small>${esc(v.endereco || 'Sem endereço')}<br>${esc(v.descricao || v.tipo_servico || 'Sem problema informado')}</small>
            </span>
        </label>
    `).join('');

    openModal('Excluir ordem de serviço', `
        <div class="delete-list">
            <p style="color:var(--muted);line-height:1.45">Selecione exatamente qual OS deseja excluir.</p>
            ${lista}
            <button class="btn btn-danger btn-block mobile-fixed-action" onclick="confirmarExcluirOSSelecionada()"><i class="fas fa-trash"></i> Excluir selecionada</button>
        </div>
    `, 50);
}

function confirmarExcluirOSSelecionada() {
    const el = document.querySelector('input[name="delete_os_id"]:checked');
    if (!el) return toast('Selecione uma OS para excluir.');
    const id = Number(el.value || 0);
    const v = visitaById(id);
    if (!v) return toast('OS não encontrada.');
    if (!confirm('Confirmar exclusão da OS de ' + (v.cliente_nome || 'cliente') + '?')) return;
    selectedId = id;
    ajax({ action: 'excluir_os', visita_id: id }).then(reloadOk);
}

function excluirOS() { openExcluirOS(); }

function baixarPdfOS() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');
    const url = new URL(location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('baixar_pdf_os', String(selectedId));
    url.searchParams.set('t', Date.now().toString());
    // Baixa o arquivo sem sair do app (o app instalado não abre PDF numa aba); sem o ajudante, abre o link normal.
    if (window.VN && typeof VN.baixar === 'function') { VN.baixar(url.toString()); return; }
    window.location.href = url.toString();
}

function limparNomeCliente(raw) {
    const txt = String(raw || '').replace(/^cliente\s*#\d+\s*[-:|]*\s*/i, '').replace(/^cliente\s*[-:|]\s*/i, '').trim();
    return txt || 'Cliente não informado';
}

function compartilharOSPublica() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');

    // Sem link público o PDF sai pelo caminho local, que não aceita modelo.
    const modelos = Array.isArray(VN_MODELOS_PDF) ? VN_MODELOS_PDF : [];
    if (!v._public_token || modelos.length < 2) {
        return enviarLinkPublico(modelos.length === 1 && v._public_token ? modelos[0].id : '');
    }

    const lembrado = (() => {
        try { return sessionStorage.getItem('vn_modelo_pdf') || ''; } catch (e) { return ''; }
    })();

    openModal('Compartilhar link da OS', `
        <div style="display:grid;gap:14px">
            <div class="field">
                <small>Modelo do PDF</small>
                <strong style="font-weight:650;line-height:1.4;color:var(--muted)">
                    É o layout com que o cliente vai abrir a Ordem de Serviço.
                </strong>
            </div>

            <div class="radio-row" style="display:grid;gap:10px">
                ${modelos.map((m, i) => `
                    <label class="radio-card">
                        <input type="radio" name="modelo_pdf" value="${esc(m.id)}"
                               ${(lembrado ? m.id === lembrado : i === 0) ? 'checked' : ''}>
                        ${esc(m.nome)}
                    </label>
                `).join('')}
            </div>

            <button class="btn btn-primary btn-block mobile-fixed-action" onclick="confirmarCompartilharOS()">
                <i class="fas fa-share-nodes"></i> Compartilhar
            </button>
        </div>
    `, 60);
}

function confirmarCompartilharOS() {
    const el = document.querySelector('input[name="modelo_pdf"]:checked');
    const modelo = el ? el.value : '';

    // Lembra a escolha para os próximos compartilhamentos da sessão.
    try { if (modelo) sessionStorage.setItem('vn_modelo_pdf', modelo); } catch (e) {}

    closeModal();
    enviarLinkPublico(modelo);
}

function enviarLinkPublico(modelo) {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');

    let url = v._public_token
        ? `${VN_PUBLIC_PDF_BASE}/os-pdf-publico.php?t=${encodeURIComponent(v._public_token || '')}`
        : `${location.origin}${location.pathname}?baixar_pdf_os=${encodeURIComponent(String(v.id || selectedId || ''))}`;
    if (modelo && v._public_token) url += `&modelo=${encodeURIComponent(modelo)}`;

    const numero = (v.numero_os || v.codigo_os || `OS #${v.id || ''}`).toString().trim();
    const local = limparNomeCliente(v.cliente_nome || v.nome_cliente || v.cliente || '');
    const tecnico = (v.funcionario_nome || v.tecnico_nome || 'Técnico não informado').toString().trim();
    const texto = `Olá! Segue a Ordem de Serviço Digital Nº *${numero}*:\n\nLocal: *${local}*\nRealizado por: *${tecnico}*\n\nClique no link para visualizar a O.S completa:\n${url}`;
    if (navigator.share) {
        navigator.share({ text: texto }).catch(() => {});
        return;
    }
    const done = () => toast('Texto copiado. Cole no WhatsApp.');
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(done).catch(done);
    else {
        const ta = document.createElement('textarea'); ta.value = texto; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
    }
}

function openComentario() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');
    if (!canEditVisit(v)) return;
    openModal('Anotação do técnico', `
        <textarea class="input" id="comentarioTxt" maxlength="3000" placeholder="Digite aqui a anotação do técnico sobre o atendimento">${esc(v.comentario || '')}</textarea>
        <div style="height:12px"></div>
        <button class="btn btn-primary btn-block mobile-fixed-action" onclick="salvarCamposModal()">Salvar anotação</button>
    `, 45);
}

function openFormulario() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');
    if (!canEditVisit(v)) return;
    openModal('Relatório de visita prestada', `
        <div style="display:grid;gap:18px">
            <div>
                <strong>A visita foi concluída com sucesso?</strong>
                <div class="radio-row" style="margin-top:10px">
                    <label class="radio-card"><input type="radio" name="visita_concluida" value="sim" ${v.visita_concluida === 'sim' ? 'checked' : ''}> SIM</label>
                    <label class="radio-card"><input type="radio" name="visita_concluida" value="nao" ${v.visita_concluida === 'nao' ? 'checked' : ''}> NÃO</label>
                </div>
            </div>

            <div>
                <strong>Foi confirmado com o zelador e a portaria se tem pendência e se está tudo ok?</strong>
                <div class="radio-row" style="margin-top:10px">
                    <label class="radio-card"><input type="radio" name="confirmado_zelador" value="sim" ${v.confirmado_zelador === 'sim' ? 'checked' : ''}> SIM</label>
                    <label class="radio-card"><input type="radio" name="confirmado_zelador" value="nao" ${v.confirmado_zelador === 'nao' ? 'checked' : ''}> NÃO</label>
                </div>
            </div>

            <div>
                <strong>Restou alguma pendência?</strong>
                <div class="radio-row" style="margin-top:10px">
                    <label class="radio-card"><input type="radio" name="restou_pendencia" value="sim" onchange="togglePendenciaBox()" ${v.restou_pendencia === 'sim' ? 'checked' : ''}> SIM</label>
                    <label class="radio-card"><input type="radio" name="restou_pendencia" value="nao" onchange="togglePendenciaBox()" ${v.restou_pendencia === 'nao' ? 'checked' : ''}> NÃO</label>
                </div>
            </div>

            <div id="pendenciaBox" style="display:${v.restou_pendencia === 'sim' ? 'block' : 'none'}">
                <strong>Informe a pendência</strong>
                <textarea class="input" id="pendenciasTxt" placeholder="Descreva somente a pendência que ficou para resolver">${esc(v.pendencias || '')}</textarea>
            </div>

            <div>
                <strong>Anotação do técnico</strong>
                <textarea class="input" id="comentarioFormTxt" placeholder="Digite aqui a anotação do técnico sobre o atendimento">${esc(v.comentario || '')}</textarea>
            </div>

            <button class="btn btn-primary btn-block mobile-fixed-action" onclick="salvarCamposModal(true)">Continuar</button>
        </div>
    `, 70);
}

function togglePendenciaBox() {
    const box = document.getElementById('pendenciaBox');
    const txt = document.getElementById('pendenciasTxt');
    const valor = radioValue('restou_pendencia');
    if (!box) return;
    box.style.display = valor === 'sim' ? 'block' : 'none';
    if (valor !== 'sim' && txt) txt.value = '';
}

function radioValue(name) {
    const el = document.querySelector(`input[name="${name}"]:checked`);
    return el ? el.value : '';
}

function salvarCamposModal(fromForm = false) {
    const v = visitaById(selectedId);
    if (!canEditVisit(v)) return;
    const restou = radioValue('restou_pendencia') || (v.restou_pendencia || '');
    const pendenciasEl = document.getElementById('pendenciasTxt');
    const comentario = fromForm ? document.getElementById('comentarioFormTxt').value : document.getElementById('comentarioTxt').value;
    ajax({
        action: 'salvar_campos',
        visita_id: selectedId,
        comentario: comentario,
        pendencias: restou === 'sim' && pendenciasEl ? pendenciasEl.value : '',
        visita_concluida: radioValue('visita_concluida') || (v.visita_concluida || ''),
        confirmado_zelador: radioValue('confirmado_zelador') || (v.confirmado_zelador || ''),
        restou_pendencia: restou
    }).then(reloadOk);
}

/* Troca o tipo da OS direto no detalhe (Corretiva/Preventiva/...). */
function salvarTipoOS(novoTipo) {
    if (!selectedId) return;
    ajax({ action: 'alterar_tipo_os', visita_id: selectedId, tipo_os: novoTipo })
        .then(function (res) {
            if (!res || !res.ok) {
                toast((res && res.msg) ? res.msg : 'Não foi possível alterar o tipo.');
                return;
            }
            toast('Tipo alterado para ' + novoTipo + '.');
            // Atualiza em memória para o selo e a aba Preventivas responderem na hora.
            const v = visitaById(selectedId);
            if (v) v.tipo_servico = novoTipo;
            renderList();
            renderDetail();
        })
        .catch(function () { toast('Erro ao alterar o tipo.'); });
}

function openFotos() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');
    if (!canEditVisit(v)) return;
    const arquivos = v._arquivos || [];
    openModal('Fotos e vídeos', `
        <div style="display:grid;gap:16px">
            <!-- Área de seleção: clique ou arraste vários arquivos de uma vez -->
            <label class="up-drop" id="upDrop">
                <input type="file" id="arquivoUpload" accept="image/*,video/*" multiple hidden>
                <span class="up-drop-ico"><i class="fas fa-cloud-arrow-up"></i></span>
                <strong>Toque para escolher fotos e vídeos</strong>
                <small>Pode selecionar vários de uma vez · até 120 MB cada</small>
            </label>

            <div id="upPreview" class="up-preview" hidden></div>

            <div id="upProgressoBox" class="up-progresso" hidden>
                <div class="up-progresso-barra"><i id="upProgressoFill"></i></div>
                <small id="upProgressoTxt">Enviando…</small>
            </div>

            <button class="btn btn-primary btn-block" id="upBotao" onclick="uploadArquivo()" disabled>
                Adicionar arquivos
            </button>

            <div class="up-galeria-titulo">
                <strong>Anexos desta OS</strong>
                <span class="up-contador">${arquivos.length}</span>
            </div>

            <div class="photos-grid">
                ${arquivos.length ? arquivos.map(a => {
                    const path = esc(a.caminho_arquivo);
                    const nome = esc(a.nome_original || 'Arquivo');
                    const btnExcluir = `<button type="button" class="photo-del" title="Excluir" onclick="excluirArquivo(event, ${Number(a.id)}, '${nome.replace(/'/g, "\\'")}')"><i class="fas fa-trash"></i></button>`;
                    if (a.tipo === 'foto') return `<div class="photo-wrap"><a class="photo-box" href="${path}" target="_blank"><img src="${path}" alt="" loading="lazy"></a>${btnExcluir}</div>`;
                    if (a.tipo === 'video') return `<div class="photo-wrap"><a class="photo-box is-video" href="${path}" target="_blank"><i class="fas fa-circle-play"></i><span>${nome}</span></a>${btnExcluir}</div>`;
                    return `<div class="photo-wrap"><a class="photo-box is-doc" href="${path}" target="_blank"><i class="fas fa-file-lines"></i><span>${nome}</span></a>${btnExcluir}</div>`;
                }).join('') : '<div class="empty">Nenhum arquivo ainda.</div>'}
            </div>
        </div>
    `, 92);

    prepararUploadMultiplo();
}

/* Excluir um anexo da OS (pede confirmação e recarrega a galeria). */
async function excluirArquivo(ev, arquivoId, nome) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    const v = visitaById(selectedId);
    if (!canEditVisit(v)) return;
    if (!confirm('Excluir "' + nome + '"? Não dá para desfazer.')) return;

    try {
        const res = await ajax({ action: 'excluir_arquivo', visita_id: selectedId, arquivo_id: arquivoId });
        if (!res.ok) return toast(res.msg || 'Não foi possível excluir.');
        toast('Arquivo excluído.');
        vnMarcarRecarga();
        setTimeout(() => {
            const url = new URL(location.href);
            url.searchParams.set('os', String(selectedId));
            url.searchParams.set('detail', '1');
            url.searchParams.set('tab', currentTab || 'pendentes');
            url.searchParams.set('_t', Date.now().toString());
            location.href = url.toString();
        }, 500);
    } catch (e) {
        toast('Erro ao excluir arquivo.');
    }
}

/* Preview das miniaturas + contagem antes de enviar, e arrastar-e-soltar. */
function prepararUploadMultiplo() {
    const input = document.getElementById('arquivoUpload');
    const drop = document.getElementById('upDrop');
    const prev = document.getElementById('upPreview');
    const botao = document.getElementById('upBotao');
    if (!input || !prev || !botao) return;

    const MAX_BYTES = 120 * 1024 * 1024;

    function tamanhoBonito(b) {
        if (b >= 1024 * 1024) return (b / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    function render() {
        const files = Array.from(input.files || []);
        prev.innerHTML = '';
        prev.hidden = files.length === 0;

        let grandes = 0;
        files.forEach(function (f) {
            const item = document.createElement('div');
            item.className = 'up-item';
            const grande = f.size > MAX_BYTES;
            if (grande) { item.classList.add('erro'); grandes++; }

            if (f.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                img.onload = function () { URL.revokeObjectURL(img.src); };
                item.appendChild(img);
            } else {
                const ico = document.createElement('span');
                ico.className = 'up-item-ico';
                ico.innerHTML = '<i class="fas fa-circle-play"></i>';
                item.appendChild(ico);
            }

            const cap = document.createElement('small');
            cap.textContent = grande ? (tamanhoBonito(f.size) + ' — grande demais') : tamanhoBonito(f.size);
            item.appendChild(cap);
            prev.appendChild(item);
        });

        const validos = files.length - grandes;
        botao.disabled = validos <= 0;
        botao.textContent = validos > 0
            ? ('Enviar ' + validos + (validos > 1 ? ' arquivos' : ' arquivo'))
            : 'Adicionar arquivos';
        if (grandes > 0) toast(grandes + ' arquivo(s) acima de 120 MB não serão enviados.');
    }

    input.addEventListener('change', render);

    if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('sobre'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('sobre'); });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                render();
            }
        });
    }
}

async function uploadArquivo() {
    const v = visitaById(selectedId);
    if (!canEditVisit(v)) return;
    const input = document.getElementById('arquivoUpload');
    if (!input.files.length) return toast('Selecione fotos ou vídeos.');

    const MAX_BYTES = 120 * 1024 * 1024;
    const fila = Array.from(input.files).filter(f => f.size > 0 && f.size <= MAX_BYTES);
    if (!fila.length) return toast('Nenhum arquivo válido para enviar.');

    const botao = document.getElementById('upBotao');
    const box = document.getElementById('upProgressoBox');
    const fill = document.getElementById('upProgressoFill');
    const txt = document.getElementById('upProgressoTxt');
    if (box) box.hidden = false;
    if (botao) { botao.disabled = true; botao.textContent = 'Enviando…'; }

    actionRunning = true;
    let okCount = 0;
    const falhas = [];

    for (let i = 0; i < fila.length; i++) {
        const file = fila[i];
        if (txt) txt.textContent = 'Enviando ' + (i + 1) + ' de ' + fila.length + ' — ' + file.name;
        if (fill) fill.style.width = Math.round((i / fila.length) * 100) + '%';

        const fd = new FormData();
        fd.append('action', 'upload_arquivo');
        fd.append('visita_id', selectedId);
        fd.append('arquivo', file);
        try {
            const res = await ajax(fd, true);
            if (res.ok) okCount++;
            else falhas.push(file.name + ': ' + (res.msg || 'erro'));
        } catch (e) {
            falhas.push(file.name + ': falha de conexão');
        }
    }

    if (fill) fill.style.width = '100%';
    if (txt) txt.textContent = okCount + ' de ' + fila.length + ' enviado(s).';
    actionRunning = false;

    // Mostra o que deu errado em vez de só somar os que deram certo.
    if (falhas.length) {
        toast(okCount + ' enviado(s), ' + falhas.length + ' com erro: ' + falhas[0]);
    } else {
        toast(okCount + ' arquivo(s) salvo(s).');
    }

    vnMarcarRecarga();
    setTimeout(() => {
        const url = new URL(location.href);
        url.searchParams.set('os', String(selectedId));
        url.searchParams.set('detail', '1');
        url.searchParams.set('tab', currentTab || 'pendentes');
        url.searchParams.set('_t', Date.now().toString());
        location.href = url.toString();
    }, falhas.length ? 2200 : 700);
}

function openAssinatura() {
    const v = visitaById(selectedId);
    if (!v) return toast('Selecione uma OS.');
    if (!canEditVisit(v)) return;
    const ass = v._assinaturas || {};
    openModal('Assinatura', `
        <div style="display:grid;gap:14px">
            <button class="btn btn-primary" onclick="openSignaturePad('tecnico')">Assinatura do técnico ${ass.tecnico ? '✓' : ''}</button>
            <button class="btn btn-primary" onclick="openSignaturePad('cliente')">Assinatura do cliente ${ass.cliente ? '✓' : ''}</button>
            ${ass.tecnico && ass.tecnico.caminho_imagem ? `<div class="field"><small>Técnico</small><img src="${esc(ass.tecnico.caminho_imagem)}" style="max-width:100%;background:#fff;border-radius:12px"></div>` : ''}
            ${ass.cliente && ass.cliente.caminho_imagem ? `<div class="field"><small>Cliente</small><img src="${esc(ass.cliente.caminho_imagem)}" style="max-width:100%;background:#fff;border-radius:12px"></div>` : ''}
        </div>
    `, 95);
}

let currentSignatureTipo = null;
let signatureFullCanvas = null;
let signatureFullCtx = null;
let signatureFullDrawing = false;
let signatureFullTouched = false;

function openSignaturePad(tipo) {
    const v = visitaById(selectedId);
    if (!canEditVisit(v)) return;
    currentSignatureTipo = tipo;
    document.getElementById('signatureFullTitle').textContent = tipo === 'tecnico' ? 'Assinatura do técnico' : 'Assinatura do cliente';

    // Nome de quem assina: só pergunta pro cliente (quem assina no local pode
    // não ser o cliente cadastrado), já vem sugerido com o nome da OS.
    const subtitulo = document.getElementById('signatureFullSubtitle');
    const nomeInput = document.getElementById('signatureNomeCliente');
    if (nomeInput) {
        if (tipo === 'cliente') {
            nomeInput.hidden = false;
            nomeInput.value = v.cliente_nome || v.nome_cliente || '';
            if (subtitulo) subtitulo.hidden = true;
        } else {
            nomeInput.hidden = true;
            if (subtitulo) subtitulo.hidden = false;
        }
    }

    const overlay = document.getElementById('signatureFullscreen');
    overlay.classList.add('open');
    closeModal();
    if (overlay.requestFullscreen) overlay.requestFullscreen().catch(() => {});
    if (screen.orientation && screen.orientation.lock) screen.orientation.lock('landscape').catch(() => {});
    setTimeout(initSignatureFullCanvas, 120);
}

function initSignatureFullCanvas() {
    signatureFullCanvas = document.getElementById('signatureCanvasFull');
    if (!signatureFullCanvas) return;
    const rect = signatureFullCanvas.getBoundingClientRect();
    const ratio = window.devicePixelRatio || 1;
    signatureFullCanvas.width = rect.width * ratio;
    signatureFullCanvas.height = rect.height * ratio;
    signatureFullCtx = signatureFullCanvas.getContext('2d');
    signatureFullCtx.scale(ratio, ratio);
    signatureFullCtx.fillStyle = '#ffffff';
    signatureFullCtx.fillRect(0, 0, rect.width, rect.height);
    signatureFullCtx.lineWidth = 3.4;
    signatureFullCtx.lineCap = 'round';
    signatureFullCtx.strokeStyle = '#111827';
    signatureFullTouched = false;

    const pos = (e) => {
        const r = signatureFullCanvas.getBoundingClientRect();
        const p = e.touches ? e.touches[0] : e;
        return {x: p.clientX - r.left, y: p.clientY - r.top};
    };
    const start = (e) => {
        e.preventDefault();
        signatureFullDrawing = true;
        signatureFullTouched = true;
        const p = pos(e);
        signatureFullCtx.beginPath();
        signatureFullCtx.moveTo(p.x, p.y);
    };
    const move = (e) => {
        if (!signatureFullDrawing) return;
        e.preventDefault();
        const p = pos(e);
        signatureFullCtx.lineTo(p.x, p.y);
        signatureFullCtx.stroke();
    };
    const end = () => { signatureFullDrawing = false; };

    signatureFullCanvas.onmousedown = start;
    signatureFullCanvas.onmousemove = move;
    signatureFullCanvas.ontouchstart = start;
    signatureFullCanvas.ontouchmove = move;
    window.onmouseup = end;
    signatureFullCanvas.ontouchend = end;
}

function clearSignatureFull() {
    if (!signatureFullCanvas || !signatureFullCtx) return;
    const rect = signatureFullCanvas.getBoundingClientRect();
    signatureFullCtx.fillStyle = '#ffffff';
    signatureFullCtx.fillRect(0, 0, rect.width, rect.height);
    signatureFullTouched = false;
}

function closeSignatureFullscreen(save) {
    const overlay = document.getElementById('signatureFullscreen');
    if (save) saveSignatureFull();
    overlay.classList.remove('open');
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    if (screen.orientation && screen.orientation.unlock) {
        try { screen.orientation.unlock(); } catch (e) {}
    }
}


function normalizarAssinaturaEmPe(canvas) {
    if (!canvas) return '';

    const w = canvas.width;
    const h = canvas.height;

    const out = document.createElement('canvas');
    const ctx = out.getContext('2d');

    // A OS é assinada com a tela deitada. Salva a imagem na posição original
    // e a correção final de rotação é feita no PDF/link público.
    out.width = w;
    out.height = h;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.drawImage(canvas, 0, 0);

    return out.toDataURL('image/jpeg', 0.9);
}


/*
   Sai do modo tela cheia/paisagem travada. Antes só o "Cancelar" fazia isso;
   "Salvar" apenas escondia a camada e dependia do recarregamento da página
   (que sempre acontecia em seguida) pra desfazer a tela cheia de verdade.
   Agora que salvar não recarrega mais nada, isso precisa ser feito aqui.
*/
function fecharTelaAssinatura() {
    const overlay = document.getElementById('signatureFullscreen');
    overlay.classList.remove('open');
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
    if (screen.orientation && screen.orientation.unlock) {
        try { screen.orientation.unlock(); } catch (e) {}
    }
}

async function saveSignatureFull() {
    const v = visitaById(selectedId);
    if (!canEditVisit(v)) return;
    if (!signatureFullCanvas || !currentSignatureTipo) return toast('Assinatura não encontrada.');
    if (!signatureFullTouched) return toast('Assine antes de salvar.');

    const tipo = currentSignatureTipo;
    const data = normalizarAssinaturaEmPe(signatureFullCanvas);

    /*
       O nome de quem assina já é sabido pelo sistema — técnico é quem está
       logado, cliente é o dono da OS. Perguntar de novo com prompt() (duas
       janelas nativas, uma atrás da outra) era o principal motivo de reclamação
       de "difícil assinar". Continua dando pra editar depois, se precisar.
    */
    const nome = tipo === 'tecnico'
        ? (VN_USUARIO_NOME || 'Técnico')
        : ((document.getElementById('signatureNomeCliente')?.value || '').trim() || v.cliente_nome || v.nome_cliente || 'Cliente');

    fecharTelaAssinatura();
    toast('Salvando assinatura…');

    try {
        const res = await ajax({
            action: 'salvar_assinatura',
            visita_id: selectedId,
            tipo,
            nome_assinante: nome,
            cpf_assinante: '',
            assinatura_base64: data
        });

        if (!res.ok) return toast(res.msg || 'Não foi possível salvar a assinatura.');

        /*
           Atualiza na hora, sem recarregar a página inteira — antes, salvar
           qualquer assinatura navegava pra mesma URL de novo (com um atraso e
           uma piscada de tela), o que parecia "voltar pro início da OS".
        */
        v._assinaturas = v._assinaturas || {};
        v._assinaturas[tipo] = { nome_assinante: nome, caminho_imagem: data, assinado_em: new Date().toISOString() };
        toast('Assinatura salva.');
        renderDetail();
    } catch (e) {
        toast('Erro ao salvar assinatura.');
    }
}

function toggleTheme() {
    const atual = document.documentElement.getAttribute('data-theme') === 'light' ? 'claro' : 'escuro';
    const novo = atual === 'escuro' ? 'claro' : 'escuro';

    aplicarTemaVN(novo, true);

    ajax({ action: 'set_theme', tema: novo }).then(res => {
        toast('Tema alterado para ' + novo + '.');
    }).catch(() => {
        toast('Tema alterado para ' + novo + '.');
    });
}

aplicarTemaVN(document.documentElement.getAttribute('data-theme') || 'dark', false);
document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === currentTab));
renderList();
renderDetail();
if ((urlParams.get('detail') === '1' || sessionStorage.getItem('vn_show_detail') === '1') && selectedId) {
    document.body.classList.add('show-detail');
}
if (selectedId) {
    persistView(document.body.classList.contains('show-detail'));
}
vnRestaurarScroll();
</script>
</body>
</html>
