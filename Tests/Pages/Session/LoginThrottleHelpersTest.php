<?php

declare(strict_types=1);

namespace Tests\Pages\Session;

use Idno\Pages\Session\Login;
use Idno\Security\LoginThrottle;

/**
 * Plugins (and the legacy pages that ask for the current password) still call the static throttle helpers of `Login`. They are no page now, only these.
 */
final class LoginThrottleHelpersTest extends \Tests\IdnoTestCase
{
    /**
     * Catches: the helpers a plugin calls before it checks a password no longer counting or clearing attempts (a plugin's own login form would then be
     * guessable without limit), or counting them under another key than the login page's, so that a guess at the plugin's form and one at the login page
     * would not share the five.
     */
    public function testTheDeprecatedHelpersCountAndClearAnAccountsAttemptsUnderTheLoginPagesKey(): void
    {
        $user = (new \ReflectionClass(\Idno\Entities\User::class))->newInstanceWithoutConstructor();
        $user->uuid = 'https://example.test/view/' . bin2hex(random_bytes(8));

        $key = Login::throttleKeyFor($user);

        $this->assertSame(LoginThrottle::keyForAccount((string) $user->uuid), $key);
        for ($n = 1; $n <= LoginThrottle::LIMIT; $n++) {
            $this->assertTrue(Login::throttleAttempt($key), 'attempt ' . $n);
        }
        $this->assertFalse(Login::throttleAttempt($key), 'the sixth is refused');
        Login::throttleReset($key);
        $this->assertTrue(Login::throttleAttempt($key), 'a cleared count starts again');
        Login::throttleReset($key);
    }
}
