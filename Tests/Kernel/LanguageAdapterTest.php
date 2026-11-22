<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\ArrayKeyTranslation;
use Idno\Core\GetTextTranslation;
use Idno\Core\Idno;
use Idno\Core\Language;
use Idno\Entities\User;
use PHPUnit\Framework\TestCase;

/**
 * Idno\Core\Language over catalogues read from .po and .mo files, with nothing from the gettext
 * extension and no change to the process locale.
 */
final class LanguageAdapterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Unit/Translation/fixtures';

    private mixed $previousUser = false;

    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->previousUser = Idno::site()->session()->currentUser();
    }

    protected function tearDown(): void
    {
        Idno::site()->session()->logUserOff();
        foreach ($this->users as $user) {
            $user->delete();
        }
        $this->users = [];
        if ($this->previousUser) {
            Idno::site()->session()->refreshSessionUser($this->previousUser);
        }
    }

    /** A saved user with a `language` attribute, logged on. */
    private function loggedOnWithLanguage(string $locale): void
    {
        $user = new User();
        $user->handle = 'languageuser' . bin2hex(random_bytes(4));
        $user->email = $user->handle . '@example.com';
        $user->setPassword(bin2hex(random_bytes(8)));
        $user->language = $locale;
        $user->save(true);
        $this->users[] = $user;
        Idno::site()->session()->refreshSessionUser($user);
        $this->assertSame($locale, Idno::site()->session()->currentUser()->language, 'the user is logged on with the attribute');
    }

    private static function language(string $locale, string $fixture = 'po'): Language
    {
        $language = new Language($locale);
        $language->register(new GetTextTranslation('test', self::FIXTURES . '/' . $fixture));

        return $language;
    }

    public function testATranslatedStringIsReturnedWithSubstitutions(): void
    {
        $language = self::language('fr_FR');

        $this->assertSame('Ben a dit bonjour', $language->_('%s said hello', ['Ben']));
        $this->assertSame('Bonjour !', $language->get('Hello!'));
    }

    public function testAMissingTranslationIsTheSourceStringWithSubstitutions(): void
    {
        $language = self::language('fr_FR');

        $this->assertSame('Ben is here', $language->_('%s is here', ['Ben']));
        $this->assertSame('Not translated yet', $language->_('Not translated yet'), 'an empty translation is not one');
        $this->assertFalse($language->getTranslation('Never seen', false));
    }

    public function testALocaleWithNoCatalogueIsTheSourceString(): void
    {
        $language = self::language('de_DE');

        $this->assertSame('Ben said hello', $language->_('Ben said hello'));
        $this->assertSame('%s a dit bonjour', self::language('fr_FR')->get('%s said hello'));
        $this->assertSame('%s said hello', $language->get('%s said hello'));
    }

    public function testARegionalLocaleUsesItsLanguagesCatalogue(): void
    {
        $this->assertSame('Bonjour !', self::language('fr_CA', 'regional')->get('Hello!'));
    }

    public function testEscEscapesTheTranslation(): void
    {
        $language = self::language('fr_FR');

        $this->assertSame('&lt;b&gt; a dit bonjour', $language->esc_('%s said hello', ['<b>']));
        $this->assertSame('Dites &laquo; salut &raquo;', $language->esc_('Say "hi"'));
    }

    public function testAnArrayKeyTranslationStillWinsInRegistrationOrder(): void
    {
        $first = new Language('fr_FR');
        $first->register(new class ('fr_FR') extends ArrayKeyTranslation {
            public function getStrings()
            {
                return ['Hello!' => 'Salut de la classe'];
            }
        });
        $first->register(new GetTextTranslation('test', self::FIXTURES . '/po'));
        $this->assertSame('Salut de la classe', $first->get('Hello!'));
        $this->assertSame('%s a dit bonjour', $first->get('%s said hello'));

        $second = new Language('fr_FR');
        $second->register(new GetTextTranslation('test', self::FIXTURES . '/po'));
        $second->register(new class ('fr_FR') extends ArrayKeyTranslation {
            public function getStrings()
            {
                return ['Hello!' => 'Salut de la classe'];
            }
        });
        $this->assertSame('Bonjour !', $second->get('Hello!'), 'the first registered translation that has the string wins');
    }

    public function testAMalformedCatalogueIsTheSourceString(): void
    {
        $language = self::language('fr_FR', 'malformed');

        $this->assertSame('Hello!', $language->_('Hello!'));
    }

    public function testConstructingLanguageDoesNotChangeTheProcessLocale(): void
    {
        $locale = setlocale(LC_ALL, '0');
        $lang = getenv('LANG');
        $language = getenv('LANGUAGE');
        $all = getenv('LC_ALL');

        new Language('fr_FR');
        self::language('fr_FR')->get('Hello!');

        $this->assertSame($locale, setlocale(LC_ALL, '0'));
        $this->assertSame($lang, getenv('LANG'));
        $this->assertSame($language, getenv('LANGUAGE'));
        $this->assertSame($all, getenv('LC_ALL'));
    }

    public function testAUserWithALanguageAttributeGetsThatLocale(): void
    {
        $this->loggedOnWithLanguage('fr_FR');

        $language = new Language();
        $language->register(new GetTextTranslation('test', self::FIXTURES . '/po'));

        $this->assertSame('fr_FR', $language->getLanguage());
        $this->assertSame('Bonjour !', $language->get('Hello!'), 'the object is fully built');
    }

    public function testAnExplicitLocaleWinsOverTheUsers(): void
    {
        $this->loggedOnWithLanguage('fr_FR');

        $this->assertSame('de_DE', (new Language('de_DE'))->getLanguage());
    }

    public function testALocaleThatIsNotOneIsNeverUsed(): void
    {
        $this->loggedOnWithLanguage('../../etc');

        $this->assertMatchesRegularExpression('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', (new Language())->getLanguage());
        $this->assertMatchesRegularExpression('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', (new Language('../../etc'))->getLanguage());
    }

    public function testTheBrowsersLanguageIsTheDefault(): void
    {
        $previous = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9,en;q=0.8';
        try {
            $this->assertSame('fr_FR', (new Language())->getLanguage());
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '../../etc';
            $this->assertSame('en_US', (new Language())->getLanguage());
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            } else {
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $previous;
            }
        }
    }

    public function testTheCoreTranslationIsTheIdnoDomain(): void
    {
        $property = new \ReflectionProperty(GetTextTranslation::class, 'domain');
        $this->assertSame('idno', $property->getValue(new GetTextTranslation()));
    }
}
