-- VN System - portal Wi-Fi
-- Rode no phpMyAdmin (hPanel > Bancos de dados > phpMyAdmin > aba SQL)

CREATE TABLE IF NOT EXISTS cadastros (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  criado_em  DATETIME     NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  cpf        VARCHAR(11)      NULL,
  bloco      VARCHAR(20)  NOT NULL,
  ap         VARCHAR(10)  NOT NULL,
  fone       VARCHAR(11)      NULL,
  local_ap   VARCHAR(80)      NULL,
  confere    VARCHAR(160)     NULL,
  mac        VARCHAR(20)      NULL,
  ip         VARCHAR(45)      NULL,
  servidor   VARCHAR(60)      NULL,
  roteador   VARCHAR(60)      NULL,
  origem_ip  VARCHAR(45)      NULL,
  PRIMARY KEY (id),
  KEY idx_criado (criado_em),
  KEY idx_cpf (cpf),
  KEY idx_bloco (bloco, ap),
  KEY idx_mac (mac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
