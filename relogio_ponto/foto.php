<?php
/*
 * Entrega a foto de uma batida — SÓ para administrador logado, e só das batidas
 * dos funcionários que ele administra.
 *
 * Antes as fotos (rostos dos funcionários) ficavam em uploads/ponto_fotos/ com
 * link direto: quem tivesse o endereço abria a foto sem login. Agora a pasta
 * está fechada no .htaccess e tudo passa por aqui.
 *
 * Uso: foto.php?id=<id da batida em ponto_registros>
 */
require_once __DIR__ . '/_config.php';
require_admin();

function foto_negar(int $status, string $msg): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    foto_negar(404, 'Foto não encontrada.');
}

$stmt = db()->prepare("SELECT foto_path, condominio_id FROM ponto_registros WHERE id = ?");
$stmt->execute([$id]);
$reg = $stmt->fetch();
if (!$reg || empty($reg['foto_path'])) {
    foto_negar(404, 'Foto não encontrada.');
}

if (!admin_eh_global() && admin_condominio_id() !== (int)$reg['condominio_id']) {
    foto_negar(403, 'Sem permissão para esta foto.');
}

/* O caminho vem do banco; mesmo assim só aceita o formato que o sistema grava
 * e confere que o arquivo está mesmo dentro de uploads/ponto_fotos. */
$rel = (string)$reg['foto_path'];
if (!preg_match('#^uploads/ponto_fotos/\d{4}/\d{2}/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$#', $rel, $m)) {
    foto_negar(404, 'Foto não encontrada.');
}
$base = realpath(__DIR__ . '/uploads/ponto_fotos');
$arquivo = realpath(__DIR__ . '/' . $rel);
if ($base === false || $arquivo === false || strpos($arquivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($arquivo)) {
    foto_negar(404, 'Foto não encontrada.');
}

$tipos = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $tipos[strtolower($m[1])]);
header('Content-Length: ' . filesize($arquivo));
header('X-Content-Type-Options: nosniff');
/* "private": foto de rosto não pode ficar em cache compartilhado. */
header('Cache-Control: private, max-age=3600');
readfile($arquivo);
