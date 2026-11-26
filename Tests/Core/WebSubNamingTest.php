<?php

namespace Tests\Core {

    /**
     * The protocol is called WebSub, in code, templates, text and documentation. This test
     * reads every tracked file and fails when one of the protocol's earlier names is back.
     *
     * What may still hold an earlier name is listed here and nowhere else: the files that
     * keep the old class and event names working, the upgrade notes that tell plugin authors
     * about them, and the names that hubs or the database already hold.
     */
    class WebSubNamingTest extends \PHPUnit\Framework\TestCase
    {

        /// Files that may name the protocol as it was called: compatibility and its documentation
        const MAY_NAME_IT = [
            'Idno/Core/PubSubHubbub.php',            // loads the class under its earlier name
            'Idno/Pages/Pubsubhubbub/Callback.php',  // the same, for the callback page
            'Idno/Core/WebSub.php',                  // declares the alias
            'Idno/Pages/WebSub/Callback.php',        // declares the alias
            'Idno/Core/EventDispatcher.php',         // the earlier event name
            'docs/install/upgrade.md',               // the one place the history is told
            'Tests/Core/WebSubCompatibilityTest.php',
            'Tests/Core/WebSubNamingTest.php',
        ];

        /// Names given out or stored before the change, which keep their spelling everywhere:
        /// the callback address and the fields on members
        const KEPT = ['pubsub/callback', 'pubsub_pending', 'pubsub_self', 'pubsub_hub'];

        /// The earlier names, in every way they were written
        const EARLIER_NAMES = [
            'the full name'  => '/pubsubhubbub/i',
            'the short name' => '/pubsub/i',
            'the acronym'    => '/\bPuSH\b/',
            'push hub'       => '/\bpush[ -]hubs?\b/i',
        ];

        /// Files that are built from others, or are not text
        const SKIPPED = '/(\.map|\.min\.js|\.min\.css|\.lock|\.png|\.jpe?g|\.gif|\.ico|\.woff2?|\.ttf|\.eot|\.otf|\.mo|\.zip|\.gz|\.pdf|\.mp[34]|\.webp|\.avif)$/i';

        private static function root()
        {
            return dirname(__DIR__, 2);
        }

        /**
         * Every file of the project that git tracks, relative to its root.
         */
        private static function trackedFiles()
        {
            $output = [];
            $status = 1;
            exec('git -C ' . escapeshellarg(self::root()) . ' ls-files 2>/dev/null', $output, $status);

            return $status === 0 ? $output : [];
        }

        /**
         * The lines of a text that hold an earlier name, as "line number: what was found".
         */
        public static function findings($text)
        {
            $found = [];
            foreach (explode("\n", $text) as $number => $line) {
                $line = str_ireplace(self::KEPT, '', $line);
                foreach (self::EARLIER_NAMES as $what => $pattern) {
                    if (preg_match($pattern, $line, $match)) {
                        $found[] = ($number + 1) . ': ' . $what . ' ("' . $match[0] . '")';
                        break;
                    }
                }
            }

            return $found;
        }

        public function testNoFileNamesTheProtocolAsItWasCalled()
        {
            $files = self::trackedFiles();
            if (count($files) < 100) {
                $this->markTestSkipped('The list of tracked files could not be read (not a git checkout).');
            }

            $problems = [];
            foreach ($files as $file) {
                if (in_array($file, self::MAY_NAME_IT, true) || preg_match(self::SKIPPED, $file)) {
                    continue;
                }
                if (preg_match('/pubsub/i', $file)) {
                    $problems[] = $file . ': the name of the file';
                }
                $path = self::root() . '/' . $file;
                if (!is_file($path) || filesize($path) > 2000000) {
                    continue;
                }
                $text = (string) file_get_contents($path);
                if (strpos($text, "\0") !== false) {
                    continue;
                }
                foreach (self::findings($text) as $finding) {
                    $problems[] = $file . ':' . $finding;
                }
            }

            $this->assertSame([], $problems, "The protocol is called WebSub. Rename these, or, if one of them keeps an earlier name working, list it in " . __CLASS__ . ".");
        }

        public function testEveryListedFileExists()
        {
            foreach (self::MAY_NAME_IT as $file) {
                $this->assertFileExists(self::root() . '/' . $file, 'A file that no longer exists should leave the list.');
            }
        }

        public function testTheScanRecognisesTheEarlierNames()
        {
            $this->assertCount(1, self::findings('new \Idno\Core\Pub' . 'SubHubbub()'));
            $this->assertCount(1, self::findings("addListener('pub" . "subhubbub/ping')"));
            $this->assertCount(1, self::findings('Learn more about Pu' . 'SH'));
            $this->assertCount(1, self::findings('tell the Pu' . 'sh hub'));
            $this->assertCount(1, self::findings('$pub' . 'sub = 1;'));
            $this->assertSame(['2: the short name ("Pub' . 'sub")'], self::findings("fine\nPub" . "sub: ping"));
        }

        public function testTheScanLeavesOtherThingsAlone()
        {
            $this->assertSame([], self::findings('$tokens.push(hub); git push; a push notification'));
            $this->assertSame([], self::findings('<link rel="hub" href="https://hub.example/">'));
            $this->assertSame([], self::findings("\$user->pubsub_pending; \$f->pubsub_self; \$f->pubsub_hub; 'pubsub/callback/'"));
            $this->assertSame([], self::findings('WebSub hub.mode=publish, X-Hub-Signature'));
        }

    }

}
