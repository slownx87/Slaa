<?php
/*
 * Painel administrativo - funcionários.
 *
 * Lista com busca, cadastro, edição, troca de PIN e desativação.
 *
 * Sobre "ver PIN": não dá. O PIN é gravado com password_hash (bcrypt), que é
 * de mão única — nem o sistema consegue ler de volta. O que existe aqui é
 * "trocar PIN": o admin define (ou sorteia) um novo e ele aparece uma única
 * vez na tela para ser entregue ao funcionário.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_admin_shell.php';
require_admin();

/** Condomínio e cargo escolhidos precisam existir (antes um id inventado virava erro de banco na tela). */
function admin_valida_condominio_e_cargo(int $condominioId, int $cargoId, int $condominioAtual = 0, int $cargoAtual = 0): void
{
    $st = db()->prepare("SELECT ativo FROM ponto_condominios WHERE id = ?");
    $st->execute([$condominioId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Esse condomínio não existe. Escolha um da lista.');
    }
    if ((int)$row['ativo'] !== 1 && $condominioId !== $condominioAtual) {
        throw new RuntimeException('Esse condomínio está desativado. Escolha outro ou reative-o antes.');
    }
    if ($cargoId > 0) {
        $st = db()->prepare("SELECT ativo FROM ponto_cargos WHERE id = ?");
        $st->execute([$cargoId]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('Esse cargo não existe. Escolha um da lista ou "Sem cargo".');
        }
        if ((int)$row['ativo'] !== 1 && $cargoId !== $cargoAtual) {
            throw new RuntimeException('Esse cargo está desativado. Escolha outro ou reative-o antes.');
        }
    }
}

/**
 * Grava o PIN escolhido pelo administrador. PIN vazio sorteia um.
 * Devolve o PIN em texto para mostrar UMA vez na tela — depois só fica o hash.
 */
function admin_aplicar_pin(int $funcionarioId, string $pin, string $nome): string
{
    $pin = trim($pin);
    if ($pin === '') {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    if (!preg_match('/^\d{4}$/', $pin)) {
        throw new RuntimeException('O PIN precisa ter exatamente 4 números.');
    }

    /* Confere contra TODO mundo, inclusive quem está desativado: se o PIN for
     * repetido e o outro funcionário voltar a ser ativado, os dois ficam sem
     * conseguir bater ponto. */
    $existente = ponto_pin_em_uso($pin, $funcionarioId);
    if ($existente) {
        throw new RuntimeException('O PIN ' . $pin . ' já é de ' . $existente['nome']
            . ((int)$existente['ativo'] === 1 ? '' : ' (desativado)') . '. Escolha outro.');
    }

    if (pin_fingerprint_disponivel()) {
        db()->prepare("UPDATE ponto_funcionarios SET pin_hash = ?, pin_fingerprint = ? WHERE id = ?")
            ->execute([password_hash($pin, PASSWORD_DEFAULT), pin_fingerprint($pin), $funcionarioId]);
    } else {
        db()->prepare("UPDATE ponto_funcionarios SET pin_hash = ? WHERE id = ?")
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $funcionarioId]);
    }
    log_action('trocar_pin_funcionario', 'Trocou PIN de ' . $nome . ' (ID ' . $funcionarioId . ')', $funcionarioId);
    return $pin;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    try {
        check_csrf();
        $acao = (string)($_POST['acao'] ?? '');

        /* ---------------------------- cadastrar ---------------------------- */
        if ($acao === 'criar') {
            $nome = trim((string)($_POST['nome'] ?? ''));
            $condominioId = (int)($_POST['condominio_id'] ?? 0);
            $cargoId = (int)($_POST['cargo_id'] ?? 0);
            $pin = trim((string)($_POST['pin'] ?? ''));

            if (mb_strlen($nome) < 3) throw new RuntimeException('Informe o nome completo.');
            if ($condominioId <= 0) throw new RuntimeException('Escolha o condomínio.');
            if (!preg_match('/^\d{4}$/', $pin)) throw new RuntimeException('O PIN precisa ter 4 números.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null && admin_condominio_id() !== $condominioId) {
                throw new RuntimeException('Sem permissão para esse condomínio.');
            }

            admin_valida_condominio_e_cargo($condominioId, $cargoId);
            $nome = mb_substr($nome, 0, 120);

            $jaUsa = ponto_pin_em_uso($pin);
            if ($jaUsa) {
                throw new RuntimeException('O PIN ' . $pin . ' já é de ' . $jaUsa['nome']
                    . ((int)$jaUsa['ativo'] === 1 ? '' : ' (desativado)') . '. Escolha outro.');
            }

            if (pin_fingerprint_disponivel()) {
                db()->prepare("INSERT INTO ponto_funcionarios (condominio_id, cargo_id, nome, cpf, telefone, email, pin_hash, pin_fingerprint, ativo) VALUES (?, ?, ?, '', ?, '', ?, ?, 1)")
                    ->execute([$condominioId, $cargoId ?: null, $nome, mb_substr(trim((string)($_POST['telefone'] ?? '')), 0, 30), password_hash($pin, PASSWORD_DEFAULT), pin_fingerprint($pin)]);
            } else {
                db()->prepare("INSERT INTO ponto_funcionarios (condominio_id, cargo_id, nome, cpf, telefone, email, pin_hash, ativo) VALUES (?, ?, ?, '', ?, '', ?, 1)")
                    ->execute([$condominioId, $cargoId ?: null, $nome, mb_substr(trim((string)($_POST['telefone'] ?? '')), 0, 30), password_hash($pin, PASSWORD_DEFAULT)]);
            }
            log_action('criar_funcionario', 'Cadastrou funcionário: ' . $nome, (int)db()->lastInsertId(), $condominioId);
            admin_json(['ok' => true, 'mensagem' => $nome . ' cadastrado.']);
        }

        /* ------------------------------ editar ------------------------------ */
        if ($acao === 'editar') {
            $id = (int)($_POST['id'] ?? 0);
            $func = admin_funcionario_autorizado($id);

            $nome = trim((string)($_POST['nome'] ?? ''));
            $cargoId = (int)($_POST['cargo_id'] ?? 0);
            $condominioId = (int)($_POST['condominio_id'] ?? 0);
            if (mb_strlen($nome) < 3) throw new RuntimeException('Informe o nome completo.');
            if ($condominioId <= 0) throw new RuntimeException('Escolha o condomínio.');
            if (admin_nivel() !== 'super_admin' && admin_condominio_id() !== null && admin_condominio_id() !== $condominioId) {
                throw new RuntimeException('Sem permissão para mover para esse condomínio.');
            }

            admin_valida_condominio_e_cargo($condominioId, $cargoId, (int)$func['condominio_id'], (int)($func['cargo_id'] ?? 0));
            $nome = mb_substr($nome, 0, 120);

            db()->prepare("UPDATE ponto_funcionarios SET nome = ?, cargo_id = ?, condominio_id = ?, telefone = ? WHERE id = ?")
                ->execute([$nome, $cargoId ?: null, $condominioId, mb_substr(trim((string)($_POST['telefone'] ?? '')), 0, 30), $id]);
            log_action('editar_funcionario', 'Editou funcionário ID ' . $id, $id, $condominioId);

            /* Campo de PIN em branco na edição = mantém o PIN atual. */
            $pinNovo = trim((string)($_POST['pin'] ?? ''));
            if ($pinNovo !== '') {
                $pinNovo = admin_aplicar_pin($id, $pinNovo, $nome);
                admin_json(['ok' => true, 'pin' => $pinNovo, 'mensagem' => 'Dados salvos e PIN alterado para ' . $pinNovo . '.']);
            }

            admin_json(['ok' => true, 'mensagem' => 'Dados salvos.']);
        }

        /* ----------------------------- trocar PIN ----------------------------- */
        if ($acao === 'trocar_pin') {
            $id = (int)($_POST['id'] ?? 0);
            $func = admin_funcionario_autorizado($id);

            $pin = admin_aplicar_pin($id, (string)($_POST['pin'] ?? ''), (string)$func['nome']);
            admin_json(['ok' => true, 'pin' => $pin, 'mensagem' => 'PIN de ' . $func['nome'] . ' agora é ' . $pin . '.']);
        }

        /* -------------------------- ativar/desativar -------------------------- */
        if ($acao === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $func = admin_funcionario_autorizado($id);

            /* Reativando: se o PIN dele for igual ao de alguém que está ativo,
             * os dois ficariam sem conseguir bater (o sistema não teria como
             * saber de quem é o PIN). Barra antes de acontecer. */
            if ((int)$func['ativo'] === 0 && !empty($func['pin_fingerprint'])) {
                $stmtPin = db()->prepare("SELECT nome FROM ponto_funcionarios
                    WHERE ativo = 1 AND id <> ? AND pin_fingerprint = ? LIMIT 1");
                $stmtPin->execute([$id, (string)$func['pin_fingerprint']]);
                $conflito = $stmtPin->fetchColumn();
                if ($conflito) {
                    throw new RuntimeException('O PIN de ' . $func['nome'] . ' é o mesmo de ' . $conflito
                        . ', que está ativo. Troque o PIN de um dos dois antes de reativar.');
                }
            }

            db()->prepare("UPDATE ponto_funcionarios SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?")->execute([$id]);
            $novo = (int)$func['ativo'] === 1 ? 'desativado' : 'reativado';
            log_action('alterar_status_funcionario', 'Funcionário ID ' . $id . ' ' . $novo, $id, (int)$func['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => $func['nome'] . ' ' . $novo . '.']);
        }

        /* ------------------------------ excluir ------------------------------ */
        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $func = admin_funcionario_autorizado($id);

            $stmt = db()->prepare("SELECT COUNT(*) FROM ponto_registros WHERE funcionario_id = ?");
            $stmt->execute([$id]);
            $batidas = (int)$stmt->fetchColumn();

            /* Apagar quem já bateu ponto destruiria a folha e as provas do
             * período. Nesse caso só desativa. */
            if ($batidas > 0) {
                throw new RuntimeException($func['nome'] . ' tem ' . $batidas . ' batida(s) registrada(s) e não pode ser excluído — use Desativar para tirar do sistema sem perder o histórico.');
            }

            /* Pedidos, advertências e resumos desse funcionário iriam ficar soltos
             * no banco (apontando para um id que não existe mais). Sai tudo junto. */
            db()->beginTransaction();
            try {
                foreach (['ponto_solicitacoes', 'ponto_advertencias', 'ponto_resumo_diario', 'ponto_ajustes'] as $tabelaFilha) {
                    if (table_exists($tabelaFilha)) {
                        db()->prepare("DELETE FROM {$tabelaFilha} WHERE funcionario_id = ?")->execute([$id]);
                    }
                }
                db()->prepare("DELETE FROM ponto_funcionarios WHERE id = ?")->execute([$id]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            log_action('excluir_funcionario', 'Excluiu funcionário ' . $func['nome'] . ' (ID ' . $id . ')', null, (int)$func['condominio_id']);
            admin_json(['ok' => true, 'mensagem' => $func['nome'] . ' excluído.']);
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $e) {
        admin_json(['ok' => false, 'error' => ponto_msg_erro($e)], 422);
    }
}

[$filtro, $params] = admin_filtro_condominio('f');
$stmt = db()->prepare("SELECT f.*, c.nome AS cargo_nome, co.nome AS condominio_nome
    FROM ponto_funcionarios f
    LEFT JOIN ponto_cargos c ON c.id = f.cargo_id
    INNER JOIN ponto_condominios co ON co.id = f.condominio_id
    WHERE 1=1 {$filtro}
    ORDER BY f.ativo DESC, f.nome");
$stmt->execute($params);
$funcionarios = $stmt->fetchAll();

$condominios = get_condominios(true);
$cargos = get_cargos(true);
$csrf = csrf_token();

app_mobile_head('Funcionários - ' . APP_NAME, 'bg-background text-on-background antialiased min-h-screen', admin_css(), 'manifest-admin.webmanifest');
admin_topbar();
?>
<main class="pt-[70px] pb-24 px-container-margin max-w-md mx-auto w-full flex flex-col gap-stack-gap-md">

<div class="flex flex-col gap-2 fade-in-up">
<h1 class="font-headline-md text-headline-md text-on-surface">Equipe</h1>
<div class="relative flex items-center">
<span class="material-symbols-outlined absolute left-2 text-secondary text-[18px]">search</span>
<input class="campo pl-8 font-body-md text-body-md" id="busca" placeholder="Buscar por nome, cargo ou local..." type="text"/>
</div>
<p class="font-label-sm text-label-sm text-secondary"><span id="contador"><?= count($funcionarios) ?></span> funcionário(s)</p>
</div>

<div class="flex flex-col gap-2" id="lista">
<?php foreach ($funcionarios as $i => $f):
    $ativo = (int)$f['ativo'] === 1; ?>
<article class="surgir cartao rounded-xl p-3 flex flex-col gap-2 <?= $ativo ? '' : 'opacity-60' ?>"
         data-id="<?= (int)$f['id'] ?>"
         data-busca="<?= h(mb_strtolower($f['nome'] . ' ' . ($f['cargo_nome'] ?? '') . ' ' . $f['condominio_nome'])) ?>"
         data-nome="<?= h((string)$f['nome']) ?>"
         data-cargo="<?= (int)($f['cargo_id'] ?? 0) ?>"
         data-condominio="<?= (int)$f['condominio_id'] ?>"
         data-telefone="<?= h((string)($f['telefone'] ?? '')) ?>"
         data-ativo="<?= $ativo ? '1' : '0' ?>"
         style="animation-delay:<?= min($i, 10) * 45 ?>ms">
<div class="flex items-start gap-3">
<div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary flex-shrink-0 font-headline-md text-headline-md">
<?= h(mb_strtoupper(mb_substr((string)$f['nome'], 0, 1))) ?>
</div>
<div class="min-w-0 flex-1">
<div class="flex items-center gap-2">
<span class="font-body-lg text-body-lg text-on-surface font-medium truncate"><?= h((string)$f['nome']) ?></span>
<?php if (!$ativo): ?><span class="px-2 py-0.5 rounded-full bg-surface-variant text-secondary font-label-sm text-label-sm flex-shrink-0">inativo</span><?php endif; ?>
</div>
<p class="font-label-bold text-label-bold text-primary truncate"><?= h((string)($f['cargo_nome'] ?: 'Sem cargo')) ?></p>
<p class="font-body-md text-body-md text-secondary flex items-center gap-1 truncate">
<span class="material-symbols-outlined text-[14px]">location_on</span><?= h((string)$f['condominio_nome']) ?>
</p>
</div>
</div>
<div class="flex items-center justify-between border-t border-outline-variant/30 pt-2">
<button class="text-primary font-label-bold text-label-bold flex items-center gap-1 px-2 py-1 rounded hover:bg-primary/10" data-pin="<?= (int)$f['id'] ?>" type="button">
<span class="material-symbols-outlined text-[16px]">key</span> Trocar PIN
</button>
<div class="flex gap-1">
<button class="w-8 h-8 rounded-full flex items-center justify-center text-secondary hover:bg-surface-variant/50" data-editar="<?= (int)$f['id'] ?>" type="button" aria-label="Editar">
<span class="material-symbols-outlined text-[18px]">edit</span>
</button>
<button class="w-8 h-8 rounded-full flex items-center justify-center <?= $ativo ? 'text-amber-500' : 'text-green-500' ?> hover:bg-surface-variant/50" data-status="<?= (int)$f['id'] ?>" type="button" aria-label="Ativar ou desativar">
<span class="material-symbols-outlined text-[18px]"><?= $ativo ? 'person_off' : 'person_check' ?></span>
</button>
<button class="w-8 h-8 rounded-full flex items-center justify-center text-error hover:bg-error/10" data-excluir="<?= (int)$f['id'] ?>" type="button" aria-label="Excluir">
<span class="material-symbols-outlined text-[18px]">delete</span>
</button>
</div>
</div>
</article>
<?php endforeach; ?>
</div>

<p class="hidden font-body-md text-body-md text-secondary text-center py-6" id="vazio">Nenhum funcionário encontrado.</p>
</main>

<button class="fixed bottom-[76px] right-container-margin w-12 h-12 bg-primary text-on-primary rounded-2xl shadow-lg flex items-center justify-center z-40 active:scale-95 transition-transform" id="btnNovo" type="button" aria-label="Cadastrar funcionário">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">add</span>
</button>

<!-- ------------------------- folha: cadastrar/editar ------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="formFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl flex flex-col" id="formFolha" style="max-height:88vh;">
<div class="flex justify-center pt-3 pb-1"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<div class="overflow-y-auto px-container-margin pb-8 flex flex-col gap-3">
<h3 class="font-headline-md text-headline-md text-on-surface" id="formTitulo">Novo funcionário</h3>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fNome">Nome completo</label>
<input class="campo font-body-lg text-body-lg" id="fNome" maxlength="120" type="text"/>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fCondominio">Condomínio</label>
<select class="campo font-body-lg text-body-lg" id="fCondominio">
<?php foreach ($condominios as $c): ?>
<option value="<?= (int)$c['id'] ?>"><?= h((string)$c['nome']) ?></option>
<?php endforeach; ?>
</select>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fCargo">Cargo</label>
<select class="campo font-body-lg text-body-lg" id="fCargo">
<option value="0">Sem cargo</option>
<?php foreach ($cargos as $c): ?>
<option value="<?= (int)$c['id'] ?>"><?= h((string)$c['nome']) ?></option>
<?php endforeach; ?>
</select>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fTelefone">Telefone (opcional)</label>
<input class="campo font-body-lg text-body-lg" id="fTelefone" maxlength="30" type="tel"/>

<div class="flex flex-col gap-2" id="blocoPin">
<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="fPin" id="fPinLabel">PIN de 4 números</label>
<p class="font-label-sm text-label-sm text-secondary hidden" id="fPinAviso">Deixe em branco para manter o PIN atual.</p>
<div class="flex gap-2">
<input class="campo font-display-time text-[24px] text-center tabular-nums tracking-[0.3em]" id="fPin" inputmode="numeric" maxlength="4" type="text"/>
<button class="px-3 rounded-xl border border-primary text-primary font-label-bold text-label-bold flex-shrink-0" id="btnSortear" type="button">Sortear</button>
</div>
</div>

<div class="flex gap-2 pt-2">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="formCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="formSalvar" type="button">Salvar</button>
</div>
</div>
</div>

<!-- ----------------------------- folha: trocar PIN ----------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="pinFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl p-container-margin pb-8 flex flex-col gap-3" id="pinFolha">
<div class="flex justify-center"><div class="w-10 h-1 rounded-full bg-outline-variant"></div></div>
<h3 class="font-headline-md text-headline-md text-on-surface" id="pinTitulo">Trocar PIN</h3>
<p class="font-body-md text-body-md text-secondary" id="pinTexto"></p>

<label class="font-label-bold text-label-bold text-secondary uppercase tracking-wider" for="pinNovo">Novo PIN</label>
<div class="flex gap-2">
<input class="campo font-display-time text-[26px] text-center tabular-nums tracking-[0.3em]" id="pinNovo" inputmode="numeric" maxlength="4" placeholder="0000" type="text"/>
<button class="px-3 rounded-xl border border-primary text-primary font-label-bold text-label-bold flex-shrink-0" id="pinSortear" type="button">Sortear</button>
</div>
<p class="font-label-sm text-label-sm text-secondary">O PIN antigo para de funcionar na hora. Anote para entregar ao funcionário — depois ele não aparece mais.</p>

<div class="flex gap-2 pt-1">
<button class="flex-1 h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="pinCancelar" type="button">Cancelar</button>
<button class="flex-1 h-[46px] rounded-full bg-primary text-on-primary font-label-bold text-label-bold" id="pinSalvar" type="button">Salvar PIN</button>
</div>
</div>

<!-- --------------------------- folha: confirmação --------------------------- -->
<div class="sheet-fundo fixed inset-0 bg-black/70 z-[80]" id="confFundo"></div>
<div class="sheet-folha fixed bottom-0 left-0 right-0 z-[90] max-w-md mx-auto bg-surface-container-lowest rounded-t-[20px] shadow-2xl p-container-margin pb-8 flex flex-col gap-3 items-center text-center" id="confFolha">
<div class="w-10 h-1 rounded-full bg-outline-variant"></div>
<div class="w-12 h-12 rounded-full bg-error/15 flex items-center justify-center text-error mt-2">
<span class="material-symbols-outlined text-[24px]" id="confIcone">delete_forever</span>
</div>
<h3 class="font-headline-md text-headline-md text-on-surface" id="confTitulo">Confirmar</h3>
<p class="font-body-md text-body-md text-secondary" id="confTexto"></p>
<div class="w-full flex flex-col gap-2 pt-2">
<button class="w-full h-[46px] rounded-full bg-error text-on-error font-label-bold text-label-bold" id="confSim" type="button">Confirmar</button>
<button class="w-full h-[46px] rounded-full border border-outline text-on-surface font-label-bold text-label-bold" id="confNao" type="button">Cancelar</button>
</div>
</div>

<?php admin_nav('funcionarios'); ?>
<?php app_mobile_footer($csrf); ?>
<script>
(() => {
    'use strict';
    const $ = (sel) => document.querySelector(sel);
    const { toast, post, vibrar } = window.PontoApp;

    let editandoId = 0;
    let idPin = 0;
    let confAcao = null;
    let recarregarAoFechar = false;
    let ocupado = false;

    /* ------------------------------- busca ------------------------------- */
    $('#busca').addEventListener('input', (evento) => {
        const termo = evento.target.value.trim().toLowerCase();
        let visiveis = 0;
        document.querySelectorAll('#lista [data-busca]').forEach((cartao) => {
            const mostra = !termo || cartao.dataset.busca.indexOf(termo) !== -1;
            cartao.style.display = mostra ? '' : 'none';
            if (mostra) visiveis++;
        });
        $('#contador').textContent = visiveis;
        $('#vazio').classList.toggle('hidden', visiveis > 0);
    });

    /* ---------------------------- folha do form ---------------------------- */
    function abrirForm(id) {
        editandoId = id || 0;
        const cartao = id ? document.querySelector(`[data-id="${id}"]`) : null;

        $('#formTitulo').textContent = cartao ? 'Editar funcionário' : 'Novo funcionário';
        $('#fNome').value = cartao ? cartao.dataset.nome : '';
        $('#fTelefone').value = cartao ? cartao.dataset.telefone : '';
        /* Sem "?." aqui de propósito: navegador de celular antigo não entende e
         * a página inteira para de rodar (fica só a barra de navegação). */
        const primeiroCond = $('#fCondominio').options[0];
        $('#fCondominio').value = cartao ? cartao.dataset.condominio : (primeiroCond ? primeiroCond.value : '');
        $('#fCargo').value = cartao ? (cartao.dataset.cargo || '0') : '0';
        $('#fPin').value = '';
        /* O PIN atual nunca aparece (é hash). Mas na edição dá para definir um
         * novo; em branco mantém o que está lá. */
        $('#fPinLabel').textContent = cartao ? 'Trocar o PIN (opcional)' : 'PIN de 4 números';
        $('#fPinAviso').classList.toggle('hidden', !cartao);
        $('#blocoPin').classList.remove('hidden');

        $('#formFundo').classList.add('aberto');
        $('#formFolha').classList.add('aberto');
    }

    function fecharForm() {
        $('#formFundo').classList.remove('aberto');
        $('#formFolha').classList.remove('aberto');
    }

    $('#btnNovo').addEventListener('click', () => abrirForm(0));
    $('#formCancelar').addEventListener('click', fecharForm);
    $('#formFundo').addEventListener('click', fecharForm);

    /* Sorteio com o gerador seguro do navegador (Math.random é previsível). */
    function pinAleatorio() {
        const n = new Uint32Array(1);
        window.crypto.getRandomValues(n);
        return String(n[0] % 10000).padStart(4, '0');
    }

    $('#btnSortear').addEventListener('click', () => {
        $('#fPin').value = pinAleatorio();
    });

    $('#formSalvar').addEventListener('click', async () => {
        if (ocupado) return;
        ocupado = true;
        $('#formSalvar').disabled = true;
        try {
            const dados = {
                acao: editandoId ? 'editar' : 'criar',
                id: editandoId,
                nome: $('#fNome').value.trim(),
                condominio_id: $('#fCondominio').value,
                cargo_id: $('#fCargo').value,
                telefone: $('#fTelefone').value.trim()
            };
            dados.pin = $('#fPin').value.trim();

            const resposta = await post(dados);
            toast(resposta.mensagem);
            vibrar(40);
            fecharForm();

            if (resposta.pin) {
                setTimeout(() => mostrarPinGerado(resposta.pin, dados.nome), 250);
                return;
            }
            setTimeout(() => window.location.reload(), 700);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
            $('#formSalvar').disabled = false;
        }
    });

    /* --------------------------- folha de confirmar --------------------------- */
    function abrirConf(titulo, texto, icone, acao) {
        $('#confTitulo').textContent = titulo;
        $('#confTexto').textContent = texto;
        $('#confIcone').textContent = icone;
        confAcao = acao;
        $('#confFundo').classList.add('aberto');
        $('#confFolha').classList.add('aberto');
    }

    function fecharConf() {
        $('#confFundo').classList.remove('aberto');
        $('#confFolha').classList.remove('aberto');
        confAcao = null;
        $('#confNao').textContent = 'Cancelar';
        if (recarregarAoFechar) {
            recarregarAoFechar = false;
            setTimeout(() => window.location.reload(), 200);
        }
    }

    $('#confNao').addEventListener('click', fecharConf);
    $('#confFundo').addEventListener('click', fecharConf);
    $('#confSim').addEventListener('click', async () => {
        const acao = confAcao;
        fecharConf();
        if (acao) await acao();
    });

    /* O PIN só pode ser lido UMA vez: fica na tela até o admin confirmar, e a
     * página só recarrega depois disso. */
    function mostrarPinGerado(pin, nome) {
        $('#confTitulo').textContent = 'PIN de ' + (nome || 'funcionário');
        $('#confIcone').textContent = 'key';
        $('#confTexto').innerHTML = 'Anote e entregue agora: <b style="font-size:26px;letter-spacing:.2em">'
            + pin + '</b><br><br>Ele não aparece de novo — o sistema guarda só a versão criptografada.';
        $('#confSim').classList.add('hidden');
        $('#confNao').textContent = 'Entendi, anotei';
        confAcao = null;
        recarregarAoFechar = true;
        $('#confFundo').classList.add('aberto');
        $('#confFolha').classList.add('aberto');
    }

    async function enviar(dados, recarregar) {
        if (ocupado) return;
        ocupado = true;
        try {
            const resposta = await post(dados);
            toast(resposta.mensagem);
            vibrar(40);
            if (resposta.pin) {
                setTimeout(() => mostrarPinGerado(resposta.pin, dados.nome), 250);
                return;
            }
            if (recarregar) setTimeout(() => window.location.reload(), 700);
        } catch (erro) {
            toast(erro.message, 'error');
        } finally {
            ocupado = false;
        }
    }

    /* ---------------------------- folha do PIN ---------------------------- */
    function fecharPin() {
        $('#pinFundo').classList.remove('aberto');
        $('#pinFolha').classList.remove('aberto');
        idPin = 0;
    }

    $('#pinCancelar').addEventListener('click', fecharPin);
    $('#pinFundo').addEventListener('click', fecharPin);
    $('#pinSortear').addEventListener('click', () => {
        $('#pinNovo').value = pinAleatorio();
    });
    $('#pinNovo').addEventListener('input', (evento) => {
        evento.target.value = evento.target.value.replace(/\D/g, '').slice(0, 4);
    });
    $('#pinSalvar').addEventListener('click', async () => {
        const novo = $('#pinNovo').value.trim();
        if (novo !== '' && !/^\d{4}$/.test(novo)) {
            toast('O PIN precisa ter 4 números.', 'error');
            return;
        }
        const id = idPin;
        const cartaoPin = document.querySelector(`[data-id="${id}"]`);
        fecharPin();
        /* O nome vai junto só para a tela mostrar "PIN de Fulano" (antes saía
           "PIN de funcionário"); o servidor ignora este campo. */
        await enviar({ acao: 'trocar_pin', id, pin: novo, nome: cartaoPin ? cartaoPin.dataset.nome : '' }, false);
    });

    document.addEventListener('click', (evento) => {
        const editar = evento.target.closest('[data-editar]');
        if (editar) { abrirForm(editar.dataset.editar); return; }

        const pin = evento.target.closest('[data-pin]');
        if (pin) {
            const cartao = document.querySelector(`[data-id="${pin.dataset.pin}"]`);
            idPin = pin.dataset.pin;
            $('#pinTitulo').textContent = 'PIN de ' + cartao.dataset.nome;
            $('#pinTexto').textContent = 'Digite o PIN que você quer para essa pessoa, ou toque em Sortear.';
            $('#pinNovo').value = '';
            $('#pinFundo').classList.add('aberto');
            $('#pinFolha').classList.add('aberto');
            setTimeout(() => $('#pinNovo').focus(), 300);
            return;
        }

        const status = evento.target.closest('[data-status]');
        if (status) {
            const cartao = document.querySelector(`[data-id="${status.dataset.status}"]`);
            const ativo = cartao.dataset.ativo === '1';
            $('#confSim').classList.remove('hidden');
            abrirConf(ativo ? 'Desativar' : 'Reativar',
                (ativo ? 'Desativar ' : 'Reativar ') + cartao.dataset.nome
                + (ativo ? '. Ele para de conseguir bater ponto, mas o histórico fica guardado.' : '. Ele volta a bater ponto normalmente.'),
                ativo ? 'person_off' : 'person_check',
                () => enviar({ acao: 'status', id: status.dataset.status }, true));
            return;
        }

        const excluir = evento.target.closest('[data-excluir]');
        if (excluir) {
            const cartao = document.querySelector(`[data-id="${excluir.dataset.excluir}"]`);
            $('#confSim').classList.remove('hidden');
            abrirConf('Excluir de vez', 'Apagar ' + cartao.dataset.nome
                + ' do sistema. Só funciona se ele nunca bateu ponto; se já bateu, use Desativar.',
                'delete_forever',
                () => enviar({ acao: 'excluir', id: excluir.dataset.excluir }, true));
        }
    });
})();
</script>
<?php app_mobile_end(); ?>
