--
-- The Idno MySQL schema as it stood at version 2020120301: every table in utf8
-- (utf8mb3), which can not store 4-byte characters such as emoji. Used by
-- Tests/Kernel/RepositoryDataConciergeTest.php to check the upgrade to utf8mb4. Do not update it.
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
  `search` longtext NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `ss_id_id` FOREIGN KEY (`_id`) REFERENCES `site` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `site_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  KEY `value` (`value`(255)),
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
  `search` longtext NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `cs_id_id` FOREIGN KEY (`_id`) REFERENCES `config` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `config_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  KEY `value` (`value`(255)),
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
  `search` longtext NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `es_id_id` FOREIGN KEY (`_id`) REFERENCES `entities` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `entities_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  KEY `value` (`value`(255)),
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
  `search` longtext NOT NULL,
  PRIMARY KEY (`_id`),
  FULLTEXT KEY `search` (`search`),
  CONSTRAINT `rs_id_id` FOREIGN KEY (`_id`) REFERENCES `reader` (`_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `reader_metadata` (
  `_id` varchar(36) NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  KEY `value` (`value`(255)),
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

REPLACE INTO `versions` VALUES('schema', '2020120301');
