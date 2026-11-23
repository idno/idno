<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\BundledTemplates;

/**
 * The progress of the port of the web and mail templates to Twig is a list: Tests/Templating/fixtures/unported.php holds
 * every `.tpl.php` that has no Twig file beside it yet, and nothing else.
 */
final class PortProgressTest extends TestCase
{
    public function testEveryUnportedTemplateIsListedAndOnlyThose(): void
    {
        $root = dirname(__DIR__, 3);
        /** @var list<string> $unported */
        $unported = require $root . '/Tests/Templating/fixtures/unported.php';
        $templates = BundledTemplates::files($root, '.tpl.php');
        $twig = BundledTemplates::twigTemplates($root);

        $this->assertSame($unported, array_values(array_unique($unported)), 'the list has a name twice');
        $sorted = $unported;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $unported, 'the list is sorted');
        $this->assertSame([], array_values(array_diff($unported, array_keys($templates))), 'listed, and there is no such `.tpl.php`');

        $ported = array_keys(array_intersect_key($twig, $templates));
        $listedAndPorted = array_values(array_intersect($unported, $ported));
        $this->assertSame([], $listedAndPorted, 'a template with a Twig file beside it is still listed as unported (remove it from unported.php)');
        $notListed = array_values(array_diff(array_keys($templates), $unported, $ported));
        $this->assertSame([], $notListed, 'a template with no Twig file beside it is not listed as unported (add it to unported.php, or port it)');
    }

    public function testATwigFileHasItsOriginalBesideItUntilThePortIsDone(): void
    {
        $root = dirname(__DIR__, 3);
        $templates = BundledTemplates::files($root, '.tpl.php');
        $alone = array_values(array_diff(array_keys(BundledTemplates::twigTemplates($root)), array_keys($templates)));

        $this->assertSame([], $alone, 'a Twig file has no `.tpl.php` beside it: its original is kept, in place, so that the equivalence test can compare them');
    }
}
