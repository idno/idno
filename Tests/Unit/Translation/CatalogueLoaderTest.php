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

    public function testPluralEntriesAreNotSupportedAndAreReturnedAsSymfonyGivesThem(): void
    {
        [$loader, $log] = self::loader();
        $directory = $this->copyOf('plural');

        $po = $loader->load($directory, 'test', 'fr_FR');
        $loader->load($directory, 'test', 'fr_FR');

        // The forms are joined with a bar under the singular and the plural id, and nothing splits them.
        $this->assertSame('%d fichier|%d fichiers', $po['%d file|%d files']);
        $this->assertSame('Ceci | cela', $po['This | that'], 'a bar in an ordinary translation is untouched');
        $this->assertSame('Bonjour|Salut', $po['Pipe|source'], 'and in an ordinary source string');
        $this->assertSame('Ligne un et deux', $po['Multi line']);
        $this->assertArrayNotHasKey('Fuzzy one', $po);

        $this->assertCount(1, $log, 'one warning per file per process');
        $this->assertSame('warning', $log[0][0]);
        $this->assertStringContainsString('fr_FR/LC_MESSAGES/test.po', $log[0][1]);
        $this->assertStringContainsString('plural', $log[0][1]);
        $this->assertStringNotContainsString($directory, $log[0][1]);

        [$other, $otherLog] = self::loader();
        $other->load(self::FIXTURES . '/po', 'test', 'fr_FR');
        $this->assertCount(0, $otherLog, 'a file with no plural entry warns of nothing');
    }

    public function testAMoFileWithAPluralEntryWarnsOfNothing(): void
    {
        [$loader, $log] = self::loader();
        $mo = $loader->load(self::FIXTURES . '/plural-mo', 'test', 'fr_FR');

        $this->assertSame('%d fichier|%d fichiers', $mo['%d file|%d files']);
        $this->assertSame('Ceci | cela', $mo['This | that']);
        $this->assertCount(0, $log, 'a compiled catalogue is not scanned');
    }

    public function testAMoFileThatIsNotACatalogueIsEmptyAndWarns(): void
    {
        $valid = (string) file_get_contents(self::FIXTURES . '/mo/fr_FR/LC_MESSAGES/test.mo');
        foreach (['truncated' => substr($valid, 0, 40), 'tiny' => 'abc', 'a huge count' => substr_replace($valid, pack('V', 0x7FFFFFFF), 8, 4)] as $name => $bytes) {
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

    public function testTheBundledPoAndMoFilesGiveTheSameStringsThroughSymfonysLoaders(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['Event/languages/fr_FR/LC_MESSAGES/event', 'Status/languages/en_US/LC_MESSAGES/status'] as $name) {
            $po = (new \Symfony\Component\Translation\Loader\PoFileLoader())->load($root . '/IdnoPlugins/' . $name . '.po', 'en', 'd')->all('d');
            $mo = (new \Symfony\Component\Translation\Loader\MoFileLoader())->load($root . '/IdnoPlugins/' . $name . '.mo', 'en', 'd')->all('d');

            $this->assertNotSame([], $po, $name);
            // Every translated string of the .mo (the compiler drops empty and fuzzy ones) is the .po's.
            foreach ($mo as $source => $translation) {
                $this->assertSame($translation, $po[$source] ?? null, $name . ': ' . $source);
            }
        }
    }
}
