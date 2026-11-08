<?php
/*
    VN System - A Pagar: entrega a foto de uma conta (?id=<id da foto>[&mini=1]).
    Só abre com login de admin (auth.php barra os outros cargos). A pasta uploads/contas_pagar
    não é acessível direto pelo navegador, então toda foto passa por aqui.
*/
require_once __DIR__ . '/_vn_orcamento_bootstrap.php';
require_once __DIR__ . '/_vn_contas_pagar_lib.php';

// Libera a sessão: a tela pede várias fotos ao mesmo tempo e não precisa esperar uma à outra.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$id = (int)($_GET['id'] ?? 0);
$arquivo = null;
$nome = 'foto';
try {
    $foto = $id > 0 ? vn_fetch_one('SELECT * FROM vn_contas_pagar_fotos WHERE id = ?', [$id]) : null;
    if ($foto) {
        $arquivo = cp_foto_arquivo($foto, !empty($_GET['mini']));
        $nome = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($foto['nome_original'] ?: 'foto')) ?: 'foto';
    }
} catch (Throwable $e) {
    error_log('VN System [contas a pagar - foto]: ' . $e->getMessage());
}

if (!$arquivo) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Foto não encontrada.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $arquivo); finfo_close($fi); }
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Arquivo não suportado.');
}

// A sessão do PHP manda "no-cache" por padrão; tira para o cache abaixo valer.
header_remove('Pragma');
header_remove('Expires');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($arquivo));
header('Content-Disposition: inline; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');   // foto não muda depois de enviada
readfile($arquivo);
exit;
