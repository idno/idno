--
-- Base Idno schema
--
-- The search text and the indexed values are utf8mb4, so they can hold any Unicode
-- character, emoji included. Everything else is ASCII (identifiers, URLs, class and field
-- names) and stays in utf8 with its shorter index keys. See 2026111401.sql.
--

--
-- Table structure for table `config`
--

CREATE TABLE IF NOT EXISTS `site` (
    `uuid` varchar(255) NOT NULL,
    `_id` varchar(36) NOT NULL,
    `siteid` varchar(36),
    `owner` varchar(255) NOT NULL,
    `entity_subtype` varchar(64) NOT NULL,
    `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `contents` longblob NOT NULL,
    `publish_status` varchar(255) NOT NULL DEFAULT 'published',

    PRIMARY KEY (`uuid`),
    KEY `owner` (`owner`,`created`),
    KEY `_id` (`_id`),
    KEY `entity_subtype` (`entity_subtype`),
    KEY `publish_status` (`publish_status`),
    KEY `siteid` (`siteid`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `site_search` (
  `_id` varchar(36) NOT NULL,
  `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `ss_id_id` FOREIGN KEY (`_id`) REFERENCES `site` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `site_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  KEY `value` (`value`(191)),
  KEY `name` (`name`),
  KEY (`_id`),
  CONSTRAINT `sm_id_id` FOREIGN KEY (`_id`) REFERENCES `site` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `config` (
  `uuid` varchar(255) NOT NULL,
  `_id` varchar(36) NOT NULL,
  `siteid` varchar(36),
  `owner` varchar(255) NOT NULL,
  `entity_subtype` varchar(64) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contents` longblob NOT NULL,
  `publish_status` varchar(255) NOT NULL DEFAULT 'published',
  PRIMARY KEY (`uuid`),
  KEY `owner` (`owner`,`created`),
  KEY `_id` (`_id`),
  KEY `entity_subtype` (`entity_subtype`),
  KEY `publish_status` (`publish_status`),
  KEY `siteid` (`siteid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `config_search` (
  `_id` varchar(36) NOT NULL,
  `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `cs_id_id` FOREIGN KEY (`_id`) REFERENCES `config` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `config_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  KEY `value` (`value`(191)),
  KEY `name` (`name`),
  KEY (`_id`),
  CONSTRAINT `cm_id_id` FOREIGN KEY (`_id`) REFERENCES `config` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `entities`
--

CREATE TABLE IF NOT EXISTS `entities` (
  `uuid` varchar(255) NOT NULL,
  `_id` varchar(36) NOT NULL,
  `siteid` varchar(36),
  `owner` varchar(255) NOT NULL,
  `entity_subtype` varchar(64) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contents` longblob NOT NULL,
  `publish_status` varchar(255) NOT NULL DEFAULT 'published',
  PRIMARY KEY (`uuid`),
  UNIQUE KEY `_id` (`_id`),
  KEY `owner` (`owner`,`created`),
  KEY `entity_subtype` (`entity_subtype`),
  KEY `publish_status` (`publish_status`),
  KEY `siteid` (`siteid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `entities_search` (
  `_id` varchar(36) NOT NULL,
  `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `es_id_id` FOREIGN KEY (`_id`) REFERENCES `entities` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `entities_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  KEY `value` (`value`(191)),
  KEY `name` (`name`),
  KEY (`_id`),
  CONSTRAINT `em_id_id` FOREIGN KEY (`_id`) REFERENCES `entities` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `reader`
--

CREATE TABLE IF NOT EXISTS `reader` (
  `uuid` varchar(255) NOT NULL,
  `_id` varchar(36) NOT NULL,
  `siteid` varchar(36),
  `owner` varchar(255) NOT NULL,
  `entity_subtype` varchar(64) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contents` longblob NOT NULL,
  `publish_status` varchar(255) NOT NULL DEFAULT 'published',
  PRIMARY KEY (`uuid`),
  UNIQUE KEY `_id` (`_id`),
  KEY `owner` (`owner`,`created`),
  KEY `entity_subtype` (`entity_subtype`),
  KEY `publish_status` (`publish_status`),
  KEY `siteid` (`siteid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `reader_search` (
  `_id` varchar(36) NOT NULL,
  `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `rs_id_id` FOREIGN KEY (`_id`) REFERENCES `reader` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `reader_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  KEY `value` (`value`(191)),
  KEY `name` (`name`),
  KEY (`_id`),
  CONSTRAINT `rm_id_id` FOREIGN KEY (`_id`) REFERENCES `reader` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `versions`
--

CREATE TABLE IF NOT EXISTS `versions` (
  `label` varchar(32) NOT NULL,
  `value` varchar(10) NOT NULL,
  PRIMARY KEY (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Session handling table
--

CREATE TABLE IF NOT EXISTS `session` (
    `session_id` varchar(255) NOT NULL,
    `session_value` mediumblob NOT NULL,
    `session_lifetime` int(11) NOT NULL,
    `session_time` int(11) NOT NULL,
    PRIMARY KEY (`session_id`)
) COLLATE utf8_bin, ENGINE=InnoDB DEFAULT CHARSET=utf8;

REPLACE INTO `versions` VALUES('schema', '2026111401');
