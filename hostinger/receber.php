<?php
/**
 * VN System - portal Wi-Fi | Endpoint que recebe os cadastros
 *
 * O login.html do hotspot faz POST aqui com um JSON.
 * URL final: https://seudominio.com.br/wifi/receber.php
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'use POST']);
    exit;
}

/* --- Le o corpo ---------------------------------------------------------- */
$bruto = file_get_contents('php://input');
if (strlen($bruto) > 8000) {          // cadastro nao chega perto disso
    http_response_code(413);
    echo json_encode(['ok' => false, 'erro' => 'corpo grande demais']);
    exit;
}

$d = json_decode($bruto, true);
if (!is_array($d)) $d = $_POST;       // fallback para form-urlencoded

/* --- Token ---------------------------------------------------------------
   Barra bot que varre a internet. Nao e segredo forte: quem esta na rede
   Wi-Fi consegue ler o token no codigo-fonte da pagina. Serve para ruido,
   nao para autenticacao.                                                    */
if (TOKEN !== '') {
    $enviado = (string)($d['token'] ?? $_GET['token'] ?? '');
    if (!hash_equals(TOKEN, $enviado)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'token invalido']);
        exit;
    }
}

/* --- Normaliza e valida --------------------------------------------------
   O navegador ja validou, mas qualquer um pode postar aqui direto.
   Entao valida de novo, do lado do servidor.                                */
function txt($v, int $max): string {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return mb_substr($v, 0, $max, 'UTF-8');
}
function dig($v, int $max): string {
    return substr(preg_replace('/\D+/', '', (string)$v), 0, $max);
}

$nome  = txt($d['nome']  ?? '', 120);
$bloco = txt($d['bloco'] ?? '', 20);
$ap    = txt($d['ap']    ?? '', 10);
$email = mb_strtolower(txt($d['email'] ?? '', 100), 'UTF-8');
$fone  = dig($d['fone']  ?? '', 11);

$erros = [];
if (mb_strlen($nome) < 5 || substr_count($nome, ' ') < 1) $erros[] = 'nome';
if ($bloco === '') $erros[] = 'bloco';
if ($ap    === '') $erros[] = 'apartamento';
if ($email !== '' && !emailValido($email)) $erros[] = 'email';
if ($fone !== '' && !in_array(strlen($fone), [10, 11], true)) $erros[] = 'celular';

if ($erros) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'dados invalidos', 'campos' => $erros]);
    exit;
}

/* --- Grava --------------------------------------------------------------- */
try {
    $sql = 'INSERT INTO cadastros
            (criado_em, nome, email, bloco, ap, fone, local_ap, confere,
             mac, ip, servidor, roteador, origem_ip)
            VALUES (:criado, :nome, :email, :bloco, :ap, :fone, :local, :confere,
                    :mac, :ip, :servidor, :roteador, :origem)';

    db()->prepare($sql)->execute([
        ':criado'   => date('Y-m-d H:i:s'),
        ':nome'     => $nome,
        ':email'    => $email !== '' ? $email : null,
        ':bloco'    => $bloco,
        ':ap'       => $ap,
        ':fone'     => $fone !== '' ? $fone : null,
        ':local'    => txt($d['local']    ?? '', 80)  ?: null,
        ':confere'  => txt($d['confere']  ?? '', 160) ?: null,
        ':mac'      => txt($d['mac']      ?? '', 20)  ?: null,
        ':ip'       => txt($d['ip']       ?? '', 45)  ?: null,
        ':servidor' => txt($d['servidor'] ?? '', 60)  ?: null,
        ':roteador' => txt($d['roteador'] ?? '', 60)  ?: null,
        ':origem'   => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    /* Nunca devolve o erro do banco para quem chamou -- vazaria estrutura.
       Guarda num arquivo para voce olhar depois.                            */
    @file_put_contents(
        __DIR__ . '/erros.log',
        date('c') . ' ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'falha ao gravar']);
}
