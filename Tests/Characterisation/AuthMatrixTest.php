<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;

/**
 * Who reaches what, as the site answers today: a handful of pages by each kind of caller. Each cell is a page,
 * a method and a caller, and the status and (for a redirect) the path of the Location header written from what the
 * site does. The cells the other tests pin (an API request on an account page, a bearer token, the Micropub
 * endpoint) are not repeated here beyond the matrix's own rows; this is the one place that shows the whole table.
 *
 * Callers: `anonymous`; `member` and `admin` by the session of a fresh login; `member-signed` and `admin-signed` by
 * the API signature (X-IDNO-USERNAME and X-IDNO-SIGNATURE, made with the user's API key) and no cookie;
 * `admin-session-member-signed`: the admin's session cookie and the member's signature in one request.
 */
final class AuthMatrixTest extends CharacterisationTestCase
{
    use LogsIn;

    private const CALLERS = ['anonymous', 'member', 'admin', 'member-signed', 'admin-signed', 'admin-session-member-signed'];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: ?string}> method, path, caller, status, Location path
     */
    public static function cells(): array
    {
        // method, path, then the answer of each caller in the order of CALLERS: a status, or [status, Location path]
        $table = [
            ['GET', '/', [200, 200, 200, 200, 200, 200]],
            ['GET', '/account/settings/', [403, 200, 200, 403, 403, 403]],
            ['GET', '/admin/', [403, 403, 200, 403, 403, 403]],
            ['GET', '/status/edit', [403, 200, 200, 200, 200, 200]],
            ['GET', '/session/login', [200, [302, '/'], [302, '/'], 200, 200, 200]],
            ['GET', '/currentUser', [404, 200, 200, 200, 200, 200]],
            ['POST', '/currentUser', [[302, '/'], [302, '/'], [302, '/'], 200, 200, 200]],
        ];
        $cells = [];
        foreach ($table as [$method, $path, $answers]) {
            foreach (self::CALLERS as $i => $caller) {
                $answer = $answers[$i];
                [$status, $location] = is_array($answer) ? $answer : [$answer, null];
                $cells[$method . ' ' . $path . ' as ' . $caller] = [$method, $path, $caller, $status, $location];
            }
        }

        return $cells;
    }

    /**
     * @dataProvider cells
     */
    public function testWhatEachCallerReaches(string $method, string $path, string $caller, int $status, ?string $location): void
    {
        $response = $this->site()->request($method, ltrim($path, '/'), $this->headersFor($caller, $path), $method === 'POST' ? [] : null);

        $this->assertSame($status, $response->status, $method . ' ' . $path . ' as ' . $caller . ': ' . substr($response->body, 0, 300));
        if ($location === null) {
            $this->assertNull($response->header('Location'), 'no redirect');
        } else {
            $this->assertSame($location, parse_url((string) $response->header('Location'), PHP_URL_PATH));
        }
    }

    public function testASignedRequestAnswersInJsonWhateverThePage(): void
    {
        $response = $this->site()->request('GET', '', self::signedHeaders($this->user(), '/'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('json', (string) $response->header('Content-Type'));
        $this->assertArrayHasKey('items', $response->json());
    }

    public function testASignedRequestThatCarriesAnotherUsersSessionActsAsTheSignedUserAndEndsThatSession(): void
    {
        $admin = $this->admin();
        $member = $this->user();
        $cookie = $this->logIn($admin);
        $this->assertSame(200, $this->site()->request('GET', 'admin/', ['Cookie' => $cookie])->status, 'the admin is logged in');

        $response = $this->site()->request('GET', 'currentUser', self::signedHeaders($member, '/currentUser') + ['Cookie' => $cookie]);

        $this->assertSame(200, $response->status);
        $this->assertSame($member->getUUID(), $response->json()['user']['uuid'] ?? $response->json()['user']['id'] ?? null, 'the request acts as the signed user, not as the session');
        $this->assertSame(403, $this->site()->request('GET', 'admin/', ['Cookie' => $cookie])->status, 'the session the request carried is gone afterwards');
    }

    public function testASignedRequestWithAWrongSignatureIsAnonymous(): void
    {
        $headers = self::signedHeaders($this->user(), '/status/edit');
        $headers['X-IDNO-SIGNATURE'] = base64_encode('not the signature');

        $response = $this->site()->request('GET', 'status/edit', $headers);

        $this->assertSame(403, $response->status);
    }

    public function testASignatureMadeForAnotherPathIsRefused(): void
    {
        $response = $this->site()->request('GET', 'status/edit', self::signedHeaders($this->user(), '/currentUser'));

        $this->assertSame(403, $response->status);
    }

    public function testASignatureCoversTheQueryString(): void
    {
        $member = $this->user();

        $signed = $this->site()->request('GET', 'currentUser?x=1', self::signedHeaders($member, '/currentUser?x=1'));
        $unsigned = $this->site()->request('GET', 'currentUser?x=1', self::signedHeaders($member, '/currentUser'));

        $this->assertSame(200, $signed->status);
        $this->assertSame(404, $unsigned->status, 'a signature over the path alone does not cover a request with a query');
    }

    public function testTheOlderHeaderNamesStillSign(): void
    {
        $member = $this->user();
        $headers = [
            'X-KNOWN-USERNAME' => $member->getHandle(),
            'X-KNOWN-SIGNATURE' => base64_encode(hash_hmac('sha256', '/currentUser', (string) $member->getAPIkey(), true)),
        ];

        $this->assertSame(200, $this->site()->request('GET', 'currentUser', $headers)->status);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $caller, string $path): array
    {
        return match ($caller) {
            'anonymous' => [],
            'member' => ['Cookie' => $this->logIn($this->user())],
            'admin' => ['Cookie' => $this->logIn($this->admin())],
            'member-signed' => self::signedHeaders($this->user(), $path),
            'admin-signed' => self::signedHeaders($this->admin(), $path),
            'admin-session-member-signed' => self::signedHeaders($this->user(), $path) + ['Cookie' => $this->logIn($this->admin())],
        };
    }
}
