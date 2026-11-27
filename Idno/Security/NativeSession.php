<?php

declare(strict_types=1);

namespace Idno\Security;

/**
 * The ONE owner of PHP's session: the only class of the application that calls PHP's `session_*` functions, sets a `session.` ini value
 * or reads the session's cookie from `$_COOKIE` (`Tests/Unit/Security/SessionOwnerTest` fails for any other file that does). Each method
 * is one primitive and nothing more, in the order and with the arguments the legacy session (`Idno\Core\Session`) has always made the
 * call with; the rules (who is signed in, when a request is stateless, what a logout does) stay in that class, which calls these.
 *
 * Shared, built with no argument. It holds one fact, whether the request carried the session's cookie (see start()).
 */
final class NativeSession
{
    /** The session lives this long, in seconds (a week): the cookie's lifetime and the garbage collection's. */
    private const LIFETIME = 60 * 60 * 24 * 7;

    private bool $carriedCookie = false;

    /**
     * The ini values of the site's sessions, before any handler is installed (so that a handler a plugin supplies sees them).
     */
    public function configure(SessionSettings $settings): void
    {
        ini_set('session.cookie_lifetime', self::LIFETIME); // Persistent cookies
        ini_set('session.gc_maxlifetime', self::LIFETIME); // Garbage collection to match

        ini_set('session.use_only_cookies', true); // Only cookies for session
        ini_set('session.cookie_httponly', true); // Restrict cookies to HTTP only (help reduce XSS attack profile)
        ini_set('session.cookie_samesite', 'Lax'); // Not sent with cross-site POSTs: the general defence for handlers that skip the form token
        ini_set('session.use_strict_mode', true); // Help mitigate session fixation
        ini_set('session.use_trans_sid', false); // Prevent transparent IDs
        if ($settings->secure) {
            ini_set('session.cookie_secure', true); // Set secure cookies when site is secure
        }
    }

    /**
     * Starts the session: the save path when given (only for a site whose sessions no handler stores), the garbage collection's odds when
     * given (`['probability' => int, 'divisor' => int]`), the cookie's name (none: PHP's own default), the `nocache` limiter (the lines that keep every page out of
     * shared caches are the site's own, whatever a host's php.ini says) and `session_start()`. A session a handler has started itself is
     * left as it is. Notes whether the request carried this session's cookie.
     *
     * @param array{probability: int, divisor: int}|null $collection
     */
    public function start(?string $name, ?string $savePath = null, ?array $collection = null): void
    {
        if ($savePath !== null) {
            session_save_path($savePath);
        }
        if ($collection !== null) {
            ini_set('session.gc_probability', (string) $collection['probability']);
            ini_set('session.gc_divisor', (string) $collection['divisor']);
        }
        if (!$this->isActive()) {
            if ($name !== null) {
                session_name($name); // none: PHP's own default name
            }
            session_cache_limiter('nocache');
            session_start();
        }
        $carried = $_COOKIE[session_name()] ?? null;
        $this->carriedCookie = is_string($carried) && $carried === session_id();
    }

    /**
     * Whether the request carried the cookie of the session start() began (a session that already existed; false again once startNew() begins another), and not none or one for a
     * session that does not exist.
     */
    public function carriedCookie(): bool
    {
        return $this->carriedCookie;
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function id(): string
    {
        return session_id();
    }

    public function name(): string
    {
        return session_name();
    }

    /**
     * Installs a session save handler (`session_write_close()` is called at the end of the request through it).
     */
    public function useHandler(\SessionHandlerInterface $handler): void
    {
        session_set_save_handler($handler, true);
    }

    /** A new id for the open session, the data kept and the old one deleted. */
    public function regenerate(): void
    {
        @session_regenerate_id(true);
    }

    public function destroy(): void
    {
        @session_destroy();
    }

    /** Lets go of the open session without writing it. */
    public function abort(): void
    {
        if ($this->isActive()) {
            session_abort();
        }
    }

    /** Writes the open session and closes it. */
    public function close(): void
    {
        if ($this->isActive()) {
            session_write_close();
        }
    }

    /**
     * Begins another session under a new id after the last one was destroyed: its cookie follows it.
     *
     * @return bool whether it started
     */
    public function startNew(): bool
    {
        session_id(session_create_id());
        $started = @session_start();
        // a session begun here was carried by no request
        $this->carriedCookie = false;

        return $started;
    }

    /**
     * Expires the session's cookie in the browser, with the attributes the cookie was set with and `SameSite=Lax` (nothing under
     * the unit tests, which have no response to carry it).
     */
    public function expireCookie(): void
    {
        if (defined('IDNO_UNIT_TEST')) {
            return;
        }
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]
            );
        }
    }

    /**
     * Takes the session's own `Set-Cookie` line off the response PHP has queued (the other cookies stay).
     */
    public function removeCookieLine(): void
    {
        $prefix  = session_name() . '=';
        $cookies = [];
        $found   = false;
        foreach (headers_list() as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookie = trim(substr($line, strlen('Set-Cookie:')));
            if (str_starts_with($cookie, $prefix)) {
                $found = true;
            } else {
                $cookies[] = $cookie;
            }
        }
        if (!$found) {
            return;
        }
        header_remove('Set-Cookie');
        foreach ($cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
    }
}
