<?php
/*
 * Entrada do painel administrativo.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';

if (is_admin_logged()) {
    redirect('admin.php');
}

$erro = '';
try {
    ensure_photo_columns();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        /* O painel dá acesso a todos os funcionários e a todo o histórico de
         * ponto: sem esta trava dá para tentar senha atrás de senha à vontade.
         * É a mesma trava do PIN, com contador próprio. */
        ponto_pin_guard('admin');

        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');

        $stmt = db()->prepare("SELECT * FROM ponto_admins WHERE usuario = ? AND ativo = 1 LIMIT 1");
        $stmt->execute([$usuario]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($senha, $admin['senha_hash'])) {
            ponto_pin_falhou('admin');
            throw new RuntimeException('Usuário ou senha inválido.');
        }
        ponto_pin_acertou('admin');

        /* Troca o identificador da sessão ao entrar: se alguém tiver plantado um
         * cookie de sessão antes do login, ele deixa de valer aqui. */
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }

        $_SESSION['ponto_admin_id'] = (int)$admin['id'];
        $_SESSION['ponto_admin_nome'] = $admin['nome'];
        $_SESSION['ponto_admin_nivel'] = $admin['nivel'];
        $_SESSION['ponto_admin_condominio_id'] = $admin['condominio_id'] !== null ? (int)$admin['condominio_id'] : null;

        log_action('login_admin', 'Admin entrou no painel.');
        redirect('admin.php');
    }
} catch (Throwable $e) {
    $erro = ponto_msg_erro($e);
}

app_mobile_head('Painel Admin - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
?>
<main class="min-h-screen flex flex-col items-center justify-center px-container-margin py-8 max-w-md mx-auto w-full gap-stack-gap-lg">

<div class="flex flex-col items-center gap-3 fade-in-up">
<?php app_mobile_logo('logo-app h-16 w-auto object-contain'); ?>
<h1 class="font-headline-lg text-headline-lg text-on-surface">Painel administrativo</h1>
<p class="font-body-md text-body-md text-secondary text-center">Funcionários, ajustes de ponto, advertências e relatórios.</p>
</div>

<form class="cartao rounded-2xl p-4 flex flex-col gap-3 w-full fade-in-up" method="post" style="animation-delay:.1s;">
<input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>"/>

<?php if ($erro): ?>
<div class="rounded-xl border border-error/40 bg-error/10 p-3 flex items-center gap-2">
<span class="material-symbols-outlined text-error text-[18px]">error</span>
<span class="font-body-md text-body-md text-on-surface"><?= h($erro) ?></span>
</div>
<?php endif; ?>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="usuario">Usuário</label>
<input autocomplete="username" class="campo font-body-lg text-body-lg" id="usuario" name="usuario" required type="text"/>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="senha">Senha</label>
<input autocomplete="current-password" class="campo font-body-lg text-body-lg" id="senha" name="senha" required type="password"/>

<button class="h-[48px] rounded-full bg-primary text-on-primary font-headline-md text-headline-md flex items-center justify-center gap-2 shadow-lg active:scale-95 transition-transform mt-1" type="submit">
<span class="material-symbols-outlined text-[18px]">login</span> Acessar painel
</button>
</form>

<!-- Aparece só quando o navegador aceita instalar (Android/Chrome/Edge). -->
<button class="hidden w-full h-[48px] rounded-full border border-primary/60 text-primary font-label-bold text-label-bold items-center justify-center gap-2 active:scale-95 transition-transform fade-in-up" data-instalar-app style="animation-delay:.2s;" type="button">
<span class="material-symbols-outlined text-[18px]">install_mobile</span>
Instalar o app no celular
</button>

<button class="font-label-bold text-label-bold text-secondary py-1 flex items-center gap-1" id="btnComoInstalar" type="button">
<span class="material-symbols-outlined text-[16px]">help</span>
Como instalar o app?
</button>

<a class="font-label-bold text-label-bold text-primary py-2" href="bater-ponto.php">Ir para bater ponto</a>
</main>

<!-- ------------------------- folha: como instalar ------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="ajudaFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl p-container-margin pb-8 flex flex-col gap-3" id="ajudaFolha">
<div class="flex justify-center"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<h3 class="font-headline-md text-headline-md text-on-surface">Instalar o app</h3>
<p class="font-body-md text-body-md text-secondary">Depois de instalado, o painel abre pelo ícone na tela do celular, em tela cheia, sem a barra do navegador.</p>

<div class="cartao rounded-xl p-3 flex flex-col gap-1">
<span class="font-label-bold text-label-bold text-primary uppercase tracking-wider">Android (Chrome)</span>
<p class="font-body-md text-body-md text-on-surface-variant">Toque em <b>Instalar o app no celular</b> aqui em cima. Se o botão não aparecer, abra o menu <b>⋮</b> do Chrome e escolha <b>Instalar aplicativo</b>.</p>
</div>

<div class="cartao rounded-xl p-3 flex flex-col gap-1">
<span class="font-label-bold text-label-bold text-primary uppercase tracking-wider">iPhone (Safari)</span>
<p class="font-body-md text-body-md text-on-surface-variant">Toque no botão <b>Compartilhar</b> (quadrado com seta para cima) e depois em <b>Adicionar à Tela de Início</b>.</p>
</div>

<p class="font-label-sm text-label-sm text-secondary">O app precisa de internet para funcionar — os horários ficam no servidor.</p>
<button class="w-full h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="ajudaFechar" type="button">Entendi</button>
</div>
<?php app_mobile_footer(csrf_token()); ?>
<script>
(() => {
    'use strict';
    const fundo = document.getElementById('ajudaFundo');
    const folha = document.getElementById('ajudaFolha');

    function abrir() { fundo.classList.add('aberto'); folha.classList.add('aberto'); }
    function fechar() { fundo.classList.remove('aberto'); folha.classList.remove('aberto'); }

    document.getElementById('btnComoInstalar').addEventListener('click', abrir);
    document.getElementById('ajudaFechar').addEventListener('click', fechar);
    fundo.addEventListener('click', fechar);
})();
</script>
<?php app_mobile_end(); ?>
