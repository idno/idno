<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * The form token: ONE per session, token id `idno`, held by Symfony's CSRF token manager on the session's own
 * storage. Every form carries it (`__bTk`), a legacy page checks it (`Bonita\Forms::validateToken()`) and a controller
 * checks it with `#[IsCsrfTokenValid(FormToken::ID, tokenKey: FormToken::FIELD)]`: the same token, the same manager.
 *
 * What a form prints is the STORED value, 64 lower-case hex characters (HexTokenGenerator), not the manager's
 * per-call randomised form: the token is stable for the session, as it always looked, and a script that is handed
 * it later holds the same one. The manager accepts the plain value, so `#[IsCsrfTokenValid]` needs nothing special.
 * The token is good for as long as the session is; logging in replaces it (`forget()`) and logging out empties the session, so it never
 * outlives the session id it was made for.
 */
final class FormToken
{
    /** The token's id. */
    public const ID = 'idno';

    /** The request field a form posts it in. */
    public const FIELD = '__bTk';

    private const SHAPE = '/\A[0-9a-f]{64}\z/';

    public function __construct(
        private readonly CsrfTokenManagerInterface $manager,
        private readonly TokenStorageInterface $storage,
    )
    {
    }

    /**
     * The session's token, made on first use.
     */
    public function value(): string
    {
        $this->manager->getToken(self::ID);

        return $this->storage->getToken(self::ID);
    }

    /**
     * Whether $submitted is the session's token. Anything that is not a string of the token's shape (an array, a
     * number, a randomised form, nothing) is simply not it.
     */
    public function isValid(mixed $submitted): bool
    {
        if (!is_string($submitted) || preg_match(self::SHAPE, $submitted) !== 1) {
            return false;
        }

        return $this->manager->isTokenValid(new CsrfToken(self::ID, $submitted));
    }

    /**
     * Replaces the session's token with a new one, at once (a page draws its forms after the session is closed, which
     * is too late to store a token): the next form is drawn with it.
     */
    public function forget(): void
    {
        $this->manager->refreshToken(self::ID);
    }

    /**
     * The variables of a page without anything of the form token, for a shell that prints them (JSON, JSONP): a `csrf`
     * key at the top level (the list an earlier release's forms kept there), and, at any depth of an array, every
     * entry whose string value holds the token in the form it is stored in (compared without regard to letter case).
     * An object is left as it is. A shell that prints variables is a script any site can load (JSONP): the token must
     * never be among them.
     *
     * @param array<mixed> $vars
     *
     * @return array<mixed>
     */
    public static function scrub(array $vars, string $token): array
    {
        unset($vars['csrf']);

        return self::withoutToken($vars, $token);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function withoutToken(array $value, string $token): array
    {
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::withoutToken($item, $token);
            } elseif ($token !== '' && is_string($item) && stripos($item, $token) !== false) {
                unset($value[$key]);
            }
        }

        return $list ? array_values($value) : $value;
    }
}
