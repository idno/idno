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
 * The full archive of a site, Migration::exportToFolder() and archiveExportFolder() (what the admin's
 * "Create archive" runs, through createCompressedArchive()), run in this process as the administrator.
 *
 * The site holds an article with an attachment and a private note; the archive holds, besides those,
 * every other entity of the site (its users included).
 *
 * Tests named "testToday..." pin what the code does now where that is a defect or a risk (the next change
 * must flip them); each is followed by the test of the wanted behaviour, marked incomplete until it passes.
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

    /** @var array<string, mixed> */
    private array $savedBootConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->admin();
        $this->logOn($admin);

        $this->pngBytes = (string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true);
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
        $zip = $this->zipPath();
        if (is_file($zip)) {
            @unlink($zip);
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

        return $archive;
    }

    private function zipPath(): string
    {
        return Idno::site()->config()->getTempDir() . str_replace('.', '_', (string) Idno::site()->config()->host) . '.zip';
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

    public function testTodayTheJsonOfAPrivateEntityDoesNotSayItIsPrivate(): void
    {
        // json/<id>.json and entities.json carry no access setting, so they cannot tell a private note
        // from a public one; only the stored document in exported_data.sql ("access") does. Anything that
        // restores an archive from the JSON would publish it.
        $path = $this->export();

        $json = $this->jsonFile($path . '/json/' . $this->note->getID() . '.json');
        $this->assertArrayNotHasKey('access', $json);
        $this->assertStringContainsString('"access":"' . str_replace('/', '\/', $this->admin()->getUUID()) . '"', $this->dumpedDocuments($path));
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

    public function testTodayTheAddressOfAnAttachmentInAnEntityPointsAtAPlaceTheArchiveDoesNotHold(): void
    {
        // The entity's attachment address is rewritten to ../files/<host>/<id path>.file (Migration.php:132)
        // but the bytes are written under files/uploads/<host>/<id path>.file (Migration.php:139): the
        // address is one directory short. And the directory loop makes an empty files/<host>/<id0> too.
        // The next change must flip this to the test below.
        $path = $this->export();
        $host = Idno::site()->config()->pathHost();

        $attachment = $this->jsonFile($path . '/json/' . $this->article->getID() . '.json')['attachments'][0];
        $this->assertSame('../files/' . $host . '/' . $this->idPath($this->fileId) . '.file', $attachment['url']);
        $this->assertFileDoesNotExist($path . '/json/' . $attachment['url']);
        $this->assertFileExists($path . '/files/uploads/' . $host . '/' . $this->idPath($this->fileId) . '.file');
        $this->assertDirectoryExists($path . '/files/' . $host . '/' . $this->fileId[0], 'an empty directory beside uploads/');
        $this->assertSame([], $this->namesIn($path . '/files/' . $host . '/' . $this->fileId[0]));
    }

    public function testTheAddressOfAnAttachmentInAnEntityPointsAtItsFileInTheArchive(): void
    {
        $this->markTestIncomplete('Migration::exportToFolder() rewrites an attachment address to ../files/<host>/... (Migration.php:132) but writes the file under files/uploads/<host>/... (Migration.php:139)');

        $path = $this->export();

        $attachment = $this->jsonFile($path . '/json/' . $this->article->getID() . '.json')['attachments'][0];
        $this->assertFileExists($path . '/json/' . $attachment['url']);
        $this->assertSame($this->pngBytes, file_get_contents($path . '/json/' . $attachment['url']));
    }

    // ------------------------------------------------------------------ config.json

    public function testConfigJsonHoldsTheConfigurationInEffectAndTheBootConfiguration(): void
    {
        $config = $this->jsonFile($this->export() . '/config.json');

        $this->assertSame(['config', 'ini_config'], array_keys($config), 'json_encode() of the Config object: its two public properties');
        $notNull = static fn (array $settings): array => array_filter($settings, static fn (mixed $value): bool => $value !== null);
        $this->assertEquals($notNull(Idno::site()->config()->config), $notNull($config['config']));
        $this->assertEquals($notNull(Idno::site()->config()->ini_config), $notNull($config['ini_config']));
        $this->assertSame(Idno::site()->config()->getURL(), $config['config']['url']);
        $this->assertSame(Idno::site()->config()->title, $config['config']['title']);
        $this->assertSame(Idno::site()->config()->path, $config['config']['path'], 'the server\'s directory');
        foreach ($config['ini_config'] as $key => $value) {
            $this->assertSame($value, $config['config'][$key], 'the boot configuration is merged over the settings: ' . $key);
        }
    }

    public function testTodayConfigJsonHoldsTheSiteSecretAndEverySecretOfTheConfiguration(): void
    {
        // Nothing is left out of config.json: the site secret (which signs the CSRF tokens), the database
        // and mail passwords, the cloud keys, whatever plugins keep in the settings. An archive is passed
        // around (to a new host, to a person), so it carries the keys to this site. The next change must
        // flip this to the test below.
        $config = Idno::site()->config();
        foreach (self::SECRETS as $key => $value) {
            $config->config[$key] = $value;
            $config->ini_config[$key] = $value;
        }
        $config->config['someplugin'] = ['apikey' => 'sentinel-plugin-key', 'secret' => 'sentinel-plugin-secret'];

        $archived = $this->jsonFile($this->export() . '/config.json');

        foreach (self::SECRETS as $key => $value) {
            $this->assertSame($value, $archived['config'][$key], $key . ' (settings)');
            $this->assertSame($value, $archived['ini_config'][$key], $key . ' (boot configuration)');
        }
        $this->assertSame(['apikey' => 'sentinel-plugin-key', 'secret' => 'sentinel-plugin-secret'], $archived['config']['someplugin']);
        $this->assertNotEmpty($archived['config']['site_secret']);
        $this->assertSame(Idno::site()->config()->site_secret, $archived['config']['site_secret']);
    }

    public function testConfigJsonHoldsNoSecret(): void
    {
        $this->markTestIncomplete('Migration::exportToFolder() writes json_encode(Idno::site()->config()) to config.json (Migration.php:83): site_secret, dbpass, dbuser, smtp_password, aws_key, aws_secret and plugin credentials are all in it');

        $config = Idno::site()->config();
        foreach (self::SECRETS as $key => $value) {
            $config->config[$key] = $value;
            $config->ini_config[$key] = $value;
        }

        $text = (string) file_get_contents($this->export() . '/config.json');

        foreach (array_values(self::SECRETS) as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
        $this->assertStringNotContainsString((string) Idno::site()->config()->site_secret, $text);
    }

    public function testTodayTheSqlDumpHoldsEveryUsersPasswordHashAndActivityPubPrivateKey(): void
    {
        // exported_data.sql is the stored documents as they are: a user's document holds the hash of their
        // password and, with the ActivityPub plugin, the private key that signs what they federate. The JSON
        // of the user (json/<id>.json) holds neither. The next change must decide what an archive may carry;
        // this pins what it carries. (An archive is also what a new host is set up from, and this dump is
        // the one file that can restore the site's private posts.)
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

    public function testTheSqlDumpHoldsNoPasswordHashOrPrivateKey(): void
    {
        $this->markTestIncomplete('Migration::exportToFolder() writes the stored documents of every user into exported_data.sql (Migration.php:189-198, through exportRecords()): their password hashes and ActivityPub private keys are in it');

        $admin = $this->admin();
        $path = $this->export();

        $dump = $this->dumpedDocuments($path);
        $this->assertStringNotContainsString(substr(json_encode((string) $admin->password, JSON_THROW_ON_ERROR), 1, -1), $dump);
        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
    }

    // ------------------------------------------------------------------ the archive

    public function testTheArchiveFunctionTheCodeCallsExistsSoTheArchiveIsMade(): void
    {
        // archiveExportFolder() calls site() unqualified (Migration.php:240), which is Idno\Core\site(), the
        // deprecated helper at the end of Idno.php: it exists. The call is not a fatal error.
        $this->assertTrue(function_exists('Idno\Core\site'));

        $archive = $this->archive($this->export());

        $this->assertIsString($archive);
        $this->assertSame(realpath($this->zipPath()), realpath($archive));
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
        $this->assertSame(realpath(Idno::site()->config()->getTempDir()), realpath(dirname($archive)));
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

    public function testTodayAnArchiveOfTheSameHostIsAddedToNotReplaced(): void
    {
        // The archive's name is the same each time and PharData opens an existing file and adds to it
        // (Migration.php:245), while the code deletes <host>.tar and <host>.tar.gz (Migration.php:240-243),
        // not the zip. The page unlinks the zip after storing it, so this shows only when that step did
        // not run (the store failed): the next archive then holds the entities of the last one. The next
        // change must flip this to the test below.
        $first = $this->archive($this->export());
        $this->assertContains('json/' . $this->note->getID() . '.json', $this->entriesOf($first));

        $this->logOn($this->admin());
        $this->note->delete();
        $second = $this->archive($this->export());

        $this->assertSame(realpath($first), realpath($second));
        $this->assertContains('json/' . $this->note->getID() . '.json', $this->entriesOf($second), 'an entity deleted since is still in the second archive');
    }

    public function testAnArchiveHoldsOnlyWhatTheSiteHeldWhenItWasMade(): void
    {
        $this->markTestIncomplete('Migration::archiveExportFolder() adds to an archive left from an earlier export of the same host (Migration.php:240-246): it removes <host>.tar and <host>.tar.gz and builds <host>.zip');

        $first = $this->archive($this->export());
        $this->assertContains('json/' . $this->note->getID() . '.json', $this->entriesOf($first));
        $this->note->delete();
        $second = $this->archive($this->export());

        $this->assertNotContains('json/' . $this->note->getID() . '.json', $this->entriesOf($second));
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

    public function testTodayTheExportFolderAndItsFilesCanBeReadByOtherUsersOfTheServer(): void
    {
        // The folder is made with mode 0777 and the files with the default mode, so they are only as
        // private as the umask: in the shared temporary directory, with a usual umask, readable by every
        // user of the machine, config.json (with its secrets) included. The next change must flip this.
        if ((umask() & 0005) !== 0) {
            $this->markTestSkipped('This process\'s umask hides new files from other users.');
        }
        $path = $this->export();

        $this->assertSame(0004, fileperms($path . '/config.json') & 0004, 'config.json is world-readable');
        $this->assertSame(0005, fileperms($path) & 0005, 'the folder is world-readable and searchable');
    }

    public function testTheExportFolderIsReadableByTheUserRunningTheSiteOnly(): void
    {
        $this->markTestIncomplete('Migration::exportToFolder() makes its folders with mode 0777 and writes its files with the default mode (Migration.php:38-62, 70-83), in the shared temporary directory');

        $path = $this->export();

        $this->assertSame(0, fileperms($path . '/config.json') & 0077);
        $this->assertSame(0, fileperms($path) & 0077);
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
