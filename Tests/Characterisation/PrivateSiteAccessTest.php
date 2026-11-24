<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * Who reaches what on a private site (`walled_garden`, which `Session::publicGatekeeper()` reads on every page that
 * is not public): a visitor is denied the home page, a member or an administrator by session and by API signature
 * is not. The answers are what the site gives today, for the home page, the login page (a public route) and a page
 * that is not public.
 */
final class PrivateSiteAccessTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const CALLERS = ['anonymous', 'member', 'admin', 'member-signed', 'admin-signed'];

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1'];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: ?string}> path, caller, status, Location path
     */
    public static function cells(): array
    {
        // path, then the answer of each caller in the order of CALLERS: a status, or [status, Location path]
        $table = [
            ['/', [403, 200, 200, 200, 200]],
            ['/content/all', [403, 200, 200, 200, 200]],
            ['/session/login', [200, [302, '/'], [302, '/'], 200, 200]],
        ];
        $cells = [];
        foreach ($table as [$path, $answers]) {
            foreach (self::CALLERS as $i => $caller) {
                [$status, $location] = is_array($answers[$i]) ? $answers[$i] : [$answers[$i], null];
                $cells['GET ' . $path . ' as ' . $caller] = [$path, $caller, $status, $location];
            }
        }

        return $cells;
    }

    /**
     * @dataProvider cells
     */
    public function testWhatEachCallerReachesOnAPrivateSite(string $path, string $caller, int $status, ?string $location): void
    {
        $this->addUser('admin1', 'correct-horse-battery-9', null, true);
        $this->addUser('member1');
        $headers = match ($caller) {
            'anonymous' => [],
            'member' => ['Cookie' => $this->loginCookie('member1', 'correct-horse-battery-9')],
            'admin' => ['Cookie' => $this->loginCookie('admin1', 'correct-horse-battery-9')],
            'member-signed' => self::signedHeadersFor('member1', $this->apiKeyOf('member1'), $path),
            'admin-signed' => self::signedHeadersFor('admin1', $this->apiKeyOf('admin1'), $path),
        };

        $response = $this->site()->request('GET', ltrim($path, '/'), $headers);

        $this->assertSame($status, $response->status, $caller . ' on ' . $path . ': ' . substr($response->body, 0, 300));
        if ($location === null) {
            $this->assertNull($response->header('Location'));
        } else {
            $this->assertSame($location, parse_url((string) $response->header('Location'), PHP_URL_PATH));
        }
    }
}
