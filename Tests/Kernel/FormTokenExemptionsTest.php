<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;

#[SitePage('Fixture\ExemptionsClass', methods: ['GET', 'POST'], formTokenReplacedBy: 'a class-wide reason')]
final class FormTokenExemptionsClassController
{
    #[\Symfony\Component\Routing\Attribute\Route('/a')]
    public function first(): void
    {
    }

    #[\Symfony\Component\Routing\Attribute\Route('/b')]
    #[SitePage('Fixture\ExemptionsOther')]
    public function second(): void
    {
    }

    public function notAnAction(): void
    {
    }
}

#[SitePage('Fixture\ExemptionsInvokable', methods: ['GET', 'POST'], formTokenReplacedBy: 'an invokable controller')]
#[\Symfony\Component\Routing\Attribute\Route('/invokable')]
final class FormTokenExemptionsInvokableController
{
    public function __invoke(): void
    {
    }
}

/** A page no route reaches (routed by a table at run time): the exemption is the class's, and no action is named. */
#[SitePage('Fixture\ExemptionsUnrouted', methods: ['GET', 'POST'], formTokenReplacedBy: 'a page with no route')]
final class FormTokenExemptionsUnroutedController
{
    public function show(): void
    {
    }
}

/**
 * A site page that takes state-changing requests without the form token (`#[SitePage(formTokenReplacedBy: ...)]`) acts as nobody and
 * authenticates the request itself, so each one is a decision to review. Every such action of the controllers of the site
 * (`Idno/Http/Controller/`) and of the test fixtures is listed, with its reason, in `fixtures/form-token-exemptions.php`.
 */
final class FormTokenExemptionsTest extends TestCase
{
    /**
     * Every exemption, however a page is reached: the actions of the classes of the code (a class that names `formTokenReplacedBy`, under `Idno/`,
     * `IdnoPlugins/` and `Tests/Fixtures/`), those of the routes of the container (every route's controller, a method's attribute or the class's,
     * an invokable controller), and the action a kept shell names (`PageShell::ACTION`).
     *
     * @return array<string, string> `Class::method` => the sentence
     */
    private static function found(): array
    {
        $found = [];
        $root = dirname(__DIR__, 2);
        foreach (['Idno', 'IdnoPlugins', 'Tests/Fixtures'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || !ctype_upper($file->getFilename()[0])) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                // only a file that names the argument (an exemption), or a shell (which names its action), is loaded
                if (!str_contains($source, 'formTokenReplacedBy') && !str_contains($source, 'extends PageShell') && !str_contains($source, 'ACTION')) {
                    continue;
                }
                $class = str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
                if (class_exists($class)) {
                    $found += self::exemptionsOf($class) + self::shellExemptionOf($class);
                }
            }
        }

        return $found + self::routedExemptions();
    }

    /**
     * @return array<string, string> the exemption of the action a kept shell names, by the action's `Class::method`
     */
    public static function shellExemptionOf(string $class): array
    {
        if (!is_subclass_of($class, \Idno\Legacy\PageShell::class) || !is_array($class::ACTION)) {
            return [];
        }
        $page = SitePage::of($class::ACTION);

        return $page?->formTokenReplacedBy !== null ? [$class::ACTION[0] . '::' . $class::ACTION[1] => $page->formTokenReplacedBy] : [];
    }

    /**
     * @return array<string, string> the exemptions of the controllers of the container's route collection
     */
    private static function routedExemptions(): array
    {
        $kernel = new \Idno\Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        try {
            $found = [];
            foreach ($kernel->getContainer()->get('router')->getRouteCollection() as $route) {
                $controller = $route->getDefault('_controller');
                if (!is_string($controller) && !is_array($controller)) {
                    continue;
                }
                $page = SitePage::of($controller);
                if ($page?->formTokenReplacedBy === null) {
                    continue;
                }
                [$class, $method] = is_array($controller)
                    ? [is_object($controller[0]) ? $controller[0]::class : $controller[0], $controller[1]]
                    : (str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke']);
                $found[$class . '::' . $method] = $page->formTokenReplacedBy;
            }

            return $found;
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * The exempt actions of a class: a method with a `#[SitePage]` of its own that says so; the class's, for each method that is an action (it carries a
     * route), else for the invokable controller of a class-level route (`__invoke`), else, for a page no route reaches, for the class as a whole (`Class::*`).
     *
     * @return array<string, string>
     */
    public static function exemptionsOf(string $class): array
    {
        $found = [];
        $reflection = new \ReflectionClass($class);
        $ofClass = ($reflection->getAttributes(SitePage::class)[0] ?? null)?->newInstance();
        $classExempt = $ofClass?->formTokenReplacedBy;
        $routed = false;
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
                continue;
            }
            $own = ($method->getAttributes(SitePage::class)[0] ?? null)?->newInstance();
            $hasRoute = $method->getAttributes(\Symfony\Component\Routing\Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
            $routed = $routed || $hasRoute;
            // a method's attribute wins over the class's; the class's reaches the methods that are actions
            $sentence = $own !== null ? $own->formTokenReplacedBy : ($hasRoute ? $classExempt : null);
            if ($sentence !== null) {
                $found[$class . '::' . $method->getName()] = $sentence;
            }
        }
        if ($classExempt !== null && !$routed) {
            $invokable = $reflection->hasMethod('__invoke') && $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
            $found[$class . ($invokable ? '::__invoke' : '::*')] = $classExempt;
        }

        return $found;
    }

    /**
     * @param array<string, string> $found
     * @param array<string, string> $listed
     *
     * @return list<string> what differs
     */
    private static function differences(array $found, array $listed): array
    {
        $differences = [];
        foreach ($found as $action => $sentence) {
            if (!array_key_exists($action, $listed)) {
                $differences[] = $action . ' takes state-changing requests without the form token and is not listed in Tests/Kernel/fixtures/form-token-exemptions.php';
            } elseif ($listed[$action] !== $sentence) {
                $differences[] = $action . ' says ' . json_encode($sentence) . ' where the list says ' . json_encode($listed[$action]);
            }
        }
        foreach ($listed as $action => $sentence) {
            if (!array_key_exists($action, $found)) {
                $differences[] = $action . ' is listed but takes no such requests any more';
            }
        }

        return $differences;
    }

    /**
     * @return array<string, string>
     */
    private static function listed(): array
    {
        return require __DIR__ . '/fixtures/form-token-exemptions.php';
    }

    public function testEveryExemptionIsListedWithItsReason(): void
    {
        $found = self::found();

        $this->assertNotSame([], $found, 'the scan saw the fixture\'s exemption');
        $this->assertSame([], self::differences($found, self::listed()));
    }

    public function testAnUnlistedExemptionFails(): void
    {
        $found = self::found();
        $listed = self::listed();
        $one = array_key_first($listed);
        unset($listed[$one]);

        $differences = self::differences($found, $listed);

        $this->assertCount(1, $differences);
        $this->assertStringContainsString($one, $differences[0]);
        $this->assertStringContainsString('not listed', $differences[0]);
    }

    public function testAListedExemptionThatIsGoneFails(): void
    {
        $listed = self::listed() + ['Idno\Http\Controller\Gone::action' => 'a sentence'];

        $differences = self::differences(self::found(), $listed);

        $this->assertCount(1, $differences);
        $this->assertStringContainsString('Gone::action', $differences[0]);
        $this->assertStringContainsString('listed but', $differences[0]);
    }

    public function testAChangedSentenceFails(): void
    {
        $listed = array_map(static fn (string $sentence): string => $sentence . ' (changed)', self::listed());

        $this->assertNotSame([], self::differences(self::found(), $listed));
    }

    public function testAClassWideExemptionReachesItsActionsExceptThoseWithAnAttributeOfTheirOwn(): void
    {
        $this->assertSame(
            [FormTokenExemptionsClassController::class . '::first' => 'a class-wide reason'],
            self::exemptionsOf(FormTokenExemptionsClassController::class)
        );
    }

    public function testAnInvokableControllerOfAClassWideRouteIsFound(): void
    {
        $this->assertSame(
            [FormTokenExemptionsInvokableController::class . '::__invoke' => 'an invokable controller'],
            self::exemptionsOf(FormTokenExemptionsInvokableController::class)
        );
    }

    public function testAnExemptPageNoRouteReachesIsFoundAndNeedsItsReasonListed(): void
    {
        $found = self::exemptionsOf(FormTokenExemptionsUnroutedController::class);

        $this->assertSame([FormTokenExemptionsUnroutedController::class . '::*' => 'a page with no route'], $found);
        $this->assertCount(1, self::differences($found, []), 'not listed: it fails');
        $this->assertSame([], self::differences($found, $found), 'listed with its reason: it passes');
    }

    public function testTheRealRouteCollectionsExemptionsAreFound(): void
    {
        $routed = self::routedExemptions();

        $this->assertSame('a test of the exemption', $routed['Tests\Fixtures\SitePageFixtureController::tokenless'] ?? null);
        $this->assertSame('a test of a shell whose page takes no form token', $routed['Tests\Fixtures\FixtureShellController::receive'] ?? null);
    }

    public function testAShellsExemptActionIsFound(): void
    {
        $this->assertSame(
            ['Tests\Fixtures\FixtureShellController::receive' => 'a test of a shell whose page takes no form token'],
            self::shellExemptionOf(\Tests\Fixtures\FixtureTokenlessShell::class)
        );
        $this->assertSame([], self::shellExemptionOf(\Tests\Fixtures\FixtureShell::class), 'a shell whose action asks for the token');
    }
}
