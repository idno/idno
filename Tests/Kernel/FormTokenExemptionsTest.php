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

/**
 * A site page that takes state-changing requests without the form token (`#[SitePage(formTokenReplacedBy: ...)]`) acts as nobody and
 * authenticates the request itself, so each one is a decision to review. Every such action of the controllers of the site
 * (`Idno/Http/Controller/`) and of the test fixtures is listed, with its reason, in `fixtures/form-token-exemptions.php`.
 */
final class FormTokenExemptionsTest extends TestCase
{
    /**
     * @return array<string, string> `Class::method` => the sentence, for the exempt actions of the classes of these directories
     */
    private static function found(string ...$directories): array
    {
        $found = [];
        foreach ($directories as $directory) {
            $root = dirname(__DIR__, 2) . '/' . $directory;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || !ctype_upper($file->getFilename()[0])) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1, -4);
                $class = str_replace('/', '\\', $relative);
                if (class_exists($class)) {
                    $found += self::exemptionsOf($class);
                }
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * @return array<string, string>
     */
    public static function exemptionsOf(string $class): array
    {
        $found = [];
        $reflection = new \ReflectionClass($class);
        $ofClass = $reflection->getAttributes(SitePage::class)[0] ?? null;
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $own = $method->getAttributes(SitePage::class)[0] ?? null;
            // a method's attribute wins over the class's; the class's reaches the methods that are actions (they carry a route)
            $attribute = $own ?? ($ofClass !== null && $method->getAttributes(\Symfony\Component\Routing\Attribute\Route::class) !== [] ? $ofClass : null);
            $page = $attribute?->newInstance();
            if ($page !== null && $page->formTokenReplacedBy !== null) {
                $found[$class . '::' . $method->getName()] = $page->formTokenReplacedBy;
            }
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
        $found = self::found('Idno/Http/Controller', 'Tests/Fixtures');

        $this->assertNotSame([], $found, 'the scan saw the fixture\'s exemption');
        $this->assertSame([], self::differences($found, self::listed()));
    }

    public function testAnUnlistedExemptionFails(): void
    {
        $found = self::found('Idno/Http/Controller', 'Tests/Fixtures');
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

        $differences = self::differences(self::found('Idno/Http/Controller', 'Tests/Fixtures'), $listed);

        $this->assertCount(1, $differences);
        $this->assertStringContainsString('Gone::action', $differences[0]);
        $this->assertStringContainsString('listed but', $differences[0]);
    }

    public function testAChangedSentenceFails(): void
    {
        $listed = array_map(static fn (string $sentence): string => $sentence . ' (changed)', self::listed());

        $this->assertNotSame([], self::differences(self::found('Idno/Http/Controller', 'Tests/Fixtures'), $listed));
    }

    public function testAClassWideExemptionReachesItsActionsExceptThoseWithAnAttributeOfTheirOwn(): void
    {
        $this->assertSame(
            [FormTokenExemptionsClassController::class . '::first' => 'a class-wide reason'],
            self::exemptionsOf(FormTokenExemptionsClassController::class)
        );
    }
}
