--
-- Store 4-byte UTF-8 characters (emoji, some CJK) in the search text and in the indexed values.
--
-- Until now these columns were utf8, which MySQL treats as utf8mb3, a character set with no
-- room for a 4-byte character. Only the two columns that hold text written by people are
-- converted. Identifiers, URLs and field names stay as they are, so no foreign key or
-- primary key is touched.
--
-- The collation is utf8mb4_unicode_520_ci because utf8mb4_general_ci and utf8mb4_unicode_ci
-- give every 4-byte character the same weight, which would make any two emoji equal.
--
-- The index on the indexed values is shortened from 255 to 191 characters. 191 characters
-- of utf8mb4 are 764 bytes, which fits the 767 byte limit of the COMPACT and REDUNDANT
-- row formats that tables created on MySQL 5.6 or MariaDB 10.1 and earlier still have.
--
-- Every statement can be applied again to a table it has already converted, where it
-- does nothing, so an upgrade that was interrupted starts again from the top. The
-- entities tables come first because they hold the posts.
--

ALTER TABLE `entities_search` MODIFY `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL;
ALTER TABLE `entities_metadata` DROP KEY `value`, MODIFY `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL, ADD KEY `value` (`value`(191));

ALTER TABLE `reader_search` MODIFY `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL;
ALTER TABLE `reader_metadata` DROP KEY `value`, MODIFY `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL, ADD KEY `value` (`value`(191));

ALTER TABLE `config_search` MODIFY `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL;
ALTER TABLE `config_metadata` DROP KEY `value`, MODIFY `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL, ADD KEY `value` (`value`(191));

ALTER TABLE `site_search` MODIFY `search` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL;
ALTER TABLE `site_metadata` DROP KEY `value`, MODIFY `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL, ADD KEY `value` (`value`(191));

REPLACE INTO `versions` VALUES('schema', '2026111401');
