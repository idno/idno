<?php

declare(strict_types=1);

namespace Idno;

use Idno\Kernel\CacheDirResolver;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?string $baseDir = null;

    public function __construct(string $environment, bool $debug, private readonly bool $bootLegacy = true)
    {
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->baseDir() . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->baseDir() . '/log';
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
