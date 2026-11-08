<?php
/*
    VN System - A Pagar (contas a pagar): dados, regras e fotos.

    Usado por:
      - contas-pagar.php        a tela (lista, nova conta, pagar, fotos)
      - contas-pagar-foto.php   entrega a foto só para quem está logado

    Precisa que _vn_orcamento_bootstrap.php já esteja carregado (vn_exec, vn_fetch_*, vn_money_db...).

    Fotos ficam em uploads/contas_pagar/<id da conta>/ (fora do alcance direto do navegador: a pasta
    recebe um .htaccess "Require all denied" e as imagens só saem por contas-pagar-foto.php).
    Cada foto tem um tipo: 'conta' (boleto, nota, foto da conta) ou 'comprovante' (prova do pagamento).
*/

/* Erro com mensagem escrita para o usuário (aparece como está na tela). */
class CpErro extends Exception {}

const CP_FOTOS_MAX_POR_CONTA = 20;
const CP_FOTO_MAX_BYTES = 15 * 1024 * 1024;

function cp_formas(): array
{
    return [
        'pix' => 'PIX', 'boleto' => 'Boleto', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro',
        'cartao' => 'Cartão', 'debito' => 'Débito automático', 'outro' => 'Outro',
    ];
}

function cp_categorias(): array
{
    return ['Aluguel', 'Água', 'Luz', 'Internet / Telefone', 'Material / Fornecedor', 'Combustível', 'Manutenção',
        'Impostos', 'Funcionários', 'Contador', 'Software / Assinaturas', 'Outros'];
}

function cp_tipos_foto(): array
{
    return ['conta' => 'Conta / boleto', 'comprovante' => 'Comprovante de pagamento'];
}

/* ------------------------------------------------------------------ tabelas */
function cp_ensure_tables(): void
{
    static $feito = false;
    if ($feito) return;
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        descricao VARCHAR(200) NOT NULL,
        fornecedor VARCHAR(180) NULL,
        categoria VARCHAR(60) NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        vencimento DATE NOT NULL,
        codigo VARCHAR(512) NULL,
        observacao TEXT NULL,
        pago TINYINT(1) NOT NULL DEFAULT 0,
        pago_em DATE NULL,
        valor_pago DECIMAL(12,2) NULL,
        forma_pagamento VARCHAR(20) NULL,
        criado_em DATETIME NOT NULL,
        INDEX (pago, vencimento),
        INDEX (pago_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_contas_pagar_fotos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conta_id INT NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'conta',
        caminho VARCHAR(255) NOT NULL,
        nome_original VARCHAR(190) NULL,
        criado_em DATETIME NOT NULL,
        INDEX (conta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/* ------------------------------------------------------------------ situação / textos */
/* paga | atrasada | hoje | semana (vence em até 7 dias) | futura */
function cp_estado(array $c, string $hoje): string
{
    if ((int)($c['pago'] ?? 0) === 1) return 'paga';
    $v = (string)($c['vencimento'] ?? '');
    if ($v === '') return 'futura';
    if ($v < $hoje) return 'atrasada';
    if ($v === $hoje) return 'hoje';
    if ($v <= date('Y-m-d', strtotime($hoje . ' +7 days'))) return 'semana';
    return 'futura';
}

/* "Venceu há 3 dias", "Vence hoje", "Vence amanhã", "Vence em 5 dias", "Vence 15/11". */
function cp_quando(string $venc, string $hoje): string
{
    if ($venc === '') return 'Sem vencimento';
    try {
        $d = (int)(new DateTime($hoje))->diff(new DateTime($venc))->format('%r%a');
    } catch (Throwable $e) {
        return 'Vence ' . vn_date_br($venc);
    }
    if ($d < 0) return 'Venceu há ' . abs($d) . (abs($d) === 1 ? ' dia' : ' dias');
    if ($d === 0) return 'Vence hoje';
    if ($d === 1) return 'Vence amanhã';
    if ($d <= 7) return 'Vence em ' . $d . ' dias';
    return 'Vence ' . substr(vn_date_br($venc), 0, 5);
}

/* ------------------------------------------------------------------ leitura */
/* Totais para os cartões do topo e as contagens das abas. Não depende da busca. */
function cp_resumo(string $hoje): array
{
    $fim7 = date('Y-m-d', strtotime($hoje . ' +7 days'));
    $mesIni = substr($hoje, 0, 8) . '01';
    $mesFim = date('Y-m-t', strtotime($mesIni));
    $r = vn_fetch_one(
        "SELECT
            COALESCE(SUM(CASE WHEN pago = 0 THEN 1 ELSE 0 END), 0) AS q_abertas,
            COALESCE(SUM(CASE WHEN pago = 0 THEN valor ELSE 0 END), 0) AS v_abertas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento < ? THEN 1 ELSE 0 END), 0) AS q_atrasadas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento < ? THEN valor ELSE 0 END), 0) AS v_atrasadas,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento >= ? AND vencimento <= ? THEN 1 ELSE 0 END), 0) AS q_semana,
            COALESCE(SUM(CASE WHEN pago = 0 AND vencimento >= ? AND vencimento <= ? THEN valor ELSE 0 END), 0) AS v_semana,
            COALESCE(SUM(CASE WHEN pago = 1 THEN 1 ELSE 0 END), 0) AS q_pagas,
            COALESCE(SUM(CASE WHEN pago = 1 AND pago_em >= ? AND pago_em <= ? THEN COALESCE(valor_pago, valor) ELSE 0 END), 0) AS v_pago_mes,
            COUNT(*) AS q_todas
         FROM vn_contas_pagar",
        [$hoje, $hoje, $hoje, $fim7, $hoje, $fim7, $mesIni, $mesFim]
    ) ?: [];
    foreach (['q_abertas', 'q_atrasadas', 'q_semana', 'q_pagas', 'q_todas'] as $k) $r[$k] = (int)($r[$k] ?? 0);
    foreach (['v_abertas', 'v_atrasadas', 'v_semana', 'v_pago_mes'] as $k) $r[$k] = (float)($r[$k] ?? 0);
    return $r;
}

/* $ver: abertas | atrasadas | semana | pagas | todas. $q: texto de busca. */
function cp_listar(string $ver, string $q, string $hoje): array
{
    $fim7 = date('Y-m-d', strtotime($hoje . ' +7 days'));
    $where = [];
    $params = [];
    $ordem = 'pago ASC, vencimento ASC, id ASC';
    switch ($ver) {
        case 'atrasadas': $where[] = 'pago = 0 AND vencimento < ?'; $params[] = $hoje; break;
        case 'semana':    $where[] = 'pago = 0 AND vencimento >= ? AND vencimento <= ?'; $params[] = $hoje; $params[] = $fim7; break;
        case 'pagas':     $where[] = 'pago = 1'; $ordem = 'pago_em DESC, id DESC'; break;
        case 'todas':     break;
        default:          $where[] = 'pago = 0'; break;
    }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $where[] = '(descricao LIKE ? OR fornecedor LIKE ? OR categoria LIKE ? OR observacao LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM vn_contas_pagar' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $ordem . ' LIMIT 400';
    return vn_fetch_all($sql, $params);
}

function cp_conta(int $id): ?array
{
    return $id > 0 ? vn_fetch_one('SELECT * FROM vn_contas_pagar WHERE id = ?', [$id]) : null;
}

/* Fotos das contas informadas, agrupadas por conta: [conta_id => [foto, ...]]. */
function cp_fotos_das_contas(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (vn_fetch_all("SELECT id, conta_id, tipo, nome_original FROM vn_contas_pagar_fotos WHERE conta_id IN ($marcas) ORDER BY id", $ids) as $f) {
        $out[(int)$f['conta_id']][] = ['id' => (int)$f['id'], 'tipo' => (string)$f['tipo'], 'nome' => (string)($f['nome_original'] ?? '')];
    }
    return $out;
}

/* ------------------------------------------------------------------ gravação */
/* Lê e valida o formulário de conta. Lança CpErro com a mensagem para o usuário. */
function cp_validar_conta(array $post): array
{
    $descricao = trim(preg_replace('/\s+/', ' ', (string)($post['descricao'] ?? '')));
    if ($descricao === '') throw new CpErro('Informe o que é a conta (ex.: Aluguel, Conta de luz).');
    if (mb_strlen($descricao) > 200) throw new CpErro('A descrição passou de 200 letras.');

    $valor = vn_money_db($post['valor'] ?? '');
    if ($valor <= 0) throw new CpErro('Informe o valor da conta.');
    if ($valor > 9999999.99) throw new CpErro('Valor muito alto. Confira se digitou certo.');

    $venc = vn_date_db_or_null($post['vencimento'] ?? '');
    if (!$venc) throw new CpErro('Informe a data de vencimento.');

    $limpa = static function ($v, int $max): ?string {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    return [
        'descricao'  => $descricao,
        'fornecedor' => $limpa(preg_replace('/\s+/', ' ', (string)($post['fornecedor'] ?? '')), 180),
        'categoria'  => $limpa(preg_replace('/\s+/', ' ', (string)($post['categoria'] ?? '')), 60),
        'valor'      => $valor,
        'vencimento' => $venc,
        'codigo'     => $limpa(preg_replace('/\s+/', '', (string)($post['codigo'] ?? '')), 512),
        'observacao' => $limpa($post['observacao'] ?? '', 2000),
    ];
}

/* Cria (id = 0) ou atualiza a conta. Devolve o id. */
function cp_salvar_conta(int $id, array $d): int
{
    if ($id > 0) {
        if (!cp_conta($id)) throw new CpErro('Conta não encontrada.');
        vn_exec('UPDATE vn_contas_pagar SET descricao = ?, fornecedor = ?, categoria = ?, valor = ?, vencimento = ?, codigo = ?, observacao = ? WHERE id = ?',
            [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['vencimento'], $d['codigo'], $d['observacao'], $id]);
        return $id;
    }
    vn_exec('INSERT INTO vn_contas_pagar (descricao, fornecedor, categoria, valor, vencimento, codigo, observacao, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$d['descricao'], $d['fornecedor'], $d['categoria'], $d['valor'], $d['vencimento'], $d['codigo'], $d['observacao'], date('Y-m-d H:i:s')]);
    return vn_last_id();
}

function cp_marcar_paga(int $id, array $post): void
{
    $c = cp_conta($id);
    if (!$c) throw new CpErro('Conta não encontrada.');
    $data = vn_date_db_or_null($post['pago_em'] ?? '');
    if (!$data) throw new CpErro('Informe a data em que pagou.');
    $valor = vn_money_db($post['valor_pago'] ?? '');
    if ($valor <= 0) $valor = (float)$c['valor'];
    if ($valor > 9999999.99) throw new CpErro('Valor muito alto. Confira se digitou certo.');
    $forma = (string)($post['forma_pagamento'] ?? '');
    if (!isset(cp_formas()[$forma])) $forma = null;
    vn_exec('UPDATE vn_contas_pagar SET pago = 1, pago_em = ?, valor_pago = ?, forma_pagamento = ? WHERE id = ?', [$data, $valor, $forma, $id]);
}

function cp_desfazer_pagamento(int $id): void
{
    if (!cp_conta($id)) throw new CpErro('Conta não encontrada.');
    vn_exec('UPDATE vn_contas_pagar SET pago = 0, pago_em = NULL, valor_pago = NULL, forma_pagamento = NULL WHERE id = ?', [$id]);
}

function cp_excluir_conta(int $id): void
{
    if (!cp_conta($id)) throw new CpErro('Conta não encontrada.');
    foreach (vn_fetch_all('SELECT id FROM vn_contas_pagar_fotos WHERE conta_id = ?', [$id]) as $f) {
        cp_excluir_foto((int)$f['id']);
    }
    vn_exec('DELETE FROM vn_contas_pagar WHERE id = ?', [$id]);
    @rmdir(cp_fotos_dir($id));   // só remove se ficou vazia
}

/* ------------------------------------------------------------------ fotos */
function cp_fotos_base(): string
{
    return __DIR__ . '/uploads/contas_pagar';
}

function cp_fotos_dir(int $contaId): string
{
    return cp_fotos_base() . '/' . $contaId;
}

/* Cria a pasta base com a trava que impede abrir os arquivos direto pelo endereço. */
function cp_fotos_preparar_base(): void
{
    $base = cp_fotos_base();
    if (!is_dir($base) && !@mkdir($base, 0775, true)) throw new CpErro('Não foi possível criar a pasta das fotos no servidor.');
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Fotos de contas a pagar: só saem por contas-pagar-foto.php (exige login).\nOptions -Indexes\nRequire all denied\n", LOCK_EX);
    }
}

/* Caminho no disco da miniatura que acompanha uma foto (th_<nome>.jpg, na mesma pasta). */
function cp_miniatura_caminho(string $arquivo): string
{
    return dirname($arquivo) . '/th_' . pathinfo($arquivo, PATHINFO_FILENAME) . '.jpg';
}

/* Gera a miniatura (lado maior = $max px) respeitando a rotação do celular. Falha em silêncio. */
function cp_miniatura_criar(string $origem, string $destino, int $max = 360): bool
{
    try {
        if (!function_exists('imagecreatetruecolor')) return false;
        $info = @getimagesize($origem);
        if (!$info) return false;
        [$w, $h] = $info;
        $mime = (string)($info['mime'] ?? '');
        if ($w <= 0 || $h <= 0 || ($w * $h) > 40000000) return false;
        @ini_set('memory_limit', '512M');
        $img = false;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($origem);
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($origem);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($origem);
        if (!$img) return false;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($origem);
            $graus = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($graus !== 0 && ($rot = imagerotate($img, $graus, 0))) { imagedestroy($img); $img = $rot; }
        }
        $w = imagesx($img); $h = imagesy($img);
        $esc = min(1.0, $max / max($w, $h));
        $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
        $mini = imagecreatetruecolor($nw, $nh);
        imagefill($mini, 0, 0, imagecolorallocate($mini, 255, 255, 255));   // PNG/WebP com transparência vira fundo branco
        imagecopyresampled($mini, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $ok = imagejpeg($mini, $destino, 78);
        imagedestroy($mini);
        return (bool)$ok;
    } catch (Throwable $e) {
        error_log('VN System [contas a pagar - miniatura]: ' . $e->getMessage());
        return false;
    }
}

/*
   Guarda as fotos enviadas ($files = $_FILES['fotos'], com vários arquivos). Só entram JPG, PNG e WebP
   de verdade (confere o conteúdo, não só o nome). Devolve ['salvas' => n, 'falhas' => [mensagens]].
*/
function cp_salvar_fotos(int $contaId, string $tipo, array $files): array
{
    require_once __DIR__ . '/_vn_media.php';
    if (!isset(cp_tipos_foto()[$tipo])) $tipo = 'conta';
    $res = ['salvas' => 0, 'falhas' => []];
    if (empty($files['name'])) return $res;

    // um arquivo só (name = texto) vira lista de um
    if (!is_array($files['name'])) {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) $files[$k] = [$files[$k] ?? null];
    }

    $existentes = (int)(vn_fetch_one('SELECT COUNT(*) AS q FROM vn_contas_pagar_fotos WHERE conta_id = ?', [$contaId])['q'] ?? 0);
    $extPorMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dir = cp_fotos_dir($contaId);

    foreach ($files['name'] as $i => $nomeArq) {
        $erro = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) continue;
        $nome = preg_replace('/[^a-zA-Z0-9._ -]/', '_', basename((string)$nomeArq));
        $rotulo = $nome !== '' ? $nome : 'foto';

        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) { $res['falhas'][] = "$rotulo: passou do tamanho que o servidor aceita."; continue; }
        if ($erro !== UPLOAD_ERR_OK) { $res['falhas'][] = "$rotulo: o envio falhou, tente de novo."; continue; }
        if ($existentes + $res['salvas'] >= CP_FOTOS_MAX_POR_CONTA) { $res['falhas'][] = 'Limite de ' . CP_FOTOS_MAX_POR_CONTA . ' fotos por conta.'; break; }

        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { $res['falhas'][] = "$rotulo: arquivo inválido."; continue; }
        $tam = (int)($files['size'][$i] ?? 0);
        if ($tam <= 0 || $tam > CP_FOTO_MAX_BYTES) { $res['falhas'][] = "$rotulo: maior que 15 MB."; continue; }

        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
        if (!isset($extPorMime[$mime]) || !@getimagesize($tmp)) { $res['falhas'][] = "$rotulo: só aceita foto (JPG, PNG ou WebP)."; continue; }

        cp_fotos_preparar_base();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $res['falhas'][] = "$rotulo: não foi possível criar a pasta da conta."; continue; }

        $arquivo = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extPorMime[$mime];
        $destino = $dir . '/' . $arquivo;
        if (!@move_uploaded_file($tmp, $destino)) { $res['falhas'][] = "$rotulo: não foi possível salvar."; continue; }
        vn_image_optimize($destino);                         // fotos de celular pesam 3-12 MB
        cp_miniatura_criar($destino, cp_miniatura_caminho($destino));
        vn_exec('INSERT INTO vn_contas_pagar_fotos (conta_id, tipo, caminho, nome_original, criado_em) VALUES (?, ?, ?, ?, ?)',
            [$contaId, $tipo, 'uploads/contas_pagar/' . $contaId . '/' . $arquivo, mb_substr($nome, 0, 190), date('Y-m-d H:i:s')]);
        $res['salvas']++;
    }
    return $res;
}

/* Caminho seguro no disco de uma foto (ou da miniatura). null se não existir ou estiver fora da pasta das fotos. */
function cp_foto_arquivo(array $foto, bool $miniatura = false): ?string
{
    $arq = __DIR__ . '/' . ltrim((string)($foto['caminho'] ?? ''), '/');
    if ($miniatura) {
        $mini = cp_miniatura_caminho($arq);
        if (is_file($mini)) $arq = $mini;
    }
    $real = realpath($arq);
    $base = realpath(cp_fotos_base());
    if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) return null;
    return $real;
}

/* Apaga a foto (arquivo, miniatura e registro). Devolve o id da conta a que ela pertencia (0 se não achou). */
function cp_excluir_foto(int $fotoId): int
{
    $f = vn_fetch_one('SELECT * FROM vn_contas_pagar_fotos WHERE id = ?', [$fotoId]);
    if (!$f) return 0;
    $principal = cp_foto_arquivo($f, false);
    if ($principal) {
        @unlink($principal);
        $mini = cp_miniatura_caminho($principal);
        if (is_file($mini)) @unlink($mini);
    }
    vn_exec('DELETE FROM vn_contas_pagar_fotos WHERE id = ?', [$fotoId]);
    return (int)$f['conta_id'];
}
