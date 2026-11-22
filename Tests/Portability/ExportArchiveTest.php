<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Migration;
use Idno\Core\Version;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;

/**
 * The full archive of a site: the folder ArchiveExporter::exportToFolder() makes (through its delegate
 * Migration::exportToFolder()) and the zip archive of it (archiveExportFolder(), createCompressedArchive()),
 * run in this process as the administrator.
 *
 * The site holds an article with an attachment and a private note; the archive holds, besides those,
 * every other entity of the site (its users included).
 */
final class ExportArchiveTest extends PortabilityTestCase
{
    private const SECRETS = [
        'smtp_password' => 'sentinel-smtp-password',
        'dbpass' => 'sentinel-database-password',
        'dbuser' => 'sentinel-database-user',
        'aws_secret' => 'sentinel-aws-secret',
        'aws_key' => 'sentinel-aws-key',
    ];

    private Entry $article;

    private Status $note;

    private string $fileId = '';

    private string $pngBytes = '';

    private string $folder = '';

    /** @var array<string, mixed> */
    private array $savedConfig = [];

    /** @var list<string> */
    private array $archives = [];

    /** @var array<string, mixed> */
    private array $savedBootConfig = [];

    private bool $settingsSaved = false;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->admin();
        $this->logOn($admin);

        $this->pngBytes = (string) base64_decode(self::PNG_BASE64, true);
        $this->fileId = $this->storeFile($this->pngBytes, 'pool.png', 'image/png');

        $article = new Entry();
        $article->title = 'Archived: tide pools — café 🌊';
        $article->body = '<p>Low tide at <strong>6:12</strong>.</p>';
        $article->attachFile(\Idno\Entities\File::getByID($this->fileId));
        $this->article = $this->store($article, $admin);

        $note = new Status();
        $note->body = 'A private note for the archive';
        $this->note = $this->store($note, $admin, $admin->getUUID());

        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'idno-archive-test-' . bin2hex(random_bytes(6));
        mkdir($this->folder, 0700);
        $this->removeAfterwards($this->folder);

        $config = Idno::site()->config();
        $this->savedConfig = $config->config;
        $this->savedBootConfig = $config->ini_config;
    }

    protected function tearDown(): void
    {
        $config = Idno::site()->config();
        $config->config = $this->savedConfig;
        $config->ini_config = $this->savedBootConfig;
        if ($this->settingsSaved) {
            $config->save(); // what the test stored goes: the stored settings are the document it started with
            $config->config = $this->savedConfig;
            $config->ini_config = $this->savedBootConfig;
        }
        foreach ($this->archives as $archive) {
            if (is_file($archive)) {
                @unlink($archive);
            }
            @rmdir(dirname($archive));
        }
        parent::tearDown();
    }

    /**
     * Runs Migration::exportToFolder() into the test's own directory.
     */
    private function export(): string
    {
        $path = Migration::exportToFolder($this->folder);
        $this->assertIsString($path);
        $this->assertDirectoryExists($path);

        return $path;
    }

    /**
     * Migration::archiveExportFolder() on an export folder, in a process of its own (see probe.php).
     */
    private function archive(string $folder): string
    {
        $run = $this->probe('archive_folder', ['folder' => $folder, 'login' => $this->admin()->getUUID()]);
        $this->assertSame(0, $run['exit'], $run['output']);
        $archive = $run['result']['archive'] ?? null;
        $this->assertIsString($archive, $run['output']);
        $this->archives[] = $archive;

        return $archive;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $path): array
    {
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path . ' is JSON');

        return $decoded;
    }

    /**
     * The SQL dump's text as the documents are written in it: MySQL's quoting doubles each backslash and
     * escapes each double quote, which SQLite's does not.
     */
    private function dumpedDocuments(string $path): string
    {
        return strtr((string) file_get_contents($path . '/exported_data.sql'), ['\\\\' => '\\', '\\"' => '"']);
    }

    private function idPath(string $id): string
    {
        return $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id;
    }

    // ------------------------------------------------------------------ the folder

    public function testTheFolderHasTheFilesAndDirectoriesTheCodeNames(): void
    {
        $path = $this->export();

        $this->assertMatchesRegularExpression('~^' . preg_quote($this->folder, '~') . '/[0-9a-f]{32}$~', $path, 'a folder named by a hash, in the directory asked for');
        $this->assertSame(
            ['config.json', 'entities.json', 'exported_data.sql', 'files', 'html', 'json', 'known.json'],
            $this->namesIn($path)
        );
        $this->assertContains('readable', $this->namesIn($path . '/files'));
        $this->assertContains('uploads', $this->namesIn($path . '/files'));
    }

    public function testKnownJsonHoldsTheSiteAddressTitleAndVersion(): void
    {
        $known = $this->jsonFile($this->export() . '/known.json');

        $this->assertSame(['url', 'title', 'version', 'build'], array_keys($known));
        $this->assertSame(Idno::site()->config()->getURL(), $known['url']);
        $this->assertSame(Idno::site()->config()->getTitle(), $known['title']);
        $this->assertSame(Version::version(), $known['version']);
        $this->assertSame(Version::build(), $known['build']);
    }

    public function testEveryEntityHasAJsonFileAnEntryInEntitiesJsonAndARowInTheSqlDump(): void
    {
        $path = $this->export();
        $records = Idno::site()->db()->countRecords([], 'entities');
        $this->assertGreaterThan(2, $records, 'the two entities of this test, and the users');

        // json/<id>.json, one for each entity
        $jsonFiles = glob($path . '/json/*.json') ?: [];
        $this->assertCount($records, $jsonFiles);
        foreach ([$this->article, $this->note] as $entity) {
            $json = $this->jsonFile($path . '/json/' . $entity->getID() . '.json');
            $this->assertSame($entity->getUUID(), $json['uuid']);
        }
        // The JSON of an entity is its ActivityStreams view (Entity::jsonSerialize()), not the stored document.
        $article = $this->jsonFile($path . '/json/' . $this->article->getID() . '.json');
        $this->assertSame('article', $article['objectType']);
        $this->assertSame('Archived: tide pools — café 🌊', $article['displayName']);
        $this->assertSame('<p>Low tide at <strong>6:12</strong>.</p>', $article['formattedContent']);
        $this->assertSame($this->article->getURL(), $article['url']);
        $this->assertSame('Test Admin User', $article['actor']['displayName']);

        // entities.json: all of them in one list, and an empty object after the last (so that the
        // list can be written without knowing which entity is the last)
        $all = $this->jsonFile($path . '/entities.json');
        $this->assertSame([], end($all), 'the list ends with an empty object');
        $uuids = array_filter(array_column($all, 'uuid'));
        $this->assertCount($records, $uuids);
        $this->assertContains($this->article->getUUID(), $uuids);
        $this->assertContains($this->note->getUUID(), $uuids);

        // exported_data.sql: one insert for each stored record, as the schema of the site stores them
        $sql = (string) file_get_contents($path . '/exported_data.sql');
        $this->assertSame($records, substr_count($sql, "insert into idno_entities (`id`,`uuid`,`type`,`owner`,`created`,`publish_status`,`data`,`search`) values ("));
        $this->assertStringContainsString("'" . $this->article->getID() . "','" . $this->article->getUUID() . "','article'", $sql);
        $this->assertStringContainsString('Archived: tide pools', $sql);
    }

    public function testAnEntityIsDrawnToAnHtmlFile(): void
    {
        $path = $this->export();

        $html = (string) file_get_contents($path . '/html/' . $this->article->getID() . '.html');
        $this->assertStringContainsString('Archived: tide pools', $html);
        $this->assertStringContainsString('<strong>6:12</strong>', $html);
        $this->assertStringContainsString('class="p-name"', $html, 'with its microformats');
        $this->assertStringContainsString('class="e-content entry-content"', $html);
    }

    public function testAPrivateEntityIsInTheArchiveToo(): void
    {
        $path = $this->export();

        $this->assertSame('note', $this->jsonFile($path . '/json/' . $this->note->getID() . '.json')['objectType']);
        $this->assertStringContainsString('A private note for the archive', $this->dumpedDocuments($path));
    }

    public function testTheJsonOfAnEntitySaysItsAccessAndPublishStatus(): void
    {
        // So that whoever handles the files can tell a private note from a public article without reading the
        // SQL dump (which says it too).
        $path = $this->export();

        $note = $this->jsonFile($path . '/json/' . $this->note->getID() . '.json');
        $this->assertSame($this->admin()->getUUID(), $note['access']);
        $this->assertSame('published', $note['publish_status']);
        $this->assertSame('PUBLIC', $this->jsonFile($path . '/json/' . $this->article->getID() . '.json')['access']);

        $all = $this->jsonFile($path . '/entities.json');
        $byUuid = [];
        foreach ($all as $entity) {
            if (isset($entity['uuid'])) {
                $byUuid[$entity['uuid']] = $entity;
            }
        }
        $this->assertSame($this->admin()->getUUID(), $byUuid[$this->note->getUUID()]['access'], 'entities.json too');
        $this->assertSame('PUBLIC', $byUuid[$this->article->getUUID()]['access']);
    }

    public function testADraftIsMarkedAsOneInItsJson(): void
    {
        $draft = new Entry();
        $draft->title = 'An unfinished draft';
        $draft->body = '<p>Not yet.</p>';
        $this->store($draft, $this->admin(), 'PUBLIC', 'draft');

        $json = $this->jsonFile($this->export() . '/json/' . $draft->getID() . '.json');

        $this->assertSame('draft', $json['publish_status']);
    }

    public function testAnAttachmentIsCopiedIntoTheFilesDirectoryWithItsMetadata(): void
    {
        $path = $this->export();
        $host = Idno::site()->config()->pathHost();

        $this->assertSame($this->pngBytes, file_get_contents($path . '/files/readable/' . $this->fileId . '.png'));
        $stored = $path . '/files/uploads/' . $host . '/' . $this->idPath($this->fileId);
        $this->assertSame($this->pngBytes, file_get_contents($stored . '.file'));
        $this->assertSame(['filename' => $this->fileId . '.png', 'mime_type' => 'image/png'], json_decode((string) file_get_contents($stored . '.data'), true));
    }

    public function testTheAddressOfAnAttachmentInAnEntityPointsAtItsFileInTheArchive(): void
    {
        $path = $this->export();
        $host = Idno::site()->config()->pathHost();

        $attachment = $this->jsonFile($path . '/json/' . $this->article->getID() . '.json')['attachments'][0];

        $this->assertSame('../files/uploads/' . $host . '/' . $this->idPath($this->fileId) . '.file', $attachment['url']);
        $this->assertFileExists($path . '/json/' . $attachment['url']);
        $this->assertSame($this->pngBytes, file_get_contents($path . '/json/' . $attachment['url']));
        $this->assertSame(['readable', 'uploads'], $this->namesIn($path . '/files'), 'and no empty directory beside uploads/');
    }

    public function testAFileAttachedToTwoEntitiesIsCopiedOnce(): void
    {
        $second = new Entry();
        $second->title = 'Another article with the same picture';
        $second->body = '<p>Again.</p>';
        $second->attachFile(\Idno\Entities\File::getByID($this->fileId));
        $this->store($second, $this->admin());

        $path = $this->export();

        $mine = array_values(array_filter($this->namesIn($path . '/files/readable'), fn (string $name): bool => str_starts_with($name, $this->fileId)));
        $this->assertSame([$this->fileId . '.png'], $mine, 'once, though two entities hold it (the site may hold other files too)');
        foreach ([$this->article, $second] as $entity) {
            $this->assertSame($this->pngBytes, file_get_contents($path . '/json/' . $this->jsonFile($path . '/json/' . $entity->getID() . '.json')['attachments'][0]['url']));
        }
    }

    // ------------------------------------------------------------------ config.json

    public function testConfigJsonHoldsTheStoredSettingsAndNeverTheBootConfiguration(): void
    {
        $config = Idno::site()->config();
        $config->config['archive_test_setting'] = 'a stored setting';
        $config->save();
        $this->settingsSaved = true;
        // What only the boot configuration (config.ini, the environment) holds: in memory, never stored.
        $config->config['boot_only_setting'] = 'from config.ini';
        $config->ini_config['boot_only_setting'] = 'from config.ini';

        $archived = $this->jsonFile($this->export() . '/config.json');

        $this->assertSame(['config'], array_keys($archived), 'the stored settings: no boot configuration beside them');
        $this->assertSame('a stored setting', $archived['config']['archive_test_setting']);
        $this->assertArrayNotHasKey('boot_only_setting', $archived['config']);
        $this->assertStringNotContainsString('from config.ini', (string) file_get_contents($this->export() . '/config.json'));
        $this->assertSame(Idno::site()->config()->title, $archived['config']['title'] ?? Idno::site()->config()->title);
    }

    public function testConfigJsonHoldsNoSecretOfAnyLayer(): void
    {
        $config = Idno::site()->config();
        // The stored layer: Config::save() stores whatever the configuration holds but the database's own settings.
        foreach (['smtp_password', 'aws_secret', 'aws_key', 'dbuser'] as $key) {
            $config->config[$key] = self::SECRETS[$key];
        }
        $config->config['someplugin'] = ['apikey' => 'sentinel-plugin-key', 'secret' => 'sentinel-plugin-secret', 'screen_name' => 'kept'];
        $config->save();
        $this->settingsSaved = true;
        $this->assertNotEmpty($config->site_secret, 'the site secret is a stored setting');
        // (The boot layer, config.ini and the environment, is never read by the export at all: the test that plants
        // values in a real BootConfig is ArchiveExporterTest::testASettingTheBootConfigurationDefinesIsNotWrittenToConfigJson.)

        $text = (string) file_get_contents($this->export() . '/config.json');

        foreach (self::SECRETS as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
        $this->assertStringNotContainsString('sentinel-plugin-key', $text);
        $this->assertStringNotContainsString('sentinel-plugin-secret', $text);
        $this->assertStringNotContainsString((string) $config->site_secret, $text);
        $archived = json_decode($text, true);
        $this->assertSame('kept', $archived['config']['someplugin']['screen_name'], 'what is no secret stays');
        $this->assertArrayNotHasKey('site_secret', $archived['config'], 'a secret is removed, not replaced by a marker a restore would load');
        $this->assertArrayNotHasKey('smtp_password', $archived['config']);
    }

    public function testTheSqlDumpKeepsEveryUsersPasswordHashAndActivityPubPrivateKeySoTheSiteCanBeRestored(): void
    {
        // exported_data.sql is the stored documents as they are: it is the one file that restores the
        // site, its users and its ActivityPub identity (a user's password hash, the key pair that signs what
        // they federate) and its private posts. The archive is therefore as sensitive as a database backup,
        // and is served to administrators only (AdminPortabilityPagesTest). The JSON of the user
        // (json/<id>.json) is the ActivityStreams view and holds neither.
        $admin = $this->admin();
        $hash = (string) $admin->password;
        $this->assertNotSame('', $hash);
        $this->assertNotEmpty($admin->privateKey, 'the ActivityPub plugin gave the user a key pair');

        $path = $this->export();

        $dump = $this->dumpedDocuments($path);
        $this->assertStringContainsString(substr(json_encode($hash, JSON_THROW_ON_ERROR), 1, -1), $dump);
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $dump);
        $json = (string) file_get_contents($path . '/json/' . $admin->getID() . '.json');
        $this->assertStringNotContainsString(substr(json_encode($hash, JSON_THROW_ON_ERROR), 1, -1), $json);
        $this->assertStringNotContainsString('PRIVATE KEY', $json);
    }

    // ------------------------------------------------------------------ the archive

    public function testAnEntitysHtmlInTheFolderIsAMicroformats2ObjectWithItsAuthor(): void
    {
        $path = $this->export();

        $html = (string) file_get_contents($path . '/html/' . $this->article->getID() . '.html');

        $this->assertStringContainsString('<article class="h-entry">', $html);
        $this->assertMatchesRegularExpression('~<a class="p-author h-card" href="[^"]+">[^<]+</a>~', $html, 'the author as an h-card');
        $this->assertStringContainsString(htmlspecialchars((string) $this->article->getURL(), ENT_QUOTES), $html, 'its address');
        $this->assertStringContainsString('class="dt-published"', $html);
        $this->assertStringContainsString('e-content', $html, 'and the drawn content inside');
    }

    public function testTheArchiveFunctionTheCodeCallsExistsSoTheArchiveIsMade(): void
    {
        // archiveExportFolder() calls site() unqualified (Migration.php:240), which is Idno\Core\site(), the
        // deprecated helper at the end of Idno.php: it exists. The call is not a fatal error.
        $this->assertTrue(function_exists('Idno\Core\site'));

        $archive = $this->archive($this->export());

        $this->assertIsString($archive);
        $this->assertFileExists($archive);
    }

    public function testTheArchiveIsAZipFileWithTheFolderAsItsRoot(): void
    {
        $archive = $this->archive($this->export());

        $names = $this->entriesOf($archive);

        foreach (['known.json', 'config.json', 'entities.json', 'exported_data.sql', 'json/' . $this->article->getID() . '.json', 'html/' . $this->article->getID() . '.html', 'files/readable/' . $this->fileId . '.png', 'files/uploads/' . Idno::site()->config()->pathHost() . '/' . $this->idPath($this->fileId) . '.file'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertSame("PK\x03\x04", substr((string) file_get_contents($archive), 0, 4), 'a zip file');
        $this->assertSame(Version::version(), json_decode($this->entryOf($archive, 'known.json'), true)['version']);
    }

    public function testTheArchiveIsNamedAfterTheHostInTheTemporaryDirectory(): void
    {
        $archive = $this->archive($this->export());

        $this->assertSame(
            str_replace('.', '_', (string) Idno::site()->config()->host) . '.zip',
            basename($archive),
            'the same name every time, for this host'
        );
        $this->assertStringStartsWith(realpath(Idno::site()->config()->getTempDir()) . '/idno-archive-', dirname(realpath($archive)));
    }

    public function testTheArchiveIsMadeInADirectoryOfItsOwnThatOnlyTheServerCanEnter(): void
    {
        $first = $this->archive($this->export());
        $this->logOn($this->admin());
        $second = $this->archive($this->export());

        $this->assertNotSame(dirname($first), dirname($second), 'the directory name cannot be guessed, and is new each time');
        $this->assertMatchesRegularExpression('~/idno-archive-[0-9a-f]{32}$~', dirname($first));
        $this->assertSame(0700, fileperms(dirname($first)) & 0777);
        $this->assertNotSame(rtrim(Idno::site()->config()->getTempDir(), '/'), dirname($first), 'never directly in the temporary directory at a predictable path');
    }

    public function testCreateCompressedArchiveReturnsTheArchiveAndRemovesTheFolder(): void
    {
        $before = $this->hashDirectories();

        $run = $this->probe('create_archive', ['login' => $this->admin()->getUUID()]);
        $this->assertSame(0, $run['exit'], $run['output']);
        $archive = $run['result']['archive'] ?? null;

        $this->assertIsString($archive);
        $this->assertFileExists($archive);
        $this->assertSame([], array_values(array_diff($this->hashDirectories(), $before)), 'the folder the export made is gone');
        $this->assertContains('known.json', $this->entriesOf($archive));
    }

    public function testAnArchiveHoldsOnlyWhatTheSiteHeldWhenItWasMade(): void
    {
        $first = $this->archive($this->export());
        $this->assertContains('json/' . $this->note->getID() . '.json', $this->entriesOf($first));
        $this->logOn($this->admin());
        $this->note->delete();
        $second = $this->archive($this->export());

        $this->assertSame(basename($first), basename($second), 'the same file name, for this host');
        $this->assertNotContains('json/' . $this->note->getID() . '.json', $this->entriesOf($second), 'an entity deleted since is not in the second archive');
    }

    // ------------------------------------------------------------------ what can go wrong

    public function testExportToFolderReturnsFalseWhenItCannotMakeItsDirectory(): void
    {
        $blocker = $this->folder . '/not-a-directory';
        file_put_contents($blocker, 'x');

        $this->assertFalse(@Migration::exportToFolder($blocker));
    }

    public function testArchiveExportFolderRefusesWhatIsNotAnExportFolder(): void
    {
        $this->assertFalse(Migration::archiveExportFolder($this->folder . '/missing'));
        $this->assertFalse(Migration::archiveExportFolder($this->folder), 'a directory with no known.json');
    }

    public function testCleanUpFolderRemovesTheFolderAndEverythingInIt(): void
    {
        $path = $this->export();
        $this->assertDirectoryExists($path . '/files/uploads');

        Migration::cleanUpFolder($path);

        $this->assertDirectoryDoesNotExist($path);
    }

    public function testTheExportFolderIsReadableByTheUserRunningTheSiteOnly(): void
    {
        $path = $this->export();

        $this->assertSame(0, fileperms($path) & 0077, 'the folder');
        foreach (['config.json', 'known.json', 'entities.json', 'exported_data.sql', 'json/' . $this->article->getID() . '.json', 'html/' . $this->article->getID() . '.html', 'files/readable/' . $this->fileId . '.png'] as $file) {
            $this->assertSame(0, fileperms($path . '/' . $file) & 0077, $file);
        }
        foreach (['json', 'html', 'files', 'files/uploads'] as $directory) {
            $this->assertSame(0, fileperms($path . '/' . $directory) & 0077, $directory);
        }
        $archive = $this->archive($path);
        $this->assertSame(0, fileperms($archive) & 0077, 'the archive');
    }

    public function testTheFolderIsNamedByRandomDigitsNotByWhatCanBeGuessed(): void
    {
        $first = $this->export();
        $second = $this->export();

        $this->assertNotSame(basename($first), basename($second));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', basename($first));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return list<string>
     */
    private function namesIn(string $directory): array
    {
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function hashDirectories(): array
    {
        return array_map('basename', glob(rtrim(Idno::site()->config()->getTempDir(), '/') . '/????????????????????????????????', GLOB_ONLYDIR) ?: []);
    }

    /**
     * @return list<string> the paths of the files in a zip archive
     */
    private function entriesOf(string $archive): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archive) === true, 'the archive opens');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    private function entryOf(string $archive, string $name): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archive) === true);
        $content = $zip->getFromName($name);
        $zip->close();
        $this->assertNotFalse($content, $name . ' is in the archive');

        return (string) $content;
    }
}
