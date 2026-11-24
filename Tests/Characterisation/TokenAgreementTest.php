<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * Symfony Security and the legacy session agree on who is making a request: for every caller of the authentication
 * matrix (AuthMatrixTest) `/_test/whoami` reports the same user as `/currentUser`, and the administrator role exactly
 * when the administration pages open; after a login and a logout in the same browser; in the queue worker's
 * LegacyUserContext::runAs() (no request: nobody left logged on, and nothing stateless left behind); and for an
 * in-process logUserOn() and logUserOff().
 */
final class TokenAgreementTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const CALLERS = ['anonymous', 'member', 'admin', 'member-signed', 'admin-signed', 'admin-session-member-signed'];

    /**
     * @return array<string, array{string}>
     */
    public static function callers(): array
    {
        return array_combine(self::CALLERS, array_map(static fn (string $c): array => [$c], self::CALLERS));
    }

    /**
     * @dataProvider callers
     */
    public function testSecurityAndTheSessionAgreeForEveryCaller(string $caller): void
    {
        $adminCookie = $this->registerUser('root');
        $this->addUser('ada');
        $memberKey = $this->apiKeyOf('ada');
        $adminKey = $this->apiKeyOf('root');

        $headers = fn (string $path): array => match ($caller) {
            'anonymous' => [],
            'member' => ['Cookie' => $this->loginCookie('ada', 'correct-horse-battery-9')],
            'admin' => ['Cookie' => $adminCookie],
            'member-signed' => self::signedHeadersFor('ada', $memberKey, $path),
            'admin-signed' => self::signedHeadersFor('root', $adminKey, $path),
            'admin-session-member-signed' => self::signedHeadersFor('ada', $memberKey, $path) + ['Cookie' => $adminCookie],
        };

        $whoami = $this->site()->request('GET', '_test/whoami', $headers('/_test/whoami'))->json();
        $current = $this->site()->request('GET', 'currentUser?_t=json', $headers('/currentUser?_t=json'));
        $adminPage = $this->site()->request('GET', 'admin/', $headers('/admin/'));

        if ($caller === 'anonymous') {
            $this->assertNull($whoami['uuid']);
            $this->assertSame(404, $current->status);
        } else {
            $legacy = $current->json()['user']['uuid'] ?? $current->json()['user']['id'] ?? null;
            $this->assertSame($legacy, $whoami['uuid'], $caller . ': Symfony Security names the user /currentUser does');
        }
        $this->assertSame(in_array('ROLE_ADMIN', $whoami['roles'], true), $adminPage->status === 200, $caller . ': the administrator role is what opens the administration');
        $this->assertSame(str_contains($caller, 'signed'), $whoami['stateless'], $caller);
    }

    public function testAfterALoginAndALogoutInTheSameBrowser(): void
    {
        $this->registerUser('root');
        $this->addUser('ada');

        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $this->assertNull($this->site()->request('GET', '_test/whoami', ['Cookie' => $cookie])->json()['uuid'], 'before the login');

        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => 'correct-horse-battery-9']);
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;
        $this->assertSame('ada', $this->site()->request('GET', '_test/whoami', ['Cookie' => $cookie])->json()['handle'], 'after the login');

        $page = $this->site()->request('GET', '', ['Cookie' => $cookie]);
        $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()], self::formToken($page->body, 'session/logout'));
        $this->assertNull($this->site()->request('GET', '_test/whoami', ['Cookie' => $cookie])->json()['uuid'], 'after the logout');
    }

    public function testTheQueueWorkersRunAsAndInProcessLogUserOnAgreeAndLeaveNobodyBehind(): void
    {
        $this->registerUser('root');
        $uuid = $this->addUser('ada');
        $other = $this->addUser('grace');

        $result = $this->runInSite(
            '$site = \Idno\Core\Idno::site(); $session = $site->session();
            $source = new \Idno\Legacy\LegacyCurrentUserSource(\Idno\Core\Idno::container()->get("kernel"));
            $context = new \Idno\Legacy\LegacyUserContext($session);
            $request = \Symfony\Component\HttpFoundation\Request::create("/");
            $who = static fn () => $source->current($request)?->getUserIdentifier();
            $out = ["before" => $who()];
            // a listener of an earlier message authenticated somebody statelessly: the next message is not that user
            $session->authenticateStatelessly(\Idno\Entities\User::getByUUID(' . $this->literal($other) . '));
            $out["stateless"] = $who();
            $out["inside"] = $context->runAs(' . $this->literal($uuid) . ', static fn () => [$who(), $session->currentUserUUID(), $session->isAPIRequest()]);
            $out["after"] = [$who(), $session->currentUser() === false, $session->isAPIRequest()];
            $out["nobody"] = $context->runAs(null, static fn () => [$who(), $session->isAPIRequest()]);
            $session->authenticateStatelessly(\Idno\Entities\User::getByUUID(' . $this->literal($other) . '));
            $context->ensureNobodyLoggedOn();
            $out["ensured"] = [$who(), $session->isAPIRequest()];
            // an in-process login and logoff
            $session->logUserOn(\Idno\Entities\User::getByUUID(' . $this->literal($uuid) . '));
            $out["logUserOn"] = [$who(), $session->currentUserUUID(), $source->current($request)?->getRoles()];
            $session->logUserOff();
            $out["logUserOff"] = $who();
            return $out;',
            true
        );

        $this->assertNull($result['before']);
        $this->assertSame($other, $result['stateless']);
        $this->assertSame([$uuid, $uuid, false], $result['inside'], 'the message runs as its user, and as nobody statelessly authenticated before it');
        $this->assertSame([null, true, false], $result['after'], 'nobody is left behind');
        $this->assertSame([null, false], $result['nobody']);
        $this->assertSame([null, false], $result['ensured']);
        $this->assertSame([$uuid, $uuid, ['ROLE_USER']], $result['logUserOn']);
        $this->assertNull($result['logUserOff']);
    }
}
