<?php
header('X-Robots-Tag: noindex, nofollow', true);
date_default_timezone_set('America/Sao_Paulo');

$configs = [__DIR__ . '/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__, 2) . '/config.php'];
foreach ($configs as $cfg) {
    if (is_file($cfg)) { require_once $cfg; break; }
}

function vn_pub_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vn_pub_clean($v): string { return preg_replace('/\s+/', ' ', trim((string)$v)); }
function vn_pub_db(): mysqli {
    global $conn, $mysqli, $conexao, $con, $link;
    foreach (['conn','mysqli','conexao','con','link'] as $g) {
        if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof mysqli) {
            $GLOBALS[$g]->set_charset('utf8mb4');
            @$GLOBALS[$g]->query("SET time_zone = '-03:00'");
            return $GLOBALS[$g];
        }
    }
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
        $db = new mysqli(DB_HOST, DB_USER, defined('DB_PASS') ? DB_PASS : '', DB_NAME);
        if ($db->connect_errno) throw new Exception('Erro ao conectar no banco.');
        $db->set_charset('utf8mb4');
        @$db->query("SET time_zone = '-03:00'");
        return $db;
    }
    throw new Exception('Configuração do banco não encontrada.');
}
function vn_pub_b64url_decode(string $s): string|false {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s, true);
}
function vn_pub_token_to_id(string $token): int {
    return vn_os_token_id($token); // _vn_security.php (aceita links antigos se VN_LEGACY_TOKEN_PASS existir)
}
function vn_pub_table_exists(mysqli $db, string $table): bool {
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    if ($dbName === '') {
        $res = $db->query('SELECT DATABASE() AS db');
        $row = $res ? $res->fetch_assoc() : null;
        $dbName = (string)($row['db'] ?? '');
    }
    $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $dbName, $table);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}
function vn_pub_column_exists(mysqli $db, string $table, string $column): bool {
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    if ($dbName === '') {
        $res = $db->query('SELECT DATABASE() AS db');
        $row = $res ? $res->fetch_assoc() : null;
        $dbName = (string)($row['db'] ?? '');
    }
    $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}
function vn_pub_fetch_one(mysqli $db, string $sql, array $params = []): ?array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Erro SQL.');
    if ($params) {
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r ?: null;
}
function vn_pub_fetch_all(mysqli $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Erro SQL.');
    if ($params) {
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && $r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}
function vn_pub_fmt_data($valor): string {
    $valor = trim((string)$valor);
    if ($valor === '' || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') return 'Não informado';
    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        if (preg_match('/Z$|[+-]\d{2}:\d{2}$/', $valor)) { $dt = new DateTime($valor); $dt->setTimezone($tz); }
        else { $dt = new DateTime(str_replace('T', ' ', $valor), $tz); }
        return strlen($valor) <= 10 ? $dt->format('d/m/Y') : $dt->format('d/m/Y \à\s H:i');
    } catch (Throwable $e) { return $valor; }
}
function vn_pub_primeira_data(array $row, array $cols): string {
    foreach ($cols as $c) if (!empty($row[$c])) return vn_pub_fmt_data($row[$c]);
    return 'Não informado';
}
function vn_pub_status($s): string {
    $s = strtolower(trim((string)$s));
    return match($s) {
        'pendente', 'aberta', 'aberto', 'em aberto' => 'Pendente',
        'em_rota' => 'Em rota',
        'em_andamento', 'andamento' => 'Em andamento',
        'concluida', 'concluída', 'finalizada', 'finalizado' => 'Finalizada',
        'nao_realizada', 'não realizada', 'cancelada', 'cancelado' => 'Não realizada',
        default => $s ? ucfirst($s) : 'Não informado'
    };
}
/*
   Texto da linha de serviço na tabela de preço (ex: "Manutenção preventiva e
   corretiva 24 horas"). NÃO é o problema relatado — aquele é o que aconteceu
   nesta visita e já tem seção própria ("3. Serviço solicitado"). Este é o
   tipo de contrato/serviço, então vem do tipo_servico da própria OS.
*/
function vn_pub_texto_servico(string $tipo): string {
    $t = mb_strtolower(trim($tipo));
    return match (true) {
        str_contains($t, 'prevent')  => 'Manutenção preventiva e corretiva 24 horas',
        str_contains($t, 'corret')   => 'Manutenção corretiva',
        str_contains($t, 'instala')  => 'Instalação de equipamentos',
        str_contains($t, 'vistoria') => 'Vistoria técnica',
        str_contains($t, 'or') && str_contains($t, 'amento') => 'Orçamento de serviço',
        default => 'Manutenção e suporte técnico',
    };
}
function vn_pub_media_src($path): string {
    $p = trim(html_entity_decode(str_replace('\\', '/', (string)$path), ENT_QUOTES, 'UTF-8'));
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) {
        $u = parse_url($p);
        $hostAtual = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $hostAtual = preg_replace('/:\\d+$/', '', $hostAtual);
        $hostUrl = strtolower((string)($u['host'] ?? ''));
        if ($hostUrl === '' || $hostUrl !== $hostAtual) return '';
        $p = (string)($u['path'] ?? '');
    }
    $p = rawurldecode($p);
    $p = strtok($p, '?#');
    $p = preg_replace('#^\./#', '', ltrim((string)$p, '/'));
    $p = preg_replace('#/+#', '/', $p);
    if (strpos($p, '..') !== false || !preg_match('#^uploads/os_visitas/[0-9]+/#', $p)) return '';
    return $p;
}
function vn_pub_media_href(string $src): string {
    $src = vn_pub_media_src($src);
    if ($src === '') return '';
    $parts = array_map('rawurlencode', explode('/', $src));
    return implode('/', $parts);
}
function vn_pub_media_abs_path(string $src): string {
    $src = vn_pub_media_src($src);
    if ($src === '') return '';
    $file = __DIR__ . '/' . $src;
    $realBase = realpath(__DIR__ . '/uploads/os_visitas');
    $realFile = realpath($file);
    if ($realBase === false || $realFile === false) return '';
    $baseNorm = str_replace('\\', '/', $realBase);
    $fileNorm = str_replace('\\', '/', $realFile);
    if (strpos($fileNorm, $baseNorm . '/') !== 0 && $fileNorm !== $baseNorm) return '';
    return $realFile;
}
function vn_pub_is_image_ext(string $src): bool {
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
}
function vn_pub_img_file_data_uri(string $src): string {
    $src = vn_pub_media_src($src);
    if ($src === '') return '';
    $file = __DIR__ . '/' . $src;
    if (!is_file($file)) return '';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return '';
    if (!function_exists('imagecreatefromstring')) {
        $bin = @file_get_contents($file);
        if (!$bin) return '';
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif'
        ];
        $mime = $mimeMap[$ext] ?? 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
    $bin = @file_get_contents($file);
    if (!$bin) return '';
    $img = @imagecreatefromstring($bin);
    if (!$img) return '';
    $w = imagesx($img); $h = imagesy($img);
    $canvas = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    ob_start(); imagejpeg($canvas, null, 90); $jpg = ob_get_clean();
    imagedestroy($canvas); imagedestroy($img);
    return $jpg ? 'data:image/jpeg;base64,' . base64_encode($jpg) : '';
}
function vn_pub_signature_landscape(string $dataUri): string {
    $dataUri = trim($dataUri);
    if ($dataUri === '') return '';
    if (!preg_match('/^data:image\/[^;]+;base64,/i', $dataUri)) return vn_pub_img_file_data_uri($dataUri);
    if (!function_exists('imagecreatefromstring')) return $dataUri;
    $raw = base64_decode(preg_replace('/^data:image\/[^;]+;base64,/i', '', $dataUri), true);
    if (!$raw) return $dataUri;
    $img = @imagecreatefromstring($raw);
    if (!$img) return $dataUri;
    $w = imagesx($img); $h = imagesy($img);
    if ($h > $w) {
        $bg = imagecolorallocate($img, 255, 255, 255);
        $rot = imagerotate($img, 90, $bg);
        if ($rot) { imagedestroy($img); $img = $rot; }
    }
    $nw = imagesx($img); $nh = imagesy($img);
    $canvas = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $nw, $nh);
    ob_start(); imagejpeg($canvas, null, 90); $jpg = ob_get_clean();
    imagedestroy($canvas); imagedestroy($img);
    return $jpg ? 'data:image/jpeg;base64,' . base64_encode($jpg) : $dataUri;
}
function vn_pub_logo(): string {
    foreach (['vn.png','./vn.png','../vn.png'] as $p) if (is_file(__DIR__ . '/' . ltrim($p, './'))) return $p;
    return 'vn.png';
}
function vn_pub_opt(string $key, string $default = ''): string {
    $v = vn_pub_clean($_GET[$key] ?? $default);
    return substr($v, 0, 220);
}
function vn_pub_color(string $key, string $default): string {
    $v = vn_pub_opt($key, $default);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $default;
}
function vn_pub_tecnico_nome(string $raw): string {
    $nome = vn_pub_clean($raw);
    if ($nome === '') return 'Tecnico';
    if (preg_match('/^slownx$/i', $nome)) return 'Tecnico';
    return $nome;
}
function vn_pub_company(): array {
    $modeloId = vn_pub_opt('modelo', '');
    if ($modeloId === '') {
        $all = glob(__DIR__ . '/pdf_templates/*.json') ?: [];
        if ($all) {
            usort($all, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
            $modeloId = basename((string)$all[0], '.json');
        }
    }
    if ($modeloId !== '' && preg_match('/^[a-z0-9_-]{1,48}$/i', $modeloId)) {
        $file = __DIR__ . '/pdf_templates/' . strtolower($modeloId) . '.json';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $cfgFile = $raw ? json_decode($raw, true) : null;
            if (is_array($cfgFile)) {
                $_GET['tpl'] = (string)($cfgFile['tpl'] ?? 'azul');
                $_GET['logo'] = (string)($cfgFile['logo'] ?? '');
                $_GET['c1'] = (string)($cfgFile['c1'] ?? '');
                $_GET['c2'] = (string)($cfgFile['c2'] ?? '');
                $_GET['emp'] = (string)($cfgFile['emp'] ?? '');
                $_GET['cnpj'] = (string)($cfgFile['cnpj'] ?? '');
                $_GET['tel'] = (string)($cfgFile['tel'] ?? '');
                $_GET['site'] = (string)($cfgFile['site'] ?? '');
                $_GET['end'] = (string)($cfgFile['end'] ?? '');
            }
        }
    }
    $tpl = vn_pub_opt('tpl', 'azul');
    $paletas = [
        'azul' => ['#1d4ed8', '#2563eb', '#eff6ff', '#bfdbfe', '#1e3a8a'],
        'grafite' => ['#1f2937', '#374151', '#f3f4f6', '#d1d5db', '#111827'],
        'claro' => ['#0f766e', '#14b8a6', '#f0fdfa', '#99f6e4', '#134e4a'],
    ];
    $base = $paletas[$tpl] ?? $paletas['azul'];
    $logo = vn_pub_opt('logo', '');
    if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
        $logoPath = str_replace('\\', '/', ltrim($logo, '/'));
        if (!preg_match('#^pdf_templates/logos/[a-zA-Z0-9._-]+$#', $logoPath) || !is_file(__DIR__ . '/' . $logoPath)) {
            $logo = '';
        } else {
            $logo = $logoPath;
        }
    }
    return [
        'c1' => vn_pub_color('c1', $base[0]),
        'c2' => vn_pub_color('c2', $base[1]),
        'line' => $base[3],
        'soft' => $base[2],
        'text' => $base[4],
        'logo' => $logo !== '' ? $logo : vn_pub_logo(),
        'empresa' => vn_pub_opt('emp', 'VN SYSTEM'),
        'cnpj' => vn_pub_opt('cnpj', '58.181.863/0001-92'),
        'tel' => vn_pub_opt('tel', '(11) 96919-0724'),
        'site' => vn_pub_opt('site', ''),
        'end' => vn_pub_opt('end', 'Rua Ana Maria, 46 - São Bernardo do Campo/SP - CEP 09669-060'),
    ];
}

$erro = '';
$visita = null;
$arquivos = [];
$assinaturas = [];
$idToken = 0;
try {
    $id = vn_pub_token_to_id((string)($_GET['t'] ?? ''));
    if ($id <= 0) throw new Exception('Link inválido ou alterado.');
    $idToken = $id;
    $db = vn_pub_db();
    if (!vn_pub_table_exists($db, 'os_visitas')) throw new Exception('Tabela os_visitas não encontrada.');
    $visita = vn_pub_fetch_one($db, 'SELECT * FROM os_visitas WHERE id = ? LIMIT 1', [$id]);
    if (!$visita) throw new Exception('OS não encontrada.');
    if (vn_pub_table_exists($db, 'os_visita_arquivos')) $arquivos = vn_pub_fetch_all($db, 'SELECT * FROM os_visita_arquivos WHERE visita_id = ? ORDER BY id ASC', [$id]);
    if (vn_pub_table_exists($db, 'os_visita_assinaturas')) {
        foreach (vn_pub_fetch_all($db, 'SELECT * FROM os_visita_assinaturas WHERE visita_id = ? ORDER BY id ASC', [$id]) as $a) {
            $assinaturas[$a['tipo_assinante'] ?? ''] = $a;
        }
    }
} catch (Throwable $e) {
    $erro = vn_public_error($e, 'os-pdf-publico');
}

/*
   Dados da ordem original (equipamento/garantia): já ficam gravados em
   ordens_servico na abertura da OS. Em vez de pedir de novo no PDF, busca
   aqui pelo vínculo os_visitas.ordem_id_local — só preenche o que existir.
*/
$ordemOrigem = [];
if ($erro === '' && $visita) {
    try {
        $ordemLocalId = (int)($visita['ordem_id_local'] ?? 0);
        if ($ordemLocalId > 0 && vn_pub_table_exists($db, 'ordens_servico')) {
            $found = vn_pub_fetch_one($db, 'SELECT * FROM ordens_servico WHERE id = ? LIMIT 1', [$ordemLocalId]);
            if ($found) $ordemOrigem = $found;
        }
    } catch (Throwable $e) { /* sem dado extra: segue só com os_visitas */ }
}

if ($erro === '' && isset($_GET['img'])) {
    $imgSrc = vn_pub_media_src((string)$_GET['img']);
    if ($imgSrc === '' || !vn_pub_is_image_ext($imgSrc)) {
        http_response_code(404);
        exit;
    }

    $permitido = false;
    foreach ($arquivos as $a) {
        $cand = vn_pub_media_src($a['caminho_arquivo'] ?? '');
        if ($cand === $imgSrc) {
            $permitido = true;
            break;
        }
    }
    if (!$permitido) {
        http_response_code(403);
        exit;
    }

    $abs = vn_pub_media_abs_path($imgSrc);
    if ($abs === '' || !is_file($abs)) {
        http_response_code(404);
        exit;
    }

    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $det = (string)finfo_file($finfo, $abs);
            finfo_close($finfo);
            if (strpos($det, 'image/') === 0) $mime = $det;
        }
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($abs));
    header('Cache-Control: private, max-age=1200');
    readfile($abs);
    exit;
}

$fotos = [];
$videos = [];
$anexos = [];
foreach ($arquivos as $a) {
    $src = vn_pub_media_src($a['caminho_arquivo'] ?? '');
    if ($src === '') continue;
    $href = vn_pub_media_href($src);
    if ($href === '') $href = $src;
    $nome = vn_pub_clean($a['nome_original'] ?? basename($src));
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $tipo = strtolower((string)($a['tipo'] ?? ''));
    if ($tipo === 'foto' || in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
        $imgProxy = 'os-pdf-publico.php?t=' . rawurlencode((string)($_GET['t'] ?? '')) . '&img=' . rawurlencode($src);
        $imgData = vn_pub_img_file_data_uri($src);
        $imgMain = $imgData !== '' ? $imgData : $imgProxy;
        $fotos[] = ['src'=>$imgMain, 'nome'=>$nome, 'fallback'=>$imgProxy, 'last'=>$href];
    }
    elseif ($tipo === 'video' || in_array($ext, ['mp4','mov','webm','m4v','3gp','avi','mkv'], true)) $videos[] = ['src'=>$href, 'nome'=>$nome, 'ext'=>$ext];
    else $anexos[] = ['src'=>$href, 'nome'=>$nome];
}

$numero = $visita ? vn_pub_clean($visita['numero_os'] ?? $visita['codigo_os'] ?? ('OS #' . ($visita['id'] ?? ''))) : '';
$cliente = $visita ? vn_pub_clean($visita['cliente_nome'] ?? 'Não informado') : '';
$tecnico = $visita ? vn_pub_tecnico_nome((string)($visita['funcionario_nome'] ?? $visita['tecnico_nome'] ?? '')) : 'Tecnico';
$cfg = vn_pub_company();

/*
   Modo do documento. O botão da lista de OS concluídas decide: com
   ?tipo=preventiva o PDF sai como Manutenção Preventiva (título + subtítulo,
   igual ao modelo pedido). Sem o parâmetro, é a OS normal.
*/
$tipoDoc = strtolower(trim((string)($_GET['tipo'] ?? '')));
$ehPreventiva = strpos($tipoDoc, 'prevent') !== false;
$docLinhaTipo = $ehPreventiva ? 'Ordem de Serviço · Manutenção Preventiva' : 'Ordem de Serviço Técnica';
$assinCli = $assinaturas['cliente']['assinatura_base64'] ?? ($assinaturas['cliente']['caminho_imagem'] ?? '');
$assinTec = $assinaturas['tecnico']['assinatura_base64'] ?? ($assinaturas['tecnico']['caminho_imagem'] ?? '');
$assinCli = vn_pub_signature_landscape((string)$assinCli);
$assinTec = vn_pub_signature_landscape((string)$assinTec);

/*
   ?formato=pdf devolve o PDF de verdade (arquivo) em vez da página para imprimir. É o que o botão "Baixar PDF" usa:
   o app instalado no celular não imprime nem abre PDF numa aba, mas baixa um arquivo normalmente.
   A marca (logo, cores, contatos) vem de "Marca dos PDFs", pela área da OS (CFTV ou Serralheria); um modelo escolhido
   em "OS Concluídas" (os-modelos-pdf.php) continua valendo por cima.
*/
if ($erro === '' && (string)($_GET['formato'] ?? '') === 'pdf') {
    try {
        require_once __DIR__ . '/_vn_pdf_docs.php';
        $txt = function ($v) { return trim(str_replace(["\r\n", "\r"], "\n", (string)$v)); };
        $nz = function ($v) use ($txt) { $t = $txt($v); return $t !== '' ? $t : 'Não informado'; };
        $linhaOs = strtolower((string)($visita['linha_servico'] ?? ($ordemOrigem['linha_servico'] ?? 'cftv'))) === 'serralheria' ? 'serralheria' : 'cftv';
        $marca = vn_marca_get($linhaOs, $db);

        $modeloPdf = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['modelo'] ?? '')));
        $arqModelo = $modeloPdf !== '' ? __DIR__ . '/pdf_templates/' . $modeloPdf . '.json' : '';
        if ($arqModelo !== '' && is_file($arqModelo)) {
            $mj = json_decode((string)@file_get_contents($arqModelo), true);
            if (is_array($mj)) {
                foreach (['emp' => 'nome', 'cnpj' => 'cnpj', 'tel' => 'telefone', 'site' => 'site', 'end' => 'endereco'] as $de => $para) {
                    if (trim((string)($mj[$de] ?? '')) !== '') $marca[$para] = trim((string)$mj[$de]);
                }
                foreach (['c1' => 'cor1', 'c2' => 'cor2'] as $de => $para) {
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)($mj[$de] ?? ''))) $marca[$para] = strtolower((string)$mj[$de]);
                }
                $lg = str_replace('\\', '/', ltrim((string)($mj['logo'] ?? ''), '/'));
                if (preg_match('#^pdf_templates/logos/[a-zA-Z0-9._-]+$#', $lg) && is_file(__DIR__ . '/' . $lg)) $marca['logo_file'] = __DIR__ . '/' . $lg;
            }
        }

        $fotosPdf = []; $videosPdf = [];
        foreach ($arquivos as $a) {
            $srcA = vn_pub_media_src($a['caminho_arquivo'] ?? '');
            if ($srcA === '') continue;
            $extA = strtolower(pathinfo($srcA, PATHINFO_EXTENSION));
            $tipoA = strtolower((string)($a['tipo'] ?? ''));
            $nomeA = vn_pub_clean($a['nome_original'] ?? basename($srcA));
            if ($tipoA === 'foto' || in_array($extA, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $fa = vn_pub_media_abs_path($srcA);
                if ($fa !== '') $fotosPdf[] = ['nome' => $nomeA, 'file' => $fa];
            } elseif ($tipoA === 'video' || in_array($extA, ['mp4', 'mov', 'webm', 'm4v', '3gp', 'avi', 'mkv'], true)) {
                $videosPdf[] = ['nome' => $nomeA, 'arquivo' => $srcA];
            }
        }

        // bloco comercial da preventiva: mesmas regras da página (valor, texto, opções, equipamento e garantia da OS)
        $valorPdf = (float)($visita['pdf_valor'] ?? 0);
        $opc = json_decode((string)($visita['pdf_opcoes'] ?? ''), true);
        if (!is_array($opc)) $opc = [];
        $oPreco = !array_key_exists('preco', $opc) || !empty($opc['preco']);
        $oTotal = !array_key_exists('total', $opc) || !empty($opc['total']);
        $oDet = !array_key_exists('detalhes', $opc) || !empty($opc['detalhes']);
        $oFotos = !array_key_exists('fotos', $opc) || !empty($opc['fotos']);
        $textoSalvo = trim((string)($visita['pdf_texto_servico'] ?? ''));
        $descServ = $textoSalvo !== '' ? $textoSalvo : vn_pub_texto_servico((string)($visita['tipo_servico'] ?? $ordemOrigem['tipo_servico'] ?? $visita['tag_servico'] ?? ''));
        $equipTxt = trim((string)($ordemOrigem['equipamento_ordem'] ?? ''));
        $itensServ = $equipTxt !== '' ? preg_split('/[\r\n;,]+/', $equipTxt) : [];

        $payloadPdf = [
            'numeroOS' => $numero, 'cliente' => $cliente !== '' ? $cliente : 'Não informado', 'tecnico' => $tecnico,
            'status' => vn_pub_status($visita['status_visita'] ?? $visita['status'] ?? ''),
            'abertura' => vn_pub_primeira_data($visita, ['data_abertura', 'criado_em', 'data_agendada']),
            'checkin' => vn_pub_fmt_data($visita['checkin_em'] ?? ''), 'checkout' => vn_pub_fmt_data($visita['checkout_em'] ?? ''),
            'enderecoCliente' => $nz($visita['endereco'] ?? ''),
            'problema' => $nz($visita['descricao'] ?? $visita['problema'] ?? ''),
            'comentario' => $nz($visita['comentario'] ?? $visita['observacao'] ?? ''),
            'pendencias' => $nz($visita['pendencias'] ?? ''),
            'tipoServico' => trim((string)($visita['tipo_servico'] ?? $ordemOrigem['tipo_servico'] ?? '')),
            'linha' => $linhaOs, 'preventiva' => $ehPreventiva,
            'fotos' => $fotosPdf, 'mostrarFotos' => $oFotos, 'videos' => $videosPdf,
            'assinaturaCliente' => $assinCli, 'assinaturaTecnico' => $assinTec,
            'nomeClienteAss' => $txt($assinaturas['cliente']['nome_assinante'] ?? ''),
            'servico' => ['descricao' => $descServ, 'valor' => $valorPdf, 'tabela' => $oPreco, 'total' => $oTotal, 'detalhes' => $oDet,
                'itens' => $itensServ, 'garantia' => trim((string)($ordemOrigem['garantia_ordem'] ?? '')), 'condicoes' => 'À vista'],
        ];
        $pdfBin = vn_pdf_os_v6($payloadPdf, $marca, $db);

        $slugOs = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $numero !== '' ? $numero : (string)$idToken)), '-');
        if ($slugOs === '') $slugOs = (string)$idToken;
        $nomeArq = (stripos($slugOs, 'OS-') === 0 ? '' : 'OS-') . $slugOs . ($ehPreventiva ? '-preventiva' : '') . '.pdf';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $nomeArq . '"');
        header('Content-Length: ' . strlen($pdfBin));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdfBin;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao gerar o PDF: ' . vn_public_error($e, 'os-pdf-publico');
    }
    exit;
}

// Endereço do PDF desta mesma OS (o botão "Baixar PDF" usa este link).
$linkPdf = 'os-pdf-publico.php?' . http_build_query(array_filter([
    't' => (string)($_GET['t'] ?? ''), 'modelo' => (string)($_GET['modelo'] ?? ''), 'tipo' => (string)($_GET['tipo'] ?? ''), 'formato' => 'pdf',
], function ($v) { return $v !== ''; }));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo vn_pub_h($numero ?: 'OS'); ?> - PDF VN System</title>
<style>
:root{--blue:#1d4ed8;--blue2:#2563eb;--line:#bfdbfe;--soft:#eff6ff;--text:#1e3a8a;--muted:#64748b;--bg:#0f172a}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:#0f172a}.top-actions{position:sticky;top:0;z-index:5;background:rgba(15,23,42,.94);backdrop-filter:blur(12px);padding:10px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}.top-actions button,.top-actions a{border:0;border-radius:12px;padding:10px 13px;background:var(--blue2);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.top-actions a{background:#334155}.sheet{width:min(100%,900px);margin:18px auto 32px;background:white;padding:34px;box-shadow:0 20px 70px rgba(0,0,0,.35)}.bar{height:9px;background:var(--blue);margin-bottom:13px}.head{border:1px solid var(--blue2);padding:14px;display:grid;grid-template-columns:76px 1fr 160px;gap:14px;align-items:center}.logo{width:70px;height:58px;border:1px solid var(--line);border-radius:12px;display:grid;place-items:center;background:var(--soft);overflow:hidden}.logo img{max-width:64px;max-height:52px}.company h1{margin:0;color:var(--blue);font-size:24px;letter-spacing:.02em}.company p{margin:5px 0 0;color:#334155;font-size:13px}.issued{border:1px solid var(--line);background:var(--soft);border-radius:12px;padding:12px;font-size:12px;color:var(--text)}.section-title{background:var(--blue2);color:white;padding:7px 10px;margin:18px 0 10px;text-transform:uppercase;font-size:13px;font-weight:800}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.box,.block{border:1px solid var(--line);background:#fff;padding:10px;min-height:52px}.box b,.block b{display:block;font-size:11px;text-transform:uppercase;color:var(--blue2);margin-bottom:6px}.box span,.block span{font-size:13px;color:#0f172a;white-space:pre-wrap}.block{margin-bottom:9px}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.photo,.video{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff}.photo b,.video b{display:block;padding:9px 10px;color:var(--blue2);font-size:12px}.photo img{width:100%;height:260px;object-fit:contain;background:#f8fafc;display:block}.videos{display:grid;gap:12px}.video video{width:100%;max-height:420px;background:#000;display:block}.video a{display:block;padding:10px;color:var(--blue);font-weight:700}.signs{display:grid;grid-template-columns:1fr 1fr;gap:16px}.sign{border:1px solid var(--line);padding:10px;min-height:142px}.sign b{display:block;color:var(--blue2);font-size:12px;margin-bottom:8px}.sign-box{height:64px;display:flex;align-items:center;justify-content:center}.sign img{max-width:100%;max-height:62px;object-fit:contain}.line{border-top:2px solid var(--blue);margin:8px 6px 6px}.footer{border-top:1px solid var(--line);margin-top:20px;padding-top:8px;color:var(--blue2);font-size:11px;display:flex;justify-content:space-between;gap:8px}.error{width:min(100%,720px);margin:40px auto;background:white;border-radius:18px;padding:25px;text-align:center}.muted{color:var(--muted)}@media(max-width:720px){.sheet{margin:0;padding:16px}.head{grid-template-columns:64px 1fr}.issued{grid-column:1/-1}.grid,.two,.signs{grid-template-columns:1fr}.company h1{font-size:19px}.photo img{height:220px}}@media print{body{background:white}.top-actions{display:none}.sheet{box-shadow:none;margin:0;width:100%;padding:22px;page-break-after:auto}.video video{display:none}.video:after{content:'Vídeo disponível no link hospedado acima.';display:block;padding:10px;color:#334155}.section-title{break-after:avoid}.photo,.video,.sign{break-inside:avoid}.footer{position:relative}}
</style>
<style>
:root{--blue:<?php echo vn_pub_h($cfg['c1']); ?>;--blue2:<?php echo vn_pub_h($cfg['c2']); ?>;--line:<?php echo vn_pub_h($cfg['line']); ?>;--soft:<?php echo vn_pub_h($cfg['soft']); ?>;--text:<?php echo vn_pub_h($cfg['text']); ?>}
</style>
</head>
<body>
<?php if ($erro): ?>
    <div class="error"><h2>Link indisponível</h2><p><?php echo vn_pub_h($erro); ?></p></div>
<?php else: ?>
<div class="top-actions">
    <button onclick="window.print()">Imprimir / salvar PDF</button>
    <a href="<?php echo vn_pub_h($linkPdf); ?>" data-baixar style="background:var(--blue2)">Baixar PDF</a>
    <button onclick="navigator.clipboard && navigator.clipboard.writeText(location.href)">Copiar link</button>
</div>
<main class="sheet" id="sheetPdf">
    <div class="bar"></div>
    <header class="head">
        <div class="logo"><img src="<?php echo vn_pub_h($cfg['logo']); ?>" alt="Logo"></div>
        <div class="company">
            <h1><?php echo vn_pub_h($cfg['empresa']); ?></h1>
            <p><?php echo vn_pub_h($docLinhaTipo); ?></p>
            <p>CNPJ: <?php echo vn_pub_h($cfg['cnpj']); ?> · Telefone: <?php echo vn_pub_h($cfg['tel']); ?></p>
            <p><?php echo vn_pub_h($cfg['end']); ?><?php if ($cfg['site'] !== '') echo ' · ' . vn_pub_h($cfg['site']); ?></p>
        </div>
        <div class="issued"><b>EMITIDO EM</b><br><?php echo date('d/m/Y H:i:s'); ?></div>
    </header>

    <!-- Faixa de título: nº da OS e, quando preventiva, o subtítulo de manutenção. -->
    <div style="background:var(--blue);color:#fff;padding:12px 14px;margin:14px 0 6px;border-radius:4px">
        <div style="font-size:17px;font-weight:800;letter-spacing:.02em;text-transform:uppercase">Ordem de Serviço <?php echo vn_pub_h($numero); ?></div>
        <?php if ($ehPreventiva): ?><div style="font-size:12.5px;font-weight:600;opacity:.94;margin-top:3px">Manutenção Preventiva</div><?php endif; ?>
    </div>

    <div class="section-title">1. Dados da Ordem</div>
    <div class="grid">
        <div class="box"><b>Número</b><span><?php echo vn_pub_h($numero); ?></span></div>
        <div class="box"><b>Status</b><span><?php echo vn_pub_h(vn_pub_status($visita['status_visita'] ?? $visita['status'] ?? '')); ?></span></div>
        <div class="box"><b>Abertura</b><span><?php echo vn_pub_h(vn_pub_primeira_data($visita, ['data_abertura','criado_em','data_agendada'])); ?></span></div>
        <div class="box"><b>Check-out</b><span><?php echo vn_pub_h(vn_pub_fmt_data($visita['checkout_em'] ?? '')); ?></span></div>
    </div>
    <div class="grid" style="margin-top:9px">
        <div class="box"><b>Check-in</b><span><?php echo vn_pub_h(vn_pub_fmt_data($visita['checkin_em'] ?? '')); ?></span></div>
        <div class="box"><b>Técnico</b><span><?php echo vn_pub_h($tecnico); ?></span></div>
        <div class="box"><b>Cliente</b><span><?php echo vn_pub_h($cliente); ?></span></div>
        <div class="box"><b>ID</b><span><?php echo (int)($visita['id'] ?? 0); ?></span></div>
    </div>

    <div class="section-title">2. Cliente e Local de Atendimento</div>
    <div class="block"><b>Endereço do atendimento</b><span><?php echo vn_pub_h(vn_pub_clean($visita['endereco'] ?? 'Não informado')); ?></span></div>

    <div class="section-title">3. Serviço solicitado</div>
    <div class="block"><b>Problema / serviço previsto</b><span><?php echo vn_pub_h(vn_pub_clean($visita['descricao'] ?? $visita['problema'] ?? 'Não informado')); ?></span></div>
    <div class="block"><b>Anotação do técnico</b><span><?php echo vn_pub_h(vn_pub_clean($visita['comentario'] ?? $visita['observacao'] ?? 'Não informado')); ?></span></div>
    <div class="block"><b>Pendências</b><span><?php echo vn_pub_h(vn_pub_clean($visita['pendencias'] ?? 'Não informado')); ?></span></div>

    <?php
        /*
           Bloco comercial. Nada disso é perguntado de novo no PDF: valor é o
           único dado novo (a OS nunca teve preço); descrição, equipamento e
           garantia já existem em ordens_servico desde a abertura da OS.
           "O que aparece no PDF" vem das opções escolhidas em os-concluidas.php.
        */
        $pdfValor = (float)($visita['pdf_valor'] ?? 0);

        $opcoes = json_decode((string)($visita['pdf_opcoes'] ?? ''), true);
        if (!is_array($opcoes)) $opcoes = [];
        $optPreco    = !array_key_exists('preco', $opcoes)    || !empty($opcoes['preco']);
        $optTotal    = !array_key_exists('total', $opcoes)    || !empty($opcoes['total']);
        $optDetalhes = !array_key_exists('detalhes', $opcoes) || !empty($opcoes['detalhes']);
        $optFotos    = !array_key_exists('fotos', $opcoes)    || !empty($opcoes['fotos']);

        $vnMoney = fn($n) => 'R$ ' . number_format((float)$n, 2, ',', '.');

        // Descrição do serviço (linha da tabela de preço): o texto informado em
        // os-concluidas.php, com o tipo_servico como sugestão só se nunca foi preenchido.
        $textoServicoSalvo = trim((string)($visita['pdf_texto_servico'] ?? ''));
        if ($textoServicoSalvo !== '') {
            $descServico = $textoServicoSalvo;
        } else {
            $tipoServicoOS = (string)($visita['tipo_servico'] ?? $ordemOrigem['tipo_servico'] ?? $visita['tag_servico'] ?? '');
            $descServico = vn_pub_texto_servico($tipoServicoOS);
        }

        // Itens/detalhes: o equipamento já cadastrado na abertura da OS, um por linha.
        $equipTxt = trim((string)($ordemOrigem['equipamento_ordem'] ?? ''));
        $itensLista = $equipTxt !== '' ? preg_split('/[\r\n;,]+/', $equipTxt) : [];

        $garantiaTxt = trim((string)($ordemOrigem['garantia_ordem'] ?? ''));

        /*
           O bloco comercial (preço, condições, itens) é exclusivo da OS
           preventiva — a OS normal continua o relatório técnico puro, sem
           valor nenhum, mesmo que um valor tenha sido salvo para essa OS.
        */
        $mostrarTabela = $ehPreventiva && $optPreco && $pdfValor > 0;
        $mostrarDetalhes = $ehPreventiva && $optDetalhes && !empty($itensLista);
    ?>
    <?php if ($mostrarTabela || $mostrarDetalhes): ?>
        <div class="section-title">Serviços</div>
        <?php if ($mostrarTabela): ?>
            <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:10px">
                <thead>
                    <tr style="background:var(--soft);color:var(--blue2)">
                        <th style="text-align:left;padding:8px;border:1px solid var(--line)">Descrição</th>
                        <th style="text-align:center;padding:8px;border:1px solid var(--line);width:56px">Unid.</th>
                        <th style="text-align:right;padding:8px;border:1px solid var(--line);width:112px">Preço unit.</th>
                        <th style="text-align:center;padding:8px;border:1px solid var(--line);width:48px">Qtd</th>
                        <th style="text-align:right;padding:8px;border:1px solid var(--line);width:120px">Preço</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:8px;border:1px solid var(--line);vertical-align:top">
                            <strong><?php echo vn_pub_h($descServico); ?></strong>
                            <?php if ($mostrarDetalhes): ?>
                                <ul style="margin:6px 0 0;padding-left:16px;color:#334155">
                                    <?php foreach ($itensLista as $it): $it = trim((string)$it); if ($it === '') continue; ?>
                                        <li><?php echo vn_pub_h($it); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;padding:8px;border:1px solid var(--line)">un.</td>
                        <td style="text-align:right;padding:8px;border:1px solid var(--line)"><?php echo vn_pub_h($vnMoney($pdfValor)); ?></td>
                        <td style="text-align:center;padding:8px;border:1px solid var(--line)">1</td>
                        <td style="text-align:right;padding:8px;border:1px solid var(--line)"><?php echo vn_pub_h($vnMoney($pdfValor)); ?></td>
                    </tr>
                    <?php if ($optTotal): ?>
                        <tr>
                            <td colspan="4" style="text-align:right;padding:8px;border:1px solid var(--line);background:var(--blue2);color:#fff;font-weight:800">Total</td>
                            <td style="text-align:right;padding:8px;border:1px solid var(--line);background:var(--blue2);color:#fff;font-weight:800"><?php echo vn_pub_h($vnMoney($pdfValor)); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="two">
                <div class="block"><b>Condições de pagamento</b><span>À vista</span></div>
                <?php if ($garantiaTxt !== ''): ?><div class="block"><b>Garantia</b><span><?php echo vn_pub_h($garantiaTxt); ?></span></div><?php endif; ?>
            </div>
        <?php elseif ($mostrarDetalhes): ?>
            <div class="block">
                <b><?php echo vn_pub_h($descServico); ?></b>
                <ul style="margin:6px 0 0;padding-left:16px">
                    <?php foreach ($itensLista as $it): $it = trim((string)$it); if ($it === '') continue; ?>
                        <li><?php echo vn_pub_h($it); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($optFotos): ?>
    <div class="section-title">4. Fotos do Atendimento</div>
    <?php if (!$fotos): ?>
        <div class="block"><span class="muted">Sem fotos anexadas.</span></div>
    <?php else: ?>
        <div class="photos">
        <?php foreach ($fotos as $i => $foto): ?>
            <figure class="photo"><b><?php echo vn_pub_h($foto['nome'] ?: ('Foto ' . ($i + 1))); ?></b><img src="<?php echo vn_pub_h($foto['src']); ?>" onerror="if(!this.dataset.f1){this.dataset.f1='1';this.src='<?php echo vn_pub_h($foto['fallback'] ?? $foto['src']); ?>';return;}if(!this.dataset.f2){this.dataset.f2='1';this.src='<?php echo vn_pub_h($foto['last'] ?? $foto['fallback'] ?? $foto['src']); ?>';return;}this.onerror=null;" alt="Foto da OS"></figure>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php endif; // optFotos ?>

    <div class="section-title" id="videos">5. Vídeos da OS</div>
    <?php if (!$videos): ?>
        <div class="block"><span class="muted">Sem vídeos anexados.</span></div>
    <?php else: ?>
        <div class="videos">
        <?php foreach ($videos as $i => $video): ?>
            <div class="video">
                <b><?php echo vn_pub_h($video['nome'] ?: ('Vídeo ' . ($i + 1))); ?></b>
                <video controls preload="metadata" playsinline src="<?php echo vn_pub_h($video['src']); ?>"></video>
                <a href="<?php echo vn_pub_h($video['src']); ?>" target="_blank" rel="noopener">Abrir vídeo em nova aba</a>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-title">6. Assinaturas</div>
    <div class="signs">
        <div class="sign"><b>ASSINATURA DO CLIENTE</b><div class="sign-box"><?php if ($assinCli): ?><img src="<?php echo vn_pub_h($assinCli); ?>" alt="Assinatura cliente"><?php else: ?><span class="muted">Sem assinatura</span><?php endif; ?></div><div class="line"></div><span><?php echo vn_pub_h(vn_pub_clean($assinaturas['cliente']['nome_assinante'] ?? 'Responsável pelo local')); ?></span></div>
        <div class="sign"><b>ASSINATURA DO TÉCNICO</b><div class="sign-box"><?php if ($assinTec): ?><img src="<?php echo vn_pub_h($assinTec); ?>" alt="Assinatura técnico"><?php else: ?><span class="muted">Sem assinatura</span><?php endif; ?></div><div class="line"></div><span><?php echo vn_pub_h($tecnico ?: 'Técnico VN System'); ?></span></div>
    </div>

    <footer class="footer"><span>VN System - (11) 96919-0724</span><span>Gerado em <?php echo date('d/m/Y H:i:s'); ?></span></footer>
</main>
<?php endif; ?>
<script src="assets/js/vn-download.js"></script>
</body>
</html>
