<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A signed API request (`X-IDNO-USERNAME` / `X-IDNO-SIGNATURE`) and the browser sessions of a site: the request leaves every session that is
 * open as it was, is the signed member whatever its method (a POST or a DELETE to a post's permalink edits or deletes it with no form token),
 * and leaves no session of its own behind, whatever page it reaches (a page that draws closes the session before the request ends).
 */
final class SignedRequestSessionsTest extends PrivateSiteTestCase
{
    use LogsIn;

    private function loggedIn(string $cookie): bool
    {
        return $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status === 200;
    }

    /**
     * The administrator's browser, the member's own browser, the member's API key and a public note of the member's (its path).
     *
     * @return array{string, string, string, string}
     */
    private function siteWithTwoBrowsers(): array
    {
        $admin = $this->registerUser('root');
        $this->addUser('ada');
        $ada = $this->loginCookie('ada', 'correct-horse-battery-9');
        $key = $this->apiKeyOf('ada');
        $note = (string) parse_url($this->publishStatus($ada, 'Ada writes a note.'), PHP_URL_PATH);
        $this->assertTrue($this->loggedIn($admin));
        $this->assertTrue($this->loggedIn($ada));

        return [$admin, $ada, $key, $note];
    }

    /** @return array<string, array{string}> */
    public static function readablePaths(): array
    {
        return ['a legacy page' => ['/currentUser?_t=json'], 'the home page' => ['/'], 'the home page as json' => ['/?_t=json'], 'a feed' => ['/feed'], 'a permalink' => ['{note}']];
    }

    /** @dataProvider readablePaths */
    public function testASignedRequestWithNoCookieLeavesTheBrowserSessionsLoggedIn(string $path): void
    {
        [$admin, $ada, $key, $note] = $this->siteWithTwoBrowsers();
        $path = str_replace('{note}', $note, $path);

        $signed = $this->site()->request('GET', ltrim($path, '/'), self::signedHeadersFor('ada', $key, $path));
        $this->assertLessThan(500, $signed->status, $this->serverLog());

        $this->assertTrue($this->loggedIn($admin), 'the administrator\'s browser is still logged in');
        $this->assertTrue($this->loggedIn($ada), 'the member\'s own browser is still logged in');
    }

    /** @return array<string, array{bool}> */
    public static function cookies(): array
    {
        return ['with no cookie' => [false], 'carrying the administrator\'s cookie' => [true]];
    }

    /** @dataProvider cookies */
    public function testASignedPostToAPermalinkEditsThePostAsTheSignedOwner(bool $withCookie): void
    {
        [$admin, $ada, $key, $note] = $this->siteWithTwoBrowsers();
        $headers = self::signedHeadersFor('ada', $key, $note) + ($withCookie ? ['Cookie' => $admin] : []);

        $answer = $this->site()->request('POST', ltrim($note, '/'), $headers, ['body' => 'Ada changes her note, signed.']);

        $this->assertSame(200, $answer->status, 'a signed POST is answered in JSON, not forwarded as a request with no token: ' . $answer->body);
        $this->assertStringContainsString('Ada changes her note, signed.', $this->site()->request('GET', ltrim($note, '/'))->body, 'the note was changed');
        $this->assertTrue($this->loggedIn($admin));
        $this->assertTrue($this->loggedIn($ada));
    }

    /** @dataProvider cookies */
    public function testASignedDeleteOfAPermalinkDeletesThePostOfTheSignedOwner(bool $withCookie): void
    {
        [$admin, $ada, $key, $note] = $this->siteWithTwoBrowsers();
        $headers = self::signedHeadersFor('ada', $key, $note . '?x=1') + ($withCookie ? ['Cookie' => $admin] : []);

        $answer = $this->site()->request('DELETE', ltrim($note, '/') . '?x=1', $headers);

        $this->assertSame(200, $answer->status, 'a signed DELETE needs no form token: ' . substr($answer->body, 0, 500));
        $this->assertSame(410, $this->site()->request('GET', ltrim($note, '/'))->status, 'the note is gone');
        $this->assertTrue($this->loggedIn($admin));
        $this->assertTrue($this->loggedIn($ada));
    }

    public function testASignedDeleteByAnotherMemberIsRefused(): void
    {
        [$admin, , , $note] = $this->siteWithTwoBrowsers();
        $this->addUser('bea');
        $beasKey = $this->apiKeyOf('bea');

        $answer = $this->site()->request('DELETE', ltrim($note, '/'), self::signedHeadersFor('bea', $beasKey, $note));

        $this->assertSame(403, $answer->status, substr($answer->body, 0, 500));
        $this->assertSame(200, $this->site()->request('GET', ltrim($note, '/'))->status, 'the note is still there');
        $this->assertTrue($this->loggedIn($admin));
    }

    /**
     * The session a signed request with no cookie STARTED is destroyed at the end of the request (AGENTS.md: "an API client leaves no session
     * behind"): also when the page it reaches draws, which closes the session (`session_write_close()`) before the request ends.
     *
     * @dataProvider readablePaths
     */
    public function testASignedRequestWithNoCookieLeavesNoSessionOfItsOwnBehind(string $path): void
    {
        [, , $key, $note] = $this->siteWithTwoBrowsers();
        $path = str_replace('{note}', $note, $path);
        $sessions = $this->sessionIds();
        $this->assertNotSame([], $sessions, 'the site writes its sessions here (the two browsers made some)');

        $this->site()->request('GET', ltrim($path, '/'), self::signedHeadersFor('ada', $key, $path));

        $this->assertSame($sessions, $this->sessionIds(), 'no session is left behind by an API client, and none of the others is removed');
    }

    public function testASignedRequestWithNoCookieIsNotGivenASessionCookie(): void
    {
        [, , $key, $note] = $this->siteWithTwoBrowsers();
        foreach (['/currentUser?_t=json', $note, '/'] as $path) {
            $answer = $this->site()->request('GET', ltrim($path, '/'), self::signedHeadersFor('ada', $key, $path));
            $this->assertSame([], array_values(array_filter($answer->lines('set-cookie'), static fn (string $cookie): bool => str_starts_with($cookie, Idno::site()->config()->sessionname . '='))), $path);
        }
    }

    /** A message added after the request became stateless is still drawn: the session is gone, the array in memory is not. */
    public function testAMessageOfASignedRequestIsStillDrawn(): void
    {
        [, , $key, $note] = $this->siteWithTwoBrowsers();
        $answer = $this->site()->request('GET', ltrim($note, '/') . '?_t=json', self::signedHeadersFor('ada', $key, $note . '?_t=json'));
        $this->assertSame(200, $answer->status);
        $this->assertNotSame('', $answer->body);
    }

    /**
     * The ids of the site's sessions, sorted: the names of the session files of its own directory (SQLite), or the `session_id` of each row of its
     * `idno_sessions` table (MySQL and MariaDB).
     *
     * @return list<string>
     */
    private function sessionIds(): array
    {
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) === 'sqlite') {
            $files = array_map('basename', glob($this->sessionDirectory() . '/sess_*') ?: []);
            sort($files);

            return $files;
        }
        $ids = array_map('strval', $this->siteConnection()->fetchFirstColumn('select session_id from idno_sessions'));
        sort($ids);

        return $ids;
    }
}
