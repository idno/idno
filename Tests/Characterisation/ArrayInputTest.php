<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * A request can send an array where a page expects text: `x[]=a`, `x[$gt]=a`, `x[$lt]=z`, `x[$not]=a` in a query or a form, and an object in a JSON
 * body or in the `json` field. PHP builds the same arrays from the first four, and a JSON object becomes the same array, so a lookup that was handed one
 * could drop or bend its own condition (the newest member answered for `email[]=x`; an invitation was found on its address alone).
 *
 * Each page that takes a name, an address or a code is sent every text field it reads in each of those shapes, with the rest of the request valid, on a
 * site of the test's own. Nothing may follow from it: no account is made, nobody is signed in, no mail is sent, no invitation is used up, no member's
 * recovery code or password changes. The answer is a page below 500, except the ones listed in `ERRORS`: a page that fails on a value it cannot read
 * answers a server error (its `trim()` or `hash_equals()` throws a type error, or the notice for an array turned into text is an exception under the
 * rig's `pedantic_mode`), which refuses the request as well. Each of them is a page whose logic has no check of its own yet.
 */
final class ArrayInputTest extends SignInSiteCase
{
    private const INVITED = 'dana@example.test';

    private const CODE = '0123456789abcdef0123456789abcd01';

    private const RECOVERY = '0123456789abcdef0123456789abcd11';

    private const NEW_PASSWORD = 'a-new-passphrase-77';

    /**
     * The fields a page answers a server error for when they are an array (any shape, any way of sending it), by request. Each fails on a value it cannot
     * read (`trim()` or `hash_equals()` throws a type error, or the notice for an array turned into text is an exception under the rig's `pedantic_mode`)
     * and so refuses the request; none of them makes an account, signs anybody in, sends mail or changes a member.
     *
     * @var array<string, list<string>>
     */
    private const ERRORS = [
        'POST /session/login' => ['email', 'password'],
        'POST /account/register' => ['email', 'handle', 'password', 'password2'],
        'POST /account/password/reset' => ['code', 'password', 'password2'],
        'GET /account/password/reset' => ['code'],
    ];

    /** The site case asks for it; this test records no answer, it asserts what followed. */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        $this->fail('ArrayInputTest records no answers');
    }

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    /** @return array<string, mixed> the shapes a request can give a value, by name */
    private static function shapes(): array
    {
        return [
            'list' => ['a'],
            'gt' => ['$gt' => 'a'],
            'lt' => ['$lt' => 'z'],
            'not' => ['$not' => 'a'],
        ];
    }

    /** The site: Bob has a known address, password and recovery code, and an invitation waits for an address. */
    private function seed(bool $open): void
    {
        $this->seedSharedSite();
        $code = str_replace(
            ['%PASSWORD%', '%RECOVERY%', '%CODE%', '%INVITED%', '%OPEN%'],
            [$this->literal(self::PASSWORD), $this->literal(self::RECOVERY), $this->literal(self::CODE), $this->literal(self::INVITED), $open ? 'true' : 'false'],
            <<<'PHP'
            $site = \Idno\Core\Idno::site();
            $site->db()->setIgnoreAccess(true);
            $bob = \Idno\Entities\User::getByHandle('bob');
            $bob->email = 'bob@example.com';
            $bob->setPassword(%PASSWORD%);
            $bob->password_recovery_code = %RECOVERY%;
            $bob->password_recovery_code_time = time();
            $bob->save(true);
            $invitation = new \Idno\Entities\Invitation();
            $invitation->_id = '00000000000040008000000000000101';
            $invitation->email = %INVITED%;
            $invitation->code = %CODE%;
            $invitation->created = (int) strtotime('2026-03-01 12:00:00 UTC');
            $invitation->save(true);
            $site->config()->open_registration = %OPEN%;
            $site->config()->from_email = 'site@example.test';
            $site->config()->save();

            return true;
            PHP
        );
        $this->assertTrue($this->runInSite($code));
        $this->site()->request('GET', '_test/mail/log');
    }

    /** @return array<string, mixed> what a request can change, read from the site */
    private function state(): array
    {
        $state = $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        $handles = array_map(static fn ($u) => (string) $u->handle, \Idno\Entities\User::get([], [], 100) ?: []);
        sort($handles);
        $invitations = array_map(static fn ($i) => (string) $i->email . '/' . (string) $i->code, \Idno\Entities\Invitation::get([], [], 100) ?: []);
        sort($invitations);
        $bob = \Idno\Entities\User::getByHandle('bob');

        return ['accounts' => $handles, 'invitations' => $invitations, 'recovery' => (string) $bob->password_recovery_code, 'hash' => (string) $bob->password];
        PHP);
        $this->assertIsArray($state);

        return $state;
    }

    /** Sends a form, as a browser (`form`), as a JSON body (`json`) or as the `json` field of a form (`field`). @param array<string, mixed> $fields */
    private function send(string $method, string $path, array $fields, string $how, string $cookie, string $token): SiteResponse
    {
        $headers = ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . ltrim($path, '/')];
        $fields += ['__bTk' => $token];
        if ($method === 'GET') {
            return $this->site()->request('GET', $path . '?' . http_build_query($fields), $headers);
        }

        return match ($how) {
            'form' => $this->site()->request('POST', $path, $headers, $fields),
            'json' => $this->site()->request('POST', $path . '?__bTk=' . $token, $headers + ['Content-Type' => 'application/json'], json_encode($fields, JSON_THROW_ON_ERROR)),
            'field' => $this->site()->request('POST', $path, $headers, ['__bTk' => $token, 'json' => json_encode($fields, JSON_THROW_ON_ERROR)]),
        };
    }

    /**
     * Sends the request with each text field in each shape, and asserts nothing followed.
     *
     * @param array<string, string> $valid the fields of a request that would succeed
     * @param list<string> $fields the fields to shape
     * @param list<string> $hows how to send a POST (`form`, `json`, `field`)
     * @param \Closure(array<string, mixed>, array<string, mixed>, SiteResponse, string): void $also further assertions: the state before and after, the answer, the case
     */
    private function sweep(string $method, string $path, array $valid, array $fields, array $hows, ?\Closure $also = null): void
    {
        $before = $this->state();
        foreach ($fields as $field) {
            foreach (self::shapes() as $shape => $value) {
                foreach ($method === 'GET' ? ['form'] : $hows as $how) {
                    $case = $method . ' ' . $path . ' ' . $field . ' as ' . $shape . ' by ' . $how;
                    [$cookie, $token] = $this->visitorSession();
                    $answer = $this->send($method, $path, [$field => $value] + $valid, $how, $cookie, $token);
                    $cookie = self::sessionCookieOrNull($answer) ?? $cookie;

                    if (!in_array($field, self::ERRORS[$method . ' ' . $path] ?? [], true)) {
                        $this->assertLessThan(500, $answer->status, $case . ' answers a server error');
                    }
                    $this->assertNotSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, $case . ' signed somebody in');
                    $this->assertSame($before, $this->state(), $case . ' changed the site');
                    $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json(), $case . ' sent mail');
                    if ($also !== null) {
                        $also($before, $this->state(), $answer, $case);
                    }
                }
            }
        }
    }

    public function testTheLoginPage(): void
    {
        $this->seed(true);
        // The control that lets the sweep fail: the same login with text signs Bob in, by the same check the sweep makes
        [$cookie, $token] = $this->visitorSession();
        $login = $this->send('POST', '/session/login', ['email' => 'bob', 'password' => self::PASSWORD], 'form', $cookie, $token);
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie])->status, 'a login of text signs in');
        $this->sweep('POST', '/session/login', ['email' => 'bob', 'password' => self::PASSWORD], ['email', 'password'], ['form', 'json', 'field']);
    }

    public function testRegistrationOnASiteOpenToIt(): void
    {
        $this->seed(true);
        $dana = ['name' => 'Dana Newcomer', 'handle' => 'dana', 'email' => self::INVITED, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD, 'code' => self::CODE];
        // an open site asks for no invitation: a name or a code that is an array may make an account (it is the one the page's own checks accept),
        // so only the address, the handle and the passwords are held to "nothing follows"
        foreach (['email', 'handle', 'password', 'password2'] as $field) {
            $this->sweep('POST', '/account/register', $dana, [$field], ['form', 'json', 'field']);
        }
    }

    public function testRegistrationOnASiteThatAsksForAnInvitation(): void
    {
        $this->seed(false);
        $dana = ['name' => 'Dana Newcomer', 'handle' => 'dana', 'email' => self::INVITED, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD, 'code' => self::CODE];
        // the name is not looked anything up by: an array for it, with an invitation that is right, makes the account (the page stores it as the title)
        $this->sweep('POST', '/account/register', $dana, ['handle', 'email', 'password', 'password2', 'code'], ['form', 'json', 'field']);

        // the form: an array for the address or the code is answered as an invitation that does not exist is
        [$cookie, $token] = $this->visitorSession();
        $none = $this->site()->request('GET', '/account/register?email=nobody%40example.test&code=ffffffffffffffffffffffffffffffff', ['Cookie' => $cookie]);
        $this->assertSame(302, $none->status);
        foreach (['email[$gt]=a&email[$lt]=z&code[$lt]=g', 'email=' . rawurlencode(self::INVITED) . '&code[]=x', 'email[]=' . rawurlencode(self::INVITED) . '&code[]=' . self::CODE, 'email=' . rawurlencode(self::INVITED) . '&code[$not]=zzz', 'email=' . rawurlencode(self::INVITED) . '&code[$gt]=0'] as $query) {
            $answer = $this->site()->request('GET', '/account/register?' . $query, ['Cookie' => $cookie]);
            $this->assertSame($none->status, $answer->status, $query);
            $this->assertSame($none->header('Location'), $answer->header('Location'), $query);
        }
        $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json());
    }

    public function testAnArrayForTheCodeIsRefusedWhereTheOldLookupFoundTheInvitationOnItsAddressAlone(): void
    {
        $this->seed(false);
        $dana = ['name' => 'Dana Newcomer', 'handle' => 'dana', 'email' => self::INVITED, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD];
        [$cookie, $token] = $this->visitorSession();
        $answer = $this->send('POST', '/account/register', ['code' => ['$lt' => 'g']] + $dana, 'form', $cookie, $token);
        $this->assertSame(302, $answer->status);
        $this->assertSame($this->baseUrl(), $answer->header('Location'));
        $this->assertContains(self::INVITED . '/' . self::CODE, $this->state()['invitations']);
        $this->assertNotContains('dana', $this->state()['accounts']);
        // and the right code makes the account
        $made = $this->send('POST', '/account/register', ['code' => self::CODE] + $dana, 'form', $cookie, $token);
        $this->assertSame(302, $made->status);
        $this->assertContains('dana', $this->state()['accounts']);
        $this->assertNotContains(self::INVITED . '/' . self::CODE, $this->state()['invitations'], 'the invitation is used up');
    }

    public function testTheRequestForAPasswordReset(): void
    {
        $this->seed(true);
        $this->sweep('POST', '/account/password', ['email' => 'bob@example.com'], ['email'], ['form', 'json', 'field']);
    }

    public function testThePasswordReset(): void
    {
        $this->seed(true);
        $valid = ['email' => 'bob@example.com', 'code' => self::RECOVERY, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD];
        $this->sweep('POST', '/account/password/reset', $valid, ['email', 'code', 'password', 'password2'], ['form', 'json', 'field']);
        $this->sweep('GET', '/account/password/reset', ['email' => 'bob@example.com', 'code' => self::RECOVERY], ['email', 'code'], ['form']);
        // positive control: the same request with text is the reset
        [$cookie, $token] = $this->visitorSession();
        $made = $this->send('POST', '/account/password/reset', $valid, 'form', $cookie, $token);
        $this->assertSame(302, $made->status);
        $this->assertNotSame(self::RECOVERY, $this->state()['recovery'], 'the code is used up by a request made of text');
    }
}
