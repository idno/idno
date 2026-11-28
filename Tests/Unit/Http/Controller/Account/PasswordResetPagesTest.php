<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Account;

use Idno\Entities\User;
use Idno\Http\Controller\Account\PasswordResetPages;
use Idno\Http\Controller\Account\PasswordResets;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\ExactShellDrawer;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Security\LoginAttempts;
use Idno\Security\LoginThrottle;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * The rules of the password-reset pages that the recorded answers over HTTP cannot show: what the service does and does not ask the site to do.
 */
final class PasswordResetPagesTest extends TestCase
{
    private PasswordResetsDouble $site;

    private ResetDrawer $drawer;

    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->site = new PasswordResetsDouble();
        $this->drawer = new ResetDrawer();
        $this->throttle = new LoginThrottle(new ArrayAdapter(), static fn (): int => 0);
    }

    private function pages(): PasswordResetPages
    {
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $throttle = $this->throttle;

        return new PasswordResetPages(
            new PageRenderer($this->drawer, $translation, $this->drawer),
            $this->site,
            new LoginAttempts(static fn (): LoginThrottle => $throttle, static fn () => new NullLogger()),
            $translation
        );
    }

    /** @param array<string, mixed> $fields */
    private static function post(string $path, array $fields): PageContext
    {
        return new PageContext(Request::create($path, 'POST', $fields), new SitePage('Idno\Pages\Account\Password', accountPage: true, publicOnPrivateSite: true, methods: ['GET', 'POST']));
    }

    /** @param array<string, mixed> $query */
    private static function get(string $path, array $query = []): PageContext
    {
        return new PageContext(Request::create($path, 'GET', $query), new SitePage('Idno\Pages\Account\Password', accountPage: true, publicOnPrivateSite: true, methods: ['GET', 'POST']));
    }

    /**
     * Catches: a member who is signed in changing another account's password (or asking for its link) through a page that is only for visitors.
     */
    public function testASignedInMemberIsRefusedEverywhere(): void
    {
        $this->site->signedIn = true;
        $this->site->member = $this->site->account = self::someone();
        $pages = $this->pages();

        $answers = [
            $pages->requestForm(self::get('/account/password')),
            $pages->request(self::post('/account/password', ['email' => 'bob@example.com'])),
            $pages->resetForm(self::get('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'c'])),
            $pages->reset(self::post('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'c', 'password' => 'long-enough-1', 'password2' => 'long-enough-1'])),
        ];

        foreach ($answers as $answer) {
            $this->assertSame(403, $answer->getStatusCode());
        }
        $this->assertSame([], $this->site->mailed);
        $this->assertSame([], $this->site->passwordsChanged);
        $this->assertSame(0, $this->site->codesIssued);
    }

    /**
     * Catches: the page telling a visitor which addresses have an account (a different answer, or a mail, for an address that is a member's, and none for
     * one that is not, a blocked one or one that is being deleted: the site hands the page `null` for all of those).
     */
    public function testAnUnknownAddressGetsTheSameAnswerAndNoMail(): void
    {
        $known = self::someone();
        $this->site->account = $known;
        $known_answer = $this->pages()->request(self::post('/account/password', ['email' => 'bob@example.com']));
        $this->assertSame(1, count($this->site->mailed));

        $this->site->account = null;
        $this->site->mailed = [];
        $unknown_answer = $this->pages()->request(self::post('/account/password', ['email' => 'nobody@example.com']));

        $this->assertSame([], $this->site->mailed, 'no mail for an address that may not reset');
        $this->assertSame(302, $unknown_answer->getStatusCode());
        $this->assertSame($known_answer->headers->get('Location'), $unknown_answer->headers->get('Location'));
        $this->assertSame('https://example.com/account/password/?sent=true', $unknown_answer->headers->get('Location'));
    }

    /**
     * Catches: an address that is no text (`email[]=...`) being used for a lookup: it is an empty address, which finds nobody.
     */
    public function testAnAddressThatIsNotTextFindsNobody(): void
    {
        $this->site->account = self::someone();
        $this->pages()->request(self::post('/account/password', ['email' => ['bob@example.com']]));

        $this->assertSame([''], $this->site->lookedUp);
    }

    /**
     * Catches: a wrong code being told apart from a wrong address or from an expired code, and the passwords being judged before the code, so that
     * whoever has no right code learns what the site's password rule is from the answer. One refusal, to the page that asks for a new link.
     */
    public function testAWrongCodeIsRefusedWithOneAnswerWhateverWasTypedAsPasswords(): void
    {
        $this->site->member = null;
        $pages = $this->pages();

        $weak = $pages->reset(self::post('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'bad', 'password' => 'abc', 'password2' => 'x']));
        $strong = $pages->reset(self::post('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'bad', 'password' => 'long-enough-1', 'password2' => 'long-enough-1']));
        $form = $pages->resetForm(self::get('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'bad']));

        foreach ([$weak, $strong, $form] as $answer) {
            $this->assertSame(302, $answer->getStatusCode());
            $this->assertSame('https://example.com/account/password', $answer->headers->get('Location'));
        }
        $this->assertSame([], $this->site->passwordsChecked, 'the passwords are not looked at');
        $this->assertSame([], $this->site->passwordsChanged);
        $this->assertCount(3, array_filter($this->site->messages, static fn (array $m): bool => $m[1] === 'alert-danger'));
    }

    /**
     * Catches: a refused new password using up the code or being redirected (which would put the code in an address), or the passwords a person
     * typed coming back in the variables of the form that is drawn again.
     */
    public function testARefusedPasswordKeepsTheCodeAndDrawsTheFormAgain(): void
    {
        $this->site->member = self::someone();
        $answer = $this->pages()->reset(self::post('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'right', 'password' => 'long-enough-1', 'password2' => 'different-2']));

        $this->assertSame(400, $answer->getStatusCode());
        $this->assertNull($answer->headers->get('Location'));
        $this->assertSame([], $this->site->passwordsChanged, 'the code is not used up and nothing is changed');
        $this->assertSame([['account/password/reset', ['email' => 'bob@example.com', 'code' => 'right']]], $this->drawer->copies);
        $this->assertStringNotContainsString('long-enough-1', serialize([$this->drawer->pages, $this->drawer->copies]));
    }

    /**
     * Catches: a member who was locked out of signing in by failed attempts still being locked out after a reset the code of which proved they own the
     * address.
     */
    public function testASuccessfulResetClearsTheThrottleCount(): void
    {
        $this->site->member = self::someone();
        $key = LoginThrottle::keyForAccount('uuid-of-bob');
        for ($i = 0; $i < LoginThrottle::LIMIT; $i++) {
            $this->throttle->attempt($key);
        }
        $this->assertFalse($this->throttle->attempt($key), 'locked out');

        $answer = $this->pages()->reset(self::post('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'right', 'password' => 'long-enough-1', 'password2' => 'long-enough-1']));

        $this->assertSame('https://example.com/session/login', $answer->headers->get('Location'), 'on to the sign-in page');
        $this->assertSame(['long-enough-1'], $this->site->passwordsChanged);
        $this->assertTrue($this->throttle->attempt($key), 'the count was cleared');
    }

    /**
     * Catches: the reset link's code and address becoming variables of the page's own template (a JSON request prints every one of them) instead of
     * variables of the body drawn on a copy, and the page saying nothing about a missing mail service.
     */
    public function testTheCodeIsOnlyEverAVariableOfTheBodyDrawnOnACopy(): void
    {
        $this->site->member = self::someone();
        $this->site->mailConfigured = false;
        $pages = $this->pages();
        $pages->resetForm(self::get('/account/password/reset', ['email' => 'bob@example.com', 'code' => 'right']));
        $pages->requestForm(self::get('/account/password'));

        $this->assertStringNotContainsString('right', serialize($this->drawer->pages));
        $this->assertSame(['account/password/reset', ['email' => 'bob@example.com', 'code' => 'right']], $this->drawer->copies[0]);
        $this->assertSame(['account/password', ['mail_configured' => false]], $this->drawer->copies[1]);
    }

    private static function someone(): User
    {
        return (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
    }
}

final class ResetDrawer implements PageDrawer, ExactShellDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $copies = [];

    /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
    public array $pages = [];

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        return '[' . $name . ']';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('<html>' . ($vars['body'] ?? '') . '</html>', []);
    }

    public function drawPageInExactShell(array $vars, int $status, string $shell): DrawnPage
    {
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('<html>' . ($vars['body'] ?? '') . '</html>', []);
    }

    public function drawTemplateOnACopy(string $name, array $vars): string
    {
        $this->copies[] = [$name, $vars];

        return '[' . $name . ']';
    }
}

final class PasswordResetsDouble implements PasswordResets
{
    public bool $signedIn = false;

    /** The account an address finds (null: nobody, a blocked address and an account being deleted included). */
    public ?User $account = null;

    /** The member a right address and code find. */
    public ?User $member = null;

    public bool $mailConfigured = true;

    public int $codesIssued = 0;

    /** @var list<string> */
    public array $lookedUp = [];

    /** @var list<string> */
    public array $mailed = [];

    /** @var list<string> */
    public array $passwordsChecked = [];

    /** @var list<string> */
    public array $passwordsChanged = [];

    /** @var list<array{string, string}> */
    public array $messages = [];

    public function isSignedIn(): bool
    {
        return $this->signedIn;
    }

    public function accountForRequest(string $email): ?User
    {
        $this->lookedUp[] = $email;

        return $this->account;
    }

    public function issueCode(User $account): ?string
    {
        $this->codesIssued++;

        return 'a-code';
    }

    public function mailCode(User $account, string $email, string $code): void
    {
        $this->mailed[] = $code;
    }

    public function memberFor(string $email, string $code): ?User
    {
        return $this->member;
    }

    public function accountId(User $account): string
    {
        return 'uuid-of-bob';
    }

    public function passwordIsStrong(string $password): bool
    {
        $this->passwordsChecked[] = $password;

        return strlen($password) >= 7;
    }

    public function changePassword(User $account, string $password): void
    {
        $this->passwordsChanged[] = $password;
    }

    public function mailIsConfigured(): bool
    {
        return $this->mailConfigured;
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
        $this->messages[] = [$text, $type];
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }
}
