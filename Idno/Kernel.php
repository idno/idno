<?php

declare(strict_types=1);

namespace Idno;

use Idno\Kernel\CacheDirResolver;
use Idno\Kernel\CodeFingerprint;
use Idno\Kernel\DoctrineTrimPass;
use Idno\Kernel\MessengerTrimPass;
use Idno\Kernel\TwigTrimPass;
use Idno\Legacy\LegacyBootstrap;
use Idno\Upgrade\SchemaGate;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradePage;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?string $baseDir = null;
    private ?string $cacheDir = null;
    private bool $legacyBooted = false;
    private bool $stepRequest = false;

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

    /**
     * Whether boot() would boot the legacy application (false for a kernel built with
     * bootLegacy: false, which never asks the schema gate either).
     */
    public function bootsLegacy(): bool
    {
        return $this->bootLegacy;
    }

    /**
     * Whether boot() has booted the legacy application: false before boot(), in a kernel built
     * with bootLegacy: false, and while the schema gate is closed.
     */
    public function legacyBooted(): bool
    {
        return $this->legacyBooted;
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Removes the console commands and the request listener DoctrineBundle registers that Idno
     * does not use (see DoctrineTrimPass), and the Messenger command that creates tables (see
     * MessengerTrimPass), and what TwigBundle registers that Idno does not use (see TwigTrimPass).
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineTrimPass());
        $container->addCompilerPass(new MessengerTrimPass());
        // After the bundle's extension pass (priority 10), which tags the extensions this pass removes, and before the pass that
        // collects the loaders and the one that collects the extensions (priority 0)
        $container->addCompilerPass(new TwigTrimPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 9);
    }

    /**
     * Boots the container, then, in a kernel that boots the legacy application: refuses a site with
     * no configuration (NotInstalledException, before anything connects), asks the schema gate, and
     * boots the legacy application only when the gate is open. With the gate closed (the database
     * needs creating or upgrading) the legacy application is NOT booted: no Idno::site(), no
     * session; web requests get the upgrade page from Idno\Upgrade\UpgradeGateListener, and the
     * console runs without the legacy commands. Once a kernel has booted the legacy application in
     * this process, the gate is not asked again.
     */
    public function boot(): void
    {
        parent::boot();

        if (!$this->bootLegacy) {
            return;
        }
        $container = $this->getContainer();
        $bootstrap = $container->get(LegacyBootstrap::class);
        $bootstrap->ensureInstalled();
        if (!$bootstrap->bootedInThisProcess()) {
            $gate = $container->get(SchemaGate::class);
            // Closed: the legacy application must not boot. Unreachable: it boots to show its
            // database error page, except for an upgrade step, which answers for itself (the
            // connection failure is final, see ConnectFailureIsFinal).
            if ($gate->closed() || ($this->stepRequest && $gate->status() === SchemaStatus::Unreachable)) {
                $this->legacyBooted = false;

                return;
            }
        }
        $bootstrap->boot();
        $this->legacyBooted = true;
    }

    /**
     * Notes whether the main request is an upgrade step (UpgradePage::isStepRequest()) before the
     * kernel boots: boot() does not boot the legacy application for it while the database is
     * unreachable, so the step can answer 503 itself.
     */
    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        if ($type === HttpKernelInterface::MAIN_REQUEST) {
            $this->stepRequest = UpgradePage::isStepRequest($request);
        }

        return parent::handle($request, $type, $catch);
    }

    public function shutdown(): void
    {
        parent::shutdown();
        $this->legacyBooted = false;
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

        return $this->baseDir = CacheDirResolver::fromEnvironment($this->getProjectDir());
    }
}
