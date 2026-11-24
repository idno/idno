<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a request as the member the legacy application has already authenticated (CurrentUserSource), so
 * that Symfony Security knows who is making it. It decides nothing: it checks no credential, reads no header and no
 * session, and looks nothing up (the passport's user is the one the source built). A request nobody authenticated is
 * not supported and goes on anonymous. The firewall is stateless: Symfony never writes the session.
 */
final class CurrentUserAuthenticator extends AbstractAuthenticator
{
    private const ATTRIBUTE = '_idno_current_user';

    public function __construct(private readonly CurrentUserSource $source)
    {
    }

    public function supports(Request $request): ?bool
    {
        $user = $this->source->current($request);
        if ($user === null) {
            return false;
        }
        $request->attributes->set(self::ATTRIBUTE, $user);

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $user = $request->attributes->get(self::ATTRIBUTE);
        if (!$user instanceof SessionUser) {
            throw new AuthenticationException('Nobody is making this request.');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): SessionUser => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof SessionUser && $user->isStateless()) {
            StatelessAuthentication::mark($request);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
