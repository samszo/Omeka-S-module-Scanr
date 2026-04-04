--
-- Table principale des personnes importées depuis le dump JSONL scanR
-- À exécuter UNE SEULE FOIS avant de lancer le job ImportJsonlToSql
--
/*
CREATE TABLE IF NOT EXISTS `scanr_person` (
  `id`          VARCHAR(255)  NOT NULL                COMMENT 'Identifiant scanR (ex: idref/123456)',
  `firstName`   VARCHAR(255)  DEFAULT NULL,
  `lastName`    VARCHAR(255)  DEFAULT NULL,
  `fullName`    VARCHAR(512)  DEFAULT NULL,
  `data`        LONGTEXT      DEFAULT NULL            COMMENT 'JSON brut de l\'enregistrement complet',
  `imported_at` TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Index classiques pour tris / equality
  KEY `idx_lastName`  (`lastName`(60)),
  KEY `idx_firstName` (`firstName`(60)),
  KEY `idx_fullName`  (`fullName`(100)),
  -- Index FULLTEXT pour la recherche rapide (BOOLEAN MODE)
  FULLTEXT KEY `ft_person_search` (`firstName`, `lastName`, `fullName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dump scanR importé depuis persons_denormalized.jsonl.gz';
*/

CREATE TABLE IF NOT EXISTS `scanr_person` (
  `id`          VARCHAR(255)  NOT NULL                COMMENT 'Identifiant scanR (ex: idref/123456)',
  `fullName`    VARCHAR(512)  DEFAULT NULL,
  `data`        LONGTEXT      DEFAULT NULL            COMMENT 'JSON brut de l\'enregistrement complet',
  `imported_at` TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Index classiques pour tris / equality
  KEY `idx_fullName`  (`fullName`(100)),
  -- Index FULLTEXT pour la recherche rapide (BOOLEAN MODE)
  FULLTEXT KEY `ft_person_search` (`fullName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dump scanR importé depuis persons_denormalized.jsonl.gz';
