<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Tests\Support\RoutedActions;

/**
 * An action routed by the legacy route table has no `#[Route]`, so `debug:router` does not show it: `fixtures/routed-pages.php` lists every one with the
 * class that names it, and this holds the list to what reflection finds in `Idno/`, `IdnoPlugins/` and `Tests/Fixtures/`.
 */
final class RoutedPagesListTest extends TestCase
{
    /**
     * @return array<string, array{action: string, class: string}>
     */
    private static function listed(): array
    {
        return require __DIR__ . '/fixtures/routed-pages.php';
    }

    public function testEveryRoutedActionIsListed(): void
    {
        $this->assertSame([], RoutedActions::listViolations(self::listed()));
        $this->assertNotSame([], self::listed(), 'the list holds the fixtures at least');
    }

    public function testAnUnlistedOrMissingOneFails(): void
    {
        $listed = self::listed();

        $unlisted = $listed;
        unset($unlisted['idno_test_routed']);
        $this->assertCount(1, RoutedActions::listViolations($unlisted));
        $this->assertStringContainsString('is not listed', RoutedActions::listViolations($unlisted)[0]);

        $gone = $listed + ['idno_gone' => ['action' => 'No\Such::action', 'class' => 'No\Such']];
        $this->assertStringContainsString('no action carries it', RoutedActions::listViolations($gone)[0]);

        $renamed = $listed;
        $renamed['idno_test_routed']['class'] = 'Tests\Fixtures\SomethingElse';
        $this->assertStringContainsString('is listed as', RoutedActions::listViolations($renamed)[0]);

        $this->assertNotSame([], RoutedActions::listViolations([]), 'an empty list fails');
    }
}
