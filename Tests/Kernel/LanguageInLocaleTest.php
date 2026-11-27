<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Language;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TestCatalogue;

final class LanguageInLocaleTest extends TestCase
{
    // A mail drawn in the site's language must not leave the page that is answering the requester in that language: the swap is undone, also when the work throws.
    public function testWorkRunsInAnotherLocaleAndTheRequestersLocaleIsBackAfterwardsEvenWhenItThrows(): void
    {
        $language = new Language('fr_FR');
        $language->register(new TestCatalogue('fr_FR', ['Hello' => 'Bonjour']));
        $language->register(new TestCatalogue('en_US', ['Hello' => 'Hello there']));
        $this->assertSame('Bonjour', $language->_('Hello'));

        $this->assertSame('Hello there', $language->inLocale('en_US', static fn () => $language->_('Hello')));
        $this->assertSame('en_US', $language->inLocale('en_US', static fn () => $language->getLanguage()));
        $this->assertSame('Bonjour', $language->_('Hello'));
        $this->assertSame('fr_FR', $language->getLanguage());

        try {
            $language->inLocale('en_US', static function (): void {
                throw new \RuntimeException('the draw failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Bonjour', $language->_('Hello'), 'back to French after a failure');
    }
}
