<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Install\EarlierSite;
use Idno\Install\InstallPaths;
use PHPUnit\Framework\TestCase;

/**
 * Whether a project root shows what an earlier site left: the one rule the web installer asks before it lets anyone set up a
 * site without the code from the server. A checkout as shipped shows nothing.
 */
final class EarlierSiteTest extends TestCase
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

    private function signs(): array
    {
        return EarlierSite::signs(new InstallPaths($this->root));
    }

    private function put(string $relative, string $content = 'x'): void
    {
        if (!is_dir(dirname($this->root . '/' . $relative))) {
            mkdir(dirname($this->root . '/' . $relative), 0755, true);
        }
        file_put_contents($this->root . '/' . $relative, $content);
    }

    public function testAShippedCheckoutShowsNothing(): void
    {
        $this->put('configuration/.htaccess', "deny from all\n");
        $this->put('data/.htaccess', "Require all denied\n");
        $this->put('data/readme.txt', 'readme');
        $this->put('Uploads/readme.txt', 'readme');
        $this->put('Uploads/.htaccess', "Require all denied\n");
        mkdir($this->root . '/Uploads/empty-host/a', 0755, true);
        $this->put('configuration/install-code', str_repeat('a', 32) . "\n");
        $this->put('data/idno.sqlite', '');

        self::assertSame([], $this->signs(), 'the tracked files, an empty folder, the code itself and an empty database file are no earlier site');
    }

    public function testADatabaseFileThatIsNotEmptyIsASign(): void
    {
        $this->put('data/idno.sqlite', 'SQLite format 3');
        self::assertSame([EarlierSite::DATABASE], $this->signs());
    }

    public function testAnySqliteFileUnderDataIsASign(): void
    {
        $this->put('data/backups/old.sqlite', 'SQLite format 3');
        self::assertSame([EarlierSite::DATABASE], $this->signs());
        $this->removeRoot();
        $this->makeRoot();
        $this->put('data/Other.SQLITE', 'SQLite format 3');
        self::assertSame([EarlierSite::DATABASE], $this->signs(), 'in any letter case');
    }

    public function testAStoredUploadIsASign(): void
    {
        $this->put('Uploads/example.com/a/b/c/d/0123456789abcdef0123456789abcdef.file', 'bytes');
        self::assertSame([EarlierSite::UPLOADS], $this->signs());
    }

    public function testAnyOtherFileInConfigurationIsASign(): void
    {
        foreach (['config.ini.bak', 'config.json', 'setup-pending', 'example.com.ini'] as $name) {
            $this->removeRoot();
            $this->makeRoot();
            $this->put('configuration/' . $name);
            self::assertSame([EarlierSite::CONFIGURATION], $this->signs(), $name);
        }
    }

    public function testEverySignIsReportedOnceInAFixedOrder(): void
    {
        $this->put('configuration/config.ini.bak');
        $this->put('Uploads/a.file');
        $this->put('Uploads/b/c.data');
        $this->put('data/idno.sqlite', 'x');
        $this->put('data/two.sqlite', 'x');

        self::assertSame([EarlierSite::DATABASE, EarlierSite::UPLOADS, EarlierSite::CONFIGURATION], $this->signs());
        self::assertStringContainsString('data', EarlierSite::describe($this->signs()));
        self::assertStringNotContainsString($this->root, EarlierSite::describe($this->signs()), 'named from the project, never by the server\'s path');
    }

    public function testFoldersThatDoNotExistShowNothing(): void
    {
        rmdir($this->root . '/Uploads');
        rmdir($this->root . '/configuration');
        rmdir($this->root . '/data');

        self::assertSame([], $this->signs());
    }

    public function testAnOperatingSystemsStrayFileIsNoSign(): void
    {
        foreach (['.DS_Store', '.gitkeep'] as $name) {
            foreach (['configuration/', 'Uploads/', 'Uploads/example.com/a/', 'data/'] as $folder) {
                $this->put($folder . $name);
            }
        }

        self::assertSame([], $this->signs());
    }

    public function testTheShippedNamesIgnoreCase(): void
    {
        $this->put('Uploads/README.TXT');
        $this->put('Uploads/.HTACCESS');
        $this->put('configuration/.HTACCESS');
        $this->put('configuration/INSTALL-CODE');
        self::assertSame([], $this->signs());

        $this->put('Uploads/another.txt');
        self::assertSame([EarlierSite::UPLOADS], $this->signs());
    }
}
