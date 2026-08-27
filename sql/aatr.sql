-- =============================================================
-- AATR Transporte & Logística — estrutura do banco
-- -------------------------------------------------------------
-- Como usar na hospedagem:
--   1. cPanel > Bancos de dados MySQL > crie o banco e o usuário
--   2. phpMyAdmin > selecione o banco > aba "Importar"
--   3. envie este arquivo e clique em Executar
--   4. abra https://seudominio.com.br/instalar.php e crie o
--      primeiro acesso do gestor, com a senha que VOCÊ escolher
--
-- Este arquivo cria SÓ a estrutura. Nenhum usuário, nenhuma
-- senha, nenhum hash — nada aqui serve de acesso ao sistema.
-- É de propósito: este arquivo vive num repositório público.
--
-- Usa CREATE TABLE IF NOT EXISTS: rodar de novo por engano não
-- apaga nada do que já estiver gravado.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- Gestores — quem cadastra as viagens
-- O primeiro é criado pelo instalar.php.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gestores` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(160) NOT NULL,
  `senha_hash` VARCHAR(255) NOT NULL,
  `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gestor_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Motoristas — quem opera a viagem no celular
-- Cadastrados pelo gestor em /admin/motoristas.php
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `motoristas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario`    VARCHAR(60)  NOT NULL,
  `senha_hash` VARCHAR(255) NOT NULL,
  `nome`       VARCHAR(120) NOT NULL,
  `veiculo`    VARCHAR(120) NOT NULL DEFAULT '',
  `telefone`   VARCHAR(20)  NOT NULL DEFAULT '',
  `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
  `criado_em`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_motorista_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Viagens — cadastradas SÓ pelo gestor
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `viagens` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`                VARCHAR(30)  NOT NULL,
  `motorista_id`          INT UNSIGNED NULL,

  `contratante_nome`      VARCHAR(140) NOT NULL,
  `contratante_fone`      VARCHAR(20)  NOT NULL DEFAULT '',

  `origem`                VARCHAR(160) NOT NULL,
  `origem_lat`            DECIMAL(10,7) NULL,
  `origem_lon`            DECIMAL(10,7) NULL,
  `destino`               VARCHAR(160) NOT NULL,
  `destino_lat`           DECIMAL(10,7) NULL,
  `destino_lon`           DECIMAL(10,7) NULL,

  `distancia_km`          INT UNSIGNED NULL,
  `duracao_min`           INT UNSIGNED NULL,
  `rota_fonte`            VARCHAR(20)  NOT NULL DEFAULT 'manual',

  `veiculo`               VARCHAR(120) NOT NULL DEFAULT '',
  `carga`                 VARCHAR(255) NOT NULL DEFAULT '',
  `peso_t`                DECIMAL(6,2) NULL,

  `status`                ENUM('agendada','em_viagem','concluida','cancelada') NOT NULL DEFAULT 'agendada',
  `previsao_carregamento` DATETIME NULL,
  `iniciada_em`           DATETIME NULL,
  `concluida_em`          DATETIME NULL,
  `criado_em`             DATETIME NOT NULL,
  `atualizado_em`         DATETIME NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_viagem_codigo` (`codigo`),
  KEY `ix_viagem_motorista` (`motorista_id`, `status`),
  KEY `ix_viagem_status` (`status`, `criado_em`),
  CONSTRAINT `fk_viagem_motorista` FOREIGN KEY (`motorista_id`)
    REFERENCES `motoristas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Eventos — a linha do tempo que o contratante enxerga
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eventos` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `viagem_id`       INT UNSIGNED NOT NULL,
  `tipo`            ENUM('criada','inicio','posicao','chegada','nota') NOT NULL,
  `titulo`          VARCHAR(160) NOT NULL,
  `recado`          VARCHAR(255) NOT NULL DEFAULT '',
  `lat`             DECIMAL(10,7) NULL,
  `lon`             DECIMAL(10,7) NULL,
  `precisao_m`      INT UNSIGNED NULL,
  `restante_km`     INT UNSIGNED NULL,
  `origem_registro` VARCHAR(20) NOT NULL DEFAULT 'motorista',
  `criado_em`       DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_evento_viagem` (`viagem_id`, `criado_em`),
  CONSTRAINT `fk_evento_viagem` FOREIGN KEY (`viagem_id`)
    REFERENCES `viagens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Tentativas de login — trava força bruta
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_tentativas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identificador` VARCHAR(120) NOT NULL,
  `ip`            VARCHAR(45)  NOT NULL,
  `criado_em`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tentativa_ident` (`identificador`, `criado_em`),
  KEY `ix_tentativa_ip` (`ip`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- Pronto. Agora abra /instalar.php no navegador para criar o
-- acesso do gestor. Depois disso, apague o instalar.php do
-- servidor — ele mesmo avisa quando ainda estiver lá.
-- =============================================================
