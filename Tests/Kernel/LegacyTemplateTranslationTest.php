<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Legacy\LegacyTemplateTranslation;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;

final class LegacyTemplateTranslationTest extends TestCase
{
    public function testItTranslatesThroughTheSitesLanguageAndFetchesItWhenFirstUsed(): void
    {
        $fetched = 0;
        $translation = new LegacyTemplateTranslation(static function () use (&$fetched) {
            ++$fetched;

            return Idno::site()->language();
        });

        $this->assertSame(0, $fetched, 'nothing is fetched when the adapter is built');
        $this->assertSame('Hello <b>', $translation->translate('Hello %s', ['<b>']), 'a string with no translation is itself, substituted, and not escaped here');
        $this->assertSame(1, $fetched);
    }
}
