<?php
/*
 * Página de emergência para descobrir a causa de um erro 500.
 *
 * Ela NÃO depende do _config.php, então funciona mesmo quando o resto do
 * sistema está quebrado. Mostra a versão do PHP, as extensões e o erro exato
 * de cada arquivo principal.
 *
 * Apague este arquivo depois que o sistema voltar ao normal.
 *
 * PROTEGIDO POR CHAVE: esta página mostra versão do PHP, extensões instaladas,
 * nomes de tabelas e, se o banco recusar a conexão, a mensagem de erro do
 * MySQL. Nada disso pode ficar aberto na internet. Ela não pode exigir login
 * (precisa funcionar justamente quando o login está quebrado), então o acesso é
 * por chave no endereço:
 *
 *     https://seusite.com.br/checar.php?chave=A_CHAVE_DO_CONFIG_LOCAL
 *
 * Troque a chave no _config.local.php se algum dia ela vazar.
 */
/* A chave NÃO fica mais escrita aqui (este arquivo vai para o git e qualquer um
 * que o visse abriria a página). Ela vem do _config.local.php:
 *     define('CHECAR_CHAVE', 'uma-chave-longa-e-unica');
 * Sem essa linha a página fica desligada e responde "Não encontrado". */
if (is_file(__DIR__ . '/_config.local.php')) {
    require_once __DIR__ . '/_config.local.php';
}

if (!defined('CHECAR_CHAVE') || strlen((string)CHECAR_CHAVE) < 12
    || !isset($_GET['chave']) || !hash_equals((string)CHECAR_CHAVE, (string)$_GET['chave'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Não encontrado.\n";
    exit;
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

function ok($condicao)
{
    return $condicao ? 'OK' : 'FALTANDO';
}

echo "=== AMBIENTE ===\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'Mínimo necessário: 7.1' . "\n";
if (PHP_VERSION_ID < 70100) {
    echo ">>> PROBLEMA: este PHP é antigo demais. Suba para 7.4 ou 8.x no painel da hospedagem.\n";
}
echo 'pdo_mysql: ' . ok(extension_loaded('pdo_mysql')) . "\n";
echo 'mbstring:  ' . ok(extension_loaded('mbstring')) . "\n";
echo 'json:      ' . ok(extension_loaded('json')) . "\n";
echo 'session:   ' . ok(extension_loaded('session')) . "\n";
echo 'Memória:   ' . ini_get('memory_limit') . "\n";
echo 'POST máx.: ' . ini_get('post_max_size') . "\n";
echo "\n";

echo "=== ARQUIVOS ===\n";
foreach (['_config.php', '_app_shell.php'] as $arquivo) {
    $caminho = __DIR__ . '/' . $arquivo;
    if (!file_exists($caminho)) {
        echo str_pad($arquivo, 18) . "NAO ENCONTRADO\n";
        continue;
    }
    try {
        include_once $caminho;
        // O _config.php desliga a exibição de erros; religa para os testes seguintes.
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
        echo str_pad($arquivo, 18) . "carregou OK\n";
    } catch (Throwable $e) {
        echo str_pad($arquivo, 18) . 'ERRO -> ' . get_class($e) . ': ' . $e->getMessage()
            . ' (arquivo ' . basename($e->getFile()) . ', linha ' . $e->getLine() . ")\n";
    }
}
echo "\n";

echo "=== BANCO ===\n";
if (!function_exists('db')) {
    echo "Não dá para testar: o _config.php não carregou.\n";
} else {
    try {
        $pdo = db();
        echo "Conexão: OK\n";
        foreach (['ponto_funcionarios', 'ponto_registros', 'ponto_resumo_diario', 'ponto_solicitacoes', 'ponto_pin_tentativas'] as $tabela) {
            try {
                $existe = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabela))->fetchColumn();
                echo str_pad($tabela, 24) . ($existe ? 'existe' : 'NAO EXISTE') . "\n";
            } catch (Throwable $e) {
                echo str_pad($tabela, 24) . 'ERRO: ' . $e->getMessage() . "\n";
            }
        }
        try {
            $pdo->query("SELECT pin_fingerprint FROM ponto_funcionarios LIMIT 1");
            echo "coluna pin_fingerprint:  existe\n";
        } catch (Throwable $e) {
            echo "coluna pin_fingerprint:  NAO EXISTE (o login por PIN fica lento)\n";
        }
    } catch (Throwable $e) {
        echo 'ERRO -> ' . $e->getMessage() . "\n";
    }
}
echo "\n";

echo "=== PASTAS ===\n";
$pasta = __DIR__ . '/uploads/ponto_fotos';
echo 'uploads/ponto_fotos: ' . (is_dir($pasta) ? 'existe' : 'nao existe')
    . ', ' . (is_writable($pasta) ? 'gravavel' : 'SEM PERMISSAO DE ESCRITA') . "\n";

echo "\nSe tudo acima estiver OK e o erro 500 continuar, o problema está na página\n";
echo "específica que você abriu. Me mande o que apareceu aqui.\n";
