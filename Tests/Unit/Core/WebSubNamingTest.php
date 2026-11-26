<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * The protocol is called WebSub, in code, templates, text, documentation and the names of tests. This test reads every tracked
 * file and fails when one of the protocol's earlier names is back.
 *
 * What may still hold an earlier name is listed here, each with its reason, and nowhere else.
 */
final class WebSubNamingTest extends TestCase
{
    /** Files that may name the protocol as it was called, and why. */
    private const MAY_NAME_IT = [
        'Idno/Core/PubSubHubbub.php' => 'loads the class under its earlier name',
        'Idno/Core/WebSub.php' => 'declares the earlier class name as an alias',
        'Idno/Core/EventDispatcher.php' => 'maps the earlier event name to the name it has now',
        'docs/install/upgrade.md' => 'the upgrade notes list the earlier names, for the one who upgrades',
        'docs/developers/plugins/upgrading.md' => 'the guide for plugin authors lists the earlier names',
        'Tests/Core/WebSubCompatibilityTest.php' => 'the test that the earlier names still work',
        'Tests/Unit/Core/WebSubNamingTest.php' => 'this test',
    ];

    /** Names given out or stored before the change, which keep their spelling wherever they appear (each a pattern that ends where the name does), and why. */
    private const KEPT = [
        '~pubsub/callback(?![A-Za-z0-9_])~i' => 'the callback address: hubs hold it for every subscription already made',
        '~pubsub_(?:pending|self|hub|secrets)(?![A-Za-z0-9_])~i' => 'the fields stored on members: no data is migrated',
        '~page-pubsub(?![A-Za-z0-9_])~i' => 'the body classes of a page are made from its address, and the callback page\'s address is kept',
    ];

    /** The one historical mention, which says what the protocol used to be called. */
    private const HISTORY = 'WebSub (formerly PubSub' . 'Hubbub)';

    /** The earlier names, in every way they were written. */
    private const EARLIER_NAMES = [
        'the name' => '/pubsub/i',
        'the acronym' => '/\bPuSH\b/',
        'push hub' => '/\bpush[ -]hubs?\b/i',
    ];

    /** Files that are built from others, or are not text. */
    private const SKIPPED = '/(\.map|\.min\.js|\.min\.css|\.lock|\.png|\.jpe?g|\.gif|\.ico|\.woff2?|\.ttf|\.eot|\.otf|\.mo|\.zip|\.gz|\.pdf|\.mp[34]|\.webp|\.avif)$/i';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> every file of the project that git tracks, relative to its root */
    private static function trackedFiles(): array
    {
        $output = [];
        $status = 1;
        exec('git -C ' . escapeshellarg(self::root()) . ' ls-files 2>/dev/null', $output, $status);

        return $status === 0 ? $output : [];
    }

    private static function withoutKeptNames(string $text): string
    {
        return (string) preg_replace(array_keys(self::KEPT), '', $text);
    }

    /** @return list<string> the lines of a text that hold an earlier name, as "line number: what was found" */
    public static function findings(string $text): array
    {
        $found = [];
        foreach (explode("\n", $text) as $number => $line) {
            $line = self::withoutKeptNames(str_ireplace(self::HISTORY, '', $line));
            foreach (self::EARLIER_NAMES as $what => $pattern) {
                if (preg_match($pattern, $line, $match)) {
                    $found[] = ($number + 1) . ': ' . $what . ' ("' . $match[0] . '")';
                    break;
                }
            }
        }

        return $found;
    }

    public function testNoFileNamesTheProtocolAsItWasCalled(): void
    {
        $files = self::trackedFiles();
        if (count($files) < 100) {
            $this->markTestSkipped('The list of tracked files could not be read (not a git checkout).');
        }

        $problems = [];
        foreach ($files as $file) {
            if (isset(self::MAY_NAME_IT[$file]) || preg_match(self::SKIPPED, $file)) {
                continue;
            }
            if (preg_match('/pubsub/i', self::withoutKeptNames($file))) {
                $problems[] = $file . ': the name of the file';
            }
            $path = self::root() . '/' . $file;
            if (!is_file($path) || filesize($path) > 2000000) {
                continue;
            }
            $text = (string) file_get_contents($path);
            if (str_contains($text, "\0")) {
                continue;
            }
            foreach (self::findings($text) as $finding) {
                $problems[] = $file . ':' . $finding;
            }
        }

        $this->assertSame([], $problems, 'The protocol is called WebSub. Rename these, or, if one of them keeps an earlier name working, list it in ' . self::class . '.');
    }

    public function testTheHistoryIsToldOnceOutsideTheUpgradeNotes(): void
    {
        $files = self::trackedFiles();
        if (count($files) < 100) {
            $this->markTestSkipped('The list of tracked files could not be read (not a git checkout).');
        }

        $where = [];
        foreach ($files as $file) {
            if (isset(self::MAY_NAME_IT[$file]) || preg_match(self::SKIPPED, $file) || !is_file(self::root() . '/' . $file) || filesize(self::root() . '/' . $file) > 2000000) {
                continue;
            }
            $count = substr_count((string) file_get_contents(self::root() . '/' . $file), self::HISTORY);
            if ($count > 0) {
                $where[$file] = $count;
            }
        }

        $this->assertSame(['AGENTS.md' => 1], $where, 'The earlier name is mentioned in one place only, "' . self::HISTORY . '", in AGENTS.md.');
    }

    public function testEveryListedFileExists(): void
    {
        foreach (array_keys(self::MAY_NAME_IT) as $file) {
            $this->assertFileExists(self::root() . '/' . $file, 'A file that no longer exists should leave the list.');
        }
    }

    public function testTheScanRecognisesTheEarlierNames(): void
    {
        $this->assertCount(1, self::findings('new \Idno\Core\Pub' . 'SubHubbub()'));
        $this->assertCount(1, self::findings("addListener('pub" . "subhubbub/ping')"));
        $this->assertCount(1, self::findings('Learn more about Pu' . 'SH'));
        $this->assertCount(1, self::findings('tell the Pu' . 'sh hub'));
        $this->assertCount(1, self::findings('$pub' . 'sub = 1;'));
        $this->assertCount(1, self::findings('<!-- Pub' . 'subhubbub -->'));
        $this->assertCount(1, self::findings('idno_pages_pub' . 'subhubbub_callback'));
        $this->assertSame(['2: the name ("Pub' . 'sub")'], self::findings("fine\nPub" . 'sub: ping'));
        // a kept spelling is kept only as it is: the earlier name that merely begins with one is still found
        $this->assertCount(1, self::findings('<body class="page-pub' . 'subhubbub-callback">'));
        $this->assertCount(1, self::findings('$user->pub' . 'sub_hubbub = 1;'));
        $this->assertCount(1, self::findings("'pub" . "sub/callbacks/'"));
        $this->assertCount(1, self::findings('$user->pub' . 'sub_pending_list'));
    }

    public function testTheScanLeavesOtherThingsAlone(): void
    {
        $this->assertSame([], self::findings('$tokens.push(hub); git push; a push notification'));
        $this->assertSame([], self::findings('<link rel="hub" href="https://hub.example/">'));
        $this->assertSame([], self::findings("\$user->pubsub_pending; \$f->pubsub_self; \$f->pubsub_hub; \$u->pubsub_secrets; 'pubsub/callback/'"));
        $this->assertSame([], self::findings('<body class="page-pubsub page-pubsub-callback-x">'));
        $this->assertSame([], self::findings('WebSub hub.mode=publish, X-Hub-Signature'));
        $this->assertSame([], self::findings('We say ' . self::HISTORY . ' once.'));
    }
}
