<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

final class KernelResourceTrackingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-test-tracking-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var', 0777, true);
        // Same layout as the repository: the parent directories exist, the guarded ones do not.
        mkdir($this->root . '/Idno', 0777, true);
        mkdir($this->root . '/Tests', 0777, true);
        $this->copyDir(dirname(__DIR__, 2) . '/config', $this->root . '/config');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'reference.php') {
                continue;
            }
            is_dir("$from/$entry") ? $this->copyDir("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function kernel(): Kernel
    {
        $root = $this->root;

        return new class ('test', true, false, $root) extends Kernel {
            public function __construct(string $environment, bool $debug, bool $bootLegacy, private readonly string $root)
            {
                parent::__construct($environment, $debug, $bootLegacy);
            }

            public function getProjectDir(): string
            {
                return $this->root;
            }

            public function getCacheDir(): string
            {
                return $this->root . '/var/cache/test';
            }

            public function getLogDir(): string
            {
                return $this->root . '/var/log';
            }
        };
    }

    public function testRoutesDirectoryCreatedAfterFirstBootIsPickedUp(): void
    {
        $first = $this->kernel();
        $first->boot();
        try {
            $first->getContainer()->get('router')->match('/kt-fixture');
            $this->fail('The route must not exist before the directory is created');
        } catch (ResourceNotFoundException) {
            // expected; this also writes the compiled URL matcher to the cache
        }
        $first->shutdown();

        $class = 'KtFixtureController' . bin2hex(random_bytes(4));
        mkdir($this->root . '/Tests/Fixtures', 0777, true);
        $file = $this->root . '/Tests/Fixtures/' . $class . '.php';
        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class $class
{
    #[Route('/kt-fixture', name: 'kt_fixture')]
    public function __invoke(): Response
    {
        return new Response('ok');
    }
}
PHP);
        require_once $file;

        $second = $this->kernel();
        $second->boot();
        $this->assertSame('kt_fixture', $second->getContainer()->get('router')->match('/kt-fixture')['_route']);
        $second->shutdown();
    }

    public function testServicesDirectoryCreatedAfterFirstBootIsPickedUp(): void
    {
        $first = $this->kernel();
        $first->boot();
        $first->shutdown();

        $class = 'KtService' . bin2hex(random_bytes(4));
        mkdir($this->root . '/Idno/Config', 0777, true);
        $file = $this->root . '/Idno/Config/' . $class . '.php';
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Idno\\Config;\n\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Autoconfigure;\n\n#[Autoconfigure(public: true)]\nfinal class $class\n{\n}\n");
        require_once $file;

        $second = $this->kernel();
        $second->boot();
        $this->assertTrue($second->getContainer()->has('Idno\\Config\\' . $class));
        $second->shutdown();
    }
}
