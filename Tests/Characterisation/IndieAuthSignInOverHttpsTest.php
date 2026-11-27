<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A site whose configured `url` is http but which is reached over https (TLS added without changing `url`; here a request that says so with
 * `X-Forwarded-Proto: https` and no `trusted_proxies` setting, as `LoginApiKeyTest` does).
 */
final class IndieAuthSignInOverHttpsTest extends PrivateSiteTestCase
{
    /**
     * Catches: a member who signs in after an IndieAuth client sent them to the login page landing on the home page instead of the authorization screen
     * (the client's sign-in then fails with no message). IndieAuth builds the address to come back to from the CONFIGURED url (http), which the redirect
     * rule must make https, not refuse, when the request is https.
     */
    public function testAMemberReturnsToTheAuthorizationScreenAfterSigningInOverHttps(): void
    {
        $this->registerUser('ada');
        $https = ['X-Forwarded-Proto' => 'https'];
        $site = preg_replace('#^http:#', 'https:', $this->baseUrl());

        $auth = $this->site()->request('GET', 'indieauth/auth?me=' . rawurlencode($this->baseUrl()) . '&client_id=https://client.invalid/&redirect_uri=https://client.invalid/cb&state=x&response_type=code', $https);
        $this->assertSame(302, $auth->status);
        $login = (string) $auth->header('Location');
        $this->assertStringStartsWith($site . 'session/login/?fwd=', $login);

        $form = $this->site()->request('GET', substr($login, strlen($site)), $https);
        $this->assertSame(200, $form->status);
        $cookie = self::sessionCookie($form);
        $this->assertSame(1, preg_match('/name="fwd" value="([^"]*)"/', $form->body, $m), 'the form carries the page to go to');
        $this->assertStringStartsWith($site . 'indieauth/auth?', html_entity_decode($m[1], ENT_QUOTES), 'the page to go to is the authorization screen, over https');

        $posted = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie] + $https, self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => 'correct-horse-battery-9', 'fwd' => html_entity_decode($m[1], ENT_QUOTES)]);
        $this->assertSame(302, $posted->status);
        $then = $this->site()->request('GET', substr((string) $posted->header('Location'), strlen($site)), ['Cookie' => self::sessionCookieOrNull($posted) ?? $cookie] + $https);

        $this->assertSame(302, $then->status);
        $this->assertStringStartsWith($site . 'indieauth/auth?', (string) $then->header('Location'), 'on to the authorization screen, not the home page');
    }
}
