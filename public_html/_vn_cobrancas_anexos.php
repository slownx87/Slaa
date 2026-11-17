<?php
/*
    VN System - Anexos (foto ou PDF) das cobranças: o mesmo recurso que o A Pagar já tinha.
    Cada anexo pertence a uma cobrança em parcelas ('cobranca') ou a uma mensalidade de um mês ('mensalidade', pelo id do
    pagamento daquele mês). Serve para guardar comprovante de recebimento, contrato, nota ou o PDF que o cliente mandou.
    Reaproveita da biblioteca do A Pagar a conferência de arquivo (foto de verdade ou PDF de verdade), a redução da foto e a miniatura.
    Os arquivos ficam em uploads/cobrancas_anexos (fechada para acesso direto) e só saem por cobrancas-anexo.php (exige login).
*/
require_once __DIR__ . '/_vn_contas_pagar_lib.php';

function cba_tipos_ref(): array
{
    return ['cobranca' => 'Cobrança em parcelas', 'mensalidade' => 'Mensalidade'];
}

function cba_ensure_tables(): void
{
    static $feito = false;
    if ($feito) return;
    vn_exec("CREATE TABLE IF NOT EXISTS vn_cobranca_anexos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref_tipo VARCHAR(12) NOT NULL,
        ref_id INT NOT NULL,
        caminho VARCHAR(255) NOT NULL,
        nome_original VARCHAR(190) NULL,
        formato VARCHAR(8) NOT NULL DEFAULT 'img',
        tamanho INT NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL,
        INDEX idx_cba_ref (ref_tipo, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

function cba_base(): string
{
    return __DIR__ . '/uploads/cobrancas_anexos';
}

function cba_preparar_base(): void
{
    $base = cba_base();
    if (!is_dir($base) && !@mkdir($base, 0775, true)) throw new CpErro('Não foi possível criar a pasta dos anexos no servidor.');
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Anexos das cobranças: só saem por cobrancas-anexo.php (exige login).\nOptions -Indexes\nRequire all denied\n", LOCK_EX);
    }
}

/* A cobrança (ou o mês da mensalidade) existe? */
function cba_ref_existe(string $tipo, int $id): bool
{
    if ($id <= 0) return false;
    if ($tipo === 'cobranca') return (bool)vn_fetch_one('SELECT id FROM vn_cobrancas WHERE id = ? LIMIT 1', [$id]);
    if ($tipo === 'mensalidade') return (bool)vn_fetch_one('SELECT id FROM vn_mensalidade_pagamentos WHERE id = ? LIMIT 1', [$id]);
    return false;
}

/* "2 fotos e 1 PDF anexados." e, se algum não entrou, o motivo. Devolve [tipo do aviso, texto]. */
function cba_texto(array $r): array
{
    $txt = '';
    if ($r['salvas'] > 0) {
        $partes = [];
        if (($r['fotos'] ?? 0) > 0) $partes[] = $r['fotos'] . ($r['fotos'] === 1 ? ' foto' : ' fotos');
        if (($r['pdfs'] ?? 0) > 0) $partes[] = $r['pdfs'] . ($r['pdfs'] === 1 ? ' PDF' : ' PDFs');
        $soFotos = ($r['pdfs'] ?? 0) === 0;
        $txt = implode(' e ', $partes) . ($r['salvas'] === 1 ? ($soFotos ? ' anexada.' : ' anexado.') : ($soFotos ? ' anexadas.' : ' anexados.'));
    }
    if ($r['falhas']) $txt = trim($txt . ' Não entrou: ' . implode(' ', array_slice($r['falhas'], 0, 3)));
    return [$r['falhas'] ? ($r['salvas'] > 0 ? 'warn' : 'err') : 'ok', $txt];
}

/*
   Guarda os anexos enviados ($files = $_FILES['fotos']): fotos (JPG, PNG, WebP) e PDF, conferindo o CONTEÚDO.
   Devolve ['salvas' => n, 'fotos' => n, 'pdfs' => n, 'falhas' => [mensagens]].
*/
function cba_salvar(string $refTipo, int $refId, array $files): array
{
    require_once __DIR__ . '/_vn_media.php';
    cba_ensure_tables();
    $res = ['salvas' => 0, 'fotos' => 0, 'pdfs' => 0, 'falhas' => []];
    if (!isset(cba_tipos_ref()[$refTipo]) || !cba_ref_existe($refTipo, $refId)) throw new CpErro('Cobrança não encontrada.');
    if (empty($files['name'])) return $res;
    if (!is_array($files['name'])) {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) $files[$k] = [$files[$k] ?? null];
    }

    $existentes = (int)(vn_fetch_one('SELECT COUNT(*) AS q FROM vn_cobranca_anexos WHERE ref_tipo = ? AND ref_id = ?', [$refTipo, $refId])['q'] ?? 0);
    $extPorMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $dir = cba_base() . '/' . $refTipo . '_' . $refId;

    foreach ($files['name'] as $i => $nomeArq) {
        $erro = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) continue;
        $nome = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$nomeArq));
        $rotulo = $nome !== '' ? $nome : 'arquivo';

        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) { $res['falhas'][] = "$rotulo: passou do tamanho que o servidor aceita."; continue; }
        if ($erro !== UPLOAD_ERR_OK) { $res['falhas'][] = "$rotulo: o envio falhou, tente de novo."; continue; }
        if ($existentes + $res['salvas'] >= CP_FOTOS_MAX_POR_CONTA) { $res['falhas'][] = 'Limite de ' . CP_FOTOS_MAX_POR_CONTA . ' anexos por cobrança.'; break; }

        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { $res['falhas'][] = "$rotulo: arquivo inválido."; continue; }
        $tam = (int)($files['size'][$i] ?? 0);
        if ($tam <= 0 || $tam > CP_FOTO_MAX_BYTES) { $res['falhas'][] = "$rotulo: maior que 15 MB."; continue; }

        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
        $ehPdf = $mime === 'application/pdf' || $mime === 'application/x-pdf';
        if ($ehPdf) {
            $ini = (string)@file_get_contents($tmp, false, null, 0, 1024);
            if (strpos($ini, '%PDF-') === false) { $res['falhas'][] = "$rotulo: o PDF está corrompido."; continue; }
            $mime = 'application/pdf';
        } elseif (!isset($extPorMime[$mime]) || !@getimagesize($tmp)) {
            $res['falhas'][] = "$rotulo: só aceita foto (JPG, PNG ou WebP) ou PDF."; continue;
        }

        cba_preparar_base();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $res['falhas'][] = "$rotulo: não foi possível criar a pasta."; continue; }
        $arquivo = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extPorMime[$mime];
        $destino = $dir . '/' . $arquivo;
        if (!@move_uploaded_file($tmp, $destino)) { $res['falhas'][] = "$rotulo: não foi possível salvar."; continue; }
        if (!$ehPdf) {
            vn_image_optimize($destino);
            cp_miniatura_criar($destino, cp_miniatura_caminho($destino));
        }
        vn_exec('INSERT INTO vn_cobranca_anexos (ref_tipo, ref_id, caminho, nome_original, formato, tamanho, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$refTipo, $refId, 'uploads/cobrancas_anexos/' . $refTipo . '_' . $refId . '/' . $arquivo, mb_substr($nome, 0, 190), $ehPdf ? 'pdf' : 'img', (int)@filesize($destino), date('Y-m-d H:i:s')]);
        $res['salvas']++;
        $res[$ehPdf ? 'pdfs' : 'fotos']++;
    }
    return $res;
}

/* Anexos de várias cobranças de uma vez: [ref_id => [anexo, ...]]. */
function cba_listar(string $refTipo, array $ids): array
{
    cba_ensure_tables();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (vn_fetch_all("SELECT id, ref_id, nome_original, formato, tamanho FROM vn_cobranca_anexos WHERE ref_tipo = ? AND ref_id IN ($in) ORDER BY id ASC", array_merge([$refTipo], $ids)) as $a) {
        $out[(int)$a['ref_id']][] = $a;
    }
    return $out;
}

function cba_anexo(int $id): ?array
{
    cba_ensure_tables();
    return $id > 0 ? (vn_fetch_one('SELECT * FROM vn_cobranca_anexos WHERE id = ?', [$id]) ?: null) : null;
}

/* Caminho seguro no disco do anexo (ou da miniatura). null se não existir ou estiver fora da pasta dos anexos. */
function cba_arquivo(array $a, bool $miniatura = false): ?string
{
    $arq = __DIR__ . '/' . ltrim((string)($a['caminho'] ?? ''), '/');
    if ($miniatura) {
        $mini = cp_miniatura_caminho($arq);
        if (is_file($mini)) $arq = $mini;
    }
    $real = realpath($arq);
    $base = realpath(cba_base());
    if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) return null;
    return $real;
}

function cba_apagar_arquivos(array $a): void
{
    $principal = cba_arquivo($a, false);
    if ($principal) {
        @unlink($principal);
        $mini = cp_miniatura_caminho($principal);
        if (is_file($mini)) @unlink($mini);
    }
}

/* Apaga um anexo (arquivo, miniatura e registro). Devolve true se existia. */
function cba_excluir(int $id): bool
{
    $a = cba_anexo($id);
    if (!$a) return false;
    cba_apagar_arquivos($a);
    vn_exec('DELETE FROM vn_cobranca_anexos WHERE id = ?', [$id]);
    return true;
}

/* Apaga todos os anexos de uma cobrança (quando ela é excluída). */
function cba_excluir_de(string $refTipo, int $refId): void
{
    cba_ensure_tables();
    foreach (vn_fetch_all('SELECT * FROM vn_cobranca_anexos WHERE ref_tipo = ? AND ref_id = ?', [$refTipo, $refId]) as $a) cba_apagar_arquivos($a);
    vn_exec('DELETE FROM vn_cobranca_anexos WHERE ref_tipo = ? AND ref_id = ?', [$refTipo, $refId]);
}
