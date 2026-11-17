<?php
/*
    VN System - Cobranças: entrega o anexo de uma cobrança (?id=<id do anexo>[&mini=1][&baixar=1]).
    Foto (JPG/PNG/WebP) ou PDF. Só abre com login de admin (como o resto da Central de Cobranças).
    A pasta uploads/cobrancas_anexos não é acessível direto pelo navegador, então todo anexo passa por aqui.
      mini=1   miniatura da foto (se existir)
      baixar=1 força o download; sem isso o PDF abre na janela
*/
require_once __DIR__ . '/_vn_orcamento_bootstrap.php';
require_once __DIR__ . '/_vn_cobrancas_anexos.php';

$id = (int)($_GET['id'] ?? 0);
$arquivo = null;
$nome = 'anexo';
try {
    $a = cba_anexo($id);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($a) {
        $arquivo = cba_arquivo($a, !empty($_GET['mini']));
        $nome = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($a['nome_original'] ?: 'anexo')) ?: 'anexo';
    }
} catch (Throwable $e) {
    error_log('VN System [cobranças - anexo]: ' . $e->getMessage());
}

if (!$arquivo) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Arquivo não encontrado.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $arquivo); finfo_close($fi); }
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Arquivo não suportado.');
}
if ($mime === 'application/pdf' && strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') $nome .= '.pdf';

header_remove('Pragma');
header_remove('Expires');
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($arquivo));
header('Content-Disposition: ' . (!empty($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($arquivo);
exit;
