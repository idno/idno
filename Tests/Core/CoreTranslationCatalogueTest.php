<?php

namespace Tests\Core {

    use Idno\Core\GetTextTranslation;
    use Idno\Core\Language;

    /**
     * Core's own strings are translated from a compiled gettext catalogue in a site's languages folder.
     * These tests compile real catalogues into a temporary folder and read them through gettext.
     */
    class CoreTranslationCatalogueTest extends \Tests\IdnoTestCase
    {

        private const LOCALE = 'en_US';

        private $folders = [];

        protected function setUp(): void
        {
            if (!extension_loaded('gettext')) {
                $this->markTestSkipped('The gettext extension is not loaded.');
            }
        }

        protected function tearDown(): void
        {
            foreach ($this->folders as $folder) {
                foreach (glob($folder . '/' . self::LOCALE . '/LC_MESSAGES/*.mo') as $file) {
                    unlink($file);
                }
                rmdir($folder . '/' . self::LOCALE . '/LC_MESSAGES');
                rmdir($folder . '/' . self::LOCALE);
                rmdir($folder);
            }
            $this->folders = [];

            // The binding of a domain is the process's: point core's domains back at the site's own folder
            GetTextTranslation::forCore();
        }

        /**
         * A translator who compiles core's catalogue under the name of the template (idno.pot) would
         * otherwise see the site untranslated.
         */
        public function testCoreStringIsTranslatedFromACatalogueNamedForIdno()
        {
            $language = $this->languageWithCatalogues(['idno' => ['Sign in to the test site' => 'Welcome back, friend']]);

            $this->assertSame('Welcome back, friend', $language->_('Sign in to the test site'));
        }

        /**
         * A site translated before the domain was renamed has known.mo, and would otherwise turn
         * back into English when it is upgraded.
         */
        public function testCoreStringIsStillTranslatedFromACatalogueMadeBeforeTheRename()
        {
            $language = $this->languageWithCatalogues(['known' => ['Sign in to the test site' => 'Welcome back, old friend']]);

            $this->assertSame('Welcome back, old friend', $language->_('Sign in to the test site'));
        }

        /**
         * A site that has both catalogues (a new one started beside the old) would otherwise show the old
         * wording for a string the new one has changed, or lose the strings only the old one has.
         */
        public function testTheNewCatalogueWinsAndTheEarlierOneFillsItsGaps()
        {
            $language = $this->languageWithCatalogues([
                'idno'  => ['In both catalogues' => 'From the new one'],
                'known' => ['In both catalogues' => 'From the old one', 'Only in the old catalogue' => 'Kept from the old one'],
            ]);

            $this->assertSame('From the new one', $language->_('In both catalogues'));
            $this->assertSame('Kept from the old one', $language->_('Only in the old catalogue'));
            $this->assertSame('In neither catalogue', $language->_('In neither catalogue'));
        }

        /**
         * A language whose core translations are read from a folder holding the given catalogues
         * (domain => [string => translation]).
         */
        private function languageWithCatalogues(array $catalogues): Language
        {
            $folder = sys_get_temp_dir() . '/idno-catalogues-' . bin2hex(random_bytes(6));
            $messages = $folder . '/' . self::LOCALE . '/LC_MESSAGES';
            mkdir($messages, 0700, true);
            $this->folders[] = $folder;
            foreach ($catalogues as $domain => $strings) {
                file_put_contents($messages . '/' . $domain . '.mo', $this->compile($strings));
            }

            $language = new Language(self::LOCALE);
            if (in_array(setlocale(LC_MESSAGES, '0'), ['C', 'POSIX'], true)) {
                // gettext translates nothing in the C locale, whatever catalogues there are
                $this->markTestSkipped('This machine has no ' . self::LOCALE . '.UTF-8 locale.');
            }
            foreach (GetTextTranslation::forCore($folder) as $translation) {
                $language->register($translation);
            }

            return $language;
        }

        /**
         * The contents of a compiled catalogue (.mo), so that the test needs no msgfmt.
         */
        private function compile(array $strings): string
        {
            $strings[''] = "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n";
            ksort($strings, SORT_STRING);

            $count = count($strings);
            $keys = '';
            $values = '';
            $keyTable = [];
            $valueTable = [];
            foreach ($strings as $key => $value) {
                $keyTable[] = [strlen($key), strlen($keys)];
                $keys .= $key . "\0";
                $valueTable[] = [strlen($value), strlen($values)];
                $values .= $value . "\0";
            }

            $keyTableAt = 28;
            $valueTableAt = $keyTableAt + 8 * $count;
            $keysAt = $valueTableAt + 8 * $count;
            $valuesAt = $keysAt + strlen($keys);

            $mo = pack('V7', 0x950412de, 0, $count, $keyTableAt, $valueTableAt, 0, $valuesAt + strlen($values));
            foreach ($keyTable as [$length, $offset]) {
                $mo .= pack('V2', $length, $keysAt + $offset);
            }
            foreach ($valueTable as [$length, $offset]) {
                $mo .= pack('V2', $length, $valuesAt + $offset);
            }

            return $mo . $keys . $values;
        }

    }

}
