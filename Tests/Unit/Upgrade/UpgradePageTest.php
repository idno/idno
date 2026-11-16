<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradePage;
use Idno\Upgrade\UpgradeProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The upgrade page: self-contained, escaped, readable without JavaScript, with the right words for
 * each status.
 */
final class UpgradePageTest extends TestCase
{
    private const NONCE = 'bm9uY2Utb2YtdGhlLXRlc3Q=';

    public function testProgressAndErrorAreEscaped(): void
    {
        $progress = new UpgradeProgress(
            ['entities' => ['source' => 1200, 'copied' => 700, 'rejected' => 3, 'done' => false]],
            false,
            'Stopped at <script>alert("x")</script> & \'quotes\'',
            UpgradeProgress::COPYING,
        );

        $html = UpgradePage::html(SchemaStatus::Legacy, $progress, '', self::NONCE);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('Stopped at &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;quotes&apos;', $html);
        self::assertStringContainsString('703 of 1200 copied, 3 not carried over', $html);
        self::assertSame(1, substr_count($html, '<script'), 'one script: the page\'s own');
        self::assertMatchesRegularExpression('/<div id="upgrade-stopped" class="stopped">/', $html, 'a stored error is shown without JavaScript');
    }

    public function testABasePathFromTheRequestIsEscapedEverywhere(): void
    {
        $html = UpgradePage::html(SchemaStatus::Empty, null, '/a"b</script><x>', self::NONCE);

        self::assertStringNotContainsString('</script><x>', $html);
        self::assertStringNotContainsString('a"b', $html);
        self::assertStringContainsString('action="/a&quot;b&lt;/script&gt;&lt;x&gt;/_upgrade/step?html=1"', $html);
        $encoded = (string) json_encode('/a"b</script><x>/_upgrade/step', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('"', substr($encoded, 1, -1));
        self::assertStringContainsString('var url = ' . $encoded . ';', $html, 'JSON-encoded in the script');
    }

    public function testTooOldHasNoScriptAndNoForm(): void
    {
        $html = UpgradePage::html(SchemaStatus::LegacyTooOld, null, '', self::NONCE);

        self::assertStringContainsString('1.6.4', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('<h1>This site&apos;s database is too old to upgrade</h1>', $html);
    }

    public function testRenamedTablesWithoutARecordAreExplainedWithoutScriptOrForm(): void
    {
        $html = html_entity_decode(UpgradePage::html(SchemaStatus::RenamedWithoutRecord, null, '', self::NONCE), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString(\Idno\Upgrade\PublicMessage::renamedWithoutRecord()->text, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertSame('Idno cannot start: ' . \Idno\Upgrade\PublicMessage::renamedWithoutRecord()->text . "\n", UpgradePage::text(SchemaStatus::RenamedWithoutRecord));
    }

    public function testOneXTablesBesideACompletedUpgradeAreExplainedWithoutScriptOrForm(): void
    {
        $message = \Idno\Upgrade\PublicMessage::oneXBesideCompletedUpgrade()->text;
        $html = html_entity_decode(UpgradePage::html(SchemaStatus::OneXBesideCompletedUpgrade, null, '', self::NONCE), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString($message, $html);
        self::assertStringContainsString('idno_', $message);
        self::assertStringContainsString('Nothing was changed', $message);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertSame('Idno cannot start: ' . $message . "\n", UpgradePage::text(SchemaStatus::OneXBesideCompletedUpgrade));
    }

    public function testAnUnsupportedServerIsToldWhatIsRequired(): void
    {
        $refusal = \Idno\Upgrade\PublicMessage::unsupportedServer('MySQL', '5.7.44');
        $html = UpgradePage::html(SchemaStatus::UnsupportedServer, null, '', self::NONCE, $refusal);

        self::assertStringContainsString('This database server is MySQL 5.7.44.', $html);
        self::assertStringContainsString('MariaDB 10.6 or later', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertSame("Idno cannot start: Idno needs MySQL 8.0 or later, or MariaDB 10.6 or later. This database server is MySQL 5.7.44.\n", UpgradePage::text(SchemaStatus::UnsupportedServer, $refusal));
    }

    /**
     * A request whose base path holds a backslash or a control character, built so that
     * getBasePath() really returns it.
     */
    public static function hostileRequest(string $directory): Request
    {
        return new Request([], [], [], [], [], ['SCRIPT_NAME' => $directory . '/index.php', 'SCRIPT_FILENAME' => '/srv/site/index.php', 'PHP_SELF' => $directory . '/index.php', 'REQUEST_URI' => $directory . '/_upgrade/step']);
    }

    /**
     * An unsafe base path (one that starts with `//`, or holds a backslash or a control character) is
     * replaced by '' (the site's root): the redirect then goes to `/` and the page posts
     * `/_upgrade/step`.
     */
    public function testAnUnsafeBasePathIsReplacedByTheRoot(): void
    {
        foreach (["/a\\b", "/a\tb", "/a\x7fb"] as $base) {
            $request = self::hostileRequest($base);
            self::assertSame($base, $request->getBasePath(), 'the precondition: the request really has this base path');
            self::assertSame('', UpgradePage::basePath($request), $base);
        }
        // Symfony reads a request URI that starts with `//` as a scheme-relative URL, so no request
        // it builds has such a base path today; the filter still refuses one.
        self::assertSame('', self::hostileRequest('//evil.example')->getBasePath());
        $schemeRelative = new class () extends Request {
            public function getBasePath(): string
            {
                return '//evil.example';
            }
        };
        self::assertSame('', UpgradePage::basePath($schemeRelative));
        self::assertSame('/blog', UpgradePage::basePath(self::hostileRequest('/blog')), 'a plain path is kept');
    }

    public function testTheStepUrlHonoursTheBasePath(): void
    {
        self::assertSame('/blog/_upgrade/step', UpgradePage::stepUrl('/blog'));
        self::assertSame('/_upgrade/step', UpgradePage::stepUrl(''));

        $html = UpgradePage::html(SchemaStatus::Empty, null, '/blog', self::NONCE);
        self::assertStringContainsString('action="/blog/_upgrade/step?html=1"', $html);
        self::assertStringContainsString('var url = "/blog/_upgrade/step";', $html);
    }

    public function testAnEmptyDatabaseIsBeingSetUp(): void
    {
        $html = UpgradePage::html(SchemaStatus::Empty, null, '', self::NONCE);

        self::assertStringContainsString('<h1>Idno is setting up its database</h1>', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('<form method="post" action="/_upgrade/step?html=1">', $html);
        self::assertStringContainsString('role="status" aria-live="polite"', $html);
    }

    public function testAOneXUpgradeSaysWhatTheSiteOwnerMustKnow(): void
    {
        $html = html_entity_decode(UpgradePage::html(SchemaStatus::Legacy, null, '', self::NONCE), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('You can close this page and open it again later', $html);
        self::assertStringContainsString('stop any Idno processes that were started before you replaced the files', $html);
        self::assertStringContainsString('Anything they write while the upgrade runs is not carried over', $html);
        self::assertStringContainsString('The old tables are kept, renamed with the prefix legacy_', $html);
        self::assertStringContainsString('If the upgrade stops, this page says why', $html);
    }

    /**
     * Nothing outside the document: no URL to any origin, no src or href at all, and every script
     * and style carries the nonce the CSP allows (and nothing else is allowed).
     */
    public function testThePageIsSelfContained(): void
    {
        foreach ([SchemaStatus::Empty, SchemaStatus::PendingMigrations, SchemaStatus::Legacy, SchemaStatus::LegacyTooOld, SchemaStatus::UnsupportedServer, SchemaStatus::RenamedWithoutRecord, SchemaStatus::OneXBesideCompletedUpgrade] as $status) {
            $html = UpgradePage::html($status, null, '', self::NONCE, \Idno\Upgrade\PublicMessage::unsupportedServer('MariaDB', '10.4.32'));

            self::assertDoesNotMatchRegularExpression('#https?:|//[a-z]#i', $html, $status->value);
            self::assertDoesNotMatchRegularExpression('/\s(src|href)=/i', $html, $status->value);
            self::assertSame(substr_count($html, '<script'), substr_count($html, '<script nonce="' . self::NONCE . '">'), $status->value);
            self::assertSame(1, substr_count($html, '<style nonce="' . self::NONCE . '">'), $status->value);
            self::assertSame(1, substr_count($html, '<h1>'), $status->value);
            self::assertStringContainsString('<html lang="en">', $html);
            self::assertStringContainsString('prefers-color-scheme: dark', $html);
        }
        self::assertSame(
            "default-src 'none'; script-src 'nonce-" . self::NONCE . "'; style-src 'nonce-" . self::NONCE . "'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            UpgradePage::contentSecurityPolicy(self::NONCE),
        );
    }

    public function testThePlainTextAnswers(): void
    {
        self::assertSame("Idno is upgrading its database.\n", UpgradePage::text(SchemaStatus::Legacy));
        self::assertSame("Idno is upgrading its database.\n", UpgradePage::text(SchemaStatus::Empty));
        self::assertStringContainsString('1.6.4', UpgradePage::text(SchemaStatus::LegacyTooOld));
    }

    /**
     * The script's limits and messages (its behaviour in a browser is not run by the test suite):
     * it honours Retry-After, stops with the generic sentence after MAX_ANSWERS_NOT_JSON answers that
     * are not JSON, and shows a step's notice after NOTICE_AFTER answers that carried it.
     */
    public function testTheScriptsLimitsAndMessages(): void
    {
        $html = UpgradePage::html(SchemaStatus::Legacy, null, '', self::NONCE);
        $encoded = (string) json_encode(\Idno\Upgrade\PublicMessage::stopped()->text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        self::assertSame(5, UpgradePage::MAX_ANSWERS_NOT_JSON);
        self::assertSame(3, UpgradePage::NOTICE_AFTER);
        self::assertStringContainsString('var maxGarbled = 5;', $html);
        self::assertStringContainsString('var noticeAfter = 3;', $html);
        self::assertStringContainsString('var stoppedText = ' . $encoded . ';', $html);
        self::assertStringContainsString("response.headers.get('Retry-After')", $html);
        self::assertStringContainsString('answer.response.status === 503', $html);
        self::assertStringContainsString('if (garbled >= maxGarbled) { stop(stoppedText); return; }', $html);
        self::assertStringContainsString('Math.min(60000, 2000 * Math.pow(2, failures - 1))', $html);
        self::assertStringNotContainsString('eval(', $html);
    }
}
