<?php

declare(strict_types=1);

namespace Tests\Unit\Translation;

use Idno\Translation\CatalogueLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class CatalogueLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            self::remove($directory);
        }
        $this->temporary = [];
        CatalogueLoader::forget();
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** A private copy of a fixture directory, so that a test never shares a loaded catalogue with another. */
    private function copyOf(string $fixture): string
    {
        $target = sys_get_temp_dir() . '/idno-catalogue-' . bin2hex(random_bytes(6));
        $this->temporary[] = $target;
        $copy = static function (string $from, string $to) use (&$copy): void {
            mkdir($to, 0777, true);
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                is_dir($from . '/' . $entry) ? $copy($from . '/' . $entry, $to . '/' . $entry) : copy($from . '/' . $entry, $to . '/' . $entry);
            }
        };
        $copy(self::FIXTURES . '/' . $fixture, $target);

        return $target;
    }

    /**
     * @return array{CatalogueLoader, \ArrayObject<int, array{string, string}>}
     */
    private static function loader(): array
    {
        $log = new \ArrayObject();
        $logger = new class ($log) extends AbstractLogger {
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = [(string) $level, (string) $message];
            }
        };

        return [new CatalogueLoader($logger), $log];
    }

    public function testReadsAPoFile(): void
    {
        $strings = (new CatalogueLoader())->load(self::FIXTURES . '/po', 'test', 'fr_FR');

        $this->assertSame('%s a dit bonjour', $strings['%s said hello']);
        $this->assertSame('Bonjour !', $strings['Hello!']);
        $this->assertSame('Dites « salut »', $strings['Say "hi"']);
    }

    public function testReadsAMoFileWhenThereIsNoPo(): void
    {
        $strings = (new CatalogueLoader())->load(self::FIXTURES . '/mo', 'test', 'fr_FR');

        $this->assertSame('Bonjour !', $strings['Hello!']);
        $this->assertSame('%s a dit bonjour', $strings['%s said hello']);
    }

    public function testThePoWinsOverTheMo(): void
    {
        $strings = (new CatalogueLoader())->load(self::FIXTURES . '/both', 'test', 'fr_FR');

        $this->assertSame('Bonjour du po', $strings['Hello!']);
    }

    public function testARegionalLocaleFallsBackToItsLanguage(): void
    {
        $loader = new CatalogueLoader();

        $this->assertSame('Bonjour !', $loader->load(self::FIXTURES . '/regional', 'test', 'fr_CA')['Hello!']);
        $this->assertSame('Bonjour !', $loader->load(self::FIXTURES . '/regional', 'test', 'fr')['Hello!']);
        $this->assertSame([], $loader->load(self::FIXTURES . '/regional', 'test', 'de_DE'));
    }

    public function testALanguageOrAnotherRegionFindsTheFirstRegionalCatalogue(): void
    {
        $loader = new CatalogueLoader();

        $this->assertSame('Bonjour !', $loader->load(self::FIXTURES . '/po', 'test', 'fr')['Hello!'], 'fr finds fr_FR');
        $this->assertSame('Bonjour !', $loader->load(self::FIXTURES . '/po', 'test', 'fr_CA')['Hello!'], 'fr_CA finds fr_FR');
        $this->assertTrue($loader->has(self::FIXTURES . '/po', 'test', 'fr'));
        $this->assertTrue($loader->has(self::FIXTURES . '/po', 'test', 'fr_CA'));
        $this->assertSame([], $loader->load(self::FIXTURES . '/po', 'test', 'de'), 'de finds nothing');
        $this->assertFalse($loader->has(self::FIXTURES . '/po', 'test', 'de'));
    }

    public function testTheLookupOrderIsTheExactLocaleThenTheLanguageThenTheFirstRegionSorted(): void
    {
        $directory = $this->copyOf('po'); // fr_FR
        $write = static function (string $locale, string $text) use ($directory): void {
            mkdir($directory . '/' . $locale . '/LC_MESSAGES', 0777, true);
            file_put_contents($directory . '/' . $locale . '/LC_MESSAGES/test.po', "msgid \"Hello!\"\nmsgstr \"" . $text . "\"\n");
        };
        $write('fr_BE', 'belge');
        $loader = new CatalogueLoader();

        $this->assertSame('belge', $loader->load($directory, 'test', 'fr')['Hello!'], 'the first region in sorted order');
        $this->assertSame('belge', $loader->load($directory, 'test', 'fr_CA')['Hello!']);
        $this->assertSame('Bonjour !', $loader->load($directory, 'test', 'fr_FR')['Hello!'], 'the exact locale first');

        $write('fr', 'neutre');
        CatalogueLoader::forget();
        $this->assertSame('neutre', $loader->load($directory, 'test', 'fr_CA')['Hello!'], 'then the language');
        $this->assertSame('neutre', $loader->load($directory, 'test', 'fr')['Hello!']);

        $write('fr_CA', 'canadien');
        CatalogueLoader::forget();
        $this->assertSame('canadien', $loader->load($directory, 'test', 'fr_CA')['Hello!'], 'the exact locale before the language');
    }

    public function testAHostileDirectoryNameIsIgnored(): void
    {
        $directory = $this->copyOf('po');
        rename($directory . '/fr_FR', $directory . '/fr_FR.bak');
        foreach (['fr_a b', 'fr_$(id)', "fr_x\ny", 'fr_', 'fr__FR', 'fr_ABCDEFGHIJ'] as $name) {
            @mkdir($directory . '/' . $name . '/LC_MESSAGES', 0777, true);
            @copy($directory . '/fr_FR.bak/LC_MESSAGES/test.po', $directory . '/' . $name . '/LC_MESSAGES/test.po');
        }
        $loader = new CatalogueLoader();

        $this->assertSame([], $loader->load($directory, 'test', 'fr'));
        $this->assertFalse($loader->has($directory, 'test', 'fr_CA'));
    }

    public function testAPluralEntryKeepsOnlyItsSingularTranslation(): void
    {
        $po = (new CatalogueLoader())->load(self::FIXTURES . '/plural', 'test', 'fr_FR');
        $mo = (new CatalogueLoader())->load(self::FIXTURES . '/plural-mo', 'test', 'fr_FR');

        foreach ([$po, $mo] as $strings) {
            $this->assertSame('%d fichier', $strings['%d file']);
            $this->assertArrayNotHasKey('%d file|%d files', $strings);
            $this->assertArrayNotHasKey('%d files', $strings);
            $this->assertArrayNotHasKey('Fuzzy one', $strings, 'a fuzzy entry is not a translation');
            $this->assertSame('Ceci | cela', $strings['This | that'], 'a bar in an ordinary translation is kept');
            $this->assertSame('Bonjour|Salut', $strings['Pipe|source'] ?? null, 'and a bar in an ordinary source string');
        }
        $this->assertSame('Ligne un et deux', $po['Multi line'], 'continuation lines are joined');
        $this->assertSame("Dites \"salut\"\t", $po['Escapes']);
    }

    public function testTheExactLocaleWinsOverItsLanguage(): void
    {
        $directory = $this->copyOf('regional');
        mkdir($directory . '/fr_CA/LC_MESSAGES', 0777, true);
        file_put_contents($directory . '/fr_CA/LC_MESSAGES/test.po', "msgid \"Hello!\"\nmsgstr \"Allo!\"\n");

        $this->assertSame('Allo!', (new CatalogueLoader())->load($directory, 'test', 'fr_CA')['Hello!']);
    }

    public function testAMissingLocaleIsEmpty(): void
    {
        [$loader, $log] = self::loader();

        $this->assertSame([], $loader->load(self::FIXTURES . '/po', 'test', 'de_DE'));
        $this->assertSame([], $loader->load(self::FIXTURES . '/nowhere', 'test', 'fr_FR'));
        $this->assertSame([], $loader->load(self::FIXTURES . '/po', 'nothing', 'fr_FR'));
        $this->assertCount(0, $log, 'a missing catalogue is normal and logs nothing');
    }

    public function testAMalformedFileIsEmptyAndWarnsOnce(): void
    {
        [$loader, $log] = self::loader();
        $directory = $this->copyOf('malformed');

        $this->assertSame([], $loader->load($directory, 'test', 'fr_FR'));
        $this->assertSame([], $loader->load($directory, 'test', 'fr_FR'));

        $this->assertCount(1, $log);
        $this->assertSame('warning', $log[0][0]);
        $this->assertStringContainsString('test.mo', $log[0][1]);
        $this->assertStringNotContainsString($directory, $log[0][1], 'the message names the file, not the server path');
    }

    public function testATruncatedOrLyingMoIsEmptyAndWarns(): void
    {
        $valid = (string) file_get_contents(self::FIXTURES . '/mo/fr_FR/LC_MESSAGES/test.mo');
        $lying = substr_replace($valid, pack('V', 0xFFFFFF), 24 + 4, 4); // the first string's offset points past the end
        foreach (['truncated' => substr($valid, 0, 40), 'tiny' => 'abc', 'lying offset' => $lying, 'a huge count' => substr_replace($valid, pack('V', 0x7FFFFFFF), 8, 4)] as $name => $bytes) {
            [$loader, $log] = self::loader();
            $directory = $this->copyOf('malformed');
            file_put_contents($directory . '/fr_FR/LC_MESSAGES/test.mo', $bytes);

            $this->assertSame([], $loader->load($directory, 'test', 'fr_FR'), $name);
            $this->assertCount(1, $log, $name);
        }
    }

    public function testAMalformedPoDoesNotThrow(): void
    {
        $directory = $this->copyOf('po');
        file_put_contents($directory . '/fr_FR/LC_MESSAGES/test.po', "msgid \"a\"\nmsgstr \"b\"\n\0\xff msgid msgid\n\"unterminated");

        $this->assertIsArray((new CatalogueLoader())->load($directory, 'test', 'fr_FR'));
    }

    public function testAnEmptyTranslationIsLeftOut(): void
    {
        $strings = (new CatalogueLoader())->load(self::FIXTURES . '/po', 'test', 'fr_FR');

        $this->assertArrayNotHasKey('Not translated yet', $strings);
        $this->assertArrayNotHasKey('', $strings, 'the header is not a string');
    }

    public function testALocaleThatIsNotOneNeverReachesTheFilesystem(): void
    {
        $directory = $this->copyOf('po');
        mkdir($directory . '/escape/LC_MESSAGES', 0777, true);
        copy($directory . '/fr_FR/LC_MESSAGES/test.po', $directory . '/escape/LC_MESSAGES/test.po');

        $loader = new CatalogueLoader();
        foreach (['../escape', 'fr_FR/../escape', "fr_FR\0", "fr_FR\n", 'fr_FR/', '', '.', '..', 'fr FR'] as $locale) {
            $this->assertSame([], $loader->load($directory . '/fr_FR', 'test', $locale), json_encode($locale));
            $this->assertSame([], $loader->load($directory, 'test', $locale), json_encode($locale));
        }
        $this->assertSame([], $loader->load($directory, '../test', 'fr_FR'), 'nor does a domain that is not one');
    }

    public function testACatalogueIsReadOncePerFile(): void
    {
        $directory = $this->copyOf('po');
        $loader = new CatalogueLoader();
        $file = $directory . '/fr_FR/LC_MESSAGES/test.po';
        $first = $loader->load($directory, 'test', 'fr_FR');

        // Same size, same modification time, other contents: only a kept catalogue can answer as before.
        $mtime = filemtime($file);
        file_put_contents($file, str_replace('Bonjour', 'Bonsoir', (string) file_get_contents($file)));
        touch($file, $mtime);
        clearstatcache();

        $this->assertSame($first, $loader->load($directory, 'test', 'fr_FR'), 'the loaded catalogue is kept for the process');
        CatalogueLoader::forget();
        $this->assertSame('Bonsoir !', $loader->load($directory, 'test', 'fr_FR')['Hello!']);
    }

    public function testHasCatalogueLooksAtTheFilesAndReadsNothing(): void
    {
        $loader = new CatalogueLoader();
        $directory = $this->copyOf('malformed');

        $this->assertTrue($loader->has($directory, 'test', 'fr_FR'));
        $this->assertTrue($loader->has(self::FIXTURES . '/regional', 'test', 'fr_CA'));
        $this->assertFalse($loader->has(self::FIXTURES . '/regional', 'test', 'de_DE'));
        $this->assertFalse($loader->has(self::FIXTURES . '/regional', 'test', '../x'));
    }

    public function testTheBundledCataloguesLoad(): void
    {
        $root = dirname(__DIR__, 3);
        $loader = new CatalogueLoader();

        $event = $loader->load($root . '/IdnoPlugins/Event/languages', 'event', 'fr_FR');
        $this->assertSame('Évènement', $event['Event'] ?? null);

        $status = $loader->load($root . '/IdnoPlugins/Status/languages', 'status', 'en_US');
        $this->assertNotSame([], $status);

        $checkin = $loader->load($root . '/IdnoPlugins/Checkin/languages', 'checkin', 'fr_FR');
        $this->assertNotSame([], $checkin);
    }
}
