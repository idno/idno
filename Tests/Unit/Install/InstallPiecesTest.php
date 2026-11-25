<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Exceptions\ConfigurationException;
use Idno\Install\CheckResult;
use Idno\Install\ConfigFile;
use Idno\Install\DatabaseChoice;
use Idno\Install\DatabaseFinding;
use Idno\Install\InstallPaths;
use Idno\Install\ServerChecks;
use Idno\Install\SetupToken;
use Idno\Install\SiteAddress;
use PHPUnit\Framework\TestCase;

/**
 * The installer's pure pieces: the server checks, the site's address, the configuration file that is
 * written once, the setup token and what a chosen database already holds.
 */
final class InstallPiecesTest extends TestCase
{
    use TemporaryRoot;

    protected function setUp(): void
    {
        $this->makeRoot();
    }

    protected function tearDown(): void
    {
        $this->removeRoot();
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, string> id => state
     */
    private function states(array $facts): array
    {
        $states = [];
        foreach (ServerChecks::run(new InstallPaths($this->root), $facts + ['php' => '8.4.1', 'extension' => static fn (string $n): bool => true, 'writable' => static fn (string $p): bool => true, 'secure' => true, 'host' => 'example.com']) as $result) {
            $states[$result->id] = $result->state;
        }

        return $states;
    }

    public function testAServerWithEverythingPasses(): void
    {
        $results = ServerChecks::run(new InstallPaths($this->root), ['php' => '8.4.0', 'secure' => true, 'host' => 'example.com', 'extension' => static fn (string $n): bool => true]);

        self::assertFalse(ServerChecks::blocking($results));
        self::assertSame(['php', 'extensions', 'configuration', 'uploads', 'data', 'https'], array_map(static fn (CheckResult $r): string => $r->id, $results));
    }

    public function testAnOldPhpAMissingExtensionAndAnUnwritableFolderFailWithAFix(): void
    {
        $results = ServerChecks::run(new InstallPaths($this->root), [
            'php' => '8.3.9',
            'extension' => static fn (string $n): bool => $n !== 'gd',
            'writable' => static fn (string $p): bool => !str_ends_with($p, '/configuration'),
            'secure' => true,
            'host' => 'example.com',
        ]);

        self::assertTrue(ServerChecks::blocking($results));
        foreach (['php', 'extensions', 'configuration'] as $id) {
            $result = ServerChecks::find($results, $id);
            self::assertNotNull($result);
            self::assertSame(CheckResult::FAIL, $result->state, $id);
            self::assertNotSame('', $result->meaning, $id);
            self::assertNotSame('', $result->fix, $id);
        }
        self::assertStringContainsString('gd', (string) ServerChecks::find($results, 'extensions')?->meaning);
        self::assertStringContainsString('chown -R www-data configuration', (string) ServerChecks::find($results, 'configuration')?->command);
        self::assertSame(CheckResult::OK, ServerChecks::find($results, 'uploads')?->state);
    }

    public function testNoDatabaseExtensionFailsAndAnUnwritableDataFolderOnlyWarnsWhenMysqlIsThere(): void
    {
        self::assertSame(CheckResult::FAIL, $this->states(['extension' => static fn (string $n): bool => !str_starts_with($n, 'pdo_')])['extensions']);
        $unwritableData = static fn (string $p): bool => !str_ends_with($p, '/data');
        self::assertSame(CheckResult::WARN, $this->states(['writable' => $unwritableData])['data']);
        self::assertSame(CheckResult::FAIL, $this->states(['writable' => $unwritableData, 'extension' => static fn (string $n): bool => $n !== 'pdo_mysql'])['data']);
        self::assertArrayNotHasKey('data', $this->states(['extension' => static fn (string $n): bool => $n !== 'pdo_sqlite']));
    }

    public function testHttpsIsRequiredExceptOnThisComputer(): void
    {
        self::assertSame(CheckResult::FAIL, $this->states(['secure' => false, 'host' => 'example.com'])['https']);
        self::assertSame(CheckResult::FAIL, $this->states(['secure' => false, 'host' => '192.168.1.5'])['https']);
        foreach (['localhost', 'localhost:8080', '127.0.0.1:8391', '[::1]', 'idno.test', 'blog.localhost'] as $host) {
            self::assertSame(CheckResult::WARN, $this->states(['secure' => false, 'host' => $host])['https'], $host);
        }
        self::assertSame(CheckResult::OK, $this->states(['secure' => true])['https']);
    }

    public function testASubdirectoryFailsAndTheCommandLineSkipsTheBrowserChecks(): void
    {
        self::assertSame(CheckResult::FAIL, $this->states(['basePath' => '/blog'])['root']);
        self::assertArrayNotHasKey('root', $this->states([]));
        $cli = $this->states(['web' => false, 'secure' => false]);
        self::assertArrayNotHasKey('https', $cli);
        self::assertSame(CheckResult::WARN, $this->states(['cacheInTemp' => true])['var']);
    }

    public function testADomainBecomesAnHttpsAddress(): void
    {
        self::assertSame('https://example.com/', SiteAddress::url('Example.COM'));
        self::assertSame('https://notes.example.com:8443/', SiteAddress::url('notes.example.com:8443'));
        self::assertSame('http://localhost:8080/', SiteAddress::url('localhost:8080'));
        self::assertSame('http://127.0.0.1:8391/', SiteAddress::url('127.0.0.1:8391'));
        self::assertSame('https://192.168.1.5/', SiteAddress::url('192.168.1.5'));
        self::assertSame('http://[::1]/', SiteAddress::url('[::1]'));
        self::assertSame('example.com', SiteAddress::fromHostHeader('EXAMPLE.com'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notDomains(): array
    {
        return array_map(static fn (string $v): array => [$v], [
            'empty' => '', 'a scheme' => 'https://example.com', 'a path' => 'example.com/blog', 'a user' => 'u@example.com',
            'a space' => 'exa mple.com', 'a quote' => "example.com'", 'a line break' => "example.com\nurl = 'x'", 'port 0' => 'example.com:0',
            'a large port' => 'example.com:70000', 'a query' => 'example.com?x', 'a leading dot' => '.example.com', 'markup' => '"><x-canary',
            'an underscore' => 'exa_mple.com', 'a bad ipv6' => '[zz]',
        ]);
    }

    /**
     * @dataProvider notDomains
     */
    public function testWhatIsNotADomainIsRefused(string $typed): void
    {
        self::assertFalse(SiteAddress::valid($typed));
        self::assertSame('', SiteAddress::fromHostHeader($typed));
        $this->expectException(\InvalidArgumentException::class);
        SiteAddress::url($typed);
    }

    public function testAWholeAddressIsAcceptedFromTheCommandLineWithoutAPath(): void
    {
        self::assertSame('http://192.168.1.5/', SiteAddress::fromUrl('http://192.168.1.5'));
        self::assertSame('https://example.com/', SiteAddress::fromUrl('HTTPS://Example.com/'));
        foreach (['ftp://example.com/', 'https://example.com/blog/', 'https://u:p@example.com/', 'example.com', 'https://example.com/?x=1'] as $url) {
            try {
                SiteAddress::fromUrl($url);
                self::fail($url . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheConfigurationHoldsTheAddressAndIsWrittenOnlyOnce(): void
    {
        $paths = new InstallPaths($this->root);
        $file = new ConfigFile($paths);
        $text = $file->build(['database' => 'sqlite', 'dbname' => $paths->sqliteFile(), 'dbhost' => '', 'url' => 'https://example.com/']);
        $read = parse_ini_string($text);
        self::assertIsArray($read);
        self::assertSame('https://example.com/', $read['url']);
        self::assertSame('sqlite', $read['database']);
        self::assertSame($this->root . '/Uploads/', $read['uploadpath']);

        self::assertTrue($file->writeOnce($text));
        self::assertSame($text, file_get_contents($paths->configFile()));
        self::assertFalse($file->writeOnce('database = "other"'));
        self::assertSame($text, file_get_contents($paths->configFile()));
    }

    public function testNoAddressLeavesTheLineOut(): void
    {
        $text = (new ConfigFile(new InstallPaths($this->root)))->build(['database' => 'sqlite', 'dbname' => '/x/idno.sqlite']);

        self::assertStringNotContainsString('url', $text);
        self::assertStringNotContainsString('%', $text);
    }

    public function testAConfigurationFileInAnyPlaceTheSiteReadsStopsTheWrite(): void
    {
        $file = new ConfigFile(new InstallPaths($this->root));
        foreach (['config.ini', 'config.json', 'configuration/config.json'] as $existing) {
            file_put_contents($this->root . '/' . $existing, '');
            self::assertFalse($file->writeOnce("database = 'sqlite'\n"), $existing);
            self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
            unlink($this->root . '/' . $existing);
        }
    }

    public function testAnUnwritableConfigurationFolderIsAnErrorThatNamesNoPath(): void
    {
        chmod($this->root . '/configuration', 0555);
        try {
            (new ConfigFile(new InstallPaths($this->root)))->writeOnce('x');
            self::fail('Nothing was thrown.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
        } finally {
            chmod($this->root . '/configuration', 0755);
        }
    }

    public function testTheSetupTokenIsKeptOnlyAsAHash(): void
    {
        $paths = new InstallPaths($this->root);
        $setup = new SetupToken($paths);
        self::assertFalse($setup->pending());
        self::assertFalse($setup->matches(str_repeat('a', 64)));

        $token = $setup->issue('Ada\'s notes');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        self::assertTrue($setup->pending());
        self::assertTrue($setup->matches($token));
        self::assertSame('Ada\'s notes', $setup->title());
        self::assertStringNotContainsString($token, (string) file_get_contents($paths->marker()));
        foreach ([strtoupper($token), substr($token, 0, 63), $token . "\n", '', null, [$token], str_repeat('0', 64)] as $wrong) {
            self::assertFalse($setup->matches($wrong));
        }

        $second = $setup->issue('Other');
        self::assertFalse($setup->matches($token));
        self::assertTrue($setup->matches($second));
        self::assertNotSame(SetupToken::fieldFor($token), SetupToken::fieldFor($second));
        self::assertNotSame($second, SetupToken::fieldFor($second));

        $setup->clear();
        self::assertFalse($setup->pending());
        self::assertFalse($setup->matches($second));
        self::assertSame('', $setup->title());
    }

    public function testACorruptMarkerMatchesNothing(): void
    {
        $paths = new InstallPaths($this->root);
        file_put_contents($paths->marker(), 'not json');
        $setup = new SetupToken($paths);

        self::assertTrue($setup->pending());
        self::assertFalse($setup->matches(str_repeat('a', 64)));
    }

    public function testANewSqliteDatabaseIsEmptyAndAMigratedOneIsAnIdnoSite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed.');
        }
        $choice = new DatabaseChoice(new InstallPaths($this->root));
        $params = $choice->params('sqlite', ['path' => '']);
        self::assertSame(DatabaseFinding::EMPTY, $choice->inspect($params)->kind);
        self::assertFalse($choice->inspect($params)->holdsASite());

        $connection = \Idno\Storage\ConnectionFactory::forConfig($params, [], $this->root);
        (new \Idno\Storage\SchemaMigrator($connection))->migrate();
        $connection->close();
        $finding = $choice->inspect($params);
        self::assertSame(DatabaseFinding::IDNO, $finding->kind);
        self::assertTrue($finding->holdsASite());
    }

    public function testAConnectionProblemIsOneOfAFixedSetOfSentences(): void
    {
        $failure = static function (int $code): \Throwable {
            $e = new \PDOException("SQLSTATE[HY000] [$code] Access denied for user 'secretuser'@'10.0.0.7'");
            $e->errorInfo = ['HY000', $code, 'x'];

            return new \RuntimeException('wrapped', 0, $e);
        };
        foreach ([1045 => 'user name or the password', 1049 => 'no database with that name', 2002 => 'could not reach', 1044 => 'not allowed', 9999 => 'error 9999'] as $code => $expected) {
            $message = DatabaseChoice::connectionProblem($failure($code));
            self::assertStringContainsString($expected, $message);
            self::assertStringNotContainsString('secretuser', $message);
            self::assertStringNotContainsString('10.0.0.7', $message);
        }
    }

    public function testTheOwnershipNotesNameTheFilesTheDirectoryAndTheWebServersUser(): void
    {
        $text = implode("\n", DatabaseChoice::sqliteOwnershipNotes('/srv/idno/data/idno.sqlite'));

        self::assertStringContainsString('/srv/idno/data/idno.sqlite', $text);
        self::assertStringContainsString('chown -R www-data /srv/idno/data', $text);
        self::assertStringContainsString('.upgrade.lock', $text);
        self::assertStringContainsString('-wal', $text);
        self::assertStringContainsString('as that user', $text);
    }
}
