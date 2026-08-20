<?php
/**
 * VN System - portal Wi-Fi | Configuracao
 *
 * Preencha os dados abaixo e suba a pasta inteira para a Hostinger.
 * ESTE ARQUIVO TEM SENHAS. Nunca mande ele para o GitHub preenchido.
 */

/* --- Banco de dados -------------------------------------------------------
   hPanel > Bancos de dados MySQL > crie um banco e um usuario.
   A Hostinger mostra os nomes ja com o prefixo (ex.: u123456789_wifi).      */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_wifi');
define('DB_USER', 'u000000000_wifi');
define('DB_PASS', 'senha-do-banco');

/* --- Token do portal ------------------------------------------------------
   O mesmo valor tem que estar em CONFIG.token no login.html.
   Troque por algo aleatorio. Gere um com:
     php -r "echo bin2hex(random_bytes(16));"                                */
define('TOKEN', 'troque-este-token-por-um-aleatorio');

/* --- Senha do painel ------------------------------------------------------
   Gere o hash da sua senha e cole aqui:
     php -r "echo password_hash('minhasenha', PASSWORD_DEFAULT);"
   O hash abaixo corresponde a senha:  trocar123                             */
define('PAINEL_HASH', '$2y$12$/w9x7a6rDwhv10jkK6AU3OyHED9UEXkrINKRwQEHJjuxpBULEiXaq');

/* --- Geral --------------------------------------------------------------- */
define('TZ', 'America/Sao_Paulo');
define('POR_PAGINA', 50);

date_default_timezone_set(TZ);

/** Conexao PDO reaproveitada. */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Escapa para HTML. Todo dado que veio do morador passa por aqui. */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Valida e-mail. */
function emailValido(string $e): bool {
    $e = trim($e);
    if ($e === '' || strlen($e) > 100) return false;
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

/** Formata celular para exibicao. */
function foneFmt(?string $f): string {
    $f = preg_replace('/\D+/', '', (string)$f);
    if (strlen($f) === 11) return '('.substr($f,0,2).') '.substr($f,2,5).'-'.substr($f,7);
    if (strlen($f) === 10) return '('.substr($f,0,2).') '.substr($f,2,4).'-'.substr($f,6);
    return $f;
}
