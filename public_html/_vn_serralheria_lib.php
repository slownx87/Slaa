<?php
/* =========================================================================
   VN System — Serralheria por medida (tabela de preços + conta do orçamento)

   Serralheiro não cobra por "peça": cobra pela MEDIDA. Portão e grade de janela por metro quadrado
   (largura × altura), corrimão, guarda-corpo e gradil por metro linear, escada por degrau, estrutura
   metálica por quilo, serviço avulso por hora ou por unidade. Esta biblioteca guarda a tabela de
   preços; a tela de preços é serralheria-precos.php e a calculadora fica dentro do orçamento
   (assets/js/vn-serr-medida.js, que recebe os dados daqui por vn_serr_para_js()).

   A conta da calculadora:
       preço da estrutura  = preço por medida × (1 + nível % / 100)        nível: Leve, Médio, Reforçado
       mão de obra         = mão de obra por medida × (1 + perfil % / 100)  perfil: Padrão, Urgente, Obra grande, Cliente VIP
       preço por medida    = preço da estrutura + mão de obra     (arredondado em centavos)
       total da linha      = medida × preço por medida

   Tabelas: vn_serr_estruturas (o que se vende e por qual medida) e vn_serr_config (mão de obra por
   medida, % dos níveis e dos perfis, e quando a tabela foi conferida). Criadas sozinhas.
   ========================================================================= */

/** Medidas em que se cobra: chave => [rótulo curto, "cobrar por", plural]. */
function vn_serr_unidades(): array {
    return [
        'm2'     => ['m²',     'Metro quadrado (m²)',  'm²'],
        'm'      => ['m',      'Metro linear (m)',     'm'],
        'un'     => ['un',     'Unidade',              'un'],
        'h'      => ['h',      'Hora (h)',             'h'],
        'degrau' => ['degrau', 'Degrau',               'degraus'],
        'kg'     => ['kg',     'Quilo (kg)',           'kg'],
    ];
}
/** "por m²", "por metro linear"... (texto que acompanha o preço na tabela). */
function vn_serr_unidade_por($u): string {
    static $por = ['m2' => 'por m²', 'm' => 'por metro linear', 'un' => 'por unidade', 'h' => 'por hora', 'degrau' => 'por degrau', 'kg' => 'por kg'];
    return $por[vn_serr_unidade_valida($u)] ?? '';
}
/** Chave válida da medida ('' quando não é nenhuma conhecida). */
function vn_serr_unidade_valida($u): string {
    $u = strtolower(trim((string)$u));
    return isset(vn_serr_unidades()[$u]) ? $u : '';
}
/** Rótulo da medida junto da quantidade: 1 degrau, 12 degraus, 6,3 m². */
function vn_serr_unidade_rotulo($u, float $qtd = 1.0): string {
    $d = vn_serr_unidades()[vn_serr_unidade_valida($u)] ?? null;
    if (!$d) return '';
    return abs($qtd - 1.0) < 0.0001 ? $d[0] : $d[2];
}

/** Número digitado ("1.234,56", "-5", "12,5%"): aceita vírgula e sinal. */
function vn_serr_num($v): float {
    $s = preg_replace('/[^0-9,.\-]/', '', (string)$v);
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    return (float)$s;
}

/* ---------------------------------------------------------------- tabelas e dados iniciais */

/** Estruturas que já vêm na tabela (nome, categoria, medida, preço). Preço 0 = a pessoa define. */
function vn_serr_sementes(): array {
    $ess = 'Estruturas essenciais';
    $out = 'A definir (sem preço ainda)';
    return [
        ['Portão de Ferro Simples',      $ess, 'm2', 480.0],
        ['Portão com Grade Ornamental',  $ess, 'm2', 620.0],
        ['Portão Deslizante',            $ess, 'm2', 780.0],
        ['Portão Basculante',            $ess, 'm2', 720.0],
        ['Portão Pivotante',             $ess, 'm2', 850.0],
        ['Portão Sanfonado',             $ess, 'm2', 700.0],
        ['Grade de Janela',              $ess, 'm2', 340.0],
        ['Guarda-corpo',                 $out, 'm',  0.0],
        ['Corrimão',                     $out, 'm',  0.0],
        ['Gradil / cerca',               $out, 'm',  0.0],
        ['Escada metálica',              $out, 'degrau', 0.0],
        ['Estrutura / cobertura metálica', $out, 'kg', 0.0],
        ['Serviço por hora',             $out, 'h',  0.0],
    ];
}

function vn_serr_ensure_tables(): void {
    static $ok = false;
    if ($ok) return;
    vn_exec("CREATE TABLE IF NOT EXISTS vn_serr_estruturas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(160) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT '',
        unidade VARCHAR(12) NOT NULL DEFAULT 'm2',
        preco DECIMAL(12,2) NOT NULL DEFAULT 0,
        mao_obra DECIMAL(12,2) NULL DEFAULT NULL,
        ordem INT NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    vn_exec("CREATE TABLE IF NOT EXISTS vn_serr_config (
        chave VARCHAR(60) NOT NULL PRIMARY KEY,
        valor VARCHAR(255) NULL,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Dados iniciais: uma vez só (quem apagar uma estrutura não a vê voltar). O marcador é gravado ANTES: se dois acessos
    // chegarem juntos na primeira vez, só um deles semeia a tabela (INSERT IGNORE devolve 0 linhas para o segundo).
    if (vn_exec("INSERT IGNORE INTO vn_serr_config (chave, valor, atualizado_em) VALUES ('semente', '1', ?)", [vn_now_sp()]) > 0) {
        try {
            foreach (vn_serr_sementes() as $i => $s) {
                vn_exec('INSERT INTO vn_serr_estruturas (nome, categoria, unidade, preco, ordem, atualizado_em) VALUES (?, ?, ?, ?, ?, ?)', [$s[0], $s[1], $s[2], $s[3], $i + 1, vn_now_sp()]);
            }
            // mão de obra por medida começa zerada (cada serralheiro cobra a sua); níveis e perfis começam com ajustes de exemplo
            $iniciais = ['mo_m2' => 0, 'mo_m' => 0, 'mo_h' => 0, 'mo_degrau' => 0, 'mo_un' => 0, 'mo_kg' => 0,
                         'nivel_leve' => -10, 'nivel_medio' => 0, 'nivel_reforcado' => 20,
                         'perfil_urgente' => 25, 'perfil_obra_grande' => -10, 'perfil_vip' => -5];
            foreach ($iniciais as $k => $v) {
                vn_exec('INSERT IGNORE INTO vn_serr_config (chave, valor, atualizado_em) VALUES (?, ?, ?)', [$k, (string)$v, vn_now_sp()]);
            }
            vn_exec("REPLACE INTO vn_serr_config (chave, valor, atualizado_em) VALUES ('revisado', '0', ?)", [vn_now_sp()]);
        } catch (Throwable $e) {
            // deu erro no meio: limpa o que entrou e solta o marcador para a próxima abertura tentar de novo
            try { vn_exec('DELETE FROM vn_serr_estruturas'); vn_exec("DELETE FROM vn_serr_config WHERE chave IN ('semente', 'revisado')"); } catch (Throwable $e2) {}
            throw $e;
        }
    }
    $ok = true;
}

/* ---------------------------------------------------------------- configuração (mão de obra, níveis, perfis) */

/** Chaves editáveis: tipo 'reais' (R$ por medida, não negativo) ou 'pct' (porcentagem, pode ser negativa). */
function vn_serr_config_chaves(): array {
    return [
        'mo_m2' => 'reais', 'mo_m' => 'reais', 'mo_h' => 'reais', 'mo_degrau' => 'reais', 'mo_un' => 'reais', 'mo_kg' => 'reais',
        'nivel_leve' => 'pct', 'nivel_medio' => 'pct', 'nivel_reforcado' => 'pct',
        'perfil_urgente' => 'pct', 'perfil_obra_grande' => 'pct', 'perfil_vip' => 'pct',
    ];
}

/** Valores atuais (números) + 'revisado' (bool) e 'atualizado_em' (data/hora ou null). */
function vn_serr_config(): array {
    vn_serr_ensure_tables();
    $c = array_fill_keys(array_keys(vn_serr_config_chaves()), 0.0);
    $rev = false; $quando = null;
    foreach (vn_fetch_all('SELECT chave, valor, atualizado_em FROM vn_serr_config') as $r) {
        $k = (string)$r['chave'];
        if (isset($c[$k])) $c[$k] = (float)$r['valor'];
        elseif ($k === 'revisado') $rev = (string)$r['valor'] === '1';
        elseif ($k === 'atualizado') $quando = (string)$r['valor'];
    }
    $c['revisado'] = $rev;
    $c['atualizado_em'] = $quando;
    return $c;
}

/** Anota que a tabela foi mexida agora (qualquer edição de preço): reinicia a conta dos "dias sem conferir". */
function vn_serr_marcar_atualizado(): void {
    vn_exec("REPLACE INTO vn_serr_config (chave, valor, atualizado_em) VALUES ('atualizado', ?, ?)", [vn_now_sp(), vn_now_sp()]);
}

/** "Conferi os preços": a tabela deixa de ser de exemplo (some o aviso na tela de preços e na calculadora). Só este botão faz isso: editar um preço sozinho não basta. */
function vn_serr_marcar_conferido(): void {
    vn_exec("REPLACE INTO vn_serr_config (chave, valor, atualizado_em) VALUES ('revisado', '1', ?)", [vn_now_sp()]);
    vn_serr_marcar_atualizado();
}

function vn_serr_config_salvar(string $chave, $valor): void {
    vn_serr_ensure_tables();
    $tipos = vn_serr_config_chaves();
    if (!isset($tipos[$chave])) throw new InvalidArgumentException('Campo desconhecido.');
    $v = vn_serr_num($valor);
    if ($tipos[$chave] === 'pct') {
        if ($v < -100 || $v > 1000) throw new InvalidArgumentException('Use uma porcentagem entre -100 e 1000.');
    } elseif ($v < 0) {
        throw new InvalidArgumentException('O valor não pode ser negativo.');
    }
    vn_exec('REPLACE INTO vn_serr_config (chave, valor, atualizado_em) VALUES (?, ?, ?)', [$chave, (string)round($v, 2), vn_now_sp()]);
    vn_serr_marcar_atualizado();
}

/* ---------------------------------------------------------------- estruturas */

function vn_serr_estruturas(bool $soAtivas = true): array {
    vn_serr_ensure_tables();
    return vn_fetch_all('SELECT * FROM vn_serr_estruturas' . ($soAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY categoria ASC, ordem ASC, nome ASC');
}

/** Estruturas agrupadas pela categoria (na ordem em que a primeira de cada categoria aparece): ['Categoria' => [linhas...]]. */
function vn_serr_agrupadas(bool $soAtivas = true): array {
    $out = [];
    foreach (vn_serr_estruturas($soAtivas) as $e) {
        $c = trim((string)$e['categoria']);
        $out[$c !== '' ? $c : 'Outras'][] = $e;
    }
    return $out;
}

/** Cria ou atualiza uma estrutura. $d: nome, categoria, unidade, preco, mao_obra ('' = usa a mão de obra geral). Devolve o id. */
function vn_serr_estrutura_salvar(int $id, array $d): int {
    vn_serr_ensure_tables();
    $nome = trim(preg_replace('/\s+/u', ' ', (string)($d['nome'] ?? '')));
    if ($nome === '') throw new InvalidArgumentException('Informe o nome da estrutura ou serviço.');
    $nome = function_exists('mb_substr') ? mb_substr($nome, 0, 160) : substr($nome, 0, 160);
    $cat = trim(preg_replace('/\s+/u', ' ', (string)($d['categoria'] ?? '')));
    $cat = function_exists('mb_substr') ? mb_substr($cat, 0, 80) : substr($cat, 0, 80);
    $un = vn_serr_unidade_valida($d['unidade'] ?? '');
    if ($un === '') throw new InvalidArgumentException('Escolha por qual medida se cobra (m², metro, degrau...).');
    $preco = vn_serr_num($d['preco'] ?? 0);
    if ($preco < 0) throw new InvalidArgumentException('O preço não pode ser negativo.');
    $moTxt = trim((string)($d['mao_obra'] ?? ''));
    $mo = $moTxt === '' ? null : vn_serr_num($moTxt);
    if ($mo !== null && $mo < 0) throw new InvalidArgumentException('A mão de obra não pode ser negativa.');
    if ($id > 0) {
        if (!vn_fetch_one('SELECT id FROM vn_serr_estruturas WHERE id = ? LIMIT 1', [$id])) throw new InvalidArgumentException('Estrutura não encontrada.');
        vn_exec('UPDATE vn_serr_estruturas SET nome = ?, categoria = ?, unidade = ?, preco = ?, mao_obra = ?, atualizado_em = ? WHERE id = ?', [$nome, $cat, $un, round($preco, 2), $mo === null ? null : round($mo, 2), vn_now_sp(), $id]);
    } else {
        $ordem = (int)((vn_fetch_one('SELECT MAX(ordem) AS m FROM vn_serr_estruturas')['m'] ?? 0)) + 1;
        vn_exec('INSERT INTO vn_serr_estruturas (nome, categoria, unidade, preco, mao_obra, ordem, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?)', [$nome, $cat, $un, round($preco, 2), $mo === null ? null : round($mo, 2), $ordem, vn_now_sp()]);
        $id = (int)vn_last_id();
    }
    vn_serr_marcar_atualizado();
    return $id;
}

function vn_serr_estrutura_excluir(int $id): void {
    vn_serr_ensure_tables();
    vn_exec('DELETE FROM vn_serr_estruturas WHERE id = ? LIMIT 1', [$id]);
}

/** Reajusta os preços das estruturas (e, se pedido, a mão de obra geral) em $pct %. O aço sobe e desce: um toque atualiza tudo. */
function vn_serr_reajustar(float $pct, bool $incluirMao): int {
    vn_serr_ensure_tables();
    if ($pct < -90 || $pct > 500 || abs($pct) < 0.0001) throw new InvalidArgumentException('Informe uma porcentagem diferente de zero (entre -90 e 500).');
    $f = 1 + $pct / 100;
    vn_exec('UPDATE vn_serr_estruturas SET preco = ROUND(preco * ?, 2), mao_obra = CASE WHEN mao_obra IS NULL THEN NULL ELSE ROUND(mao_obra * ?, 2) END, atualizado_em = ?', [$f, $incluirMao ? $f : 1, vn_now_sp()]);
    $n = (int)(vn_fetch_one('SELECT COUNT(*) AS n FROM vn_serr_estruturas')['n'] ?? 0);
    if ($incluirMao) {
        foreach (['mo_m2', 'mo_m', 'mo_h', 'mo_degrau', 'mo_un', 'mo_kg'] as $k) {
            vn_exec('UPDATE vn_serr_config SET valor = ROUND(CAST(valor AS DECIMAL(12,2)) * ?, 2), atualizado_em = ? WHERE chave = ?', [$f, vn_now_sp(), $k]);
        }
    }
    vn_serr_marcar_atualizado();
    return $n;
}

/* ---------------------------------------------------------------- dados para a calculadora */

/** Níveis (Leve/Médio/Reforçado) e perfis de mão de obra, com o % atual. */
function vn_serr_niveis(array $cfg): array {
    return [['k' => 'leve', 'nome' => 'Leve', 'pct' => (float)$cfg['nivel_leve']], ['k' => 'medio', 'nome' => 'Médio', 'pct' => (float)$cfg['nivel_medio']],
            ['k' => 'reforcado', 'nome' => 'Reforçado', 'pct' => (float)$cfg['nivel_reforcado']]];
}
function vn_serr_perfis(array $cfg): array {
    return [['k' => 'padrao', 'nome' => 'Padrão', 'pct' => 0.0], ['k' => 'urgente', 'nome' => 'Urgente', 'pct' => (float)$cfg['perfil_urgente']],
            ['k' => 'obra_grande', 'nome' => 'Obra grande', 'pct' => (float)$cfg['perfil_obra_grande']], ['k' => 'vip', 'nome' => 'Cliente VIP', 'pct' => (float)$cfg['perfil_vip']]];
}

/** Tudo que a calculadora do orçamento precisa, pronto para virar JSON. */
function vn_serr_para_js(): array {
    $cfg = vn_serr_config();
    $est = [];
    foreach (vn_serr_estruturas(true) as $e) {
        $est[] = ['id' => (int)$e['id'], 'nome' => (string)$e['nome'], 'cat' => (string)$e['categoria'], 'un' => (string)$e['unidade'],
                  'preco' => (float)$e['preco'], 'mo' => $e['mao_obra'] === null ? null : (float)$e['mao_obra']];
    }
    $dias = null;
    if (!empty($cfg['atualizado_em'])) { $t = strtotime((string)$cfg['atualizado_em']); if ($t) $dias = (int)floor((time() - $t) / 86400); }
    $un = [];
    foreach (vn_serr_unidades() as $k => $d) $un[$k] = ['curto' => $d[0], 'plural' => $d[2], 'nome' => $d[1]];
    return [
        'estruturas' => $est,
        'mo' => ['m2' => (float)$cfg['mo_m2'], 'm' => (float)$cfg['mo_m'], 'h' => (float)$cfg['mo_h'], 'degrau' => (float)$cfg['mo_degrau'], 'un' => (float)$cfg['mo_un'], 'kg' => (float)$cfg['mo_kg']],
        'niveis' => vn_serr_niveis($cfg), 'perfis' => vn_serr_perfis($cfg),
        'revisado' => (bool)$cfg['revisado'], 'dias' => $dias, 'unidades' => $un,
    ];
}
