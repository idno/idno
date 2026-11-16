--
-- Base Idno schema for SQLite: the tables, columns, keys and indexes of
-- warmup/schemas/mysql/mysql.sql (after its upgrades), for Idno\Storage\DbalRecordStore
-- with Idno\Storage\Platform\SqliteDialect.
--
-- It can only be loaded, and the database only used, on a connection that SqliteDialect has
-- set up (Idno\Storage\Platform\SqliteConnectionSetup): the text columns use the `idno_ci`
-- collation registered there, which compares text folded to upper case as MySQL's
-- utf8mb3_general_ci does. Loading it twice is harmless (IF NOT EXISTS everywhere). Every
-- statement ends with a semicolon at the end of a line and no statement has one inside a line
-- break, so it can be split on ";\n" as the legacy installer splits the MySQL schema.
--
-- SQLite support is, in this release, for tests and development: no site is installed on it.
--
-- LIMITS OF A DATABASE BUILT FROM THIS SCHEMA (because of the `idno_ci` collation):
--  - Outside a connection set up by SqliteConnectionSetup (for example the `sqlite3` command
--    line) every statement that touches a table with an `idno_ci` column fails with "no such
--    collation sequence: idno_ci": any SELECT, INSERT, UPDATE or DELETE on site, config,
--    entities, reader, their _search and _metadata tables, and versions; PRAGMA integrity_check
--    and quick_check; VACUUM. Only the `session` table is usable there. `.dump` writes a dump,
--    but loading it into a plain `sqlite3` fails at the first CREATE TABLE.
--  - What works: copying the database file together with its -wal and -shm files while no
--    writer is active, and the `sqlite3` `.backup` command (a page copy). Checks, VACUUM,
--    REINDEX and restoring a dump work only through a connection set up by
--    SqliteConnectionSetup.
--  - The collation's order comes from PHP's mbstring case tables (mb_strtoupper), which PHP
--    releases change (8.3 and 8.4 did). An index built under one PHP version may be mis-ordered
--    under another for the characters whose case mapping changed, so lookups and unique checks
--    on them can be missed: after a PHP upgrade run PRAGMA integrity_check and REINDEX idno_ci
--    through a set-up connection.
--  - Full Unicode case folding equates strings MySQL keeps apart ('ß' = 'SS', 'ﬁ' = 'FI'), so
--    unique keys can clash on SQLite where they do not on MySQL.
--
-- Differences from MySQL, all deliberate:
--  - no full-text index: search terms are matched by the `idno_search` function.
--  - `created` is text ('Y-m-d H:i:s', compared byte by byte), not a timestamp: the dialect
--    checks and normalises a `created` operand before it reaches SQLite.
--  - index names are global in SQLite, so each is prefixed with its table's name.
--  - lengths are not enforced (SQLite ignores varchar lengths; indexed values are cut to 255
--    bytes before they are stored), and the `value` index is on the whole column.
--  - in `site` and `config`, `_id` is not unique, and SQLite cannot declare a foreign key to a
--    column that is not unique, so the foreign keys of their `_search` and `_metadata` tables
--    are triggers that do what InnoDB does: a row whose `_id` is in no parent row is refused
--    ("FOREIGN KEY constraint failed"), and deleting (or changing the `_id` of) any parent row
--    deletes (or changes) the child rows with its `_id`, even when another parent row shares it.
--

--
-- Table structure for table `site`
--

CREATE TABLE IF NOT EXISTS "site" (
  "uuid" varchar(255) NOT NULL COLLATE idno_ci,
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "siteid" varchar(36) COLLATE idno_ci,
  "owner" varchar(255) NOT NULL COLLATE idno_ci,
  "entity_subtype" varchar(64) NOT NULL COLLATE idno_ci,
  "created" text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "contents" longblob NOT NULL,
  "publish_status" varchar(255) NOT NULL DEFAULT 'published' COLLATE idno_ci,
  PRIMARY KEY ("uuid")
);
CREATE INDEX IF NOT EXISTS "site_owner" ON "site" ("owner", "created");
CREATE INDEX IF NOT EXISTS "site__id" ON "site" ("_id");
CREATE INDEX IF NOT EXISTS "site_entity_subtype" ON "site" ("entity_subtype");
CREATE INDEX IF NOT EXISTS "site_publish_status" ON "site" ("publish_status");
CREATE INDEX IF NOT EXISTS "site_siteid" ON "site" ("siteid");

CREATE TABLE IF NOT EXISTS "site_search" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "search" longtext NOT NULL,
  PRIMARY KEY ("_id")
);

CREATE TABLE IF NOT EXISTS "site_metadata" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "name" varchar(64) NOT NULL COLLATE idno_ci,
  "value" text NOT NULL COLLATE idno_ci
);
CREATE INDEX IF NOT EXISTS "site_metadata_value" ON "site_metadata" ("value");
CREATE INDEX IF NOT EXISTS "site_metadata_name" ON "site_metadata" ("name");
CREATE INDEX IF NOT EXISTS "site_metadata__id" ON "site_metadata" ("_id");

-- The foreign keys ss_id_id and sm_id_id (site._id is not unique).
CREATE TRIGGER IF NOT EXISTS "ss_id_id_insert" BEFORE INSERT ON "site_search" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "site" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "ss_id_id_update" BEFORE UPDATE OF "_id" ON "site_search" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "site" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "sm_id_id_insert" BEFORE INSERT ON "site_metadata" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "site" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "sm_id_id_update" BEFORE UPDATE OF "_id" ON "site_metadata" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "site" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "site_delete_cascade" AFTER DELETE ON "site" FOR EACH ROW BEGIN DELETE FROM "site_search" WHERE "_id" = OLD."_id"; DELETE FROM "site_metadata" WHERE "_id" = OLD."_id"; END;
CREATE TRIGGER IF NOT EXISTS "site_update_cascade" AFTER UPDATE OF "_id" ON "site" FOR EACH ROW BEGIN UPDATE "site_search" SET "_id" = NEW."_id" WHERE "_id" = OLD."_id"; UPDATE "site_metadata" SET "_id" = NEW."_id" WHERE "_id" = OLD."_id"; END;

--
-- Table structure for table `config`
--

CREATE TABLE IF NOT EXISTS "config" (
  "uuid" varchar(255) NOT NULL COLLATE idno_ci,
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "siteid" varchar(36) COLLATE idno_ci,
  "owner" varchar(255) NOT NULL COLLATE idno_ci,
  "entity_subtype" varchar(64) NOT NULL COLLATE idno_ci,
  "created" text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "contents" longblob NOT NULL,
  "publish_status" varchar(255) NOT NULL DEFAULT 'published' COLLATE idno_ci,
  PRIMARY KEY ("uuid")
);
CREATE INDEX IF NOT EXISTS "config_owner" ON "config" ("owner", "created");
CREATE INDEX IF NOT EXISTS "config__id" ON "config" ("_id");
CREATE INDEX IF NOT EXISTS "config_entity_subtype" ON "config" ("entity_subtype");
CREATE INDEX IF NOT EXISTS "config_publish_status" ON "config" ("publish_status");
CREATE INDEX IF NOT EXISTS "config_siteid" ON "config" ("siteid");

CREATE TABLE IF NOT EXISTS "config_search" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "search" longtext NOT NULL,
  PRIMARY KEY ("_id")
);

CREATE TABLE IF NOT EXISTS "config_metadata" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "name" varchar(64) NOT NULL COLLATE idno_ci,
  "value" text NOT NULL COLLATE idno_ci
);
CREATE INDEX IF NOT EXISTS "config_metadata_value" ON "config_metadata" ("value");
CREATE INDEX IF NOT EXISTS "config_metadata_name" ON "config_metadata" ("name");
CREATE INDEX IF NOT EXISTS "config_metadata__id" ON "config_metadata" ("_id");

-- The foreign keys cs_id_id and cm_id_id (config._id is not unique).
CREATE TRIGGER IF NOT EXISTS "cs_id_id_insert" BEFORE INSERT ON "config_search" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "config" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "cs_id_id_update" BEFORE UPDATE OF "_id" ON "config_search" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "config" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "cm_id_id_insert" BEFORE INSERT ON "config_metadata" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "config" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "cm_id_id_update" BEFORE UPDATE OF "_id" ON "config_metadata" FOR EACH ROW WHEN NOT EXISTS (SELECT 1 FROM "config" WHERE "_id" = NEW."_id") BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END;
CREATE TRIGGER IF NOT EXISTS "config_delete_cascade" AFTER DELETE ON "config" FOR EACH ROW BEGIN DELETE FROM "config_search" WHERE "_id" = OLD."_id"; DELETE FROM "config_metadata" WHERE "_id" = OLD."_id"; END;
CREATE TRIGGER IF NOT EXISTS "config_update_cascade" AFTER UPDATE OF "_id" ON "config" FOR EACH ROW BEGIN UPDATE "config_search" SET "_id" = NEW."_id" WHERE "_id" = OLD."_id"; UPDATE "config_metadata" SET "_id" = NEW."_id" WHERE "_id" = OLD."_id"; END;

--
-- Table structure for table `entities`
--

CREATE TABLE IF NOT EXISTS "entities" (
  "uuid" varchar(255) NOT NULL COLLATE idno_ci,
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "siteid" varchar(36) COLLATE idno_ci,
  "owner" varchar(255) NOT NULL COLLATE idno_ci,
  "entity_subtype" varchar(64) NOT NULL COLLATE idno_ci,
  "created" text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "contents" longblob NOT NULL,
  "publish_status" varchar(255) NOT NULL DEFAULT 'published' COLLATE idno_ci,
  PRIMARY KEY ("uuid")
);
CREATE UNIQUE INDEX IF NOT EXISTS "entities__id" ON "entities" ("_id");
CREATE INDEX IF NOT EXISTS "entities_owner" ON "entities" ("owner", "created");
CREATE INDEX IF NOT EXISTS "entities_entity_subtype" ON "entities" ("entity_subtype");
CREATE INDEX IF NOT EXISTS "entities_publish_status" ON "entities" ("publish_status");
CREATE INDEX IF NOT EXISTS "entities_siteid" ON "entities" ("siteid");

CREATE TABLE IF NOT EXISTS "entities_search" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "search" longtext NOT NULL,
  PRIMARY KEY ("_id"),
  CONSTRAINT "es_id_id" FOREIGN KEY ("_id") REFERENCES "entities" ("_id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS "entities_metadata" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "name" varchar(64) NOT NULL COLLATE idno_ci,
  "value" text NOT NULL COLLATE idno_ci,
  CONSTRAINT "em_id_id" FOREIGN KEY ("_id") REFERENCES "entities" ("_id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS "entities_metadata_value" ON "entities_metadata" ("value");
CREATE INDEX IF NOT EXISTS "entities_metadata_name" ON "entities_metadata" ("name");
CREATE INDEX IF NOT EXISTS "entities_metadata__id" ON "entities_metadata" ("_id");

--
-- Table structure for table `reader`
--

CREATE TABLE IF NOT EXISTS "reader" (
  "uuid" varchar(255) NOT NULL COLLATE idno_ci,
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "siteid" varchar(36) COLLATE idno_ci,
  "owner" varchar(255) NOT NULL COLLATE idno_ci,
  "entity_subtype" varchar(64) NOT NULL COLLATE idno_ci,
  "created" text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "contents" longblob NOT NULL,
  "publish_status" varchar(255) NOT NULL DEFAULT 'published' COLLATE idno_ci,
  PRIMARY KEY ("uuid")
);
CREATE UNIQUE INDEX IF NOT EXISTS "reader__id" ON "reader" ("_id");
CREATE INDEX IF NOT EXISTS "reader_owner" ON "reader" ("owner", "created");
CREATE INDEX IF NOT EXISTS "reader_entity_subtype" ON "reader" ("entity_subtype");
CREATE INDEX IF NOT EXISTS "reader_publish_status" ON "reader" ("publish_status");
CREATE INDEX IF NOT EXISTS "reader_siteid" ON "reader" ("siteid");

CREATE TABLE IF NOT EXISTS "reader_search" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "search" longtext NOT NULL,
  PRIMARY KEY ("_id"),
  CONSTRAINT "rs_id_id" FOREIGN KEY ("_id") REFERENCES "reader" ("_id") ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS "reader_metadata" (
  "_id" varchar(36) NOT NULL COLLATE idno_ci,
  "name" varchar(64) NOT NULL COLLATE idno_ci,
  "value" text NOT NULL COLLATE idno_ci,
  CONSTRAINT "rm_id_id" FOREIGN KEY ("_id") REFERENCES "reader" ("_id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS "reader_metadata_value" ON "reader_metadata" ("value");
CREATE INDEX IF NOT EXISTS "reader_metadata_name" ON "reader_metadata" ("name");
CREATE INDEX IF NOT EXISTS "reader_metadata__id" ON "reader_metadata" ("_id");

--
-- Table structure for table `versions`
--

CREATE TABLE IF NOT EXISTS "versions" (
  "label" varchar(32) NOT NULL COLLATE idno_ci,
  "value" varchar(10) NOT NULL COLLATE idno_ci,
  PRIMARY KEY ("label")
);

--
-- Session handling table (binary comparison, as MySQL's utf8_bin)
--

CREATE TABLE IF NOT EXISTS "session" (
  "session_id" varchar(255) NOT NULL,
  "session_value" mediumblob NOT NULL,
  "session_lifetime" int(11) NOT NULL,
  "session_time" int(11) NOT NULL,
  PRIMARY KEY ("session_id")
);

-- The version a MySQL database reaches when this schema is installed: mysql.sql is 2020120201,
-- and the MySQL backend then applies 2020120301 (the site_search table, here above, and setting
-- siteid on existing rows, of which a new database has none). There is no SQLite upgrade path.
REPLACE INTO "versions" VALUES ('schema', '2020120301');
