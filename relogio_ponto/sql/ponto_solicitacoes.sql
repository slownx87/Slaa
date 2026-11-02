-- Tabelas do app do funcionário.
--
-- Todas são criadas sozinhas na primeira vez que a tela precisa delas
-- (ensure_solicitacoes_table / ensure_advertencias_table / ensure_pin_tentativas_table
-- no _config.php). Este arquivo serve como referência para montar as telas do
-- administrador.


-- ============================================================================
-- 1) Pedidos de correção de batida feitos pelo funcionário
-- ============================================================================
--
-- Como chega aqui: no histórico o funcionário toca no dia, abre o modal com
-- todas as batidas, escolhe qual horário está errado (ou avisa que faltou
-- bater), informa a hora certa e o motivo.
--
--   tipo_solicitacao = 'alterar' -> registro_id aponta para a batida existente
--   tipo_solicitacao = 'criar'   -> registro_id é NULL e tipo_registro diz qual
--                                   batida deve ser incluída
--
-- Como o admin responde: NÃO altere ponto_registros na mão. Chame
--
--     ponto_solicitacao_aprovar($id, admin_id(), 'resposta opcional');
--     ponto_solicitacao_recusar($id, admin_id(), 'motivo da recusa');
--
-- A função de aprovar já faz tudo dentro de uma transação: corrige ou cria a
-- batida, grava o histórico em ponto_ajustes, refaz o resumo do dia
-- (recalc_resumo) e marca o pedido como aprovado.

CREATE TABLE IF NOT EXISTS ponto_solicitacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    funcionario_id INT NOT NULL,
    condominio_id INT NOT NULL,
    registro_id INT NULL COMMENT 'batida a corrigir; NULL quando é inclusão',
    tipo_solicitacao VARCHAR(10) NOT NULL DEFAULT 'alterar' COMMENT 'alterar | criar',
    data_ponto DATE NULL COMMENT 'dia da jornada que o funcionário quer corrigir',
    data_hora_solicitada DATETIME NULL COMMENT 'horário que ele diz ser o correto',
    data_hora_atual DATETIME NULL COMMENT 'o que está gravado hoje, para o admin comparar',
    tipo_registro VARCHAR(30) NULL COMMENT 'entrada, saida, saida_almoco...',
    motivo TEXT NOT NULL,
    -- Colunas de foto mantidas por compatibilidade; hoje ficam nulas.
    foto_path VARCHAR(255) NULL,
    foto_mime VARCHAR(60) NULL,
    foto_tamanho INT NULL,
    status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
    admin_id INT NULL,
    resposta_admin TEXT NULL,
    criado_em DATETIME NOT NULL,
    respondido_em DATETIME NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    KEY idx_sol_funcionario (funcionario_id),
    KEY idx_sol_condominio (condominio_id),
    KEY idx_sol_status (status),
    KEY idx_sol_data (data_ponto),
    KEY idx_sol_registro (registro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se a tabela foi criada numa versão anterior, o sistema acrescenta sozinho as
-- colunas abaixo. Rode na mão só se preferir:
-- ALTER TABLE ponto_solicitacoes ADD COLUMN registro_id INT NULL AFTER condominio_id;
-- ALTER TABLE ponto_solicitacoes ADD COLUMN tipo_solicitacao VARCHAR(10) NOT NULL DEFAULT 'alterar' AFTER registro_id;
-- ALTER TABLE ponto_solicitacoes ADD COLUMN data_hora_atual DATETIME NULL AFTER data_hora_solicitada;

-- Consulta pronta para a fila do admin:
-- SELECT s.*, f.nome AS funcionario, c.nome AS condominio
--   FROM ponto_solicitacoes s
--   JOIN ponto_funcionarios f ON f.id = s.funcionario_id
--   JOIN ponto_condominios  c ON c.id = s.condominio_id
--  WHERE s.status = 'pendente'
--  ORDER BY s.criado_em ASC;


-- ============================================================================
-- 2) Advertências
-- ============================================================================
--
-- Quem cadastra é o administrador. O funcionário só vê a contagem e a lista no
-- histórico do mês (card "Advert.").
--
--   tipo: verbal | escrita | suspensao

CREATE TABLE IF NOT EXISTS ponto_advertencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    funcionario_id INT NOT NULL,
    condominio_id INT NOT NULL,
    data_advertencia DATE NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'escrita',
    motivo TEXT NOT NULL,
    admin_id INT NULL,
    criado_em DATETIME NOT NULL,
    KEY idx_adv_funcionario (funcionario_id),
    KEY idx_adv_condominio (condominio_id),
    KEY idx_adv_data (data_advertencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3) Índice que deixa a conferência do PIN rápida
-- ============================================================================
--
-- Sem ele o sistema roda password_verify (bcrypt) em todo funcionário ativo a
-- cada tentativa, e a tela do PIN demora vários segundos. Rode uma vez:

CREATE INDEX idx_func_pin_fingerprint ON ponto_funcionarios (pin_fingerprint);


-- ============================================================================
-- 4) Controle de tentativas de PIN por IP (trava contra força bruta)
-- ============================================================================
--
-- Para destravar alguém na mão:
-- DELETE FROM ponto_pin_tentativas WHERE ip = '000.000.000.000';

CREATE TABLE IF NOT EXISTS ponto_pin_tentativas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    tentativas INT NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    atualizado_em DATETIME NOT NULL,
    UNIQUE KEY uq_ponto_pin_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
