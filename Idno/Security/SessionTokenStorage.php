<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;

/**
 * Where the CSRF token manager keeps the form token: in PHP's session, when one is active, through Symfony's
 * NativeSessionTokenStorage (which works directly on `$_SESSION`; the legacy application owns the session, so Symfony
 * needs neither a session of its own nor a request stack). That class's whole job is the native session, and so is
 * this one's: it asks PHP only whether a session is active (`session_status()`), never reads a superglobal itself.
 *
 * Forms are also drawn where no session is active: the console, the queue worker (the archive export draws entities
 * with action links) and a test in-process. NativeSessionTokenStorage would start a session there, which sends headers
 * and writes a session nobody asked for. This storage never starts one: with none active the token lives in this
 * object, so a process draws one token for as long as it lives, and nothing is written or sent.
 */
final class SessionTokenStorage implements ClearableTokenStorageInterface
{
    private readonly NativeSessionTokenStorage $session;

    /** @var array<string, string> */
    private array $process = [];

    public function __construct()
    {
        $this->session = new NativeSessionTokenStorage();
    }

    public function getToken(string $tokenId): string
    {
        if ($this->active()) {
            return $this->session->getToken($tokenId);
        }
        if (!isset($this->process[$tokenId])) {
            throw new TokenNotFoundException('The CSRF token with ID ' . $tokenId . ' does not exist.');
        }

        return $this->process[$tokenId];
    }

    public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
    {
        if ($this->active()) {
            $this->session->setToken($tokenId, $token);

            return;
        }
        $this->process[$tokenId] = $token;
    }

    public function hasToken(string $tokenId): bool
    {
        return $this->active() ? $this->session->hasToken($tokenId) : isset($this->process[$tokenId]);
    }

    public function removeToken(string $tokenId): ?string
    {
        if ($this->active()) {
            return $this->session->removeToken($tokenId);
        }
        $token = $this->process[$tokenId] ?? null;
        unset($this->process[$tokenId]);

        return $token;
    }

    public function clear(): void
    {
        if ($this->active()) {
            $this->session->clear();
        }
        $this->process = [];
    }

    private function active(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }
}
