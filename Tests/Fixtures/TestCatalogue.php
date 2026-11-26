<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\ArrayKeyTranslation;

/**
 * Test-environment only: a catalogue of a few strings for one locale, registered by `TestEventHooks` when `IDNO_TEST_TRANSLATIONS` names it, so that a test
 * can show that a page translates a string of core, which ships no catalogue but the English one.
 */
final class TestCatalogue extends ArrayKeyTranslation
{
    /**
     * @param array<string, string> $strings
     */
    public function __construct(string $locale, private readonly array $strings)
    {
        parent::__construct($locale);
    }

    public function getStrings()
    {
        return $this->strings;
    }
}
