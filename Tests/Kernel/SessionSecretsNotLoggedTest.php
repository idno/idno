<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * No log line, at any level, holds a credential: an API signature (given or expected), a user's API key or an
 * access token. The site runs at the debug level, the most it writes, and its log is the web server's.
 */
final class SessionSecretsNotLoggedTest extends PrivateSiteTestCase
{
    use LogsIn;

    protected function siteEnvironment(): array
    {
        return ['IDNO_LOGLEVEL' => '4'];
    }

    public function testNoCredentialReachesTheLog(): void
    {
        $this->addUser('signer');
        $apiKey = $this->apiKeyOf('signer');
        $token = bin2hex(random_bytes(16));
        $this->runInSite(
            '$u = \Idno\Entities\User::getByHandle(\'signer\'); $u->indieauth_tokens = [' . $this->literal($token) . ' => ['
            . '\'me\' => \'http://example.com/\', \'scope\' => \'create\', \'client_id\' => \'http://client.example.com/\', \'issued_at\' => time()]]; $u->save(true); return true;'
        );

        $uri = '/currentUser?_t=json';
        $good = self::signedHeadersFor('signer', $apiKey, $uri);
        $wrong = ['X-IDNO-USERNAME' => 'signer', 'X-IDNO-SIGNATURE' => base64_encode(random_bytes(32))];
        $expected = base64_encode(hash_hmac('sha256', $uri, $apiKey, true));

        $this->assertSame(200, $this->site()->request('GET', ltrim($uri, '/'), $good)->status);
        $this->site()->request('GET', ltrim($uri, '/'), $wrong);
        $this->assertSame(200, $this->site()->request('GET', 'micropub/endpoint?q=config', ['Authorization' => 'Bearer ' . $token])->status);
        $this->site()->request('GET', 'micropub/endpoint?q=config&access_token=' . $token);
        $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer ' . $token], ['h' => 'entry', 'content' => 'x', 'access_token' => $token]);
        $this->site()->request('POST', 'micropub/endpoint', ['Content-Type' => 'application/json'], json_encode(['access_token' => $token, 'type' => ['h-entry'], 'properties' => ['content' => ['x']]]));

        $log = $this->serverLogContents();
        $this->assertStringContainsString('API auth', $log, 'the site logged at the debug level');
        $this->assertStringContainsString('MicroPub endpoint pinged', $log);
        foreach (['the signature the client sent' => $good['X-IDNO-SIGNATURE'], 'the expected signature' => $expected, 'a wrong signature' => $wrong['X-IDNO-SIGNATURE'], 'the API key' => $apiKey, 'the access token' => $token] as $what => $secret) {
            $this->assertStringNotContainsString($secret, $log, $what . ' is in the log');
        }
    }
}
