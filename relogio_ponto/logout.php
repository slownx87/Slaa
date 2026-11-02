<?php
require_once __DIR__ . '/_config.php';

/* Limpa os três lugares: a memória, o arquivo da sessão no servidor e o cookie
 * no navegador. Só session_destroy() deixa o cookie vivo no celular. */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();

redirect('login-admin.php');
