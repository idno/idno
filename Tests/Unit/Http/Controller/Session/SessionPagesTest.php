<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Session;

use Idno\Entities\User;
use Idno\Http\Controller\Session\SessionPages;
use Idno\Http\Controller\Session\SignIn;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Security\LoginAttempts;
use Idno\Security\LoginThrottle;
use Idno\Security\Passwords;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Tests\Unit\Http\Controller\Discovery\DiscoveryDoubles;

require_once dirname(__DIR__) . '/Discovery/DiscoveryDoubles.php';

/**
 * The sign-in page's rules that the recorded answers over HTTP cannot show: what happens while another request is in the middle of its password check, and
 * what a name nobody has costs.
 */
final class SessionPagesTest extends TestCase
{
    private function pages(SignInDouble $site, ?\Closure $spend = null): SessionPages
    {
        $throttle = new LoginThrottle(new ArrayAdapter());
        $attempts = new LoginAttempts(static fn (): LoginThrottle => $throttle, static fn (): NullLogger => new NullLogger());

        return new SessionPages(DiscoveryDoubles::renderer(DiscoveryDoubles::drawer()), $site, $attempts, new Passwords(4), new class implements \Idno\Templating\TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        }, $spend);
    }

    private static function post(string $name): PageContext
    {
        $request = Request::create('/session/login', 'POST', ['email' => $name, 'password' => 'a wrong password', 'fwd' => '']);

        return new PageContext($request, new SitePage('Idno\Pages\Session\Login', methods: ['GET', 'POST']));
    }

    /**
     * Catches: the throttle being asked AFTER the password is checked. Requests that arrive together would each be in their own check before any of them
     * is counted, so each would get a guess: a password could be tried as many times as requests can be sent at once. Here every check starts another sign-in
     * for the same account while it runs, as parallel requests would, and exactly five checks may happen.
     */
    public function testRequestsInTheMiddleOfAPasswordCheckAreCountedSoOnlyFiveGuessesGetThrough(): void
    {
        $site = new SignInDouble();
        $pages = $this->pages($site);
        $depth = 0;
        $site->whileChecking = function () use ($pages, &$depth): void {
            if (++$depth < 12) {
                $pages->signIn(self::post('bob'));
            }
        };

        $pages->signIn(self::post('bob'));

        $this->assertSame(LoginThrottle::LIMIT, $site->checks, 'a sixth password check began while the first five were still running');
    }

    /**
     * Catches: a login for a name nobody has skipping the verification a wrong password costs, so that the time the page takes tells a visitor which names are
     * members' (the one message for the two is no use if the time differs). A member with a wrong password spends none of its own here: the double's check
     * stands for the real one.
     */
    public function testALoginForANameNobodyHasSpendsAVerificationAndAMemberWithAWrongPasswordDoesNot(): void
    {
        $spent = [];
        $site = new SignInDouble();
        $site->known = false;
        $pages = $this->pages($site, static function (string $password) use (&$spent): void {
            $spent[] = $password;
        });

        $pages->signIn(self::post('nobody-has-this-name'));

        $this->assertSame(['a wrong password'], $spent, 'one verification was spent on the password');
        $this->assertSame(0, $site->checks, 'there is no member whose password could be checked');

        $spent = [];
        $known = new SignInDouble();
        $this->pages($known, static function (string $password) use (&$spent): void {
            $spent[] = $password;
        })->signIn(self::post('bob'));
        $this->assertSame([], $spent, 'a member\'s own check is the cost: nothing more is spent');
        $this->assertSame(1, $known->checks);
    }
}

final class SignInDouble implements SignIn
{
    public bool $known = true;

    public int $checks = 0;

    public ?\Closure $whileChecking = null;

    public function currentUser(): ?User
    {
        return null;
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function accountFor(string $login): ?User
    {
        if (!$this->known) {
            return null;
        }
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->uuid = 'https://example.com/view/bob';

        return $user;
    }

    public function checkPassword(User $user, string $password): bool
    {
        $this->checks++;
        if ($this->whileChecking !== null) {
            ($this->whileChecking)();
        }

        return false;
    }

    public function mayLogOn(User $user): bool
    {
        return true;
    }

    public function logOn(User $user): void
    {
    }

    public function logOff(): void
    {
    }

    public function apiKey(User $user): string
    {
        return 'key';
    }

    public function trigger(string $event, array $data): void
    {
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
    }

    public function templateType(): string
    {
        return 'default';
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function configuredUrl(): string
    {
        return 'https://example.com/';
    }

    public function siteTitle(): string
    {
        return 'Example';
    }

    public function userTitle(User $user): string
    {
        return 'Member';
    }

    public function isSecure(): bool
    {
        return true;
    }

    public function decodeForward(string $value): string
    {
        return $value;
    }

    public function loginAddress(string $fwd): string
    {
        return 'https://example.com/session/login/?fwd=' . $fwd;
    }
}
