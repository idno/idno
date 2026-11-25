<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * The lines that keep every page out of shared caches (`Expires` in 1981, `Cache-Control: no-store, no-cache, must-revalidate`,
 * `Pragma: no-cache`) are the session's cache limiter's, `nocache`. The site sets it itself before it starts the session, so a host whose
 * php.ini says `public`, `private`, `private_no_expire` or nothing still sends them (CachingHeadersTest pins them for the host's default).
 */
final class SessionCacheLimiterTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const PASSWORD = 'correct-horse-battery-9';

    /**
     * @return array<string, array{0: string}>
     */
    public static function limiters(): array
    {
        return ['public' => ['public'], 'private' => ['private'], 'private_no_expire' => ['private_no_expire'], 'none' => ['']];
    }

    /**
     * @dataProvider limiters
     */
    public function testAHostConfiguredForAnotherLimiterStillSendsTheNoCacheLines(string $limiter): void
    {
        $this->addUser('ada', self::PASSWORD, null, true);
        $member = $this->loginCookie('ada', self::PASSWORD);
        $client = new HttpSiteClient($this->startExtraServer([], ['session.cache_limiter' => $limiter]));

        foreach ([[], ['Cookie' => $member]] as $headers) {
            foreach (['', '_test/site-page/draw-new?case=draw', '_test/site-page/draw-legacy?case=draw', 'robots.txt'] as $path) {
                $response = $client->request('GET', $path, $headers);
                $label = '[' . $limiter . '] ' . $path . ' ' . json_encode(array_keys($headers));

                $this->assertSame(200, $response->status, $label);
                $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $response->lines('Expires'), $label);
                $this->assertSame(['no-store, no-cache, must-revalidate'], $response->lines('Cache-Control'), $label);
                $this->assertSame(['no-cache'], $response->lines('Pragma'), $label);
            }
        }
    }
}
