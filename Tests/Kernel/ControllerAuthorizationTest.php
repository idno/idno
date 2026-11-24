<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Tests\Characterisation\CharacterisationTestCase;
use Tests\Support\LogsIn;

/**
 * A new-style controller can ask for a logged-in user or a role (`#[IsGranted]`): `/_test/admin-only` and
 * `/_test/member-only` (Tests\Fixtures\WhoAmIController). Every refusal is Idno\Security\DeniedResponder's: status 403
 * with the legacy 403 page for a caller that wants HTML, `{"error":"forbidden"}` for one that wants JSON, for a
 * visitor and a member alike (never a login redirect), and a plain-text 403 when the legacy application that owns the
 * page is not booted.
 */
final class ControllerAuthorizationTest extends CharacterisationTestCase
{
    use LogsIn;

    public function testAnonymousGetsThe403Page(): void
    {
        $response = $this->site()->request('GET', '_test/admin-only');

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $this->assertNull($response->header('Location'), 'no login redirect');
        $this->assertStringContainsString('<html', $response->body, 'the legacy page, in its shell');
        $this->assertStringContainsString('id="soft-forward"', $response->body, 'the 403 page of a visitor: the link to log in');
        $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }

    public function testMemberGets403(): void
    {
        $response = $this->site()->request('GET', '_test/admin-only', ['Cookie' => $this->logIn($this->user())]);

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $this->assertNull($response->header('Location'));
        $this->assertStringContainsString('result-403', $response->body, 'the 403 page of a member: no access to this content');
    }

    public function testAdminGets200(): void
    {
        $response = $this->site()->request('GET', '_test/admin-only', ['Cookie' => $this->logIn($this->admin())]);

        $this->assertSame(200, $response->status);
        $this->assertSame(['admin' => true], $response->json());
    }

    public function testAMemberOnlyRouteOpensForAMemberAndNotForAVisitor(): void
    {
        $this->assertSame(200, $this->site()->request('GET', '_test/member-only', ['Cookie' => $this->logIn($this->user())])->status);
        $this->assertSame(403, $this->site()->request('GET', '_test/member-only')->status);
    }

    public function testApiSignedAdminGets403(): void
    {
        $response = $this->site()->request('GET', '_test/admin-only', self::signedHeaders($this->admin(), '/_test/admin-only'));

        $this->assertSame(403, $response->status, 'a signature makes nobody an administrator');
        $this->assertSame(200, $this->site()->request('GET', '_test/member-only', self::signedHeaders($this->admin(), '/_test/member-only'))->status, 'but a member');
    }

    public function testAJsonCallerGetsTheErrorObjectWhoeverItIs(): void
    {
        foreach ([[], ['Cookie' => $this->logIn($this->user())]] as $headers) {
            $response = $this->site()->request('GET', '_test/admin-only', $headers + ['Accept' => 'application/json']);

            $this->assertSame(403, $response->status);
            $this->assertSame(['error' => 'forbidden'], $response->json());
        }
        $this->assertSame(['error' => 'forbidden'], $this->site()->request('GET', '_test/admin-only?_t=json')->json());
    }

    public function testAnArrayForTheTemplateTypeIsStill403ForAVisitorAndAMember(): void
    {
        foreach ([[], ['Cookie' => $this->logIn($this->user())]] as $headers) {
            $this->assertSame(403, $this->site()->request('GET', '_test/admin-only?_t[]=x', $headers)->status);
        }
    }

    public function testTheDeniedPageSendsNoCrossOriginHeaderAndLeavesThePendingMessagesAlone(): void
    {
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'nobody-' . bin2hex(random_bytes(3)), 'password' => 'x']);

        $denied = $this->site()->request('GET', '_test/admin-only', ['Cookie' => $cookie]);

        $this->assertSame(403, $denied->status);
        $this->assertNull($denied->header('Access-Control-Allow-Origin'), 'a page of a controller is not readable by every site');
        $page = $this->site()->request('GET', 'session/login', ['Cookie' => $cookie]);
        $this->assertStringContainsString("couldn't find your username", \Tests\Acceptance\Browser::text($page->body), 'the message waiting in the session was not used up by the denied page');
    }

    public function testATemplateTypeThatIsNotHtmlOrJsonIsAnsweredInPlainText(): void
    {
        $response = $this->site()->request('GET', '_test/admin-only?_t=rss');

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('text/plain', (string) $response->header('Content-Type'));
        $this->assertStringNotContainsString('<rss', $response->body);
    }

    public function testWithoutTheLegacyApplicationTheAnswerIsPlain403(): void
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        try {
            $this->assertFalse($kernel->legacyBooted());
            $denied = $kernel->handle(Request::create('/_test/admin-only'));
            $public = $kernel->handle(Request::create('/_test/ping'));
            $whoami = $kernel->handle(Request::create('/_test/whoami'));
        } finally {
            $kernel->shutdown();
        }

        $this->assertSame(403, $denied->getStatusCode());
        $this->assertStringStartsWith('text/plain', (string) $denied->headers->get('Content-Type'));
        $this->assertSame("Forbidden\n", $denied->getContent());
        $this->assertSame(200, $public->getStatusCode(), 'a public controller answers with the firewall in place and no legacy application');
        $this->assertSame('pong', $public->getContent());
        $this->assertNull(json_decode((string) $whoami->getContent(), true)['uuid'], 'nobody is making a request the legacy application never authenticated');
    }
}
