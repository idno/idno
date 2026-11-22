<?php

declare(strict_types=1);

namespace Tests\Unit\Translation;

use Idno\Translation\LocaleNegotiator;
use PHPUnit\Framework\TestCase;

final class LocaleNegotiatorTest extends TestCase
{
    public function testTheFirstLanguageOfAnAcceptLanguageHeaderIsTheLocale(): void
    {
        $this->assertSame('fr_FR', LocaleNegotiator::fromAcceptLanguage('fr-FR,fr;q=0.9,en;q=0.8'));
        $this->assertSame('de', LocaleNegotiator::fromAcceptLanguage('de'));
        $this->assertSame('de_DE', LocaleNegotiator::fromAcceptLanguage('de-DE;q=0.7'));
        $this->assertSame('zh_Hant_TW', LocaleNegotiator::fromAcceptLanguage('zh-Hant-TW, en'));
        $this->assertSame('fr_FR', LocaleNegotiator::fromAcceptLanguage('FR-FR'));
    }

    public function testTheRegionAndScriptAreNormalised(): void
    {
        $this->assertSame('fr_FR', LocaleNegotiator::fromAcceptLanguage('fr-fr'), 'a two-letter region is upper-cased');
        $this->assertSame('pt_BR', LocaleNegotiator::fromAcceptLanguage('PT-br,pt;q=0.8'));
        $this->assertSame('zh_Hant', LocaleNegotiator::fromAcceptLanguage('zh-hant'), 'a four-letter script is title-cased');
        $this->assertSame('zh_Hant_TW', LocaleNegotiator::fromAcceptLanguage('ZH-HANT-tw'));
        $this->assertSame('es_419', LocaleNegotiator::fromAcceptLanguage('es-419'), 'any other subtag is kept as given');
        $this->assertSame('en_US_posix', LocaleNegotiator::fromAcceptLanguage('en-US-posix'));
    }

    public function testAnExplicitLocaleAndTheEnvironmentGoThroughTheSameRule(): void
    {
        $this->assertSame('fr_FR', LocaleNegotiator::normalise('fr-fr', 'en_US'));
        $this->assertSame('en_US', LocaleNegotiator::normalise('en_US.UTF-8', 'x'), 'an encoding is removed');
        $this->assertSame('sr_RS', LocaleNegotiator::normalise('sr_RS@latin', 'x'), 'a modifier is removed');
        $this->assertSame('x', LocaleNegotiator::normalise('../../etc', 'x'));
        $this->assertSame('fr_FR', LocaleNegotiator::fromEnvironment(['LANG' => 'fr_fr.UTF-8'], 'en_US'));
        $this->assertSame('zh_Hant', LocaleNegotiator::fromEnvironment(['LANGUAGE' => 'zh-hant:en'], 'en_US'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unusableHeaders(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'star' => ['*'];
        yield 'header injection' => ["en\r\nX: y"];
        yield 'path traversal' => ['../../etc'];
        yield 'path traversal with a language' => ['fr/../../etc'];
        yield 'a null byte' => ["fr\0_FR"];
        yield 'a very long value' => [str_repeat('a', 300)];
        yield 'a very long locale' => ['en' . str_repeat('-US', 100)];
        yield 'a leading comma' => [',fr'];
        yield 'one letter' => ['f'];
        yield 'a trailing newline' => ["fr_FR\n"];
    }

    /**
     * @dataProvider unusableHeaders
     */
    public function testAnUnusableHeaderIsTheDefault(?string $header): void
    {
        $this->assertSame('en_US', LocaleNegotiator::fromAcceptLanguage($header));
        $this->assertSame('pt_BR', LocaleNegotiator::fromAcceptLanguage($header, 'pt_BR'));
    }

    public function testTheResultAlwaysMatchesThePattern(): void
    {
        foreach (['fr-FR', 'x', 'abcd', 'en-', '-fr', 'en_US_POSIX', "fr\n", ' ', 'en us', 'zh-Hans-CN-x-private'] as $header) {
            $this->assertMatchesRegularExpression('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*\\z/', LocaleNegotiator::fromAcceptLanguage($header), json_encode($header));
        }
    }

    public function testTheConsoleReadsLanguageThenLang(): void
    {
        $this->assertSame('fr_FR', LocaleNegotiator::fromEnvironment(['LANG' => 'fr_FR.UTF-8'], 'en_US'));
        $this->assertSame('de', LocaleNegotiator::fromEnvironment(['LANGUAGE' => 'de', 'LANG' => 'fr_FR'], 'en_US'));
        $this->assertSame('de_AT', LocaleNegotiator::fromEnvironment(['LANGUAGE' => 'de_AT:de:en', 'LANG' => 'fr_FR'], 'en_US'));
        $this->assertSame('fr_FR', LocaleNegotiator::fromEnvironment(['LANGUAGE' => 'C', 'LANG' => 'fr_FR.UTF-8'], 'en_US'), 'an unusable LANGUAGE falls through to LANG');
        $this->assertSame('sr_RS', LocaleNegotiator::fromEnvironment(['LANG' => 'sr_RS.UTF-8@latin'], 'en_US'));
    }

    public function testTheConsoleFallsBackToTheDefault(): void
    {
        $this->assertSame('en_US', LocaleNegotiator::fromEnvironment(['LANG' => 'C'], 'en_US'));
        $this->assertSame('en_US', LocaleNegotiator::fromEnvironment(['LANG' => 'C.UTF-8'], 'en_US'));
        $this->assertSame('en_US', LocaleNegotiator::fromEnvironment(['LANG' => 'POSIX'], 'en_US'));
        $this->assertSame('en_GB', LocaleNegotiator::fromEnvironment([], 'en_GB'));
        $this->assertSame('en_US', LocaleNegotiator::fromEnvironment(['LANG' => '../../etc'], 'en_US'));
        $this->assertSame('en_US', LocaleNegotiator::fromEnvironment(['LANG' => ['fr_FR']], 'en_US'), 'a value that is not a string is no locale');
    }
}
