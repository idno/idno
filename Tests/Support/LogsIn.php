<?php

declare(strict_types=1);

namespace Tests\Support;

use Idno\Core\Idno;
use Idno\Entities\User;
use PHPUnit\Framework\Assert;
use Tests\Characterisation\SiteClient;
use Tests\Characterisation\SiteResponse;

/**
 * What a test over HTTP needs to be somebody: log in through the login form, read the session cookie a response
 * sets, read the signed token of a form, and sign a request with a user's API key. The name of the session cookie
 * is read from the site's configuration, never assumed.
 *
 * The pure parts are static, so that a class that is not a test case (Tests\Acceptance\Browser) uses them too; the
 * parts that make requests use the using class's own site() (the black-box client of the site under test).
 */
trait LogsIn
{
    /**
     * The black-box client of the site under test.
     */
    abstract protected function site(): SiteClient;

    /**
     * Logs the user in through the login form, with a new password set for this test (the test users' passwords are
     * random), and returns the session cookie (`name=value`) of the logged-in user.
     */
    protected function logIn(User $user): string
    {
        $password = bin2hex(random_bytes(12));
        // Not setPassword(): that is a change of the password, which ends the user's other sessions, and a test that logs
        // one user in twice means two live sessions.
        $user->setPasswordHash((new \Idno\Security\Passwords())->hash($password));
        $user->save(true);

        return $this->loginCookie($user->handle, $password);
    }

    /**
     * Logs in through the login form (by handle or email address) and returns the session cookie of the logged-in
     * user; the test fails when the site does not log them in.
     */
    protected function loginCookie(string $handle, string $password): string
    {
        $form = $this->site()->request('GET', 'session/login');
        Assert::assertSame(200, $form->status, 'the login form draws');
        $cookie = self::sessionCookie($form);
        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => $handle, 'password' => $password]);
        Assert::assertSame(302, $login->status, 'the login answers with a redirect: ' . substr($login->body, 0, 1000));
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;
        Assert::assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, $handle . ' is logged in');

        return $cookie;
    }

    /**
     * The session cookie a response sets, as `name=value`; the test fails when there is none.
     */
    protected static function sessionCookie(SiteResponse $response): string
    {
        $found = self::sessionCookieOrNull($response);
        Assert::assertNotNull($found, 'a session cookie: ' . $response->header('Set-Cookie'));

        return $found;
    }

    /**
     * The session cookie a response sets, as `name=value`, or null.
     */
    protected static function sessionCookieOrNull(SiteResponse $response): ?string
    {
        $name = (string) Idno::site()->config()->sessionname;
        if (preg_match_all('/(?:^|[,;]\s*)' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m) > 0) {
            return $name . '=' . end($m[1]);
        }

        return null;
    }

    /**
     * The CSRF token fields (`__bTs`, `__bTk`, `__bTa`) of the form in $html whose action ends with $action.
     *
     * @return array<string, string>
     */
    protected static function formToken(string $html, string $action): array
    {
        foreach (preg_split('/<form\b/i', $html) ?: [] as $form) {
            if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                continue;
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                    $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                }
            }
            if (count($fields) === 3) {
                return $fields;
            }
        }
        Assert::fail('No signed form posting to ' . $action . ' in the page.');
    }

    /**
     * The headers that sign a request for $requestUri (the path and query as sent, with the leading slash) with the
     * user's API key.
     *
     * @return array<string, string>
     */
    protected static function signedHeaders(User $user, string $requestUri): array
    {
        return self::signedHeadersFor((string) $user->getHandle(), (string) $user->getAPIkey(), $requestUri);
    }

    /**
     * As signedHeaders(), for a user of another site (a site of the test's own), by handle and API key.
     *
     * @return array<string, string>
     */
    protected static function signedHeadersFor(string $handle, string $apiKey, string $requestUri): array
    {
        return [
            'X-IDNO-USERNAME' => $handle,
            'X-IDNO-SIGNATURE' => base64_encode(hash_hmac('sha256', $requestUri, $apiKey, true)),
        ];
    }
}
