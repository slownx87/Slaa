<?php
/*
 * Vss E Acj - Relógio de Ponto
 * Edite os dados do banco abaixo antes de subir para a Hostinger.
 */

declare(strict_types=1);

/* Deixe true enquanto instala/testa. Em produção mantenha false: com true o
 * PHP exibe erros e stack traces na tela, vazando caminhos, SQL e detalhes do
 * banco para qualquer visitante. */
const VN_DEBUG = false;
if (VN_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    /* Nada aparece na tela do visitante, mas os erros passam a ir para o log do
     * servidor (antes error_reporting(0) apagava tudo e um erro 500 ficava sem
     * pista nenhuma). Na Hostinger: hPanel > Avançado > Logs de erro do PHP. */
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
}

if (session_status() === PHP_SESSION_NONE) {
    /* Sessão isolada do Relógio de Ponto (cookie próprio) e com flags de
     * segurança, para não compartilhar login com outros sistemas no domínio. */
    $vnSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('VNPONTOSESS');
    /* O padrão do PHP apaga a sessão após ~24 min sem uso. Num celular/tablet de
     * portaria que fica com a tela de ponto aberta o dia todo isso derrubava o
     * token e a próxima batida dava "Sessão expirada". 12 h cobre qualquer turno. */
    @ini_set('session.gc_maxlifetime', '43200');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $vnSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $vnSecure, true);
    }
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

/* Dados do banco: NÃO ficam mais neste arquivo.
 *
 * Crie o arquivo _config.local.php (copie o _config.local.php.example) na mesma
 * pasta e preencha lá. Ele é ignorado pelo git, então a senha nunca vai parar
 * num repositório. Também aceita variáveis de ambiente PONTO_DB_HOST,
 * PONTO_DB_NAME, PONTO_DB_USER e PONTO_DB_PASS. */
if (is_file(__DIR__ . '/_config.local.php')) {
    require_once __DIR__ . '/_config.local.php';
}
if (!defined('DB_HOST')) define('DB_HOST', getenv('PONTO_DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('PONTO_DB_NAME') ?: 'SEU_BANCO_AQUI');
if (!defined('DB_USER')) define('DB_USER', getenv('PONTO_DB_USER') ?: 'SEU_USUARIO_AQUI');
if (!defined('DB_PASS')) define('DB_PASS', getenv('PONTO_DB_PASS') ?: '');
const APP_NAME = 'Vss E Acj - Relógio de Ponto';
const LOGO_PATH = 'assets/logo-vss-acj.png';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_NAME === 'SEU_BANCO_AQUI' || DB_USER === 'SEU_USUARIO_AQUI') {
        throw new RuntimeException('Edite o arquivo _config.php e coloque os dados reais do banco de dados.');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    } catch (Throwable $e) {
        throw new RuntimeException('Erro na conexão com o banco. Confira DB_HOST, DB_NAME, DB_USER e DB_PASS no _config.php. Detalhe: ' . $e->getMessage());
    }
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now_sp(): string
{
    return date('Y-m-d H:i:s');
}

function today_sp(): string
{
    return date('Y-m-d');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/* A tela pede um token novo quando recebe "Sessão expirada" e repete a ação
 * sozinha (ver app_mobile_footer). Só devolve o token da PRÓPRIA sessão. */
if (PHP_SAPI !== 'cli' && isset($_GET['novo_token']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['token' => csrf_token()]);
    exit;
}

function check_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function is_admin_logged(): bool
{
    return !empty($_SESSION['ponto_admin_id']);
}

function require_admin(): void
{
    if (!is_admin_logged()) {
        redirect('login-admin.php');
    }
}

function admin_id(): ?int
{
    return isset($_SESSION['ponto_admin_id']) ? (int)$_SESSION['ponto_admin_id'] : null;
}

function admin_nome(): string
{
    return $_SESSION['ponto_admin_nome'] ?? 'Admin';
}

function admin_nivel(): string
{
    return $_SESSION['ponto_admin_nivel'] ?? '';
}


function ponto_admins_count(): int
{
    if (!table_exists('ponto_admins')) return 0;
    try { return (int)db()->query("SELECT COUNT(*) FROM ponto_admins")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function require_admin_or_first_install(): void
{
    // Permite instalar somente enquanto não existir nenhum admin cadastrado.
    // Depois disso, instalar/diagnóstico exigem login do admin.
    if (ponto_admins_count() > 0) {
        require_admin();
    }
}

/** Admin que enxerga tudo: super admin ou sem condomínio vinculado. */
function admin_eh_global(): bool
{
    return admin_nivel() === 'super_admin' || admin_condominio_id() === null;
}

function admin_condominio_id(): ?int
{
    return isset($_SESSION['ponto_admin_condominio_id']) && $_SESSION['ponto_admin_condominio_id'] !== null
        ? (int)$_SESSION['ponto_admin_condominio_id']
        : null;
}

function admin_where_condominio(string $alias = ''): array
{
    $prefix = $alias ? $alias . '.' : '';
    if (admin_nivel() === 'super_admin' || admin_condominio_id() === null) {
        return ['', []];
    }
    return [" AND {$prefix}condominio_id = ? ", [admin_condominio_id()]];
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_show(): void
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($items as $item) {
        $type = $item['type'] === 'error' ? 'error' : 'success';
        echo '<div class="alert ' . h($type) . '">' . h($item['message']) . '</div>';
    }
}

function table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_photo_columns(): void
{
    if (table_exists('ponto_registros') && !column_exists('ponto_registros', 'foto_path')) {
        db()->exec("ALTER TABLE ponto_registros ADD COLUMN foto_path VARCHAR(255) NULL AFTER observacao");
    }
    if (table_exists('ponto_registros') && !column_exists('ponto_registros', 'foto_mime')) {
        db()->exec("ALTER TABLE ponto_registros ADD COLUMN foto_mime VARCHAR(60) NULL AFTER foto_path");
    }
    if (table_exists('ponto_registros') && !column_exists('ponto_registros', 'foto_tamanho')) {
        db()->exec("ALTER TABLE ponto_registros ADD COLUMN foto_tamanho INT NULL AFTER foto_mime");
    }
}

function get_condominios(bool $onlyActive = true): array
{
    $sql = "SELECT * FROM ponto_condominios" . ($onlyActive ? " WHERE ativo = 1" : "") . " ORDER BY nome";
    return db()->query($sql)->fetchAll();
}

function get_cargos(bool $onlyActive = true): array
{
    $sql = "SELECT * FROM ponto_cargos" . ($onlyActive ? " WHERE ativo = 1" : "") . " ORDER BY nome";
    return db()->query($sql)->fetchAll();
}

function pin_fingerprint(string $pin): string
{
    // Impressão digital determinística do PIN: o mesmo PIN gera sempre o mesmo valor,
    // o que permite achar PIN repetido sem guardar o PIN em texto aberto.
    return hash_hmac('sha256', $pin, 'vss-acj-ponto-pin-fingerprint-v1');
}

function pin_fingerprint_disponivel(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        db()->query("SELECT pin_fingerprint FROM ponto_funcionarios LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        try {
            db()->exec("ALTER TABLE ponto_funcionarios ADD COLUMN pin_fingerprint VARCHAR(64) NULL");
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    return $ok;
}

function find_funcionario_by_pin(int $condominioId, string $pin): ?array
{
    // Se o condomínio vier zerado, detecta automaticamente pelo PIN cadastrado do funcionário.
    // Se o mesmo PIN existir em mais de um funcionário ativo, o sistema pede para escolher o condomínio para evitar batida errada.
    $params = [];
    $whereCondominio = '';
    if ($condominioId > 0) {
        $whereCondominio = ' AND f.condominio_id = ?';
        $params[] = $condominioId;
    }

    /* Sem este filtro o sistema roda password_verify (bcrypt, ~100ms cada) em
     * TODO funcionário ativo a cada tentativa, e a tela do PIN trava por vários
     * segundos. A impressão digital corta a lista para quem realmente pode
     * bater com esse PIN; os cadastros antigos ainda sem impressão digital
     * continuam sendo conferidos um a um e são preenchidos logo abaixo. */
    $whereFingerprint = '';
    if (pin_fingerprint_disponivel()) {
        $whereFingerprint = ' AND (f.pin_fingerprint = ? OR f.pin_fingerprint IS NULL OR f.pin_fingerprint = \'\')';
        $params[] = pin_fingerprint($pin);
    }

    $stmt = db()->prepare("SELECT f.*, c.nome AS cargo_nome, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE f.ativo = 1 AND co.ativo = 1 {$whereCondominio} {$whereFingerprint}
        ORDER BY f.nome");
    $stmt->execute($params);

    $matches = [];
    foreach ($stmt->fetchAll() as $func) {
        if (password_verify($pin, $func['pin_hash'])) {
            // Preenche a impressão digital do PIN de cadastros antigos na primeira batida certa.
            if (empty($func['pin_fingerprint']) && pin_fingerprint_disponivel()) {
                try {
                    db()->prepare("UPDATE ponto_funcionarios SET pin_fingerprint = ? WHERE id = ?")
                        ->execute([pin_fingerprint($pin), (int)$func['id']]);
                } catch (Throwable $e) {
                    // não impede a batida
                }
            }
            $matches[] = $func;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    if (count($matches) > 1 && $condominioId <= 0) {
        throw new RuntimeException('Existe mais de um funcionário ativo com esse PIN. Selecione o condomínio para identificar corretamente.');
    }

    return null;
}

/**
 * Procura QUALQUER funcionário que já use esse PIN — inclusive os desativados.
 *
 * find_funcionario_by_pin() só enxerga quem está ativo, e é ela que o painel
 * usava para conferir PIN repetido. Resultado: dava para entregar a alguém o
 * mesmo PIN de um funcionário desligado e, no dia em que esse funcionário fosse
 * reativado, os DOIS paravam de conseguir bater ponto (o sistema não sabe de
 * quem é o PIN). Esta função fecha essa porta.
 *
 * @param int $ignorarId Não considera este funcionário (é ele quem está trocando).
 * @return array|null Linha do funcionário que já usa o PIN, ou null se estiver livre.
 */
function ponto_pin_em_uso(string $pin, int $ignorarId = 0): ?array
{
    $params = [];
    $whereFingerprint = '';
    if (pin_fingerprint_disponivel()) {
        $whereFingerprint = " AND (f.pin_fingerprint = ? OR f.pin_fingerprint IS NULL OR f.pin_fingerprint = '')";
        $params[] = pin_fingerprint($pin);
    }

    $stmt = db()->prepare("SELECT f.id, f.nome, f.ativo, f.pin_hash, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE 1=1 {$whereFingerprint}
        ORDER BY f.ativo DESC, f.nome");
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $func) {
        if ((int)$func['id'] === $ignorarId) continue;
        if (password_verify($pin, (string)$func['pin_hash'])) {
            return $func;
        }
    }
    return null;
}

function get_funcionario(int $id): ?array
{
    $stmt = db()->prepare("SELECT f.*, c.nome AS cargo_nome, co.nome AS condominio_nome
        FROM ponto_funcionarios f
        LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
        INNER JOIN ponto_condominios co ON co.id = f.condominio_id
        WHERE f.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function registros_do_dia(int $funcionarioId, string $data): array
{
    $stmt = db()->prepare("SELECT * FROM ponto_registros WHERE funcionario_id = ? AND data_ponto = ? ORDER BY data_hora ASC, id ASC");
    $stmt->execute([$funcionarioId, $data]);
    return $stmt->fetchAll();
}

/**
 * Janela do turno que vira a noite, a partir do horário do cargo.
 *
 * Antes valiam dois números fixos para todo mundo: entrada a partir das 17:00 e
 * saída até as 08:00 da manhã seguinte. Escala que vira a noite começando mais
 * cedo (16:00-02:00) ou terminando mais tarde (22:00-10:00) não era reconhecida
 * e a saída ficava "antes da entrada". Agora, se o cargo tem horário que vira a
 * meia-noite (saída <= entrada), a janela se adapta a ele — com os 17:00/08:00 de
 * sempre como mínimo, então nada muda para quem já funcionava.
 *
 * @return array{0:int,1:int} [a partir de que minuto a entrada é "da noite",
 *                              até que minuto da madrugada a saída ainda é deste turno]
 */
function ponto_janela_noite_da_jornada(?array $jornada): array
{
    $limiar = 17 * 60;
    $fim = 8 * 60;
    foreach ((array)($jornada['turnos'] ?? []) as $t) {
        if (empty($t['definido']) || !isset($t['entrada_min'], $t['saida_min'])) continue;
        if ((int)$t['saida_min'] > (int)$t['entrada_min']) continue; // não vira a noite
        /* Só baixa o limiar quando o turno que vira a noite começa ANTES das 17h
         * (com 2 h de folga para quem chega adiantado); a partir das 17h o padrão
         * de sempre já cobre, e nada muda para quem já funcionava. */
        if ((int)$t['entrada_min'] < $limiar) {
            $limiar = max(0, (int)$t['entrada_min'] - 120);
        }
        $fim = max($fim, min(1439, (int)$t['saida_min'] + 120));
    }
    return [$limiar, $fim];
}

function ponto_janela_noite(?int $funcionarioId): array
{
    static $cache = [];
    if (!$funcionarioId) {
        return ponto_janela_noite_da_jornada(null);
    }
    if (!isset($cache[$funcionarioId])) {
        try {
            $cache[$funcionarioId] = ponto_janela_noite_da_jornada(ponto_jornada_do_funcionario($funcionarioId));
        } catch (Throwable $e) {
            $cache[$funcionarioId] = ponto_janela_noite_da_jornada(null);
        }
    }
    return $cache[$funcionarioId];
}

function ponto_min_para_hms(int $min): string
{
    $min = max(0, min(1439, $min));
    return sprintf('%02d:%02d:59', intdiv($min, 60), $min % 60);
}

function registros_jornada_dia_com_turno_noite(int $funcionarioId, string $data): array
{
    // Puxa as batidas do dia da jornada/PDF e também saídas da madrugada do dia seguinte.
    // Isso corrige o ajuste/calendário quando a saída real foi salva com data_ponto do dia seguinte.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $data = today_sp();
    }

    $proximoDia = date('Y-m-d', strtotime($data . ' +1 day'));
    $iniMadrugada = $proximoDia . ' 00:00:00';
    $fimMadrugada = $proximoDia . ' ' . ponto_min_para_hms(ponto_janela_noite($funcionarioId)[1]);

    $stmt = db()->prepare("
        SELECT * FROM ponto_registros
        WHERE funcionario_id = ?
          AND (
                data_ponto = ?
                OR (tipo <> 'entrada' AND data_hora BETWEEN ? AND ?)
          )
        ORDER BY data_hora ASC, id ASC
    ");
    $stmt->execute([$funcionarioId, $data, $iniMadrugada, $fimMadrugada]);
    $rows = $stmt->fetchAll();

    $temEntradaNoite = false;
    foreach ($rows as $r) {
        if ((string)($r['data_ponto'] ?? '') === $data && (string)($r['tipo'] ?? '') === 'entrada' && ponto_eh_entrada_noite((string)($r['data_hora'] ?? ''), $funcionarioId)) {
            $temEntradaNoite = true;
            break;
        }
    }

    if (!$temEntradaNoite) {
        $rows = array_values(array_filter($rows, function ($r) use ($data) {
            return (string)($r['data_ponto'] ?? '') === $data;
        }));
    }

    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $id = ponto_registro_ordem_id($r);
        if ($id > 0 && isset($seen[$id])) continue;
        if ($id > 0) $seen[$id] = true;
        $out[] = $r;
    }

    usort($out, function ($a, $b) {
        $cmp = strcmp((string)($a['data_hora'] ?? ''), (string)($b['data_hora'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return ponto_registro_ordem_id($a) <=> ponto_registro_ordem_id($b);
    });

    return ponto_normalizar_turno_noite_registros($out);
}

/**
 * A MESMA regra de registros_jornada_dia_com_turno_noite(), mas sem ir ao banco:
 * recebe as batidas do período já carregadas e separa as de um dia.
 *
 * Existe por causa da velocidade: a tela de relatórios montava o espelho
 * consultando o banco um dia de cada vez, para cada funcionário — um mês com 50
 * pessoas passava de mil consultas só para desenhar a página. As batidas já vêm
 * todas numa consulta só; daqui para frente é só separação em memória.
 *
 * @param array $registros Batidas do funcionário no período (com data_ponto e data_hora).
 */
function ponto_registros_do_dia_em_memoria(array $registros, string $data, ?int $funcionarioId = null): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return [];
    }

    /* A janela da noite é do cargo do funcionário. Sem o id explícito, pega da
     * primeira batida (quem chama sempre passa as batidas de uma pessoa só). */
    if ($funcionarioId === null && $registros) {
        $primeira = reset($registros);
        $funcionarioId = (int)($primeira['funcionario_id'] ?? 0) ?: null;
    }

    $proximoDia = date('Y-m-d', strtotime($data . ' +1 day'));
    $iniMadrugada = $proximoDia . ' 00:00:00';
    $fimMadrugada = $proximoDia . ' ' . ponto_min_para_hms(ponto_janela_noite($funcionarioId)[1]);

    $doDia = [];
    $madrugadaSeguinte = [];
    $temEntradaNoite = false;

    foreach ($registros as $r) {
        $dataPonto = (string)($r['data_ponto'] ?? '');
        $dataHora = (string)($r['data_hora'] ?? '');
        $tipo = (string)($r['tipo'] ?? '');

        if ($dataPonto === $data) {
            $doDia[] = $r;
            if ($tipo === 'entrada' && ponto_eh_entrada_noite($dataHora, $funcionarioId)) {
                $temEntradaNoite = true;
            }
            continue;
        }
        if ($tipo !== 'entrada' && $dataHora >= $iniMadrugada && $dataHora <= $fimMadrugada) {
            $madrugadaSeguinte[] = $r;
        }
    }

    /* A saída da madrugada seguinte só pertence a este dia quando ele começou
     * de noite; senão seria a batida de outra pessoa do turno da manhã. */
    $rows = $temEntradaNoite ? array_merge($doDia, $madrugadaSeguinte) : $doDia;
    if (!$rows) {
        return [];
    }

    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $id = ponto_registro_ordem_id($r);
        if ($id > 0 && isset($seen[$id])) continue;
        if ($id > 0) $seen[$id] = true;
        $out[] = $r;
    }

    usort($out, function ($a, $b) {
        $cmp = strcmp((string)($a['data_hora'] ?? ''), (string)($b['data_hora'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return ponto_registro_ordem_id($a) <=> ponto_registro_ordem_id($b);
    });

    return ponto_normalizar_turno_noite_registros($out);
}

function ponto_jornada_numero_por_registro(array $registros): array
{
    $map = [];
    $jornadas = ponto_jornadas_from_registros($registros);
    foreach ($jornadas as $idx => $jornada) {
        $num = $idx + 1;
        foreach (($jornada['eventos'] ?? []) as $ev) {
            $id = ponto_registro_ordem_id($ev);
            if ($id > 0) $map[$id] = $num;
        }
    }
    return $map;
}


function ponto_registro_ordem_id(array $r): int
{
    if (isset($r['id'])) return (int)$r['id'];
    if (isset($r['registro_id'])) return (int)$r['registro_id'];
    return 0;
}

function ponto_minutos_hora(?string $dt): ?int
{
    if (!$dt) return null;
    $ts = strtotime($dt);
    if (!$ts) return null;
    return ((int)date('H', $ts) * 60) + (int)date('i', $ts);
}

function ponto_data_hora(?string $dt): string
{
    $ts = $dt ? strtotime($dt) : false;
    return $ts ? date('Y-m-d', $ts) : '';
}

function ponto_normalizar_turno_noite_registros(array $registros): array
{
    // Corrige somente em memória para cálculo/relatório.
    // Caso antigo/manual comum: Entrada registrada 17:00+ com data_ponto do dia,
    // e Saída final preenchida como 05:50/06:00 na mesma data em vez do dia seguinte.
    // Sem isso, a saída fica antes da entrada e o PDF marca a jornada como aberta.
    if (!$registros) return $registros;

    /* A chave inclui o funcionário. Sem isso, uma lista com várias pessoas (o
     * PDF do espelho monta o mês inteiro de todo mundo de uma vez) contaminava:
     * bastava UMA pessoa ter entrado às 18:00 no dia para que a batida de
     * madrugada de QUALQUER outra pessoa naquele mesmo dia fosse jogada para o
     * dia seguinte, estourando as horas dela no relatório. */
    $chave = function (array $r) {
        $dataPonto = (string)($r['data_ponto'] ?? ponto_data_hora((string)($r['data_hora'] ?? '')));
        return (string)($r['funcionario_id'] ?? '0') . '|' . $dataPonto;
    };

    $entradaNoitePorData = [];
    foreach ($registros as $r) {
        if ((string)($r['tipo'] ?? '') !== 'entrada') continue;
        $dataPonto = (string)($r['data_ponto'] ?? ponto_data_hora((string)($r['data_hora'] ?? '')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPonto)) continue;
        $min = ponto_minutos_hora((string)($r['data_hora'] ?? ''));
        if ($min !== null && $min >= ponto_janela_noite((int)($r['funcionario_id'] ?? 0) ?: null)[0]) {
            $entradaNoitePorData[$chave($r)] = true;
        }
    }

    foreach ($registros as &$r) {
        $tipo = (string)($r['tipo'] ?? '');
        if ($tipo === 'entrada') continue;

        $dataPonto = (string)($r['data_ponto'] ?? '');
        $dataHora = (string)($r['data_hora'] ?? '');
        if (!isset($entradaNoitePorData[$chave($r)])) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPonto)) continue;
        if (ponto_data_hora($dataHora) !== $dataPonto) continue;

        $min = ponto_minutos_hora($dataHora);
        // Janela da madrugada para fechamento do turno noturno (do cargo; mínimo até 08:00).
        if ($min !== null && $min >= 0 && $min <= ponto_janela_noite((int)($r['funcionario_id'] ?? 0) ?: null)[1]) {
            $ts = strtotime($dataHora . ' +1 day');
            if ($ts) {
                $r['data_hora'] = date('Y-m-d H:i:s', $ts);
                $r['_ajustado_turno_noite_pdf'] = 1;
            }
        }
    }
    unset($r);

    usort($registros, function ($a, $b) {
        $cmp = strcmp((string)($a['data_hora'] ?? ''), (string)($b['data_hora'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return ponto_registro_ordem_id($a) <=> ponto_registro_ordem_id($b);
    });

    return $registros;
}

/* Quantos ciclos Entrada → Saída final a pessoa pode fazer no MESMO dia.
 *
 * Está em 1: bateu a saída final, o dia acabou e o app não oferece mais nada
 * até virar o dia. Com valor maior, logo após a saída aparecia "Entrada da 2ª
 * jornada" como única opção — a pessoa tocava (não tinha outra), começava uma
 * jornada nova e o app pedia para finalizar de novo, num laço.
 *
 * Só aumente se realmente existir gente que faz dois expedientes separados no
 * mesmo dia. Turno noturno NÃO precisa disso: a jornada que vira a noite conta
 * como um dia só. */
const PONTO_MAX_JORNADAS_DIA = 1;

function ponto_max_jornadas_por_dia(): int
{
    return PONTO_MAX_JORNADAS_DIA;
}

function ponto_ordem_jornada_label(int $numero): string
{
    return $numero . 'ª jornada';
}

function ponto_eh_entrada_noite(?string $dt, ?int $funcionarioId = null): bool
{
    $min = ponto_minutos_hora($dt);
    return $min !== null && $min >= ponto_janela_noite($funcionarioId)[0];
}

function ponto_eh_saida_madrugada_turno_noite(?string $dt): bool
{
    $min = ponto_minutos_hora($dt);
    // Janela principal pedida: 05:50 até 06:00.
    // Mantém tolerância até 08:00 para casos reais que atrasaram, sem inventar horário.
    return $min !== null && $min >= 0 && $min <= (8 * 60);
}

function ponto_saida_prevista_turno_noite_texto(): string
{
    return 'saída não registrada (prev. 05:50-06:00)';
}


function ponto_jornadas_from_registros(array $registros): array
{
    $registros = ponto_normalizar_turno_noite_registros($registros);

    $jornadas = [];
    $atual = null;

    foreach ($registros as $r) {
        $tipo = (string)($r['tipo'] ?? '');

        if ($tipo === 'entrada') {
            if ($atual !== null) {
                $jornadas[] = $atual;
            }
            $atual = [
                'entrada' => $r,
                'saida' => null,
                'eventos' => [$r],
                'aberta' => true,
            ];
            continue;
        }

        if ($atual === null) {
            // Ignora saídas soltas sem uma entrada anterior para não fechar a jornada errada.
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

    if ($atual !== null) {
        $jornadas[] = $atual;
    }

    return $jornadas;
}

/* ATENÇÃO — o que conta é SEMPRE a ÚLTIMA jornada do dia.
 *
 * Estas duas funções olhavam TODAS as jornadas: bastava uma ficar aberta em
 * qualquer ponto do dia para o dia inteiro nunca fechar. Isso quebrava de
 * verdade nos dias com DUAS entradas — gente que tocou "Entrada" duas vezes, ou
 * que bateu "Entrada" no lugar de "Volta do almoço", ou os dias antigos de
 * quando o sistema permitia 3 jornadas por dia. Nesses dias:
 *
 *   entrada 08:00 | entrada 13:00 | saída 17:00
 *   ^ essa primeira ficava aberta para sempre
 *
 * O funcionário pedia o fechamento, o administrador autorizava, a saída era
 * gravada — e o aviso "Dia sem saída" continuava lá, porque a primeira jornada
 * seguia aberta. Pedir de novo não adiantava: cada pedido só cria UMA saída, e
 * ela sempre fechava a última jornada. Laço sem fim.
 *
 * Pior: `jornada_fechada()` também é a peneira do histórico e dos relatórios, e
 * esses dias eram descartados de TODOS os totais em silêncio.
 *
 * Agora uma entrada solta no meio do dia é tratada pelo que ela é — uma batida
 * repetida — e não impede o dia de fechar. Ela continua sem somar hora nenhuma
 * (o sistema não inventa tempo que ninguém comprovou); quem corrige a batida
 * duplicada é o administrador, na tela de Ajustes.
 */
function ponto_jornada_aberta(array $registros): ?array
{
    $jornadas = ponto_jornadas_from_registros($registros);
    if (!$jornadas) {
        return null;
    }
    $ultima = $jornadas[count($jornadas) - 1];
    return !empty($ultima['aberta']) ? $ultima : null;
}

function jornada_fechada(array $registros): bool
{
    $jornadas = ponto_jornadas_from_registros($registros);
    if (!$jornadas) {
        return false;
    }
    return empty($jornadas[count($jornadas) - 1]['aberta']);
}

function jornada_iniciada(array $registros): bool
{
    foreach ($registros as $r) {
        if (($r['tipo'] ?? '') === 'entrada') {
            return true;
        }
    }
    return false;
}

function data_ponto_ativa(int $funcionarioId, ?string $data = null): string
{
    $data = $data ?: today_sp();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $data = today_sp();
    }

    $registrosHoje = registros_do_dia($funcionarioId, $data);
    if (jornada_iniciada($registrosHoje)) {
        return $data;
    }

    // Turno noturno: depois da meia-noite ainda deve continuar a jornada do dia anterior.
    // Sem isso, o sistema oferece uma nova Entrada e o PDF fica com o dia anterior em aberto.
    $ontem = date('Y-m-d', strtotime($data . ' -1 day'));
    $registrosOntem = registros_do_dia($funcionarioId, $ontem);
    if (jornada_iniciada($registrosOntem) && !jornada_fechada($registrosOntem)) {
        return $ontem;
    }

    return $data;
}

/**
 * Dias ANTERIORES que ficaram sem a saída final.
 *
 * data_ponto_ativa() só enxerga um dia para trás e desiste assim que o dia de
 * hoje tem batida — então um dia esquecido some do app e nunca mais fecha.
 * Esta função acha esses dias para a tela poder cobrar o fechamento.
 *
 * @return array<int, array{data:string, data_br:string, entrada:string, entrada_dt:string, pedido:string}>
 */
function ponto_dias_em_aberto(int $funcionarioId, int $diasAtras = 10): array
{
    $hoje = today_sp();
    $ativa = data_ponto_ativa($funcionarioId, $hoje);

    /* Dias que já têm pedido de fechamento esperando resposta. A tela precisa
     * dizer isso: sem essa informação o funcionário via o mesmo aviso vermelho
     * depois de mandar o pedido, achava que não tinha ido e tentava de novo —
     * aí levava "você já pediu o fechamento desse dia", que parece erro. */
    $pedidos = [];
    try {
        ensure_solicitacoes_table();
        $stmtP = db()->prepare("SELECT data_ponto, id FROM ponto_solicitacoes
            WHERE funcionario_id = ? AND status = 'pendente' AND tipo_registro = 'saida'
              AND data_ponto IS NOT NULL");
        $stmtP->execute([$funcionarioId]);
        foreach ($stmtP->fetchAll() as $p) {
            $pedidos[(string)$p['data_ponto']] = '#' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
        }
    } catch (Throwable $e) {
        // sem a tabela ainda: a tela só não mostra o protocolo
    }

    $out = [];
    for ($i = $diasAtras; $i >= 1; $i--) {
        $dia = date('Y-m-d', strtotime($hoje . ' -' . $i . ' day'));
        if ($dia === $ativa) {
            continue; // esse já é o dia em que a pessoa está batendo
        }

        $registros = registros_do_dia($funcionarioId, $dia);
        if (!$registros || !jornada_iniciada($registros) || jornada_fechada($registros)) {
            continue;
        }

        $entradaDt = '';
        foreach ($registros as $r) {
            if ((string)$r['tipo'] === 'entrada') {
                $entradaDt = (string)$r['data_hora'];
                break;
            }
        }

        $out[] = [
            'data' => $dia,
            'data_br' => date('d/m/Y', strtotime($dia)),
            'entrada' => $entradaDt !== '' ? date('H:i', strtotime($entradaDt)) : '--:--',
            'entrada_dt' => $entradaDt,
            'pedido' => (string)($pedidos[$dia] ?? ''),
        ];
    }
    return $out;
}

function has_pair(array $registros, string $saidaTipo, string $voltaTipo): bool
{
    $saida = false;
    foreach ($registros as $r) {
        if ($r['tipo'] === $saidaTipo) {
            $saida = true;
        }
        if ($saida && $r['tipo'] === $voltaTipo) {
            return true;
        }
    }
    return false;
}

function open_interval(array $registros): ?array
{
    $stack = null;
    foreach ($registros as $r) {
        if (in_array($r['tipo'], ['saida_almoco', 'saida_janta', 'saida_descanso'], true)) {
            $stack = $r;
        }
        if ($stack && (($stack['tipo'] === 'saida_almoco' && $r['tipo'] === 'volta_almoco') || ($stack['tipo'] === 'saida_janta' && $r['tipo'] === 'volta_janta') || ($stack['tipo'] === 'saida_descanso' && $r['tipo'] === 'volta_descanso'))) {
            $stack = null;
        }
    }
    return $stack;
}

function allowed_ponto_actions(int $funcionarioId, string $data): array
{
    $registros = registros_do_dia($funcionarioId, $data);
    $jornadas = ponto_jornadas_from_registros($registros);
    $qtdJornadas = count($jornadas);
    $jornadaAberta = ponto_jornada_aberta($registros);

    if ($qtdJornadas === 0) {
        return ['entrada' => 'Entrada'];
    }

    if ($jornadaAberta === null) {
        if ($qtdJornadas < ponto_max_jornadas_por_dia()) {
            $proxima = $qtdJornadas + 1;
            return ['entrada' => 'Entrada da ' . ponto_ordem_jornada_label($proxima)];
        }
        return [];
    }

    $eventosAtuais = $jornadaAberta['eventos'] ?? [];
    $open = open_interval($eventosAtuais);

    /* Intervalo aberto (esqueceu de bater a volta do almoço/janta): mostra a
     * volta, mas a SAÍDA FINAL continua disponível. Sem isso quem vai embora
     * sem ter batido a volta fica preso e não consegue encerrar o dia. */
    if ($open) {
        $actions = [];
        if ($open['tipo'] === 'saida_almoco')        $actions['volta_almoco'] = 'Volta do almoço';
        elseif ($open['tipo'] === 'saida_janta')     $actions['volta_janta'] = 'Volta da janta';
        elseif ($open['tipo'] === 'saida_descanso')  $actions['volta_descanso'] = 'Volta do descanso';

        $actions['saida'] = 'Saída final';
        if ($data !== today_sp()) {
            $actions['entrada'] = 'Entrada de hoje';
        }
        return $actions;
    }

    $actions = [];

    /* A jornada aberta é de um dia anterior (esqueceu de bater a saída ontem).
     * Precisa poder começar o dia de hoje mesmo assim, senão fica travado até
     * o administrador corrigir. A entrada é gravada no dia de hoje. */
    if ($data !== today_sp()) {
        $actions['entrada'] = 'Entrada de hoje';
    }
    /* Almoço e janta só aparecem na faixa de horário de cada refeição. Fora
     * dela o botão some, para ninguém bater janta de manhã por engano. A volta
     * é tratada acima e nunca é bloqueada: quem saiu precisa poder voltar. */
    if (!has_pair($eventosAtuais, 'saida_almoco', 'volta_almoco') && ponto_hora_de_almoco()) {
        $actions['saida_almoco'] = 'Saída para almoço';
    }
    if (!has_pair($eventosAtuais, 'saida_janta', 'volta_janta') && ponto_hora_de_janta()) {
        $actions['saida_janta'] = 'Saída para janta';
    }
    if (!has_pair($eventosAtuais, 'saida_descanso', 'volta_descanso')) {
        $actions['saida_descanso'] = 'Saída para descanso';
    }
    $actions['saida'] = 'Saída final';
    return $actions;
}


/* Faixas de horário das refeições. Almoço a partir das 12:00 e janta a partir
 * das 18:00; fora disso o botão não aparece na tela de bater ponto.
 * O "fim" é a última hora cheia em que a opção ainda aparece: 17 quer dizer
 * que o almoço fica disponível até 17:59. Mude aqui para ajustar. */
const PONTO_ALMOCO_HORA_INICIO = 12;
const PONTO_ALMOCO_HORA_FIM = 17;
const PONTO_JANTA_HORA_INICIO = 18;
const PONTO_JANTA_HORA_FIM = 23;

function ponto_hora_de_almoco(?int $hora = null): bool
{
    $hora = $hora === null ? (int)date('G') : $hora;
    return $hora >= PONTO_ALMOCO_HORA_INICIO && $hora <= PONTO_ALMOCO_HORA_FIM;
}

function ponto_hora_de_janta(?int $hora = null): bool
{
    $hora = $hora === null ? (int)date('G') : $hora;
    return $hora >= PONTO_JANTA_HORA_INICIO && $hora <= PONTO_JANTA_HORA_FIM;
}

function tipo_ponto_label(string $tipo): string
{
    $labels = [
        'entrada' => 'Entrada',
        'saida_almoco' => 'Saída para almoço',
        'volta_almoco' => 'Volta do almoço',
        'saida_janta' => 'Saída para janta',
        'volta_janta' => 'Volta da janta',
        'saida_descanso' => 'Saída para descanso',
        'volta_descanso' => 'Volta do descanso',
        'saida' => 'Saída final',
    ];
    return $labels[$tipo] ?? $tipo;
}

function save_photo_base64(string $base64, int $funcionarioId, string $tipo): array
{
    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64, $m)) {
        throw new RuntimeException('Foto inválida. Capture uma foto pela câmera antes de bater o ponto.');
    }

    $mimeExt = strtolower($m[1]);
    $ext = $mimeExt === 'jpeg' ? 'jpg' : $mimeExt;
    $clean = preg_replace('/^data:image\/[a-zA-Z0-9+.-]+;base64,/', '', $base64);
    $bin = base64_decode($clean, true);
    if ($bin === false || strlen($bin) < 2000) {
        throw new RuntimeException('Foto muito pequena ou inválida. Capture novamente.');
    }
    if (strlen($bin) > 8 * 1024 * 1024) {
        throw new RuntimeException('Foto muito grande. Capture em qualidade menor.');
    }
    if (!@getimagesizefromstring($bin)) {
        throw new RuntimeException('O arquivo enviado não parece ser uma imagem válida.');
    }

    $dirRel = 'uploads/ponto_fotos/' . date('Y') . '/' . date('m');
    $dirAbs = __DIR__ . '/' . $dirRel;
    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de fotos.');
    }

    $name = 'func_' . $funcionarioId . '_' . preg_replace('/[^a-z0-9_]/i', '_', $tipo) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $pathAbs = $dirAbs . '/' . $name;
    $pathRel = $dirRel . '/' . $name;

    if (file_put_contents($pathAbs, $bin, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar a foto.');
    }

    return [$pathRel, 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext), strlen($bin)];
}

/**
 * Troca só o horário de uma batida, escolhendo o DIA certo: entre ontem, o dia
 * dela e amanhã, vale o mais próximo do horário antigo.
 *
 * Antes o dia ficava sempre o da batida antiga. Corrigir uma entrada de 23:50
 * para 00:10 gravava 00:10 do MESMO dia (23h40 antes), e a saída da madrugada
 * do turno noturno não podia ser corrigida para o outro lado da meia-noite.
 *
 * @param string $hora  "HH:MM"
 * @param string $atual data_hora gravada hoje ("Y-m-d H:i:s")
 */
function ponto_data_hora_mais_proxima(string $hora, string $atual): string
{
    $tsAtual = strtotime($atual);
    $base = date('Y-m-d', $tsAtual);
    $melhor = $base . ' ' . $hora . ':00';
    $menor = abs(strtotime($melhor) - $tsAtual);
    foreach (['-1 day', '+1 day'] as $desloc) {
        $cand = date('Y-m-d', strtotime($base . ' ' . $desloc)) . ' ' . $hora . ':00';
        $dist = abs(strtotime($cand) - $tsAtual);
        if ($dist < $menor) {
            $menor = $dist;
            $melhor = $cand;
        }
    }
    return $melhor;
}

/** Latitude/longitude só entram no banco se forem números de verdade. */
function ponto_coordenada($valor, float $limite): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '' || !is_numeric($valor)) {
        return null;
    }
    $n = (float)$valor;
    return abs($n) <= $limite ? rtrim(rtrim(number_format($n, 7, '.', ''), '0'), '.') : null;
}

function diff_minutes(?string $start, ?string $end): int
{
    if (!$start || !$end) return 0;
    $s = strtotime($start);
    $e = strtotime($end);
    if (!$s || !$e || $e <= $s) return 0;
    return (int)floor(($e - $s) / 60);
}

function calculate_interval_minutes(array $registros, string $saidaTipo, string $voltaTipo): int
{
    $registros = ponto_normalizar_turno_noite_registros($registros);
    $total = 0;
    $start = null;
    foreach ($registros as $r) {
        if ($r['tipo'] === $saidaTipo) {
            $start = $r['data_hora'];
        } elseif ($r['tipo'] === $voltaTipo && $start) {
            $total += diff_minutes($start, $r['data_hora']);
            $start = null;
        }
    }
    return $total;
}

/* Se ligado, o PDF fecha o turno noturno sem saída batida com um horário
 * estimado entre 05:50 e 06:00 (comportamento do relatório antigo). O horário
 * estimado sai marcado com * e com legenda no rodapé.
 * Deixe false para o PDF mostrar exatamente o que foi batido — aí PDF e telas
 * ficam com números idênticos. */
const PONTO_PDF_ESTIMA_SAIDA_NOTURNA = true;

/* Teto do trecho que ainda está aberto quando a tela conta "até agora".
 *
 * Sem isso, quem esqueceu de bater a saída ontem abria o app hoje e via o card
 * HOJE com 26h30 — o sistema somava desde a entrada de ontem até este minuto.
 * 16h cobre com folga qualquer turno real (inclusive 12x36 com hora a mais); do
 * teto para cima o trecho é tratado como esquecido e não soma nada, que é o
 * mesmo que a tela já diz no painel vermelho "Dia sem saída". */
const PONTO_JORNADA_ABERTA_MAX_MIN = 960;

/**
 * Turno noturno sem saída batida: gera uma saída entre 05:50 e 06:00 da
 * madrugada seguinte. É determinístico pela entrada, então o mesmo dia gera
 * sempre o mesmo horário.
 */
function ponto_saida_noturna_estimada(string $entradaDt): string
{
    $ts = strtotime($entradaDt);
    if (!$ts) return $entradaDt;
    $diaSeguinte = strtotime(date('Y-m-d', strtotime('+1 day', $ts)) . ' 00:00:00');
    mt_srand($ts);
    $seg = mt_rand(21000, 21600); // 05:50:00 = 21000s, 06:00:00 = 21600s
    mt_srand();
    return date('Y-m-d H:i:s', $diaSeguinte + $seg);
}

/**
 * CONTA AS HORAS DE UMA JORNADA. Esta é a única função que faz essa conta —
 * telas, resumo diário, relatórios e PDF passam todos por aqui, para nunca
 * mais darem números diferentes para o mesmo dia.
 *
 * Regras:
 *   entrada .......... começa a contar trabalho
 *   saída de almoço/janta/descanso ... fecha o trecho de trabalho e abre intervalo
 *   volta ............ fecha o intervalo e volta a contar trabalho
 *   saída final ...... fecha o que estiver aberto
 *
 * Quem sai sem ter batido a volta do intervalo tem o intervalo fechado na
 * saída e nada é contado como trabalho nesse trecho.
 *
 * Opções:
 *   'agora'                  => fecha o trecho ainda aberto até esse horário
 *                               (é o que alimenta o card "Hoje" em tempo real)
 *   'estimar_saida_noturna'  => fecha turno noturno sem saída com horário estimado
 *
 * @return array{trabalho_min:int, intervalo_min:int, pares_trabalho:array,
 *               pares_intervalo:array, trabalho_aberto:bool,
 *               intervalo_aberto:bool, saida_estimada:?string}
 */
function ponto_calcular_jornada(array $eventos, array $opcoes = []): array
{
    $agora = $opcoes['agora'] ?? null;
    $estimarNoturno = !empty($opcoes['estimar_saida_noturna']);

    $eventos = ponto_normalizar_turno_noite_registros($eventos);

    $inicioTrabalho = null;
    $inicioIntervalo = null;
    $trabalho = 0;
    $intervalo = 0;
    $paresTrabalho = [];
    $paresIntervalo = [];
    $saidaEstimada = null;

    $abreIntervalo = ['saida_almoco' => true, 'saida_janta' => true, 'saida_descanso' => true];
    $fechaIntervalo = ['volta_almoco' => true, 'volta_janta' => true, 'volta_descanso' => true];

    foreach ($eventos as $r) {
        $tipo = (string)($r['tipo'] ?? '');
        $dt = (string)($r['data_hora'] ?? '');
        if ($dt === '') continue;

        if ($tipo === 'entrada') {
            $inicioTrabalho = $dt;
            $inicioIntervalo = null;
            continue;
        }

        if (isset($abreIntervalo[$tipo])) {
            if ($inicioTrabalho) {
                $trabalho += diff_minutes($inicioTrabalho, $dt);
                $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => $dt, 'estimado' => false];
                $inicioTrabalho = null;
            }
            $inicioIntervalo = $dt;
            continue;
        }

        if (isset($fechaIntervalo[$tipo])) {
            if ($inicioIntervalo) {
                $intervalo += diff_minutes($inicioIntervalo, $dt);
                $paresIntervalo[] = ['ini' => $inicioIntervalo, 'fim' => $dt, 'estimado' => false];
                $inicioIntervalo = null;
            }
            $inicioTrabalho = $dt;
            continue;
        }

        if ($tipo === 'saida') {
            if ($inicioTrabalho) {
                $trabalho += diff_minutes($inicioTrabalho, $dt);
                $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => $dt, 'estimado' => false];
            } elseif ($inicioIntervalo) {
                $intervalo += diff_minutes($inicioIntervalo, $dt);
                $paresIntervalo[] = ['ini' => $inicioIntervalo, 'fim' => $dt, 'estimado' => false];
            }
            $inicioTrabalho = null;
            $inicioIntervalo = null;
        }
    }

    $trabalhoAberto = $inicioTrabalho !== null;
    $intervaloAberto = $inicioIntervalo !== null;

    if ($inicioTrabalho !== null) {
        /* Trecho aberto há mais tempo do que qualquer turno real: a pessoa
         * esqueceu de bater a saída. Contar até agora somaria dezenas de horas
         * que ninguém trabalhou, então ele fica aberto e não soma. */
        $abertoDemais = $agora && diff_minutes($inicioTrabalho, $agora) > PONTO_JORNADA_ABERTA_MAX_MIN;

        if ($agora && !$abertoDemais) {
            $trabalho += diff_minutes($inicioTrabalho, $agora);
            $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => $agora, 'estimado' => true];
        } elseif ($abertoDemais) {
            $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => null, 'estimado' => false];
        } elseif ($estimarNoturno && ponto_eh_entrada_noite($inicioTrabalho)) {
            $saidaEstimada = ponto_saida_noturna_estimada($inicioTrabalho);
            $trabalho += diff_minutes($inicioTrabalho, $saidaEstimada);
            $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => $saidaEstimada, 'estimado' => true];
        } else {
            $paresTrabalho[] = ['ini' => $inicioTrabalho, 'fim' => null, 'estimado' => false];
        }
    }
    if ($inicioIntervalo !== null) {
        $paresIntervalo[] = ['ini' => $inicioIntervalo, 'fim' => null, 'estimado' => false];
    }

    return [
        'trabalho_min' => $trabalho,
        'intervalo_min' => $intervalo,
        'pares_trabalho' => $paresTrabalho,
        'pares_intervalo' => $paresIntervalo,
        'trabalho_aberto' => $trabalhoAberto,
        'intervalo_aberto' => $intervaloAberto,
        'saida_estimada' => $saidaEstimada,
    ];
}

/** Minutos trabalhados na jornada, contando só o que foi realmente batido. */
function trabalho_minutos_em_eventos(array $eventos): int
{
    $calc = ponto_calcular_jornada($eventos);
    return $calc['trabalho_min'];
}

function recalc_resumo(int $funcionarioId, string $data): void
{
    $func = get_funcionario($funcionarioId);
    if (!$func) return;

    $registros = registros_do_dia($funcionarioId, $data);
    if (!$registros) return;

    $jornadas = ponto_jornadas_from_registros($registros);
    $entrada = null;
    $saida = null;
    $status = 'aberto';
    $trabalho = 0;

    foreach ($jornadas as $jornada) {
        $eventos = $jornada['eventos'] ?? [];
        foreach ($eventos as $r) {
            if ($r['tipo'] === 'entrada' && !$entrada) {
                $entrada = $r['data_hora'];
            }
            if ($r['tipo'] === 'saida') {
                $saida = $r['data_hora'];
            }
        }
        $trabalho += trabalho_minutos_em_eventos($eventos);
    }

    $almoco = calculate_interval_minutes($registros, 'saida_almoco', 'volta_almoco');
    $janta = calculate_interval_minutes($registros, 'saida_janta', 'volta_janta');
    $descanso = calculate_interval_minutes($registros, 'saida_descanso', 'volta_descanso');
    $intervalos = $almoco + $janta + $descanso;

    if ($jornadas && jornada_fechada($registros)) {
        $status = 'fechado';
    }
    if (open_interval($registros)) {
        $status = 'incompleto';
    }

    $stmt = db()->prepare("INSERT INTO ponto_resumo_diario
        (funcionario_id, condominio_id, data_ponto, entrada, saida, total_trabalho_minutos, total_almoco_minutos, total_janta_minutos, total_descanso_minutos, total_geral_intervalos_minutos, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            condominio_id = VALUES(condominio_id), entrada = VALUES(entrada), saida = VALUES(saida),
            total_trabalho_minutos = VALUES(total_trabalho_minutos), total_almoco_minutos = VALUES(total_almoco_minutos),
            total_janta_minutos = VALUES(total_janta_minutos), total_descanso_minutos = VALUES(total_descanso_minutos),
            total_geral_intervalos_minutos = VALUES(total_geral_intervalos_minutos), status = VALUES(status)");
    $stmt->execute([(int)$func['id'], (int)$func['condominio_id'], $data, $entrada, $saida, $trabalho, $almoco, $janta, $descanso, $intervalos, $status]);
}

function fmt_minutes(int $minutes): string
{
    $h = intdiv(max(0, $minutes), 60);
    $m = max(0, $minutes) % 60;
    return sprintf('%02dh%02d', $h, $m);
}

function log_action(string $acao, string $descricao = '', ?int $funcionarioId = null, ?int $condominioId = null): void
{
    if (!table_exists('ponto_logs')) return;
    $stmt = db()->prepare("INSERT INTO ponto_logs (admin_id, funcionario_id, condominio_id, acao, descricao, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([admin_id(), $funcionarioId, $condominioId, $acao, $descricao, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
}

/* =============================================================================
 * App mobile do funcionário (bater ponto / histórico / solicitar ajuste)
 * ========================================================================== */

/* Número do WhatsApp do administrador que recebe as solicitações de ajuste.
 * Formato internacional, só dígitos: 55 (Brasil) + DDD + número. */
const PONTO_WHATSAPP_ADMIN = '5511963331767';

/* Referências usadas só para as barras de progresso e o resumo de extras/atrasos
 * do app. Não existe jornada contratual cadastrada por funcionário no banco;
 * quando isso for criado, troque estas constantes pela carga do cargo. */
const PONTO_JORNADA_PADRAO_MINUTOS = 480;   // 8h por dia trabalhado
const PONTO_SEMANA_PADRAO_MINUTOS = 2640;   // 44h por semana (cargo sem horário)

/* Trava de força bruta do PIN: o PIN tem só 4 dígitos, então sem isso dá para
 * varrer as 10 mil combinações em minutos. */
const PONTO_PIN_MAX_TENTATIVAS = 5;
const PONTO_PIN_BLOQUEIO_SEGUNDOS = 300;

function ponto_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

function ensure_pin_tentativas_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ponto_pin_tentativas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        tentativas INT NOT NULL DEFAULT 0,
        bloqueado_ate DATETIME NULL,
        atualizado_em DATETIME NOT NULL,
        UNIQUE KEY uq_ponto_pin_ip (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* A mesma trava serve para o PIN do funcionário e para a senha do admin, mas
 * com contadores separados: errar o PIN no portão não pode bloquear o login do
 * administrador, nem o contrário. */
function ponto_tentativa_chave(string $escopo = 'pin'): string
{
    return substr($escopo . ':' . ponto_client_ip(), 0, 45);
}

function ponto_pin_guard(string $escopo = 'pin'): void
{
    try {
        ensure_pin_tentativas_table();
        $stmt = db()->prepare("SELECT bloqueado_ate FROM ponto_pin_tentativas WHERE ip = ?");
        $stmt->execute([ponto_tentativa_chave($escopo)]);
        $ate = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return; // a trava nunca pode impedir alguém de bater o ponto
    }

    if ($ate && strtotime((string)$ate) > time()) {
        $faltam = (int)ceil((strtotime((string)$ate) - time()) / 60);
        $oQue = $escopo === 'admin' ? 'senha' : 'PIN';
        throw new RuntimeException('Muitas tentativas de ' . $oQue . ' erradas. Tente novamente em ' . max(1, $faltam) . ' minuto(s).');
    }
}

function ponto_pin_falhou(string $escopo = 'pin'): void
{
    try {
        ensure_pin_tentativas_table();
        /* A ordem importa: o MySQL aplica o SET da esquerda para a direita, então
         * bloqueado_ate precisa ser calculado antes de tentativas ser sobrescrita. */
        $inicioJanela = date('Y-m-d H:i:s', time() - PONTO_PIN_BLOQUEIO_SEGUNDOS);
        db()->prepare("INSERT INTO ponto_pin_tentativas (ip, tentativas, atualizado_em) VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                bloqueado_ate = IF(IF(atualizado_em < ?, 1, tentativas + 1) >= ?, ?, bloqueado_ate),
                tentativas = IF(atualizado_em < ?, 1, tentativas + 1),
                atualizado_em = VALUES(atualizado_em)")
            ->execute([
                ponto_tentativa_chave($escopo),
                now_sp(),
                $inicioJanela,
                PONTO_PIN_MAX_TENTATIVAS,
                date('Y-m-d H:i:s', time() + PONTO_PIN_BLOQUEIO_SEGUNDOS),
                $inicioJanela,
            ]);
    } catch (Throwable $e) {
        // silencioso de propósito
    }
}

function ponto_pin_acertou(string $escopo = 'pin'): void
{
    try {
        ensure_pin_tentativas_table();
        db()->prepare("DELETE FROM ponto_pin_tentativas WHERE ip = ?")->execute([ponto_tentativa_chave($escopo)]);
    } catch (Throwable $e) {
        // silencioso de propósito
    }
}

function ensure_solicitacoes_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ponto_solicitacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        funcionario_id INT NOT NULL,
        condominio_id INT NOT NULL,
        registro_id INT NULL,
        tipo_solicitacao VARCHAR(10) NOT NULL DEFAULT 'alterar',
        data_ponto DATE NULL,
        data_hora_solicitada DATETIME NULL,
        data_hora_atual DATETIME NULL,
        tipo_registro VARCHAR(30) NULL,
        motivo TEXT NOT NULL,
        foto_path VARCHAR(255) NULL,
        foto_mime VARCHAR(60) NULL,
        foto_tamanho INT NULL,
        status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
        admin_id INT NULL,
        resposta_admin TEXT NULL,
        criado_em DATETIME NOT NULL,
        respondido_em DATETIME NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        KEY idx_sol_funcionario (funcionario_id),
        KEY idx_sol_condominio (condominio_id),
        KEY idx_sol_status (status),
        KEY idx_sol_data (data_ponto),
        KEY idx_sol_registro (registro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Instalações que já criaram a tabela na versão anterior ganham as colunas
     * novas aqui, sem precisar rodar SQL na mão. */
    $novas = [
        'registro_id' => "ADD COLUMN registro_id INT NULL AFTER condominio_id",
        'tipo_solicitacao' => "ADD COLUMN tipo_solicitacao VARCHAR(10) NOT NULL DEFAULT 'alterar' AFTER registro_id",
        'data_hora_atual' => "ADD COLUMN data_hora_atual DATETIME NULL AFTER data_hora_solicitada",
    ];
    foreach ($novas as $coluna => $sql) {
        try {
            if (!column_exists('ponto_solicitacoes', $coluna)) {
                db()->exec("ALTER TABLE ponto_solicitacoes " . $sql);
            }
        } catch (Throwable $e) {
            // segue sem a coluna: o erro aparece na tela que precisar dela
        }
    }
}

function ensure_advertencias_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ponto_advertencias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        funcionario_id INT NOT NULL,
        condominio_id INT NOT NULL,
        data_advertencia DATE NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'escrita',
        motivo TEXT NOT NULL,
        admin_id INT NULL,
        criado_em DATETIME NOT NULL,
        KEY idx_adv_funcionario (funcionario_id),
        KEY idx_adv_condominio (condominio_id),
        KEY idx_adv_data (data_advertencia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ponto_advertencia_tipo_label(string $tipo): string
{
    $labels = [
        'verbal' => 'Advertência verbal',
        'escrita' => 'Advertência escrita',
        'suspensao' => 'Suspensão',
    ];
    return $labels[$tipo] ?? 'Advertência';
}

/** Advertências do funcionário dentro de um intervalo de datas. */
function ponto_advertencias_periodo(int $funcionarioId, string $ini, string $fim): array
{
    ensure_advertencias_table();
    $stmt = db()->prepare("SELECT * FROM ponto_advertencias
        WHERE funcionario_id = ? AND data_advertencia BETWEEN ? AND ?
        ORDER BY data_advertencia DESC, id DESC");
    $stmt->execute([$funcionarioId, $ini, $fim]);
    return $stmt->fetchAll();
}

function ponto_advertencias_total(int $funcionarioId): int
{
    ensure_advertencias_table();
    $stmt = db()->prepare("SELECT COUNT(*) FROM ponto_advertencias WHERE funcionario_id = ?");
    $stmt->execute([$funcionarioId]);
    return (int)$stmt->fetchColumn();
}

function ponto_solicitacao_status_label(string $status): string
{
    $labels = ['pendente' => 'Aguardando', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado'];
    return $labels[$status] ?? $status;
}

/**
 * Aplica de verdade um pedido de ajuste aprovado pelo administrador: corrige
 * ou cria a batida, grava o histórico em ponto_ajustes e refaz o resumo do dia.
 *
 * É esta função que a tela do admin deve chamar ao clicar em "Autorizar".
 *
 * @return string Resumo do que foi feito, para mostrar ao admin.
 */
function ponto_solicitacao_aprovar(int $solicitacaoId, ?int $adminId = null, string $resposta = '', string $tipoEscolhido = '', int $registroEscolhido = 0): string
{
    ensure_solicitacoes_table();

    $stmt = db()->prepare("SELECT * FROM ponto_solicitacoes WHERE id = ?");
    $stmt->execute([$solicitacaoId]);
    $s = $stmt->fetch();
    if (!$s) {
        throw new RuntimeException('Solicitação não encontrada.');
    }
    if ((string)$s['status'] !== 'pendente') {
        throw new RuntimeException('Essa solicitação já foi respondida.');
    }

    /* Pedidos antigos (feitos antes das colunas registro_id/tipo_registro
     * existirem) chegam sem dizer qual batida é. Nesses casos o admin escolhe
     * na hora de aprovar e a escolha completa o pedido. */
    if ($tipoEscolhido !== '' && tipo_ponto_label($tipoEscolhido) !== $tipoEscolhido) {
        $s['tipo_registro'] = $tipoEscolhido;
    }
    if ($registroEscolhido > 0) {
        $s['registro_id'] = $registroEscolhido;
        $s['tipo_solicitacao'] = 'alterar';
    } elseif ($tipoEscolhido !== '' && empty($s['registro_id'])) {
        $s['tipo_solicitacao'] = 'criar';
    }

    $novaDataHora = (string)($s['data_hora_solicitada'] ?? '');
    if (!$novaDataHora || !strtotime($novaDataHora)) {
        throw new RuntimeException('A solicitação não tem um horário válido.');
    }

    $funcionarioId = (int)$s['funcionario_id'];
    $protocolo = '#' . str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT);
    $motivo = 'Pedido do funcionário ' . $protocolo . ': ' . (string)$s['motivo'];
    $diasAfetados = [];

    db()->beginTransaction();
    try {
        /* Trava o pedido e confere de novo: se dois administradores tocassem em
         * "Autorizar" ao mesmo tempo, os dois passavam pela checagem lá de cima
         * e a batida era criada duas vezes. */
        $trava = db()->prepare("SELECT status FROM ponto_solicitacoes WHERE id = ? FOR UPDATE");
        $trava->execute([$solicitacaoId]);
        if ((string)$trava->fetchColumn() !== 'pendente') {
            throw new RuntimeException('Essa solicitação já foi respondida.');
        }

        if ((string)($s['tipo_solicitacao'] ?? 'criar') === 'criar' || empty($s['registro_id'])) {
            $tipo = (string)($s['tipo_registro'] ?? '');
            if ($tipo === '' || tipo_ponto_label($tipo) === $tipo) {
                throw new RuntimeException('Este pedido é antigo e não diz qual batida é. Escolha o tipo da batida antes de autorizar.');
            }
            $dataPonto = (string)($s['data_ponto'] ?: date('Y-m-d', strtotime($novaDataHora)));

            db()->prepare("INSERT INTO ponto_registros
                (funcionario_id, condominio_id, tipo, data_ponto, data_hora, observacao, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, '', 'solicitacao-funcionario')")
                ->execute([
                    $funcionarioId,
                    (int)$s['condominio_id'],
                    $tipo,
                    $dataPonto,
                    $novaDataHora,
                    'Batida criada a partir do pedido ' . $protocolo . ' aprovado em ' . now_sp() . '. Motivo: ' . (string)$s['motivo'],
                ]);
            $novoId = (int)db()->lastInsertId();

            db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'criar', NULL, ?, NULL, ?, ?, ?)")
                ->execute([$novoId, $funcionarioId, (int)$s['condominio_id'], $novaDataHora, $tipo, $motivo . ' | Dia da jornada/PDF: ' . $dataPonto, $adminId]);

            $diasAfetados[$dataPonto] = true;
            $descricao = tipo_ponto_label($tipo) . ' criada em ' . date('d/m/Y H:i', strtotime($novaDataHora));
        } else {
            $stmtR = db()->prepare("SELECT * FROM ponto_registros WHERE id = ?");
            $stmtR->execute([(int)$s['registro_id']]);
            $r = $stmtR->fetch();
            if (!$r) {
                throw new RuntimeException('A batida desse pedido não existe mais.');
            }
            if ((int)$r['funcionario_id'] !== $funcionarioId) {
                throw new RuntimeException('A batida não pertence a esse funcionário.');
            }

            db()->prepare("INSERT INTO ponto_ajustes (registro_id, funcionario_id, condominio_id, tipo_ajuste, data_hora_antiga, data_hora_nova, tipo_registro_antigo, tipo_registro_novo, motivo, admin_id) VALUES (?, ?, ?, 'alterar', ?, ?, ?, ?, ?, ?)")
                ->execute([
                    (int)$r['id'], $funcionarioId, (int)$r['condominio_id'],
                    $r['data_hora'], $novaDataHora, $r['tipo'], $r['tipo'],
                    $motivo . ' | Dia da jornada/PDF: ' . (string)$r['data_ponto'], $adminId,
                ]);

            /* O dia da jornada (data_ponto) não muda: o pedido corrige só o
             * horário de uma batida que já pertence àquele dia. */
            db()->prepare("UPDATE ponto_registros SET data_hora = ?, observacao = CONCAT(COALESCE(observacao,''), '\n[Ajustado pelo pedido ', ?, ']') WHERE id = ?")
                ->execute([$novaDataHora, $protocolo, (int)$r['id']]);

            $diasAfetados[(string)$r['data_ponto']] = true;
            $descricao = tipo_ponto_label((string)$r['tipo']) . ' de '
                . date('H:i', strtotime((string)$r['data_hora'])) . ' para '
                . date('H:i', strtotime($novaDataHora));
        }

        db()->prepare("UPDATE ponto_solicitacoes SET status = 'aprovado', admin_id = ?, resposta_admin = ?, respondido_em = ? WHERE id = ?")
            ->execute([$adminId, $resposta !== '' ? $resposta : null, now_sp(), $solicitacaoId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    foreach (array_keys($diasAfetados) as $dia) {
        recalc_resumo($funcionarioId, (string)$dia);
        $repetida = ponto_entrada_repetida_do_dia($funcionarioId, (string)$dia);
        if ($repetida !== '') {
            /* O dia fecha, mas uma entrada repetida faz o sistema perder o
             * trecho antes dela (não dá para saber se a pessoa trabalhou ou
             * saiu sem bater). O admin precisa saber para corrigir em Ajustes,
             * senão as horas ficam menores e ninguém entende por quê. */
            $descricao .= '. ATENÇÃO: o dia ' . date('d/m', strtotime((string)$dia))
                . ' tem uma Entrada repetida às ' . $repetida
                . ' — corrija em Ajustes, senão as horas ficam menores.';
        }
    }
    log_action('aprovar_solicitacao', 'Aprovou o pedido ' . $protocolo . ': ' . $descricao, $funcionarioId, (int)$s['condominio_id']);

    return $descricao;
}

/**
 * Hora da Entrada repetida de um dia, se existir.
 *
 * "Repetida" = uma jornada que abriu e nunca fechou, com outra jornada depois
 * dela. Na prática é gente que tocou Entrada duas vezes ou que bateu Entrada no
 * lugar de Volta do almoço.
 *
 * @return string 'HH:MM' da entrada que sobrou, ou '' se o dia estiver limpo.
 */
function ponto_entrada_repetida_do_dia(int $funcionarioId, string $data): string
{
    $jornadas = ponto_jornadas_from_registros(registros_do_dia($funcionarioId, $data));
    $total = count($jornadas);
    if ($total < 2) {
        return '';
    }
    for ($i = 0; $i < $total - 1; $i++) {
        if (!empty($jornadas[$i]['aberta'])) {
            $dt = (string)($jornadas[$i]['entrada']['data_hora'] ?? '');
            return $dt !== '' ? date('H:i', strtotime($dt)) : '';
        }
    }
    return '';
}

function ponto_solicitacao_recusar(int $solicitacaoId, ?int $adminId = null, string $resposta = ''): void
{
    ensure_solicitacoes_table();

    $stmt = db()->prepare("SELECT * FROM ponto_solicitacoes WHERE id = ?");
    $stmt->execute([$solicitacaoId]);
    $s = $stmt->fetch();
    if (!$s) {
        throw new RuntimeException('Solicitação não encontrada.');
    }
    if ((string)$s['status'] !== 'pendente') {
        throw new RuntimeException('Essa solicitação já foi respondida.');
    }

    $upd = db()->prepare("UPDATE ponto_solicitacoes SET status = 'recusado', admin_id = ?, resposta_admin = ?, respondido_em = ? WHERE id = ? AND status = 'pendente'");
    $upd->execute([$adminId, $resposta !== '' ? $resposta : null, now_sp(), $solicitacaoId]);
    if ($upd->rowCount() < 1) {
        throw new RuntimeException('Essa solicitação já foi respondida.');
    }

    log_action('recusar_solicitacao', 'Recusou o pedido #' . str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT), (int)$s['funcionario_id'], (int)$s['condominio_id']);
}

/* Autorização de curta duração emitida logo depois de um PIN correto, para a
 * pessoa ir do histórico até o formulário de ajuste sem digitar o PIN de novo. */
function ponto_token_emitir(int $funcionarioId, int $segundos = 900): string
{
    $token = bin2hex(random_bytes(16));

    /* Closure comum de propósito: "fn() =>" só existe do PHP 7.4 em diante e
     * derrubaria o sistema inteiro em hospedagem com PHP mais antigo. */
    $agora = time();
    $validos = [];
    foreach ((array)($_SESSION['ponto_tokens'] ?? []) as $chave => $dados) {
        if (is_array($dados) && (int)(isset($dados['exp']) ? $dados['exp'] : 0) > $agora) {
            $validos[$chave] = $dados;
        }
    }

    $validos[$token] = ['func' => $funcionarioId, 'exp' => $agora + $segundos];
    $_SESSION['ponto_tokens'] = $validos;
    return $token;
}

function ponto_token_funcionario(string $token): ?int
{
    $dados = $_SESSION['ponto_tokens'][$token] ?? null;
    if (!is_array($dados) || (int)($dados['exp'] ?? 0) <= time()) {
        unset($_SESSION['ponto_tokens'][$token]);
        return null;
    }
    return (int)$dados['func'];
}

/* Igual a trabalho_minutos_em_eventos(), mas conta o tempo da jornada que ainda
 * está aberta até agora. É o que alimenta o card "Hoje" do app. */
function ponto_trabalho_minutos_ate_agora(array $eventos, ?string $agora = null): int
{
    $calc = ponto_calcular_jornada($eventos, ['agora' => $agora ?: now_sp()]);
    return $calc['trabalho_min'];
}

function ponto_minutos_trabalhados_dia(int $funcionarioId, string $data, bool $contarAberta = true): int
{
    $lista = registros_jornada_dia_com_turno_noite($funcionarioId, $data);
    if (!$lista) return 0;

    $total = 0;
    foreach (ponto_jornadas_from_registros($lista) as $jornada) {
        $eventos = $jornada['eventos'] ?? [];
        $total += $contarAberta
            ? ponto_trabalho_minutos_ate_agora($eventos)
            : trabalho_minutos_em_eventos($eventos);
    }
    return $total;
}

function ponto_inicio_semana(?string $data = null): string
{
    $data = $data ?: today_sp();
    $ts = strtotime($data);
    $diaSemana = (int)date('N', $ts); // 1 = segunda
    return date('Y-m-d', strtotime('-' . ($diaSemana - 1) . ' days', $ts));
}

/* Minutos do card "Semana": segunda-feira até o dia informado.
 *
 * Só a jornada do dia ativo pode contar o tempo "até agora". Num dia antigo em
 * que a pessoa esqueceu de bater a saída, contar até agora somaria dezenas de
 * horas fantasmas na semana. */
function ponto_minutos_trabalhados_semana(int $funcionarioId, ?string $data = null): int
{
    $data = $data ?: today_sp();
    $ativa = data_ponto_ativa($funcionarioId, $data);

    $total = 0;
    $ts = strtotime(ponto_inicio_semana($data));
    $fimTs = strtotime($data);
    while ($ts !== false && $ts <= $fimTs) {
        $dia = date('Y-m-d', $ts);
        $total += ponto_minutos_trabalhados_dia($funcionarioId, $dia, $dia === $ativa);
        $ts = strtotime('+1 day', $ts);
    }
    return $total;
}

/* =============================================================================
 * Horário de trabalho por cargo
 *
 * Cada cargo guarda a hora de entrada, a de saída, quanto tempo de intervalo
 * não conta como trabalho e a tolerância de atraso. É daí que saem a jornada
 * esperada (para hora extra) e o horário certo de chegada (para atraso).
 *
 * Cargo sem horário preenchido cai na regra automática antiga (08:00 comercial
 * / 18:00 noturno, 8h por dia), então nada quebra enquanto você não cadastra.
 * ========================================================================== */
function ensure_cargo_horarios(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $novas = [
        'hora_entrada' => "ADD COLUMN hora_entrada TIME NULL",
        'hora_saida' => "ADD COLUMN hora_saida TIME NULL",
        'minutos_intervalo' => "ADD COLUMN minutos_intervalo INT NOT NULL DEFAULT 60",
        'tolerancia_minutos' => "ADD COLUMN tolerancia_minutos INT NOT NULL DEFAULT 15",
        /* NULL = sem limite de chegada antecipada (não controla). */
        'antecipacao_minutos' => "ADD COLUMN antecipacao_minutos INT NULL DEFAULT " . PONTO_ANTECIPACAO_PADRAO_MIN,
        /* 1 = dia sem nenhuma batida conta como falta (exceto domingo/feriado).
         * 0 = não controla (escala 12x36 / revezamento, em que o dia de folga varia). */
        'controla_falta' => "ADD COLUMN controla_falta TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($novas as $coluna => $sql) {
        try {
            if (!column_exists('ponto_cargos', $coluna)) {
                db()->exec("ALTER TABLE ponto_cargos " . $sql);
            }
        } catch (Throwable $e) {
            // segue sem a coluna: o sistema usa a regra automática
        }
    }
}

/** Converte 'HH:MM' ou 'HH:MM:SS' em minutos desde a meia-noite. */
function ponto_hhmm_para_min(?string $hora): ?int
{
    if (!$hora || !preg_match('/^(\d{1,2}):(\d{2})/', $hora, $m)) {
        return null;
    }
    return ((int)$m[1] * 60) + (int)$m[2];
}

/**
 * Jornada esperada a partir da linha do cargo.
 *
 * @return array{definido:bool, entrada_min:?int, saida_min:?int,
 *               jornada_min:int, intervalo_min:int, tolerancia:int,
 *               entrada:string, saida:string}
 */
function ponto_jornada_do_cargo(?array $cargo): array
{
    $padrao = [
        'definido' => false,
        'entrada_min' => null,
        'saida_min' => null,
        'jornada_min' => PONTO_JORNADA_PADRAO_MINUTOS,
        'intervalo_min' => 60,
        'tolerancia' => PONTO_TOLERANCIA_ATRASO_MIN,
        'antecipacao' => null,
        'controla_falta' => 1,
        'entrada' => '',
        'saida' => '',
    ];
    if (!$cargo) {
        return $padrao;
    }
    if (isset($cargo['controla_falta']) && $cargo['controla_falta'] !== '') {
        $padrao['controla_falta'] = (int)$cargo['controla_falta'] ? 1 : 0;
    }

    /* Sem horário cadastrado o sistema deduz 08:00/18:00 pela batida, mas a
     * tolerância de atraso que o administrador escolheu para o cargo continua
     * valendo. (O limite de chegada antecipada precisa de um horário fixo para
     * ter contra o que medir, então só funciona com entrada/saída preenchidas.) */
    if (isset($cargo['tolerancia_minutos']) && $cargo['tolerancia_minutos'] !== '') {
        $padrao['tolerancia'] = max(0, (int)$cargo['tolerancia_minutos']);
    }

    $entradaMin = ponto_hhmm_para_min((string)($cargo['hora_entrada'] ?? ''));
    $saidaMin = ponto_hhmm_para_min((string)($cargo['hora_saida'] ?? ''));
    if ($entradaMin === null || $saidaMin === null) {
        return $padrao;
    }

    /* Saída menor que a entrada = turno que vira a noite (18:00 → 06:00). */
    $bruto = $saidaMin > $entradaMin ? ($saidaMin - $entradaMin) : ((1440 - $entradaMin) + $saidaMin);
    $intervalo = max(0, (int)($cargo['minutos_intervalo'] ?? 60));
    /* Atraso tolerado: 0 = sem tolerância (todo minuto de atraso conta). */
    $tolerancia = max(0, (int)($cargo['tolerancia_minutos'] ?? PONTO_TOLERANCIA_ATRASO_MIN));
    /* Chegada antecipada tolerada: NULL/vazio = sem limite (não controla). */
    $antecipacao = (isset($cargo['antecipacao_minutos']) && $cargo['antecipacao_minutos'] !== '')
        ? max(0, (int)$cargo['antecipacao_minutos'])
        : null;

    return [
        'definido' => true,
        'entrada_min' => $entradaMin,
        'saida_min' => $saidaMin,
        'jornada_min' => max(0, $bruto - $intervalo),
        'intervalo_min' => $intervalo,
        'tolerancia' => $tolerancia,
        'antecipacao' => $antecipacao,
        'controla_falta' => $padrao['controla_falta'],
        'entrada' => sprintf('%02d:%02d', intdiv($entradaMin, 60), $entradaMin % 60),
        'saida' => sprintf('%02d:%02d', intdiv($saidaMin, 60), $saidaMin % 60),
    ];
}

/* Horários ADICIONAIS do cargo: quem faz escala (12x36, revezamento) pode
 * entrar em mais de um horário sem que nenhum deles conte como atraso. */
function ensure_cargo_turnos_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ponto_cargo_turnos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cargo_id INT NOT NULL,
        hora_entrada TIME NOT NULL,
        hora_saida TIME NOT NULL,
        minutos_intervalo INT NOT NULL DEFAULT 60,
        tolerancia_minutos INT NOT NULL DEFAULT 15,
        antecipacao_minutos INT NULL DEFAULT " . PONTO_ANTECIPACAO_PADRAO_MIN . ",
        descricao VARCHAR(60) NULL,
        criado_em DATETIME NOT NULL,
        KEY idx_turno_cargo (cargo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Tabela criada na versão anterior: ganha a coluna nova sozinha. */
    try {
        if (!column_exists('ponto_cargo_turnos', 'antecipacao_minutos')) {
            db()->exec("ALTER TABLE ponto_cargo_turnos ADD COLUMN antecipacao_minutos INT NULL DEFAULT " . PONTO_ANTECIPACAO_PADRAO_MIN . " AFTER tolerancia_minutos");
        }
    } catch (Throwable $e) {
        // segue sem a coluna: o turno extra usa a regra do cargo
    }
}

/**
 * Todos os horários que o cargo aceita: o principal (gravado em ponto_cargos)
 * mais os adicionais da tabela ponto_cargo_turnos.
 *
 * @return array<int, array> lista de jornadas no formato de ponto_jornada_do_cargo()
 */
function ponto_turnos_do_cargo(?array $cargo): array
{
    if (!$cargo) return [];

    $turnos = [];
    $principal = ponto_jornada_do_cargo($cargo);
    if ($principal['definido']) {
        $principal['id'] = 0;
        $principal['principal'] = true;
        $principal['horario'] = $principal['entrada'] . '-' . $principal['saida'];
        $principal['descricao'] = 'Principal';
        $turnos[] = $principal;
    }

    try {
        ensure_cargo_turnos_table();
        $stmt = db()->prepare("SELECT * FROM ponto_cargo_turnos WHERE cargo_id = ? ORDER BY hora_entrada");
        $stmt->execute([(int)$cargo['id']]);
        foreach ($stmt->fetchAll() as $t) {
            $j = ponto_jornada_do_cargo($t);
            if (!$j['definido']) continue;
            $j['id'] = (int)$t['id'];
            $j['principal'] = false;
            $j['horario'] = $j['entrada'] . '-' . $j['saida'];
            $j['descricao'] = (string)($t['descricao'] ?? '') ?: $j['horario'];
            $turnos[] = $j;
        }
    } catch (Throwable $e) {
        // segue só com o principal
    }

    /* Ordena pelo horário de entrada: a tela e o desempate ficam previsíveis,
     * independentemente de qual horário foi cadastrado primeiro.
     * (Closure comum, não "fn() =>": veja a nota em ponto_token_emitir().) */
    usort($turnos, function (array $a, array $b) {
        $ma = isset($a['entrada_min']) ? (int)$a['entrada_min'] : 0;
        $mb = isset($b['entrada_min']) ? (int)$b['entrada_min'] : 0;
        return $ma <=> $mb;
    });

    return $turnos;
}

/**
 * Turno usado quando não dá para deduzir pela batida (dia sem entrada): o
 * principal do cargo, que é o que o administrador declarou como padrão. A
 * lista vem ordenada por horário, então $turnos[0] nem sempre é ele.
 */
function ponto_turno_padrao(array $turnos): ?array
{
    foreach ($turnos as $t) {
        if (!empty($t['principal'])) {
            return $t;
        }
    }
    return $turnos ? $turnos[0] : null;
}

/** "06:00-14:00 · 14:00-22:00" — todos os horários aceitos, para mostrar na tela. */
function ponto_turnos_texto(array $turnos): string
{
    $partes = [];
    foreach ($turnos as $t) {
        $partes[] = (string)($t['horario'] ?? (($t['entrada'] ?? '') . '-' . ($t['saida'] ?? '')));
    }
    return implode(' · ', array_unique(array_filter($partes)));
}

/** Só os horários adicionais do cargo (linhas cruas da tabela), para a tela. */
function ponto_turnos_extras_do_cargo(int $cargoId): array
{
    try {
        ensure_cargo_turnos_table();
        $stmt = db()->prepare("SELECT * FROM ponto_cargo_turnos WHERE cargo_id = ? ORDER BY hora_entrada");
        $stmt->execute([$cargoId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Jornada esperada do funcionário (pelo cargo dele). Cacheia por requisição.
 * A chave 'turnos' traz todos os horários aceitos.
 */
function ponto_jornada_do_funcionario(int $funcionarioId): array
{
    static $cache = [];
    if (isset($cache[$funcionarioId])) {
        return $cache[$funcionarioId];
    }

    $cargo = null;
    try {
        ensure_cargo_horarios();
        $stmt = db()->prepare("SELECT c.* FROM ponto_funcionarios f
            INNER JOIN ponto_cargos c ON c.id = f.cargo_id
            WHERE f.id = ?");
        $stmt->execute([$funcionarioId]);
        $cargo = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $cargo = null;
    }

    $jornada = ponto_jornada_do_cargo($cargo);
    $turnos = ponto_turnos_do_cargo($cargo);
    $jornada['turnos'] = $turnos;
    /* Só o horário adicional já basta para o cargo ser considerado definido. */
    if (!$jornada['definido'] && $turnos) {
        $jornada = array_merge($turnos[0], ['turnos' => $turnos]);
    }

    /* Cargo de escala: quem lê estas chaves soltas (jornada_min, entrada,
     * saida) estaria olhando só o horário principal. 'multi' avisa que existe
     * mais de um horário e que o número certo do dia sai de
     * ponto_turno_do_dia(); 'turnos_texto' é o rótulo para a tela. */
    $jornada['multi'] = count($turnos) > 1;
    $jornada['turnos_texto'] = $turnos ? ponto_turnos_texto($turnos) : '';
    /* Se controla falta é do cargo (não dos horários extras, que não têm a flag). */
    $jornada['controla_falta'] = ($cargo && isset($cargo['controla_falta']) && $cargo['controla_falta'] !== '')
        ? ((int)$cargo['controla_falta'] ? 1 : 0)
        : 1;

    return $cache[$funcionarioId] = $jornada;
}

/**
 * Escolhe qual dos horários do cargo a pessoa cumpriu naquele dia: o que tem a
 * entrada mais perto da batida real. É assim que quem faz escala não aparece
 * atrasado por ter entrado no outro turno.
 *
 * Três detalhes que faziam o cargo de escala cair sempre no horário principal:
 *
 *  1. Olhava só a PRIMEIRA batida de entrada. Num dia com duas jornadas (ou
 *     com uma entrada corrigida na mão) o turno era decidido pela batida
 *     errada. Agora vale a entrada mais próxima entre todas as do dia.
 *  2. Empate ia para o primeiro da lista — que é sempre o principal. Quem tem
 *     06:00 e 14:00 e bate 10:00 ficava com 06:00 por sorteio. O desempate
 *     agora é por quanto a pessoa realmente trabalhou.
 *  3. Sem nenhuma entrada devolvia o principal calado; segue devolvendo, mas
 *     é o único caso em que isso acontece.
 */
function ponto_turno_do_dia(array $registros, ?array $jornada = null): array
{
    $turnos = ($jornada['turnos'] ?? []);
    if (!$turnos) {
        return $jornada ?: ponto_jornada_do_cargo(null);
    }
    if (count($turnos) === 1) {
        return $turnos[0];
    }

    $entradas = [];
    foreach ($registros as $r) {
        if ((string)($r['tipo'] ?? '') === 'entrada') {
            $min = ponto_minutos_hora((string)($r['data_hora'] ?? ''));
            if ($min !== null) {
                $entradas[] = $min;
            }
        }
    }
    if (!$entradas) {
        return ponto_turno_padrao($turnos) ?: $turnos[0];
    }

    /* Quanto a pessoa trabalhou de fato: é o desempate. Entre dois horários
     * igualmente distantes da batida, vale o que tem a jornada mais parecida
     * com o dia que ela cumpriu. */
    $trabalho = 0;
    foreach (ponto_jornadas_from_registros($registros) as $j) {
        $trabalho += trabalho_minutos_em_eventos($j['eventos'] ?? []);
    }

    $melhor = ponto_turno_padrao($turnos) ?: $turnos[0];
    $melhorChave = null;
    foreach ($turnos as $t) {
        $distancia = null;
        foreach ($entradas as $entrada) {
            /* Distância no relógio: 23:50 e 00:10 estão a 20 min, não a 23h40. */
            $d = abs($entrada - (int)$t['entrada_min']);
            $d = min($d, 1440 - $d);
            if ($distancia === null || $d < $distancia) {
                $distancia = $d;
            }
        }
        /* Critérios em ordem: 1) mais perto da batida; 2) jornada mais
         * parecida com a que ela cumpriu; 3) empatou tudo, fica o principal.
         * (PHP compara arrays de mesmo tamanho posição a posição.) */
        $chave = [
            $distancia,
            $trabalho > 0 ? abs($trabalho - (int)$t['jornada_min']) : 0,
            !empty($t['principal']) ? 0 : 1,
        ];
        if ($melhorChave === null || $chave < $melhorChave) {
            $melhorChave = $chave;
            $melhor = $t;
        }
    }
    return $melhor;
}

/**
 * O horário do cargo que vale para aquele dia, já com o rótulo pronto. É por
 * aqui que as telas devem passar quando o cargo tem mais de um horário — ler
 * $jornada['jornada_min'] direto devolve sempre o principal.
 *
 * @return array jornada do turno + chaves 'horario' e 'descricao'
 */
function ponto_turno_efetivo(array $registros, ?array $jornada = null): array
{
    $turno = ponto_turno_do_dia($registros, $jornada);
    if (!isset($turno['horario'])) {
        $turno['horario'] = !empty($turno['definido'])
            ? (($turno['entrada'] ?? '') . '-' . ($turno['saida'] ?? ''))
            : '';
    }
    if (!isset($turno['descricao'])) {
        $turno['descricao'] = $turno['horario'];
    }
    return $turno;
}

/* =============================================================================
 * Atraso pelo horário de entrada
 *
 * Dois turnos, com 15 minutos de tolerância cada:
 *   Comercial — entrada esperada 08:00; bateu depois das 08:15 é atraso.
 *   Noturno   — entrada esperada 18:00; bateu depois das 18:15 é atraso.
 *
 * O turno é deduzido da própria batida: entrada a partir das 17:00 é noturno,
 * antes disso é comercial. Quem chega adiantado nunca é marcado como atrasado.
 * ========================================================================== */
const PONTO_ENTRADA_COMERCIAL_MIN = 480;   // 08:00
const PONTO_ENTRADA_NOTURNO_MIN = 1080;    // 18:00
const PONTO_CORTE_TURNO_NOTURNO_MIN = 1020; // 17:00 — a partir daqui é turno da noite
const PONTO_TOLERANCIA_ATRASO_MIN = 15;
/* Padrão de "pode chegar antes": 10 min. Cargo novo já vem com 10, e os cargos que
 * já existiam quando a coluna foi criada ficam com 10. Campo em branco continua
 * sendo "sem limite" (NULL). */
const PONTO_ANTECIPACAO_PADRAO_MIN = 10;

/**
 * Horário de entrada esperado (minutos desde 00:00).
 *
 * Se o cargo tem horário cadastrado, é ele que manda. Sem cadastro, o sistema
 * deduz pelo próprio horário batido (08:00 comercial / 18:00 noturno).
 */
function ponto_entrada_esperada_min(?string $dataHora, ?array $jornada = null): int
{
    if ($jornada && !empty($jornada['definido']) && $jornada['entrada_min'] !== null) {
        return (int)$jornada['entrada_min'];
    }

    $min = ponto_minutos_hora($dataHora);
    if ($min === null) {
        return PONTO_ENTRADA_COMERCIAL_MIN;
    }
    return $min >= PONTO_CORTE_TURNO_NOTURNO_MIN
        ? PONTO_ENTRADA_NOTURNO_MIN
        : PONTO_ENTRADA_COMERCIAL_MIN;
}

/** Nome do turno da batida, para mostrar na tela. */
function ponto_turno_label(?string $dataHora): string
{
    $min = ponto_minutos_hora($dataHora);
    return ($min !== null && $min >= PONTO_CORTE_TURNO_NOTURNO_MIN) ? 'noturno' : 'comercial';
}

/**
 * Diferença, medida NO RELÓGIO, entre a batida e o horário esperado:
 * de -720 (bem adiantado) a +720 (bem atrasado), dando a volta na meia-noite.
 *
 * A subtração direta quebrava nos turnos que viram o dia: entrada esperada
 * 23:00 e batida às 00:05 dava 5 - 1380 = negativo e o atraso de 1h05 sumia;
 * ao contrário, turno das 00:30 com batida às 23:00 (adiantado) virava 22h de
 * "atraso".
 */
function ponto_diferenca_entrada_min(?string $dataHora, int $esperadoMin): ?int
{
    $min = ponto_minutos_hora($dataHora);
    if ($min === null) {
        return null;
    }
    $diferenca = $min - $esperadoMin;
    if ($diferenca > 720) {
        $diferenca -= 1440;
    } elseif ($diferenca < -720) {
        $diferenca += 1440;
    }
    return $diferenca;
}

/**
 * Minutos de atraso de uma entrada. Zero quando chegou dentro da tolerância
 * do cargo. O atraso é contado a partir do horário esperado, não da tolerância:
 * quem bate 08:20 (tolerância 15) tem 20 minutos de atraso, não 5.
 * Adiantado nunca é atraso.
 */
function ponto_atraso_entrada_minutos(?string $dataHora, ?array $jornada = null): int
{
    $esperado = ponto_entrada_esperada_min($dataHora, $jornada);
    $diferenca = ponto_diferenca_entrada_min($dataHora, $esperado);
    if ($diferenca === null) {
        return 0;
    }
    $tolerancia = ($jornada && isset($jornada['tolerancia']))
        ? (int)$jornada['tolerancia']
        : PONTO_TOLERANCIA_ATRASO_MIN;

    return $diferenca > $tolerancia ? $diferenca : 0;
}

/**
 * Chegada ANTECIPADA além do que o cargo permite. Devolve quantos minutos
 * antes do horário a pessoa bateu, mas só quando passou do limite do cargo;
 * zero se o cargo não tem limite (campo vazio) ou se ela chegou dentro dele.
 */
function ponto_antecipacao_entrada_minutos(?string $dataHora, ?array $jornada = null): int
{
    if (!$jornada || !isset($jornada['antecipacao']) || $jornada['antecipacao'] === null) {
        return 0;
    }
    $esperado = ponto_entrada_esperada_min($dataHora, $jornada);
    $diferenca = ponto_diferenca_entrada_min($dataHora, $esperado);
    if ($diferenca === null || $diferenca >= 0) {
        return 0;
    }
    return (-$diferenca) > (int)$jornada['antecipacao'] ? -$diferenca : 0;
}

/**
 * APURAÇÃO DO DIA — a conta que vale para telas, relatório e PDF.
 *
 * Parte do que a pessoa trabalhou de verdade e aplica as duas regras do cargo
 * (Configurações > Cargos):
 *
 *  - Atraso tolerado: chegar até N min depois da entrada não conta como falta.
 *    Passou de N, o atraso inteiro conta (08:20 com tolerância 15 = 20 min).
 *    Tolerância 0/vazia = todo minuto conta.
 *  - Chegada antecipada tolerada: chegar até N min antes é aceito. Antes disso o
 *    app AVISA E NÃO DEIXA bater a entrada (ponto_entrada_antecipada_bloqueio).
 *    Se existir uma batida mais cedo mesmo assim (lançada à mão pelo admin ou
 *    anterior à regra), o que passar do limite NÃO conta como trabalhado — só
 *    vale a partir de (entrada - N). Campo vazio = sem limite.
 *
 * Continua valendo a escolha do administrador: trabalhar além da jornada não
 * gera crédito, então a falta nunca é negativa.
 *
 * @param int|null $trabalhoBruto Minutos já contados por quem chama (ex.: o PDF
 *                                 inclui a saída noturna estimada). Null = conta aqui.
 * @return array{turno:array, jornada_min:int, trabalho_bruto_min:int,
 *               trabalho_min:int, atraso_chegada_min:int, atraso_tolerado_min:int,
 *               antecipacao_min:int, antecipacao_excedida_min:int, falta_min:int,
 *               entrada:?string, esperado_min:int}
 */
function ponto_apurar_dia(array $registros, ?array $jornada = null, ?int $trabalhoBruto = null): array
{
    $turno = ponto_turno_efetivo($registros, $jornada);
    $jornadaMin = (int)($turno['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS);

    if ($trabalhoBruto === null) {
        $trabalhoBruto = 0;
        foreach (ponto_jornadas_from_registros($registros) as $j) {
            $trabalhoBruto += trabalho_minutos_em_eventos($j['eventos'] ?? []);
        }
    }

    /* Primeira entrada do dia (a mais cedo). */
    $entradaDt = null;
    foreach ($registros as $r) {
        if ((string)($r['tipo'] ?? '') !== 'entrada') continue;
        $dt = (string)($r['data_hora'] ?? '');
        if ($dt !== '' && ($entradaDt === null || strcmp($dt, $entradaDt) < 0)) {
            $entradaDt = $dt;
        }
    }

    $esperadoMin = $entradaDt !== null
        ? ponto_entrada_esperada_min($entradaDt, $turno)
        : (int)($turno['entrada_min'] ?? PONTO_ENTRADA_COMERCIAL_MIN);

    $atrasoChegada = 0;
    $atrasoTolerado = 0;
    $antecipacao = 0;
    $antecipacaoExcedida = 0;

    if ($entradaDt !== null) {
        $diferenca = ponto_diferenca_entrada_min($entradaDt, $esperadoMin);
        if ($diferenca !== null) {
            $tolerancia = isset($turno['tolerancia']) ? (int)$turno['tolerancia'] : PONTO_TOLERANCIA_ATRASO_MIN;
            if ($diferenca > 0) {
                $atrasoChegada = $diferenca;
                if ($diferenca <= $tolerancia) {
                    $atrasoTolerado = $diferenca;
                }
            } elseif ($diferenca < 0) {
                $antecipacao = -$diferenca;
                $limite = $turno['antecipacao'] ?? null;
                if ($limite !== null && $antecipacao > (int)$limite) {
                    $antecipacaoExcedida = min($antecipacao - (int)$limite, $trabalhoBruto);
                }
            }
        }
    }

    $trabalho = $trabalhoBruto - $antecipacaoExcedida;

    return [
        'turno' => $turno,
        'jornada_min' => $jornadaMin,
        'trabalho_bruto_min' => $trabalhoBruto,
        'trabalho_min' => $trabalho,
        'atraso_chegada_min' => $atrasoChegada,
        'atraso_tolerado_min' => $atrasoTolerado,
        'antecipacao_min' => $antecipacao,
        'antecipacao_excedida_min' => $antecipacaoExcedida,
        'falta_min' => max(0, $jornadaMin - ($trabalho + $atrasoTolerado)),
        'entrada' => $entradaDt,
        'esperado_min' => $esperadoMin,
    ];
}

/** "atraso até 15 min · chegada até 10 min antes" — as regras do cargo em uma linha. */
function ponto_tolerancias_texto(array $jornada): string
{
    $atraso = (int)($jornada['tolerancia'] ?? 0);
    $partes = [$atraso > 0 ? 'atraso até ' . $atraso . ' min' : 'sem tolerância de atraso'];
    if (isset($jornada['antecipacao']) && $jornada['antecipacao'] !== null) {
        $partes[] = 'chegada até ' . (int)$jornada['antecipacao'] . ' min antes';
    } elseif (!empty($jornada['definido'])) {
        $partes[] = 'chegada antecipada sem limite';
    }
    if (isset($jornada['controla_falta']) && !$jornada['controla_falta']) {
        $partes[] = 'não conta falta';
    }
    return implode(' · ', $partes);
}

/**
 * ATRASO DO DIA = quanto faltou para fechar a jornada.
 *
 * É esta a conta que vale no sistema (escolha do administrador em 05/08/2026):
 * pega tanto quem chegou tarde quanto quem saiu mais cedo, num número só.
 * Trabalhar além da jornada NÃO gera crédito e não passa para outro dia — o
 * excedente é descartado, por isso o mínimo é sempre zero.
 *
 * Desde a tolerância por cargo, o atraso de chegada que couber na tolerância é
 * perdoado e a chegada antecipada acima do limite do cargo não é contada (ver
 * ponto_apurar_dia).
 *
 * @return array{minutos:int, trabalhado:int, jornada_min:int, entrada:?string,
 *               esperado:string, turno:string, atraso_tolerado:int,
 *               antecipacao_excedida:int, atraso_chegada:int, antecipacao:int}
 */
function ponto_falta_horas_do_dia(array $registros, ?array $jornada = null, ?int $trabalhoBruto = null): array
{
    $ap = ponto_apurar_dia($registros, $jornada, $trabalhoBruto);
    $turno = $ap['turno'];
    $esperadoMin = $ap['esperado_min'];

    return [
        'minutos' => $ap['falta_min'],
        'trabalhado' => $ap['trabalho_min'],
        'jornada_min' => $ap['jornada_min'],
        'entrada' => $ap['entrada'] ? date('H:i', strtotime($ap['entrada'])) : null,
        'esperado' => sprintf('%02d:%02d', intdiv($esperadoMin, 60), $esperadoMin % 60),
        /* Horário do cargo que valeu neste dia — sem isto, um cargo de escala
         * mostra "atrasou" sem dizer contra qual dos horários. */
        'turno' => !empty($turno['definido'])
            ? ((string)($turno['horario'] ?? '') ?: (string)($turno['descricao'] ?? ''))
            : ponto_turno_label($ap['entrada']),
        'turno_horario' => (string)($turno['horario'] ?? ''),
        'turno_descricao' => (string)($turno['descricao'] ?? ''),
        'atraso_chegada' => $ap['atraso_chegada_min'],
        'atraso_tolerado' => $ap['atraso_tolerado_min'],
        'antecipacao' => $ap['antecipacao_min'],
        'antecipacao_excedida' => $ap['antecipacao_excedida_min'],
    ];
}

/**
 * Atraso só pelo horário de chegada. Continua existindo para o painel marcar
 * em vermelho quem bateu a entrada fora do horário, mas NÃO é o número que
 * entra no total de atraso do mês.
 */
function ponto_atraso_do_dia(array $registros, ?array $jornada = null): array
{
    $entrada = null;
    foreach ($registros as $r) {
        if ((string)($r['tipo'] ?? '') === 'entrada') {
            $entrada = (string)($r['data_hora'] ?? '');
            break;
        }
    }

    if ($entrada === null || $entrada === '') {
        return ['minutos' => 0, 'entrada' => null, 'esperado' => '', 'turno' => '', 'turno_horario' => '', 'turno_descricao' => '', 'jornada_min' => $jornada['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS];
    }

    /* Cargo com vários horários: vale o mais próximo do que a pessoa bateu. */
    $turno = ponto_turno_efetivo($registros, $jornada);
    $esperadoMin = ponto_entrada_esperada_min($entrada, $turno);

    return [
        'minutos' => ponto_atraso_entrada_minutos($entrada, $turno),
        'entrada' => date('H:i', strtotime($entrada)),
        'esperado' => sprintf('%02d:%02d', intdiv($esperadoMin, 60), $esperadoMin % 60),
        'turno' => !empty($turno['definido'])
            ? ((string)($turno['horario'] ?? '') ?: (string)($turno['descricao'] ?? ''))
            : ponto_turno_label($entrada),
        'turno_horario' => (string)($turno['horario'] ?? ''),
        'turno_descricao' => (string)($turno['descricao'] ?? ''),
        'jornada_min' => (int)($turno['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS),
    ];
}

function ponto_fmt_hm(int $minutos): string
{
    $minutos = max(0, $minutos);
    return sprintf('%02dh %02dm', intdiv($minutos, 60), $minutos % 60);
}

function ponto_fmt_hm_sinal(int $minutos): string
{
    $sinal = $minutos < 0 ? '-' : '+';
    $abs = abs($minutos);
    return $sinal . sprintf('%dh %02dm', intdiv($abs, 60), $abs % 60);
}


/**
 * Meta da semana (para a barra "Semana" do app) a partir da jornada do cargo.
 *
 * Antes era sempre 44 h. Agora: jornada longa (10 h ou mais, típica de 12x36)
 * conta 3,5 dias por semana (média da escala); jornada normal conta 5,5 dias,
 * que dá as mesmas 44 h para quem faz 8 h por dia e ajusta quem faz 6 h ou 7 h.
 * Cargo sem horário cadastrado continua com as 44 h.
 */
function ponto_meta_semana_min(int $jornadaMin, bool $horarioDefinido): int
{
    if (!$horarioDefinido || $jornadaMin <= 0) {
        return PONTO_SEMANA_PADRAO_MINUTOS;
    }
    if ($jornadaMin >= 600) {
        return (int)round($jornadaMin * 3.5);
    }
    return min(PONTO_SEMANA_PADRAO_MINUTOS, (int)round($jornadaMin * 5.5));
}

/* =============================================================================
 * Folgas, feriados e FALTAS
 *
 * Regra do administrador: dia SEM NENHUMA batida é falta, menos domingo e
 * feriado (que são folga). Se a pessoa trabalhar no domingo/feriado, o dia
 * conta como dia normal (tem batida, então nunca é tratado como folga/falta).
 *
 * Feriados nacionais entram sozinhos. Municipais/estaduais e pontos facultativos
 * (Carnaval, Corpus Christi...) o administrador cadastra em Configurações.
 * ========================================================================== */

/** Data da Páscoa (calendário gregoriano) em "Y-m-d". */
function ponto_pascoa(int $ano): string
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/** @return array<string,string> data => nome */
function ponto_feriados_nacionais(int $ano): array
{
    $f = [
        "$ano-01-01" => 'Confraternização Universal',
        date('Y-m-d', strtotime(ponto_pascoa($ano) . ' -2 days')) => 'Sexta-feira Santa',
        "$ano-04-21" => 'Tiradentes',
        "$ano-05-01" => 'Dia do Trabalho',
        "$ano-09-07" => 'Independência do Brasil',
        "$ano-10-12" => 'Nossa Senhora Aparecida',
        "$ano-11-02" => 'Finados',
        "$ano-11-15" => 'Proclamação da República',
        "$ano-12-25" => 'Natal',
    ];
    if ($ano >= 2024) {
        $f["$ano-11-20"] = 'Consciência Negra';
    }
    ksort($f);
    return $f;
}

function ensure_feriados_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ponto_feriados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data DATE NOT NULL,
        nome VARCHAR(80) NOT NULL,
        anual TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL,
        KEY idx_fer_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Feriados cadastrados pelo administrador (linhas cruas da tabela). */
function ponto_feriados_extras(): array
{
    try {
        ensure_feriados_table();
        return db()->query("SELECT * FROM ponto_feriados ORDER BY data, id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Todos os feriados de um ano: nacionais + cadastrados. @return array<string,string> */
function ponto_feriados_do_ano(int $ano): array
{
    static $cache = [];
    if (isset($cache[$ano])) {
        return $cache[$ano];
    }
    $f = ponto_feriados_nacionais($ano);
    foreach (ponto_feriados_extras() as $e) {
        $data = (string)$e['data'];
        if (!preg_match('/^(\d{4})-(\d{2}-\d{2})$/', $data, $m)) continue;
        if ((int)$m[1] === $ano) {
            $f[$data] = (string)$e['nome'];
        } elseif ((int)$e['anual'] === 1 && (int)$m[1] < $ano) {
            $f[$ano . '-' . $m[2]] = (string)$e['nome'];
        }
    }
    ksort($f);
    return $cache[$ano] = $f;
}

/**
 * Motivo da folga do dia, ou null se é dia de trabalho normal.
 * Feriado tem preferência sobre domingo na hora de dar o nome.
 */
function ponto_folga_do_dia(string $data): ?string
{
    $ts = strtotime($data);
    if (!$ts) return null;
    $feriados = ponto_feriados_do_ano((int)date('Y', $ts));
    if (isset($feriados[$data])) {
        return $feriados[$data];
    }
    return (int)date('N', $ts) === 7 ? 'Domingo' : null;
}

/**
 * Primeira e última batida do funcionário e se ele está ativo. Serve para não
 * cobrar falta antes de ele começar nem depois de ele sair.
 *
 * @return array{primeiro:?string, ultimo:?string, ativo:bool}
 */
function ponto_funcionario_janela(int $funcionarioId): array
{
    static $cache = [];
    if (isset($cache[$funcionarioId])) {
        return $cache[$funcionarioId];
    }
    $stmt = db()->prepare("SELECT f.ativo, MIN(r.data_ponto) AS primeiro, MAX(r.data_ponto) AS ultimo
        FROM ponto_funcionarios f
        LEFT JOIN ponto_registros r ON r.funcionario_id = f.id
        WHERE f.id = ?
        GROUP BY f.id, f.ativo");
    $stmt->execute([$funcionarioId]);
    $row = $stmt->fetch();
    return $cache[$funcionarioId] = [
        'primeiro' => $row && $row['primeiro'] ? (string)$row['primeiro'] : null,
        'ultimo' => $row && $row['ultimo'] ? (string)$row['ultimo'] : null,
        'ativo' => !$row || (int)$row['ativo'] === 1,
    ];
}

/**
 * Esse dia SEM batida é falta? Só se: o cargo controla falta; o dia já acabou
 * (hoje ainda dá tempo de bater); o funcionário já tinha começado (a partir da
 * primeira batida dele) e, se foi desativado, ainda não tinha saído; e não é
 * domingo nem feriado.
 */
function ponto_dia_eh_falta(string $dia, array $janela, bool $controlaFalta, ?string $hoje = null): bool
{
    if (!$controlaFalta) return false;
    if ($dia >= ($hoje ?: today_sp())) return false;
    if ($janela['primeiro'] === null || $dia < $janela['primeiro']) return false;
    if (empty($janela['ativo']) && $janela['ultimo'] !== null && $dia > $janela['ultimo']) return false;
    return ponto_folga_do_dia($dia) === null;
}

/** Jornada que se perde num dia de falta: a do turno principal do cargo (ou 8 h). */
function ponto_jornada_padrao_min(array $jornada): int
{
    $turno = !empty($jornada['turnos']) ? ponto_turno_padrao($jornada['turnos']) : null;
    return (int)($turno['jornada_min'] ?? $jornada['jornada_min'] ?? PONTO_JORNADA_PADRAO_MINUTOS);
}


/**
 * A entrada de agora é CEDO DEMAIS para o horário do cargo?
 *
 * Só vale quando o cargo tem "Antes até (min)" preenchido (campo vazio = sem
 * limite, nunca bloqueia). Quem tenta bater a entrada antes de
 * (entrada do turno - limite) é avisado e a batida NÃO é gravada. Atrasado nunca
 * é bloqueado aqui. Em cargo com vários horários vale o turno mais próximo da hora.
 *
 * @return array{liberado_em:string, turno:string, antecipacao:int, faltam_min:int, mensagem:string}|null
 *         null = pode bater.
 */
function ponto_entrada_antecipada_da_jornada(?array $jornada, string $agora): ?array
{
    if (!$jornada || empty($jornada['turnos'])) {
        return null;
    }
    $turno = ponto_turno_do_dia([['tipo' => 'entrada', 'data_hora' => $agora]], $jornada);
    if (empty($turno['definido']) || !isset($turno['antecipacao']) || $turno['antecipacao'] === null) {
        return null;
    }

    $limite = (int)$turno['antecipacao'];
    $diferenca = ponto_diferenca_entrada_min($agora, (int)$turno['entrada_min']);
    if ($diferenca === null || $diferenca >= -$limite) {
        return null;
    }

    $faltam = (-$diferenca) - $limite;
    $liberadoMin = (((int)$turno['entrada_min'] - $limite) % 1440 + 1440) % 1440;
    $liberado = sprintf('%02d:%02d', intdiv($liberadoMin, 60), $liberadoMin % 60);
    $horario = (string)($turno['horario'] ?? (($turno['entrada'] ?? '') . '-' . ($turno['saida'] ?? '')));

    return [
        'liberado_em' => $liberado,
        'turno' => $horario,
        'antecipacao' => $limite,
        'faltam_min' => $faltam,
        'mensagem' => 'Ainda não dá para bater a entrada. Seu horário é ' . $horario
            . ($limite > 0 ? ' e a entrada só é liberada a partir das ' . $liberado . ' (até ' . $limite . ' min antes)' : ' e a entrada só é liberada a partir das ' . $liberado)
            . '. Faltam ' . $faltam . ' min — volte às ' . $liberado . '.',
    ];
}

function ponto_entrada_antecipada_bloqueio(int $funcionarioId, ?string $agora = null): ?array
{
    try {
        return ponto_entrada_antecipada_da_jornada(ponto_jornada_do_funcionario($funcionarioId), $agora ?: now_sp());
    } catch (Throwable $e) {
        return null; // nunca impede alguém de bater o ponto por falha de leitura do cargo
    }
}
