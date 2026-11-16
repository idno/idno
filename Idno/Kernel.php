<?php

declare(strict_types=1);

namespace Idno;

use Idno\Kernel\CacheDirResolver;
use Idno\Kernel\CodeFingerprint;
use Idno\Kernel\DoctrineTrimPass;
use Idno\Legacy\LegacyBootstrap;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?string $baseDir = null;
    private ?string $cacheDir = null;

    public function __construct(string $environment, bool $debug, private readonly bool $bootLegacy = true)
    {
        parent::__construct($environment, $debug);
    }

    /**
     * The kernel the entry points (index.php, Idno/start.php, bin/console) run: environment
     * from APP_ENV (default "prod"), debug from APP_DEBUG ("1", "true", "on", "yes" are
     * true; anything else, or unset, is false). Both are read from $_SERVER. With $bootLegacy
     * false the kernel boots without the legacy application (bin/console, for idno:upgrade).
     */
    public static function fromGlobals(bool $bootLegacy = true): self
    {
        $environment = $_SERVER['APP_ENV'] ?? '';
        $debug = $_SERVER['APP_DEBUG'] ?? false;

        return new self(
            is_string($environment) && $environment !== '' ? $environment : 'prod',
            filter_var($debug, FILTER_VALIDATE_BOOLEAN),
            $bootLegacy
        );
    }

    /**
     * Whether boot() boots the legacy application too.
     */
    public function bootsLegacy(): bool
    {
        return $this->bootLegacy;
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Removes the console commands and the request listener DoctrineBundle registers that Idno
     * does not use (see DoctrineTrimPass).
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineTrimPass());
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->bootLegacy) {
            $this->getContainer()->get(LegacyBootstrap::class)->boot();
        }
    }

    /**
     * "<cache base>/<env>/<build>-<key>". The key hashes the code fingerprint of the
     * container's input files together with the project directory's real path, so that
     * overwriting the files, or changing code without changing the build number, never
     * reuses a container compiled from different code, and two installs sharing one cache
     * base (IDNO_CACHE_DIR) never share a container: it holds the project directory's
     * absolute path, and so reads that install's config.ini.
     */
    public function getCacheDir(): string
    {
        if ($this->cacheDir !== null) {
            return $this->cacheDir;
        }

        $projectDir = $this->getProjectDir();
        $realProjectDir = realpath($projectDir);
        $key = substr(sha1(CodeFingerprint::compute($projectDir) . "\0" . ($realProjectDir !== false ? $realProjectDir : $projectDir)), 0, 12);

        return $this->cacheDir = $this->baseDir() . '/' . $this->environment . '/' . $this->buildNumber() . '-' . $key;
    }

    public function getLogDir(): string
    {
        return $this->baseDir() . '/log';
    }

    /**
     * The "build" value from version.idno, or "dev" if it cannot be read.
     */
    private function buildNumber(): string
    {
        $file = $this->getProjectDir() . '/version.idno';
        $version = is_file($file) ? @parse_ini_file($file) : false;
        $build = is_array($version) ? ($version['build'] ?? '') : '';

        return is_scalar($build) && (string) $build !== '' ? (string) $build : 'dev';
    }

    private function baseDir(): string
    {
        if ($this->baseDir !== null) {
            return $this->baseDir;
        }

        $override = $_SERVER['IDNO_CACHE_DIR'] ?? $_ENV['IDNO_CACHE_DIR'] ?? getenv('IDNO_CACHE_DIR');

        return $this->baseDir = CacheDirResolver::resolve($this->getProjectDir(), is_string($override) ? $override : null);
    }
}
