<?php

declare(strict_types=1);

namespace Idno;

use Idno\Kernel\CacheDirResolver;
use Idno\Kernel\CodeFingerprint;
use Idno\Legacy\LegacyBootstrap;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
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
     * true; anything else, or unset, is false). Both are read from $_SERVER.
     */
    public static function fromGlobals(): self
    {
        $environment = $_SERVER['APP_ENV'] ?? '';
        $debug = $_SERVER['APP_DEBUG'] ?? false;

        return new self(
            is_string($environment) && $environment !== '' ? $environment : 'prod',
            filter_var($debug, FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->bootLegacy) {
            $this->getContainer()->get(LegacyBootstrap::class)->boot();
        }
    }

    /**
     * Includes the Idno build number and a fingerprint of the container's input files, so
     * that overwriting the files, or changing code without changing the build number,
     * never reuses a container compiled from different code.
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir ??= $this->baseDir() . '/' . $this->environment . '/' . $this->buildNumber()
            . '-' . CodeFingerprint::compute($this->getProjectDir());
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
