<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Bonita\Forms;
use Idno\Core\Bonita\Main;
use Idno\Core\Idno;
use Idno\Entities\User;
use Tests\Characterisation\CharacterisationTestCase;
use Tests\Support\LogsIn;

/**
 * The form token on Symfony's CSRF token manager (`Idno\Security\FormToken`): one token for a session, the 64 hex
 * characters stored, never a per-call randomised form; `Forms::token()` ignores its arguments; `Forms::validateToken()`
 * accepts that value and nothing else (the time and the action a form names are no part of it); the check of a
 * controller (`#[IsCsrfTokenValid]`) is on the same token; and forms draw in a process with no request.
 */
final class CsrfAdapterTest extends CharacterisationTestCase
{
    use LogsIn;

    private ?User $member = null;

    /** @var array<string, mixed> */
    private array $requestBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestBefore = $_REQUEST;
        $user = new User();
        $user->handle = 'csrfa' . substr(md5(random_bytes(8)), 0, 8);
        $user->email = $user->handle . '@example.com';
        $user->setTitle('Csrf Adapter User');
        $user->setPassword(bin2hex(random_bytes(12)));
        $this->registerFixture($user);
        $user->save(true);
        $user->getAPIkey();
        $this->member = $user;
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->requestBefore;
        Idno::site()->session()->resetStatelessAuthentication();
        parent::tearDown();
    }

    private function member(): User
    {
        $this->assertNotNull($this->member);

        return $this->member;
    }

    public function testTokenIsStablePerSessionAndDiffersBetweenSessions(): void
    {
        $first = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($first);
        $again = $this->site()->request('GET', 'session/login', ['Cookie' => $cookie]);
        $other = $this->site()->request('GET', 'session/login');

        $a = self::formToken($first->body, 'session/login')['__bTk'];
        $b = self::formToken($again->body, 'session/login')['__bTk'];
        $c = self::formToken($other->body, 'session/login')['__bTk'];

        $this->assertSame($a, $b, 'the same session, the same token');
        $this->assertNotSame($a, $c, 'another session, another token');
    }

    public function testTokenIs64HexCharacters(): void
    {
        $page = $this->site()->request('GET', 'session/login');

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', self::formToken($page->body, 'session/login')['__bTk']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', Forms::token('/x', 1));
    }

    public function testTheFirstPageAfterALoginCarriesTheTokenThatIsStoredForTheSession(): void
    {
        // The page shell draws its forms after the session is closed (before it is sent): the token must already be in the session.
        $cookie = $this->logIn($this->member());
        $home = $this->site()->request('GET', '', ['Cookie' => $cookie]);
        $homeToken = self::formToken($home->body, 'session/logout');
        $settings = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);

        $this->assertSame($homeToken['__bTk'], self::formToken($settings->body, 'account/settings/')['__bTk']);
        $out = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $cookie], ['__bTk' => $homeToken['__bTk']]);
        $this->assertSame(200, $out->status, 'and the session accepts it');
    }

    public function testActionAndTimeAreIgnored(): void
    {
        $this->assertSame(Forms::token('/a', 1), Forms::token('/b?c=d', '99999999999'));
        $_REQUEST = ['__bTk' => Forms::token('/a', 1), '__bTs' => 'not a time', '__bTa' => ['not', 'an action']];

        $this->assertTrue(Forms::validateToken('', false), 'the token alone is checked');
        $this->assertTrue(Forms::validateToken('/elsewhere', false));
    }

    public function testArrayTokenIsFalseNotATypeError(): void
    {
        foreach ([['x'], [], ['__bTk' => 'y']] as $odd) {
            $_REQUEST = ['__bTk' => $odd, '__bTs' => '1', '__bTa' => '/x'];

            $this->assertFalse(Forms::validateToken('', false));
        }
        $_REQUEST = ['__bTk' => Forms::token('', 0), '__bTs' => ['1'], '__bTa' => ['x']];
        $this->assertTrue(Forms::validateToken('', false), 'a time or an action that is an array is ignored too');

        $cookie = $this->logIn($this->member());
        $page = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);
        $fields = self::formToken($page->body, 'account/settings/');
        $response = $this->site()->request('POST', 'account/settings', ['Cookie' => $cookie], ['__bTk' => ['x']] + $fields + ['name' => 'Array Token', 'email' => $this->member()->email]);

        $this->assertSame(302, $response->status, 'refused as an invalid token, not a server error');
        $this->assertStringContainsString('Invalid token.', $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie])->body);
    }

    public function testPreUpgradeHmacTokenIsRefused(): void
    {
        $action = '/account/settings/';
        $time = (string) time();
        $hmac = hash_hmac('sha256', $action, (string) Main::getSiteSecret(), true);
        $hmac = hash_hmac('sha256', $time, $hmac, true);
        $old = hash_hmac('sha256', session_id() ?: 'x', $hmac);
        $_REQUEST = ['__bTk' => $old, '__bTs' => $time, '__bTa' => $action];

        $this->assertNotSame($old, Forms::token($action, $time), 'a token made from the site secret is not the stored one');
        $this->assertFalse(Forms::validateToken('', false));
    }

    public function testStatelessRequestSkipsTheCheck(): void
    {
        $_REQUEST = [];
        $this->assertFalse(Forms::validateToken('', false), 'no token, a browser request: refused');

        Idno::site()->session()->authenticateStatelessly($this->member());

        $this->assertTrue(Forms::validateToken('', false), 'a stateless request carries no form token');
    }

    public function testFormsDrawWithNoRequest(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(dirname(__DIR__, 2) . '/Tests/Kernel/csrf_console.php') . ' 2>&1';
        exec($command, $lines, $exit);
        $output = implode("\n", $lines);

        $this->assertSame(0, $exit, $output);
        $drawn = json_decode(trim((string) end($lines)), true);
        $this->assertIsArray($drawn, $output);
        $this->assertSame(1, preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $drawn['first'], $one), $drawn['first']);
        $this->assertSame(1, preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $drawn['second'], $two));
        $this->assertSame($one[1], $two[1], 'one token for the process');
        $this->assertStringContainsString($one[1], $drawn['signed'], 'a signed form carries the same token');
        $this->assertFalse($drawn['statusChanged'], 'drawing a form changes the session\'s state');
        $this->assertSame([], $drawn['headers'], 'and sends no header');
    }

    public function testControllerAttributeChecksTheSameToken(): void
    {
        $cookie = $this->logIn($this->member());
        $page = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);
        $token = self::formToken($page->body, 'account/settings/')['__bTk'];

        $good = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $cookie], ['__bTk' => $token]);
        $wrong = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $cookie], ['__bTk' => str_repeat('0', 64)]);
        $none = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $cookie]);
        $visitor = $this->site()->request('POST', '_test/csrf-member', [], ['__bTk' => $token]);
        $other = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $this->logIn($this->member())], ['__bTk' => $token]);

        $this->assertSame(200, $good->status, $good->body);
        $this->assertSame(['member' => true], $good->json());
        $array = $this->site()->request('POST', '_test/csrf-member', ['Cookie' => $cookie], ['__bTk' => ['x']]);
        $this->assertSame(400, $array->status, 'an array for the token is a malformed request to Symfony (never a server error): ' . substr($array->body, 0, 200));
        foreach (['wrong' => $wrong, 'none' => $none, 'visitor' => $visitor, 'another session' => $other] as $name => $response) {
            $this->assertSame(403, $response->status, $name . ': ' . substr($response->body, 0, 200));
        }
    }
}
