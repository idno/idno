<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\CurrentUserAuthenticator;
use Idno\Security\CurrentUserSource;
use Idno\Security\SessionUser;
use Idno\Security\StatelessAuthentication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class CurrentUserAuthenticatorTest extends TestCase
{
    private function source(?SessionUser $user, int &$calls = 0): CurrentUserSource
    {
        return new class($user, $calls) implements CurrentUserSource {
            public function __construct(private readonly ?SessionUser $user, private int &$calls)
            {
            }

            public function current(Request $request): ?SessionUser
            {
                ++$this->calls;

                return $this->user;
            }
        };
    }

    public function testNobodyIsNotSupported(): void
    {
        $authenticator = new CurrentUserAuthenticator($this->source(null));

        $this->assertFalse($authenticator->supports(Request::create('/')));
    }

    public function testTheSourcesUserIsThePassportsUserWithoutALookup(): void
    {
        $user = new SessionUser('uuid-1', 'ada', true);
        $calls = 0;
        $authenticator = new CurrentUserAuthenticator($this->source($user, $calls));
        $request = Request::create('/');

        $this->assertTrue($authenticator->supports($request));
        $passport = $authenticator->authenticate($request);

        $this->assertSame($user, $passport->getUser(), 'the user the source built, nothing looked up');
        $this->assertSame(1, $calls, 'the source is asked once');
    }

    public function testAuthenticatingWithoutSupportingFails(): void
    {
        $this->expectException(AuthenticationException::class);
        (new CurrentUserAuthenticator($this->source(null)))->authenticate(Request::create('/'));
    }

    public function testASuccessMarksAStatelessRequestAndOnlyThat(): void
    {
        $authenticator = new CurrentUserAuthenticator($this->source(null));
        $session = Request::create('/');
        $api = Request::create('/');

        $authenticator->onAuthenticationSuccess($session, $authenticator->createToken($this->passport(new SessionUser('u', 'ada', false)), 'main'), 'main');
        $authenticator->onAuthenticationSuccess($api, $authenticator->createToken($this->passport(new SessionUser('u', 'ada', false, true)), 'main'), 'main');

        $this->assertFalse(StatelessAuthentication::isStateless($session));
        $this->assertTrue(StatelessAuthentication::isStateless($api));
    }

    public function testAFailureAndASuccessAnswerNothingSoTheRequestGoesOn(): void
    {
        $authenticator = new CurrentUserAuthenticator($this->source(null));
        $request = Request::create('/');
        $token = $authenticator->createToken($this->passport(new SessionUser('u', 'ada', false)), 'main');

        $this->assertNull($authenticator->onAuthenticationFailure($request, new AuthenticationException()));
        $this->assertNull($authenticator->onAuthenticationSuccess($request, $token, 'main'));
    }

    private function passport(SessionUser $user): \Symfony\Component\Security\Http\Authenticator\Passport\Passport
    {
        return new \Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport(
            new \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge($user->getUserIdentifier(), static fn (): SessionUser => $user)
        );
    }
}
