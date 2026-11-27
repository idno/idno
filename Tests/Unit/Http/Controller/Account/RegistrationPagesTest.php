<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Account;

use Idno\Entities\User;
use Idno\Http\Controller\Account\NewAccount;
use Idno\Http\Controller\Account\RegistrationAdmission;
use Idno\Http\Controller\Account\RegistrationInvitation;
use Idno\Http\Controller\Account\RegistrationPages;
use Idno\Http\Controller\Account\Registrations;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\ExactShellDrawer;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The registration page's rules that the recorded answers over HTTP show less precisely: what is asked of the site, and in what order, for a request
 * that must not make an account or must not use up an invitation.
 */
final class RegistrationPagesTest extends TestCase
{
    private RegistrationsDouble $site;

    private ExactDrawer $drawer;

    protected function setUp(): void
    {
        $this->site = new RegistrationsDouble();
        $this->drawer = new ExactDrawer();
    }

    private function pages(): RegistrationPages
    {
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };

        return new RegistrationPages(new PageRenderer($this->drawer, $translation, $this->drawer), $this->site, $translation);
    }

    /** @param array<string, mixed> $fields */
    private static function post(array $fields): PageContext
    {
        return new PageContext(Request::create('/account/register', 'POST', $fields), new SitePage('Idno\Pages\Account\Register', accountPage: true, methods: ['GET', 'POST']));
    }

    /** @return array<string, string> */
    private static function valid(): array
    {
        return ['name' => 'Dana', 'handle' => 'dana', 'email' => 'dana@example.test', 'password' => 'a-long-passphrase', 'password2' => 'a-long-passphrase', 'code' => ''];
    }

    /**
     * Catches: a site with no account letting somebody register (or even asking about an invitation) because an invitation names an administrator: the site's
     * first administrator is made by the installation alone, so nothing is read of the form and no account is made, whatever code the POST carries.
     */
    public function testASiteWithNoAccountRegistersNobodyWhateverInvitationThePostCarries(): void
    {
        $this->site->hasAccounts = false;
        $this->site->open = false;
        $this->site->invitation = new RegistrationInvitation('dana@example.test', true, 'Ada', new \stdClass());

        $answer = $this->pages()->register(self::post(['code' => 'a-valid-administrators-code'] + self::valid()));

        $this->assertSame(302, $answer->getStatusCode());
        $this->assertSame([], $this->site->created, 'no account');
        $this->assertSame([], $this->site->invitationsChecked, 'the invitation was not even looked at');
    }

    /**
     * Catches: a form that fails its checks (the passwords differ) using up the invitation it came with, so that the person has to ask for another link.
     * The invitation is used only inside `create()`, which a failed form never reaches.
     */
    public function testAFormThatFailsItsChecksNeverTouchesTheInvitation(): void
    {
        $this->site->open = false;
        $this->site->invitation = new RegistrationInvitation('dana@example.test', false, 'Ada', new \stdClass());

        $answer = $this->pages()->register(self::post(['password2' => 'something else', 'code' => 'the-code'] + self::valid()));

        $this->assertSame(400, $answer->getStatusCode());
        $this->assertSame([], $this->site->created);
        $this->assertSame([['dana@example.test', 'the-code']], $this->site->invitationsChecked, 'it was only looked at');
    }

    /**
     * Catches: a handle of `0` being accepted. The page has always counted `0` as no handle (PHP's `empty()`), and a profile at `/profile/0` is the 404 page
     * (a missing or `0` handle), so an account made with it could not be reached.
     */
    public function testAHandleOfZeroIsNoHandle(): void
    {
        $answer = $this->pages()->register(self::post(['handle' => '0'] + self::valid()));

        $this->assertSame(400, $answer->getStatusCode());
        $this->assertSame([], $this->site->created);
        $this->assertContains(['Please create a username.', 'alert-danger'], $this->site->messages);
    }

    /**
     * Catches: the passwords a person typed coming back in the variables of a refused form, which the page's JSON and JSONP output would print and a
     * template might put in a field. Only the email, code, name and handle are given back.
     */
    public function testARefusedFormGivesBackWhatWasTypedButNeverThePasswords(): void
    {
        $this->pages()->register(self::post(['handle' => 'bad handle!'] + self::valid()));

        $this->assertSame(400, $this->drawer->pages[0][1]);
        [$name, $vars] = $this->drawer->copies[0];
        $this->assertSame('account/register', $name);
        $this->assertSame(['email' => 'dana@example.test', 'code' => '', 'name' => 'Dana', 'handle' => 'bad handle!', 'invited' => false, 'is_admin' => false, 'inviter' => ''], $vars);
        $this->assertStringNotContainsString('a-long-passphrase', serialize([$this->drawer->pages, $this->drawer->copies]));
    }

    /**
     * Catches: the invitation's code and address becoming variables of the page's own template (the site's template object), where the JSON and JSONP
     * output of the page prints every variable: they are given to the form's body alone, drawn on a copy.
     */
    public function testTheInvitationsCodeIsAVariableOfTheBodyAloneNeverOfThePage(): void
    {
        $this->site->open = false;
        $this->site->invitation = new RegistrationInvitation('dana@example.test', false, 'Ada', new \stdClass());

        $this->pages()->form(new PageContext(Request::create('/account/register?email=dana%40example.test&code=the-code'), new SitePage('Idno\Pages\Account\Register', accountPage: true, methods: ['GET', 'POST'])));

        $this->assertSame(['body', 'title'], array_keys($this->drawer->pages[0][0]));
        $this->assertSame('the-code', $this->drawer->copies[0][1]['code']);
        $this->assertStringNotContainsString('the-code', serialize($this->drawer->pages));
        $this->assertSame([], $this->drawer->templates, 'nothing was drawn on the site\'s own template object');
    }
}

final class ExactDrawer implements PageDrawer, ExactShellDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

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
        $this->templates[] = [$name, $vars];

        return '[' . $name . ']';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        throw new \LogicException('The registration page is drawn in the exact shell.');
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

final class RegistrationsDouble implements Registrations
{
    public bool $hasAccounts = true;

    public bool $open = true;

    public ?RegistrationInvitation $invitation = null;

    /** @var list<array{string, string}> */
    public array $invitationsChecked = [];

    /** @var list<string> */
    public array $created = [];

    /** @var list<array{string, string}> */
    public array $messages = [];

    public function siteHasAccounts(): bool
    {
        return $this->hasAccounts;
    }

    public function signedInName(): ?string
    {
        return null;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function canAddUsers(): bool
    {
        return true;
    }

    public function checkInvitation(string $email, string $code): RegistrationAdmission
    {
        $this->invitationsChecked[] = [$email, $code];

        return new RegistrationAdmission($this->invitation, $this->invitation === null ? RegistrationAdmission::INVALID : '');
    }

    public function emailIsBlocked(string $email): bool
    {
        return false;
    }

    public function accountExistsForEmail(string $email): bool
    {
        return false;
    }

    public function handleIsTaken(string $handle): bool
    {
        return false;
    }

    public function passwordIsStrong(string $password): bool
    {
        return strlen($password) >= 7;
    }

    public function validates(array $fields): bool
    {
        return true;
    }

    public function create(string $email, string $handle, string $password, string $name, ?RegistrationInvitation $invitation): NewAccount
    {
        $this->created[] = $handle;

        return new NewAccount(NewAccount::FAILED);
    }

    public function logOn(User $user): void
    {
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
        $this->messages[] = [$text, $type];
    }

    public function messageAtStart(string $text, string $type = 'alert-info'): void
    {
        array_unshift($this->messages, [$text, $type]);
    }

    public function siteUrl(): string
    {
        return 'https://example.com/';
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }
}
