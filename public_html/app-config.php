<?php
require_once __DIR__ . '/_vn_security.php'; // sessão/cookie/permissões antes do session_start()
/*
 * VN System - Configuração do aplicativo Android.
 *
 * O app entra no sistema pelo próprio site (o login fica salvo no app). Depois do login ele pergunta aqui se já pode
 * conferir os avisos de OS nova/alterada/excluída e de cobranças chegando (app-notificacoes.php), e recebe a chave desses avisos
 * (VN_APP_TOKEN, a mesma do vn-secrets.php). A chave só sai para administrador e funcionário logados. Sem login ou sem chave
 * configurada no servidor, a resposta vem sem chave e o app simplesmente não liga os avisos.
 */
vn_session_start();
require_once __DIR__ . '/config.php';

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cargo     = strtolower(trim((string)($_SESSION['cargo'] ?? '')));
$usuarioId = (int)($_SESSION['admin_id'] ?? $_SESSION['id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0);
$logado    = ($cargo !== '' && $usuarioId > 0);

$resp = ['ok' => true, 'logado' => $logado, 'cargo' => $logado ? $cargo : '', 'chave' => ''];
if ($logado && in_array($cargo, ['admin', 'funcionario'], true) && defined('VN_APP_TOKEN') && (string)VN_APP_TOKEN !== '') {
    $resp['chave'] = (string)VN_APP_TOKEN;
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE);
