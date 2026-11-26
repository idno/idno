<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\Controller\HomepageController;
use Idno\Http\Page\SitePage;
use Idno\Legacy\PageShell;
use Idno\Pages\Homepage;
use PHPUnit\Framework\TestCase;

/**
 * A kept shell names the controller action that serves its page (`PageShell::ACTION`) and takes that action's `#[SitePage]`, so a request
 * served through the shell is the page its controller serves. Every shell of core and of the fixtures is held to it here, and so is every
 * action that says it keeps a shell.
 */
final class ShellActionsTest extends TestCase
{
    /**
     * @return list<class-string<PageShell>> the shells under Idno/Pages and Tests/Fixtures (subclasses of a shell included)
     */
    private static function shells(): array
    {
        $shells = [];
        foreach (self::classesIn('Idno/Pages', 'Idno\\Pages') as $class) {
            if (is_subclass_of($class, PageShell::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $shells[] = $class;
            }
        }
        foreach (self::classesIn('Tests/Fixtures', 'Tests\\Fixtures') as $class) {
            if (is_subclass_of($class, PageShell::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $shells[] = $class;
            }
        }
        sort($shells);

        return $shells;
    }

    /**
     * @return list<class-string>
     */
    private static function classesIn(string $directory, string $namespace): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2) . '/' . $directory;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || !ctype_upper($file->getBasename()[0])) {
                // (a lower case file is a script, which loading would run)
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = $namespace . '\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array<string, array{0: class-string<PageShell>}>
     */
    public static function shellCases(): array
    {
        $cases = [];
        foreach (self::shells() as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    public function testTheScanFindsTheShells(): void
    {
        $shells = self::shells();

        $this->assertContains(Homepage::class, $shells);
        $this->assertContains(\Tests\Fixtures\FixtureShell::class, $shells);
        $this->assertContains(\Tests\Fixtures\SubclassedFixtureShell::class, $shells, 'a subclass of a shell is held to the rule too');
    }

    /**
     * @dataProvider shellCases
     *
     * @param class-string<PageShell> $class
     */
    public function testEveryShellNamesAnActionThatExists(string $class): void
    {
        $action = self::actionOf($class);

        $this->assertIsArray($action, $class . ' names the action that serves its page');
        $this->assertCount(2, $action);
        $this->assertTrue(class_exists($action[0]), $class . ': ' . $action[0] . ' is a class');
        $this->assertTrue(method_exists($action[0], $action[1]) && (new \ReflectionMethod($action[0], $action[1]))->isPublic(), $class . ': ' . $action[0] . '::' . $action[1] . ' is a public method');
        $this->assertNotNull(SitePage::of($action), $class . ': the action carries #[SitePage]');
    }

    /**
     * @dataProvider shellCases
     *
     * @param class-string<PageShell> $class
     */
    public function testTheActionCarriesShellTrueAndTheShellsName(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $action = self::actionOf($class);
        $this->assertIsArray($action, $class . ' names its action');
        $page = SitePage::of($action);

        $this->assertNotNull($page);
        $this->assertTrue($page->shell, $class . ': the action says its class is kept as a shell');
        $this->assertSame($reflection->getReflectionConstant('SHELL')?->getValue(), $page->name, $class . ': and names the shell, not a subclass of it');
    }

    public function testEveryShellTrueActionIsNamedByItsShell(): void
    {
        $actions = [];
        foreach ([...self::classesIn('Idno/Http/Controller', 'Idno\\Http\\Controller'), ...self::classesIn('Tests/Fixtures', 'Tests\\Fixtures')] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $page = SitePage::of([$class, $method->getName()]);
                if ($page !== null && $page->shell && $method->getAttributes(SitePage::class) !== [] && is_subclass_of($page->name, PageShell::class)) {
                    $actions[$page->name][] = [$class, $method->getName(), $page];
                }
            }
        }

        $this->assertNotSame([], $actions);
        foreach ($actions as $shell => $served) {
            $named = self::actionOf($shell);
            $this->assertIsArray($named, $shell . ' names its action');
            $own = SitePage::of($named);
            $this->assertContains($named, array_map(static fn (array $action): array => [$action[0], $action[1]], $served), $shell . ': ' . implode('::', $named) . ' is one of the actions that keep it');
            foreach ($served as [$class, $method, $page]) {
                $this->assertSame(
                    self::flagsWithoutMethods($own),
                    self::flagsWithoutMethods($page),
                    $class . '::' . $method . ' keeps ' . $shell . ' with other flags than its action ' . implode('::', $named)
                );
            }
        }
    }

    private static function actionOf(string $class): mixed
    {
        return defined($class . '::ACTION') ? constant($class . '::ACTION') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function flagsWithoutMethods(SitePage $page): array
    {
        $flags = get_object_vars($page);
        unset($flags['methods']);

        return $flags;
    }

    public function testTheHomepageShellNamesTheHomeAction(): void
    {
        $this->assertSame([HomepageController::class, 'home'], self::actionOf(Homepage::class));
        // (the one that serves the root: `content()` agrees with it on every flag, but it is not what the shell names)
        $this->assertEquals(SitePage::of([HomepageController::class, 'content']), SitePage::of(self::actionOf(Homepage::class)));
    }
}
