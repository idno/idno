<?php

declare(strict_types=1);

namespace Tests\Support;

use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The controller actions routed by the legacy route table (`#[RoutedByLegacyTable]`) and the legacy classes that name them (the public constant `ACTION`),
 * found by reflection over the code, for the tests that hold them to their rules (`LegacyRoutedPagesTest`, `RoutedPagesListTest`).
 */
final class RoutedActions
{
    /**
     * Every action that carries the attribute, in `Idno/`, `IdnoPlugins/` and `Tests/Fixtures/`.
     *
     * @return array<string, array{action: string, hasRoute: bool, hasPage: bool, shell: bool}> route name => the action as `Class::method`, whether it also has a `#[Route]` and a `#[SitePage]`, and whether the page is a kept shell's
     */
    public static function actions(): array
    {
        $found = [];
        foreach (self::classesIn(['Idno', 'IdnoPlugins', 'Tests/Fixtures'], 'RoutedByLegacyTable') as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(RoutedByLegacyTable::class) as $attribute) {
                    $page = SitePage::of([$class, $method->getName()]);
                    $found[$attribute->newInstance()->route] = [
                        'action' => $class . '::' . $method->getName(),
                        'hasRoute' => $method->getAttributes(Route::class) !== [] || (new \ReflectionClass($class))->getAttributes(Route::class) !== [],
                        'hasPage' => $page !== null,
                        'shell' => $page !== null && $page->shell,
                    ];
                }
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Every class that declares a non-null `ACTION` of its own (a subclass that inherits one is not one), under the directories of pages, placeholders and fixtures.
     *
     * @return array<class-string, array{0: string, 1: string}> class => the action it names
     */
    public static function namers(): array
    {
        $found = [];
        foreach (self::classesIn(['Idno/Pages', 'Idno/Legacy/Placeholder', 'Tests/Fixtures', 'IdnoPlugins'], 'ACTION') as $class) {
            $reflection = new \ReflectionClass($class);
            $constant = $reflection->getReflectionConstant('ACTION');
            if ($constant === false || $constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            $value = $constant->getValue();
            if (is_array($value) && count($value) === 2) {
                $found[$class] = [(string) $value[0], (string) $value[1]];
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * What the list file says is wrong: an action that is not listed, a listed one that is gone or whose class differs.
     *
     * @param array<string, array{action: string, class: string}> $listed
     *
     * @return list<string>
     */
    public static function listViolations(array $listed): array
    {
        $actual = [];
        $namers = self::namers();
        foreach (self::actions() as $route => $action) {
            $names = [];
            foreach ($namers as $class => [$controller, $method]) {
                if ($controller . '::' . $method === $action['action']) {
                    $names[] = $class;
                }
            }
            $actual[$route] = ['action' => $action['action'], 'class' => $names === [] ? '(none)' : implode(', ', $names)];
        }
        $found = [];
        foreach ($actual as $route => $entry) {
            if (!isset($listed[$route])) {
                $found[] = $route . ' (' . $entry['action'] . ') is routed by the legacy table and is not listed';
            } elseif ($listed[$route] !== $entry) {
                $found[] = $route . ' is listed as ' . json_encode($listed[$route]) . ' and is ' . json_encode($entry);
            }
        }
        foreach ($listed as $route => $entry) {
            if (!isset($actual[$route])) {
                $found[] = $route . ' is listed and no action carries it';
            }
        }

        return $found;
    }

    /**
     * @param list<string> $directories under the project
     * @param string       $needle      a word the file must hold to be loaded
     *
     * @return list<class-string>
     */
    private static function classesIn(array $directories, string $needle): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach ($directories as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || !ctype_upper($file->getFilename()[0]) || $file->getFilename() === 'RoutedByLegacyTable.php') {
                    continue;
                }
                if (!str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                    continue;
                }
                $class = str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
