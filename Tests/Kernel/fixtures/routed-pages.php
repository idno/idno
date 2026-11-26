<?php

declare(strict_types=1);

/**
 * Every controller action routed by the legacy route table (`#[Idno\Http\Page\RoutedByLegacyTable]`, no `#[Route]`), which `debug:router` does not list:
 * the route's name => the action as `Class::method` and the class whose public constant `ACTION` names it (a kept shell, or a placeholder of its own
 * under `Idno\Legacy\Placeholder\`). Read by `Tests\Kernel\RoutedPagesListTest`, which fails for an action that is not listed here and for a listed one that is
 * gone or is named by another class: adding a page routed this way is a change to this list, where a reviewer reads it.
 *
 * @return array<string, array{action: string, class: string}>
 */
return [
    'idno_permalink' => ['action' => 'Idno\Http\Controller\PermalinkController::view', 'class' => 'Idno\Pages\Entity\View'],
    'idno_permalink_other_layout' => ['action' => 'Idno\Http\Controller\PermalinkController::otherLayout', 'class' => 'Idno\Legacy\Placeholder\PermalinkOtherLayout'],
    'idno_test_routed' => ['action' => 'Tests\Fixtures\RoutedFixtureController::show', 'class' => 'Tests\Fixtures\RoutedPlaceholder'],
    'idno_test_routed_shell' => ['action' => 'Tests\Fixtures\RoutedFixtureController::showShell', 'class' => 'Tests\Fixtures\RoutedFixtureShell'],
];
