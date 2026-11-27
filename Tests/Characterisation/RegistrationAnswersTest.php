<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the registration page (`/account/register`) answers, recorded over HTTP on sites of the test's own (a POST makes an account, so the site is not
 * shared): the form to a visitor of a site open to registration and of one that asks for an invitation (a valid code, an administrator's, a wrong one,
 * one for another address, one already used, one that has expired, one that was withdrawn, one that was sent again), in each template type and for
 * each way of asking; the form to a member, to a signed request and on a site with no account; the POST for each field the page validates, with the
 * page the same browser loads next, who is signed in and whether an account now exists; and the POSTs that are no form. The answers are
 * `Tests/Characterisation/fixtures/answers/registration/`. They were first captured from the page as it was before invitations had roles and expired
 * (a refusal was a redirect home, a failed form a redirect to the Referer); they are captured again where the page now answers otherwise.
 *
 * The site is `SignInSiteCase`'s (Ada the administrator, Bob a member, Carol), with no posts of Bob's, invitations for `dana@example.test` and
 * `erin@example.test` (each with a fixed id, code and time, good for ever), one for `fran@example.test` that expired in March 2026, one for
 * `gus@example.test` that makes an administrator (sent by Ada), and the address `blocked@example.test` on the blocklist. A site is open to registration
 * (the setting's default) unless the scenario closes it, and a scenario that makes an account puts the accounts, the invitations and the settings back
 * as they were before it runs again (it runs twice when a capture is verified). A form token and a session id are secrets of a session: no recording
 * holds one; the address a redirect carries as base64 is recorded decoded.
 *
 * More: what a registered account can do (the session id and form token are new, it signs in with the password typed and no other, a password over 72
 * bytes, a name holding markup shown escaped); the events a registration and each refusal trigger (`Tests\Fixtures\RegistrationEventHook`: its mode makes a
 * listener refuse the password, veto the registration or say the site is full); the user limit (`users/add/check`), which the form and the POST both
 * ask; a private site (`walled_garden`); and the setting pinned closed by the boot configuration (`IDNO_OPEN_REGISTRATION`) over a stored open one. A
 * field sent as an array is an empty one. That an invitation is used once, makes the role it was stored with and nothing a request says, and takes
 * the address it was made for is `InvitationRegistrationTest`'s.
 *
 * A site closed to new users with no invitation to give (`users/add/check` answering false: nothing in core listens to it) is not recorded: nothing
 * of core or the bundled plugins can make a site say so.
 */
final class RegistrationAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'registration';

    private const CHANGED = [];

    private const NEW_PASSWORD = 'a-new-passphrase-77';

    private const CODE_DANA = '0123456789abcdef0123456789abcd01';

    private const CODE_ERIN = '0123456789abcdef0123456789abcd02';

    private const CODE_FRAN = '0123456789abcdef0123456789abcd03';

    private const CODE_FRAN_RESENT = '0123456789abcdef0123456789abcd04';

    private const CODE_GUS = '0123456789abcdef0123456789abcd05';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    private string $eventsDirectory = '';

    /** The directory the registration events are recorded in, and the mode of the listeners is chosen by (`RegistrationEventHook`). */
    private function eventsDirectory(): string
    {
        if ($this->eventsDirectory === '') {
            $this->eventsDirectory = sys_get_temp_dir() . '/idno-registration-events-' . bin2hex(random_bytes(6));
            mkdir($this->eventsDirectory, 0700);
        }

        return $this->eventsDirectory;
    }

    protected function siteEnvironment(): array
    {
        $environment = ['IDNO_TEST_REGISTRATION_EVENTS' => $this->eventsDirectory()];
        $test = $this->getName(false);
        if (str_contains($test, 'PrivateSite')) {
            $environment['IDNO_WALLED_GARDEN'] = '1';
        }
        if (str_contains($test, 'BootConfiguration')) {
            // the setting is pinned closed by the boot configuration, whatever the stored one says
            $environment['IDNO_OPEN_REGISTRATION'] = '0';
        }

        return $environment;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->eventsDirectory !== '') {
            array_map('unlink', glob($this->eventsDirectory . '/*') ?: []);
            rmdir($this->eventsDirectory);
        }
    }

    /** What the listeners of the registration's events saw since the last call (the name, and the handle when there is one), and forgets it. @return list<string> */
    private function events(): array
    {
        $file = $this->eventsDirectory() . '/events.jsonl';
        $seen = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $event = json_decode($line, true);
            $seen[] = $event['event'] . ($event['handle'] !== null ? ':' . $event['handle'] : '');
        }
        @unlink($file);

        return $seen;
    }

    /** Makes the listeners answer false for one event (`deny-add`, `veto-validate`, `weak-password`), or none (''). */
    private function mode(string $mode): void
    {
        file_put_contents($this->eventsDirectory() . '/mode', $mode);
    }

    /** Whether a login with a password signs the browser in as the handle. */
    private function canSignIn(string $handle, string $password): bool
    {
        [$cookie, $token] = $this->visitorSession();
        $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => $handle, 'password' => $password]);
        $who = $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]);

        return $who->status === 200 && str_contains((string) ($who->json()['user']['url'] ?? ''), '/profile/' . $handle);
    }

    /** A POST of a registration as a browser makes it, recording nothing. @param array<string, mixed> $fields */
    private function register(array $fields, string $path = '/account/register'): SiteResponse
    {
        [$cookie, $token] = $this->visitorSession();
        $this->events();

        return $this->site()->request('POST', $path, ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/register'], ['__bTk' => $token] + $fields);
    }

    /** The fields of a valid registration. @return array<string, string> */
    private static function dana(): array
    {
        return ['name' => 'Dana Newcomer', 'handle' => 'dana', 'email' => 'dana@example.test', 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD];
    }

    /** Seeds the site and puts the registration state in place. */
    private function seedRegistration(bool $open = true): void
    {
        $this->seedSharedSite();
        $this->putBack($open);
    }

    /** Removes every account but the three seeded ones and every invitation, writes the two invitations, the blocklist and the registration setting. */
    private function putBack(bool $open): void
    {
        $code = str_replace(
            ['%OPEN%', '%DANA%', '%ERIN%', '%FRAN%', '%GUS%'],
            [$open ? 'true' : 'false', $this->literal(self::CODE_DANA), $this->literal(self::CODE_ERIN), $this->literal(self::CODE_FRAN), $this->literal(self::CODE_GUS)],
            <<<'PHP'
            $site = \Idno\Core\Idno::site();
            $site->db()->setIgnoreAccess(true);
            foreach (\Idno\Entities\User::get([], [], 100) ?: [] as $user) {
                if (!in_array($user->handle, ['ada', 'bob', 'carol'], true)) {
                    $user->delete();
                }
            }
            foreach (\Idno\Entities\Invitation::get([], [], 100) ?: [] as $invitation) {
                $invitation->delete();
            }
            $ada = \Idno\Entities\User::getByHandle('ada');
            $make = static function (string $id, string $email, string $code, string $expires, string $role) use ($ada): void {
                $invitation = new \Idno\Entities\Invitation();
                $invitation->_id = $id;
                $invitation->email = $email;
                $invitation->code = $code;
                $invitation->created = (int) strtotime('2026-03-01 12:00:00 UTC');
                $invitation->expires = (int) strtotime($expires);
                $invitation->setRole($role);
                $invitation->inviter_name = 'Ada Admin';
                $invitation->invited_by = $ada->getUUID();
                if (!$invitation->save(true)) {
                    throw new \RuntimeException('invitation not saved');
                }
            };
            $make('00000000000040008000000000000101', 'dana@example.test', %DANA%, '2100-01-01 00:00:00 UTC', 'member');
            $make('00000000000040008000000000000102', 'erin@example.test', %ERIN%, '2100-01-01 00:00:00 UTC', 'member');
            $make('00000000000040008000000000000104', 'fran@example.test', %FRAN%, '2026-03-15 12:00:00 UTC', 'member');
            $make('00000000000040008000000000000105', 'gus@example.test', %GUS%, '2100-01-01 00:00:00 UTC', 'admin');
            $site->config()->open_registration = %OPEN%;
            $site->config()->blocked_emails = ['blocked@example.test'];
            $site->config()->save();

            return true;
            PHP
        );
        $this->assertTrue($this->runInSite($code));
    }

    /**
     * Fran's expired invitation as an administrator's "Send again" leaves it: its time starts again and it has a new code (renew() makes a random one,
     * so the new code is a constant here, as every recorded code is).
     */
    private function resendFrans(): void
    {
        $this->assertTrue($this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $i = \Idno\Entities\Invitation::getByEmail("fran@example.test"); $i->renew(); $i->code = ' . $this->literal(self::CODE_FRAN_RESENT) . '; return (bool) $i->save(true);'));
    }

    /** Whether Dana's invitation is withdrawn, as the Users page does it. */
    private function withdrawDanas(): void
    {
        $this->assertTrue($this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\Invitation::getByEmail("dana@example.test")->delete();'));
    }

    /** The handles of the accounts the site has, sorted. @return list<string> */
    private function handles(): array
    {
        $found = $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $h = array_map(static fn ($u) => (string) $u->handle, \Idno\Entities\User::get([], [], 100) ?: []); sort($h); return $h;');
        $this->assertIsArray($found);

        return $found;
    }

    /** Whether the invitation for an address is still there. */
    private function invitationFor(string $email): bool
    {
        return (bool) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\Invitation::getByEmail(' . $this->literal($email) . ');');
    }

    /** @return array<string, mixed> what the site stores of the account with a handle (none: an empty list) */
    private function accountOf(string $handle): array
    {
        $found = $this->runInSite('$u = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . '); return $u ? ["handle" => (string) $u->handle, "email" => (string) $u->email, "title" => (string) $u->getTitle(), "admin" => (bool) $u->admin] : [];');
        $this->assertIsArray($found);

        return $found;
    }

    /**
     * The page a request names, from a visitor's browser (or Bob's): the answer, and for a redirect the page the browser loads next. The recordings'
     * names are literals at each call (the orphan check reads the sources): `answer`, and `then` when the answer is a redirect.
     *
     * @param array{answer: string, then?: string} $names
     * @param array<string, string> $headers
     */
    private function visit(array $names, string $path, array $headers = [], ?string $viewer = null): SiteResponse
    {
        $cookie = $viewer === null ? $this->visitorSession()[0] : (string) $this->bobsSession();
        $s = $viewer === null ? $this->scrubWithNonce() : $this->scrubWithNonce($this->tokenWith(['Cookie' => $cookie]));
        $answer = $this->site()->request('GET', $path, ['Cookie' => $cookie] + $headers);
        $this->answer($names['answer'], $answer, static fn (SiteResponse $r): SiteResponse => self::withoutTheTrace($s($r)));
        if (isset($names['then']) && $answer->header('Location') !== null) {
            $this->answer($names['then'], $this->follow($answer, $cookie), $s);
        }

        return $answer;
    }

    /**
     * A POST to the registration page and what follows it: the answer, the page the browser is sent to, the home page after that (where a message
     * that was waiting shows), who is signed in, and the administrators' page (which no registration opens).
     *
     * @param array{answer: string, then?: string, after?: string, user?: string, admin?: string} $names the recordings' names (literals at each call)
     * @param array<string, mixed> $fields
     * @param array{path?: string, headers?: array<string, string>, token?: bool, cookie?: string, referer?: bool|string, chain?: bool} $options
     */
    private function submit(array $names, array $fields, array $options = []): SiteResponse
    {
        $path = $options['path'] ?? '/account/register';
        if (isset($options['cookie'])) {
            $cookie = $options['cookie'];
            $token = $this->tokenWith(['Cookie' => $cookie]);
        } else {
            [$cookie, $token] = $this->visitorSession();
        }
        $this->events();
        $s = $this->scrubWithNonce($token);
        $xhr = isset($options['headers']['X-Requested-With']);
        // the legacy router's lines for a script's request hold the time of the request
        $strip = static fn (SiteResponse $r): SiteResponse => $xhr ? self::withoutLastModified(self::withoutTheTrace($s($r))) : self::withoutTheTrace($s($r));
        $headers = ['Cookie' => $cookie] + ($options['headers'] ?? []);
        $referer = $options['referer'] ?? true;
        if ($referer === true) {
            $headers += ['Referer' => $this->baseUrl() . 'account/register'];
        } elseif (is_string($referer)) {
            // an address of this site (as a path) or another
            $headers += ['Referer' => str_starts_with($referer, '/') ? rtrim($this->baseUrl(), '/') . $referer : $referer];
        }
        $body = (($options['token'] ?? true) ? ['__bTk' => $token] : []) + $fields;
        $answer = $this->site()->request('POST', $path, $headers, $body);
        $this->answer($names['answer'], $answer, $strip);
        $after = self::sessionCookieOrNull($answer) ?? $cookie;
        if (isset($names['then']) && $answer->header('Location') !== null) {
            $then = $this->follow($answer, $cookie);
            $this->answer($names['then'], $then, $s);
            $after = self::sessionCookieOrNull($then) ?? $after;
            if (isset($names['after'])) {
                $this->answer($names['after'], $this->site()->request('GET', '/', ['Cookie' => $after]), $s);
            }
        }
        if (isset($names['user'])) {
            $this->answer($names['user'], $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $after]), $s);
        }
        if (isset($names['admin'])) {
            $this->answer($names['admin'], $this->site()->request('GET', '/admin/', ['Cookie' => $after]), $s);
        }

        return $answer;
    }

    /**
     * A POST (see submit()), and whether it made an account; an account it made is removed again (the invitations and settings put back as they were).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     */
    private function attempt(array $names, array $fields, array $options, bool $open): bool
    {
        $before = $this->handles();
        $this->submit($names, $fields, $options);
        $made = array_values(array_diff($this->handles(), $before)) !== [];
        if ($made) {
            $this->putBack($open);
        }

        return $made;
    }

    /**
     * A registration with some of a valid one's fields changed, on a site open to registration put back as it was: made or not as `$accepted` says.
     *
     * @param array{answer: string, then?: string, after?: string, user?: string} $names
     * @param array<string, mixed> $changed
     * @param array<string, mixed> $options as submit()'s
     */
    private function refused(array $names, array $changed, bool $accepted, array $options = []): void
    {
        $this->putBack(true);
        $made = $this->attempt($names, $changed + self::dana(), $options, true);
        $this->assertSame($accepted, $made, $names['answer'] . ($accepted ? ' makes an account' : ' makes no account'));
    }

    public function testTheFormOnASiteOpenToRegistration(): void
    {
        $this->seedRegistration();
        $s = $this->scrubViewers();
        $this->twice(function () use ($s): void {
            $this->visit(['answer' => 'form-open-html'], '/account/register');
            $this->visit(['answer' => 'form-open-json'], '/account/register?_t=json');
            $this->visit(['answer' => 'form-open-jsonp'], '/account/register?_t=jsonp&callback=f');
            $this->visit(['answer' => 'form-open-markdown'], '/account/register?_t=markdown');
            $this->visit(['answer' => 'form-open-rss'], '/account/register?_t=rss');
            $this->visit(['answer' => 'form-open-slash'], '/account/register/');
            $this->visit(['answer' => 'form-open-double-slash'], '//account/register');
            $this->visit(['answer' => 'form-open-prefilled'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-open-prefilled-hostile'], '/account/register?email=' . rawurlencode('"><x-canary a=\'1') . '&code=' . rawurlencode('"><x-canary a=\'2'));
            $this->visit(['answer' => 'form-open-array-query'], '/account/register?email[]=x&code[]=y');
            $this->answer('form-open-head', $this->site()->request('HEAD', '/account/register'), $s);
            $this->answer('form-open-options', $this->site()->request('OPTIONS', '/account/register'), $s);
            $this->answer('form-open-xhr', $this->site()->request('GET', '/account/register', ['X-Requested-With' => 'XMLHttpRequest']), static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($s($r)));
            $this->answer('form-open-malformed-host', $this->site()->request('GET', '/account/register', ['Host' => 'bad host!']), $s);
            // a member is sent home with a message, whatever the form's type
            $this->visit(['answer' => 'form-open-bob-html', 'then' => 'form-open-bob-html-then'], '/account/register', [], 'bob');
            $this->visit(['answer' => 'form-open-bob-json', 'then' => 'form-open-bob-json-then'], '/account/register?_t=json', [], 'bob');
            // a signed request is refused: it is an account page
            $this->answer('form-open-signed-html', $this->site()->request('GET', '/account/register', self::signedHeadersFor('bob', self::KEY_BOB, '/account/register')), $s);
            $this->answer('form-open-signed-json', $this->site()->request('GET', '/account/register?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/account/register?_t=json')), $s);
        });
    }

    public function testTheFormOnASiteThatAsksForAnInvitation(): void
    {
        $this->seedRegistration(false);
        $this->twice(function (): void {
            $this->visit(['answer' => 'form-closed-no-code'], '/account/register');
            $this->visit(['answer' => 'form-closed-no-code-json'], '/account/register?_t=json');
            $this->visit(['answer' => 'form-closed-wrong-code'], '/account/register?email=dana%40example.test&code=ffffffffffffffffffffffffffffffff');
            $this->visit(['answer' => 'form-closed-code-no-email'], '/account/register?code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-email-no-code'], '/account/register?email=dana%40example.test');
            $this->visit(['answer' => 'form-closed-code-of-another-address'], '/account/register?email=dana%40example.test&code=' . self::CODE_ERIN);
            $this->visit(['answer' => 'form-closed-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-upper-case-email'], '/account/register?email=DANA%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-json'], '/account/register?_t=json&email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-markdown'], '/account/register?_t=markdown&email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-slash'], '/account/register/?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-array-code'], '/account/register?email=dana%40example.test&code[]=x');
            // an invitation that expired, one that makes an administrator, one withdrawn, one sent again (its old link stays refused)
            $this->visit(['answer' => 'form-closed-expired-code'], '/account/register?email=fran%40example.test&code=' . self::CODE_FRAN);
            $this->visit(['answer' => 'form-closed-expired-code-json'], '/account/register?_t=json&email=fran%40example.test&code=' . self::CODE_FRAN);
            $this->visit(['answer' => 'form-closed-admin-code'], '/account/register?email=gus%40example.test&code=' . self::CODE_GUS);
            $this->resendFrans();
            $this->visit(['answer' => 'form-closed-resent-old-code'], '/account/register?email=fran%40example.test&code=' . self::CODE_FRAN);
            $this->visit(['answer' => 'form-closed-resent-new-code'], '/account/register?email=fran%40example.test&code=' . self::CODE_FRAN_RESENT);
            $this->withdrawDanas();
            $this->visit(['answer' => 'form-closed-withdrawn-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->putBack(false);
            // a field sent as an array is an empty one: the form is refused as it is for no code
            $none = $this->refusalOf('/account/register?email=dana%40example.test');
            foreach (['code[]=x', 'code[$lt]=g', 'code[$gt]=0', 'code[$not]=zzz', 'email[$gt]=a&email[$lt]=z&code[$lt]=g', 'email[]=dana%40example.test&code[]=x'] as $query) {
                $query = str_starts_with($query, 'email') ? $query : 'email=dana%40example.test&' . $query;
                $this->assertSame($none[0], $this->refusalOf('/account/register?' . $query)[0], $query . ' is refused');
            }
            $this->visit(['answer' => 'form-closed-bob-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA, [], 'bob');
            $this->answer('form-closed-signed-valid-code', $this->site()->request('GET', '/account/register?email=dana%40example.test&code=' . self::CODE_DANA, self::signedHeadersFor('bob', self::KEY_BOB, '/account/register?email=dana%40example.test&code=' . self::CODE_DANA)), $this->scrubViewers());
        });
    }

    /**
     * What a visitor is told for a request of the form: its status, where it sends the browser, and the page it lands on.
     *
     * @return array{int, string, string}
     */
    private function refusalOf(string $path): array
    {
        [$cookie, $token] = $this->visitorSession();
        $answer = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
        $landed = $answer->header('Location') === null ? $answer : $this->follow($answer, $cookie);
        $scrubbed = ($this->scrubWithNonce($token))($landed);

        return [$answer->status, (string) $answer->header('Location'), $scrubbed->body];
    }

    /**
     * The scrub that also replaces what varies by account made and by response: the id of an account the test made (the page makes it), the default
     * picture it is given (chosen by a hash of that id), and the nonce of the "being set up" page's policy.
     */
    private function scrubWithNonce(string ...$tokens): \Closure
    {
        $scrub = $this->scrub(...$tokens);
        $swap = static fn (string $text): string => (string) preg_replace(['/(?!00000000-0000-4000-8000-)[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '/gfx[\\\\\/]+users[\\\\\/]+default-\d+\.png/', '/nonce-[A-Za-z0-9_\/+=-]{16,}/', '/nonce="[A-Za-z0-9_\/+=-]{16,}"/'], ['{new-id}', 'gfx/users/default-{n}.png', 'nonce-{nonce}', 'nonce="{nonce}"'], $text);

        return static function (SiteResponse $r) use ($scrub, $swap): SiteResponse {
            $r = $scrub($r);

            return new SiteResponse($r->status, array_map($swap, $r->headers), $swap($r->body), array_map($swap, $r->lines));
        };
    }

    public function testTheFormOnASiteWithNoAccount(): void
    {
        // no seeding: the site has no user at all
        $this->twice(function (): void {
            $this->visit(['answer' => 'form-no-account-html', 'then' => 'form-no-account-html-then'], '/account/register');
            $this->visit(['answer' => 'form-no-account-json', 'then' => 'form-no-account-json-then'], '/account/register?_t=json');
            $this->visit(['answer' => 'form-no-account-slash', 'then' => 'form-no-account-slash-then'], '/account/register/');
            $this->answer('form-no-account-head', $this->site()->request('HEAD', '/account/register'), static fn (SiteResponse $r): SiteResponse => $r);
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrubWithNonce($token);
            $post = $this->site()->request('POST', '/account/register', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/register'], ['__bTk' => $token] + self::dana());
            $this->answer('register-post-no-account', $post, $s);
            $after = self::sessionCookieOrNull($post) ?? $cookie;
            $this->answer('register-post-no-account-then', $this->follow($post, $cookie), $s);
            $this->answer('register-post-no-account-home', $this->site()->request('GET', '/', ['Cookie' => $after]), $s);
            $this->answer('register-post-no-account-json', $this->site()->request('POST', '/account/register?_t=json', ['Cookie' => $cookie], ['__bTk' => $token] + self::dana()), $s);
            $this->assertSame([], $this->handles(), 'nobody was made an account, an administrator least of all');
        });
    }

    public function testRegisteringOnASiteOpenToRegistration(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-valid', 'then' => 'register-post-open-valid-then', 'after' => 'register-post-open-valid-after', 'user' => 'register-post-open-valid-user', 'admin' => 'register-post-open-valid-admin'], self::dana());
            $this->assertSame(['ada', 'bob', 'carol', 'dana'], $this->handles(), 'the account was made');
            $this->assertSame(['handle' => 'dana', 'email' => 'dana@example.test', 'title' => 'Dana Newcomer', 'admin' => false], $this->accountOf('dana'), 'registering never makes an administrator');

            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-handle-case-and-spaces', 'then' => 'register-post-open-handle-case-and-spaces-then', 'after' => 'register-post-open-handle-case-and-spaces-after', 'user' => 'register-post-open-handle-case-and-spaces-user', 'admin' => 'register-post-open-handle-case-and-spaces-admin'], ['handle' => ' DaNa ', 'name' => '', 'email' => ' dana@example.test ', 'password' => ' ' . self::NEW_PASSWORD . ' ', 'password2' => self::NEW_PASSWORD]);
            $this->assertSame(['handle' => 'dana', 'email' => 'dana@example.test', 'title' => 'dana', 'admin' => false], $this->accountOf('dana'), 'the handle is lower case and trimmed, an empty name is the handle');

            // a code is checked on an open site too, because it may make an administrator: one that is no invitation is refused, not ignored
            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-with-a-code', 'user' => 'register-post-open-with-a-code-user'], self::dana() + ['code' => 'not an invitation']);
            $this->assertSame([], $this->accountOf('dana'), 'a code that is no invitation registers nobody on an open site');
            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-with-an-invitation', 'then' => 'register-post-open-with-an-invitation-then', 'user' => 'register-post-open-with-an-invitation-user'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertNotSame([], $this->accountOf('dana'), 'an invitation that is right registers on an open site and is used up');
            $this->assertFalse($this->invitationFor('dana@example.test'));

            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-json', 'then' => 'register-post-open-json-then', 'after' => 'register-post-open-json-after', 'user' => 'register-post-open-json-user', 'admin' => 'register-post-open-json-admin'], self::dana(), ['path' => '/account/register?_t=json']);
            $this->assertNotSame([], $this->accountOf('dana'));

            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-no-referer', 'then' => 'register-post-open-no-referer-then', 'after' => 'register-post-open-no-referer-after', 'user' => 'register-post-open-no-referer-user', 'admin' => 'register-post-open-no-referer-admin'], self::dana(), ['referer' => false]);

            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-foreign-referer', 'then' => 'register-post-open-foreign-referer-then', 'after' => 'register-post-open-foreign-referer-after', 'user' => 'register-post-open-foreign-referer-user', 'admin' => 'register-post-open-foreign-referer-admin'], self::dana(), ['headers' => ['Referer' => 'https://evil.example/steal'], 'referer' => false]);
            $this->putBack(true);
        });
    }

    public function testRegistrationsTheSiteRefuses(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->refused(['answer' => 'register-post-mismatched-passwords'], ['password2' => 'another-passphrase-1'], false);
            $this->refused(['answer' => 'register-post-mismatched-passwords-json'], ['password2' => 'another-passphrase-1'], false, ['path' => '/account/register?_t=json']);
            $this->refused(['answer' => 'register-post-taken-handle', 'user' => 'register-post-taken-handle-user'], ['handle' => 'bob'], false);
            $this->refused(['answer' => 'register-post-taken-handle-other-case'], ['handle' => 'BOB'], false);
            $this->refused(['answer' => 'register-post-taken-handle-padded'], ['handle' => ' bob '], false);
            $this->refused(['answer' => 'register-post-taken-email'], ['email' => 'bob@example.com'], false);
            $this->refused(['answer' => 'register-post-taken-email-other-case'], ['email' => 'BOB@Example.com'], false);
            $this->refused(['answer' => 'register-post-taken-handle-and-email'], ['handle' => 'bob', 'email' => 'bob@example.com'], false);
            $this->refused(['answer' => 'register-post-blocked-email'], ['email' => 'blocked@example.test'], false);
            $this->refused(['answer' => 'register-post-blocked-email-other-case'], ['email' => 'Blocked@Example.test'], false);
            $this->refused(['answer' => 'register-post-invalid-handle'], ['handle' => 'not valid!'], false);
            $this->refused(['answer' => 'register-post-handle-with-a-slash'], ['handle' => 'da/na'], false);
            $this->refused(['answer' => 'register-post-handle-with-a-dot'], ['handle' => 'da.na'], false);
            $this->refused(['answer' => 'register-post-handle-too-long'], ['handle' => str_repeat('d', 33)], false);
            $this->refused(['answer' => 'register-post-handle-of-32', 'then' => 'register-post-handle-of-32-then', 'after' => 'register-post-handle-of-32-after', 'user' => 'register-post-handle-of-32-user', 'admin' => 'register-post-handle-of-32-admin'], ['handle' => str_repeat('d', 32)], true);
            $this->refused(['answer' => 'register-post-empty-handle'], ['handle' => ''], false);
            $this->refused(['answer' => 'register-post-spaces-for-a-handle'], ['handle' => '   '], false);
            $this->refused(['answer' => 'register-post-invalid-email', 'user' => 'register-post-invalid-email-user'], ['email' => 'not-an-address'], false);
            $this->refused(['answer' => 'register-post-empty-email'], ['email' => ''], false);
            $this->refused(['answer' => 'register-post-no-handle-and-no-email'], ['handle' => '', 'email' => ''], false);
            $this->refused(['answer' => 'register-post-short-password'], ['password' => 'six-pw', 'password2' => 'six-pw'], false);
            $this->refused(['answer' => 'register-post-seven-character-password', 'then' => 'register-post-seven-character-password-then', 'after' => 'register-post-seven-character-password-after', 'user' => 'register-post-seven-character-password-user', 'admin' => 'register-post-seven-character-password-admin'], ['password' => 'sevenpw', 'password2' => 'sevenpw'], true);
            $this->refused(['answer' => 'register-post-empty-passwords'], ['password' => '', 'password2' => ''], false);
            $this->refused(['answer' => 'register-post-blank-passwords'], ['password' => '       ', 'password2' => '       '], false);
            $this->refused(['answer' => 'register-post-no-second-password'], ['password2' => ''], false);
            $this->refused(['answer' => 'register-post-no-name', 'then' => 'register-post-no-name-then', 'after' => 'register-post-no-name-after', 'user' => 'register-post-no-name-user', 'admin' => 'register-post-no-name-admin'], ['name' => ''], true);
            $this->refused(['answer' => 'register-post-every-fault-at-once'], ['handle' => 'bob/!', 'email' => 'bob@example.com', 'password' => 'x', 'password2' => 'y'], false);
            $this->putBack(true);
        });
    }

    public function testPostsThatAreNoForm(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            $made = [];
            $try = function (array $names, array $fields, array $options = []) use (&$made): void {
                $made[$names['answer']] = $this->attempt($names, $fields, $options, true);
            };
            // no form token
            $try(['answer' => 'register-post-no-token', 'then' => 'register-post-no-token-then', 'after' => 'register-post-no-token-after'], self::dana(), ['token' => false]);
            // a token that is not the session's
            $try(['answer' => 'register-post-wrong-token', 'then' => 'register-post-wrong-token-then', 'after' => 'register-post-wrong-token-after'], ['__bTk' => str_repeat('a', 64)] + self::dana(), ['token' => false]);
            $try(['answer' => 'register-post-no-token-json', 'then' => 'register-post-no-token-json-then', 'after' => 'register-post-no-token-json-after'], self::dana(), ['token' => false, 'path' => '/account/register?_t=json']);
            // an array in place of each field
            $try(['answer' => 'register-post-array-name', 'then' => 'register-post-array-name-then', 'after' => 'register-post-array-name-after', 'user' => 'register-post-array-name-user', 'admin' => 'register-post-array-name-admin'], ['name' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-handle'], ['handle' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-email'], ['email' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-password'], ['password' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-password2'], ['password2' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-code', 'then' => 'register-post-array-code-then', 'after' => 'register-post-array-code-after', 'user' => 'register-post-array-code-user', 'admin' => 'register-post-array-code-admin'], ['code' => ['x']] + self::dana());
            $try(['answer' => 'register-post-array-token', 'then' => 'register-post-array-token-then', 'after' => 'register-post-array-token-after'], ['__bTk' => ['x']] + self::dana(), ['token' => false]);
            // a script's request is not redirected
            $try(['answer' => 'register-post-xhr-refused'], ['email' => 'not-an-address'] + self::dana(), ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            $try(['answer' => 'register-post-xhr-no-token'], self::dana(), ['headers' => ['X-Requested-With' => 'XMLHttpRequest'], 'token' => false]);
            $try(['answer' => 'register-post-xhr-valid', 'user' => 'register-post-xhr-valid-user', 'admin' => 'register-post-xhr-valid-admin'], self::dana(), ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            // a field sent as an array is an empty one: an empty name is the handle, an empty code is none (an open site asks for none), and a POST a script
            // sends is registered as any other
            $this->assertSame(['register-post-array-name', 'register-post-array-code', 'register-post-xhr-valid'], array_keys(array_filter($made)), 'the POSTs that make an account');
            // a signed request is an account page's: refused
            $seen = static fn (array $before, array $after): bool => array_diff($after, $before) !== [];
            $before = $this->handles();
            $signed = self::signedHeadersFor('bob', self::KEY_BOB, '/account/register');
            $this->answer('register-post-signed', $this->site()->request('POST', '/account/register', $signed, self::dana()), $this->scrubWithNonce());
            $this->answer('register-post-signed-json', $this->site()->request('POST', '/account/register?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/account/register?_t=json'), self::dana()), $this->scrubWithNonce());
            $this->assertFalse($seen($before, $this->handles()), 'a signed request makes no account');
            // a malformed Host
            [$cookie, $token] = $this->visitorSession();
            $answer = $this->site()->request('POST', '/account/register', ['Cookie' => $cookie, 'Host' => 'bad host!'], ['__bTk' => $token] + self::dana());
            $this->answer('register-post-malformed-host', $answer, $this->scrubWithNonce($token));
            // a defect: the legacy router serves the registration of a request whose Host Symfony refused, and makes the account
            $this->assertTrue($seen($before, $this->handles()), 'a request with a malformed Host makes an account');
            $this->putBack(true);

            // the other methods, by a visitor with the token in the address and with none
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $x = ['name' => 'x'];
            $this->answer('register-put-visitor', $this->site()->request('PUT', '/account/register', [], $x), $s);
            $this->answer('register-put-visitor-with-token', $this->site()->request('PUT', '/account/register?__bTk=' . $token, ['Cookie' => $cookie], $x), $s);
            $this->answer('register-delete-visitor', $this->site()->request('DELETE', '/account/register', [], $x), $s);
            $this->answer('register-delete-visitor-with-token', $this->site()->request('DELETE', '/account/register?__bTk=' . $token, ['Cookie' => $cookie], $x), $s);
            $this->assertSame(['ada', 'bob', 'carol'], $this->handles());
        });
    }

    public function testRegisteringByInvitation(): void
    {
        $this->seedRegistration(false);
        $this->twice(function (): void {
            $this->putBack(false);
            $before = $this->handles();
            // no code, a wrong one, one for another address, none for this address
            $made = [];
            $try = function (array $names, array $fields, array $options = []) use (&$made): void {
                $made[$names['answer']] = $this->attempt($names, $fields, $options, false);
            };
            $try(['answer' => 'register-post-closed-no-code'], self::dana());
            $try(['answer' => 'register-post-closed-wrong-code'], self::dana() + ['code' => 'ffffffffffffffffffffffffffffffff']);
            $try(['answer' => 'register-post-closed-code-of-another-address'], self::dana() + ['code' => self::CODE_ERIN]);
            $try(['answer' => 'register-post-closed-code-no-email'], ['email' => ''] + self::dana() + ['code' => self::CODE_DANA]);
            $try(['answer' => 'register-post-closed-no-code-json'], self::dana(), ['path' => '/account/register?_t=json']);
            $try(['answer' => 'register-post-closed-array-code', 'user' => 'register-post-closed-array-code-user', 'admin' => 'register-post-closed-array-code-admin'], self::dana() + ['code' => ['x']]);
            $try(['answer' => 'register-post-closed-no-token', 'then' => 'register-post-closed-no-token-then', 'after' => 'register-post-closed-no-token-after'], self::dana() + ['code' => self::CODE_DANA], ['token' => false]);
            // an invitation that has expired
            $try(['answer' => 'register-post-closed-expired-code'], ['email' => 'fran@example.test', 'handle' => 'fran'] + self::dana() + ['code' => self::CODE_FRAN]);
            // an array in place of the code is no invitation: the answer is a wrong code's
            $this->assertSame([], array_keys(array_filter($made)), 'the POSTs that make an account');
            $wrongCode = $this->register(self::dana() + ['code' => 'ffffffffffffffffffffffffffffffff']);
            foreach ([['x'], ['$lt' => 'g'], ['$gt' => '0'], ['$not' => 'zzz']] as $code) {
                $arrayCode = $this->register(self::dana() + ['code' => $code]);
                $this->assertSame($wrongCode->status, $arrayCode->status);
                $this->assertSame($wrongCode->header('Location'), $arrayCode->header('Location'));
            }
            $this->assertSame($before, $this->handles());
            $this->assertTrue($this->invitationFor('dana@example.test'), 'a refusal leaves the invitation');

            // the invitation is right, the details are not: the invitation stays
            $this->submit(['answer' => 'register-post-closed-valid-code-taken-handle'], ['handle' => 'bob'] + self::dana() + ['code' => self::CODE_DANA], ['referer' => '/account/register?email=dana%40example.test&code=' . self::CODE_DANA]);
            $this->submit(['answer' => 'register-post-closed-valid-code-mismatched-passwords'], ['password2' => 'another-passphrase-1'] + self::dana() + ['code' => self::CODE_DANA], ['referer' => '/account/register?email=dana%40example.test&code=' . self::CODE_DANA]);
            $this->assertSame($before, $this->handles());
            $this->assertTrue($this->invitationFor('dana@example.test'), 'a failed registration leaves the invitation');

            // an invitation that was sent again: its old code is refused and its new one registers
            $this->resendFrans();
            $this->submit(['answer' => 'register-post-closed-resent-old-code'], ['email' => 'fran@example.test', 'handle' => 'fran'] + self::dana() + ['code' => self::CODE_FRAN]);
            $this->assertSame([], $this->accountOf('fran'), 'the old code of a resent invitation registers nobody');
            $this->submit(['answer' => 'register-post-closed-resent-new-code', 'then' => 'register-post-closed-resent-new-code-then'], ['email' => 'fran@example.test', 'handle' => 'fran'] + self::dana() + ['code' => self::CODE_FRAN_RESENT]);
            $this->assertSame(['handle' => 'fran', 'email' => 'fran@example.test', 'title' => 'Dana Newcomer', 'admin' => false], $this->accountOf('fran'));
            $this->putBack(false);
            // an invitation that makes an administrator: only because it was stored so
            $this->submit(['answer' => 'register-post-closed-admin-code', 'then' => 'register-post-closed-admin-code-then', 'user' => 'register-post-closed-admin-code-user'], ['email' => 'gus@example.test', 'handle' => 'gus'] + self::dana() + ['code' => self::CODE_GUS]);
            $this->assertSame(['handle' => 'gus', 'email' => 'gus@example.test', 'title' => 'Dana Newcomer', 'admin' => true], $this->accountOf('gus'));
            $this->putBack(false);
            $before = $this->handles();

            // the invitation is right: the account is made and the invitation is gone
            $this->submit(['answer' => 'register-post-closed-valid-code', 'then' => 'register-post-closed-valid-code-then', 'after' => 'register-post-closed-valid-code-after', 'user' => 'register-post-closed-valid-code-user', 'admin' => 'register-post-closed-valid-code-admin'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame(['ada', 'bob', 'carol', 'dana'], $this->handles());
            $this->assertFalse($this->invitationFor('dana@example.test'), 'the invitation is used up');
            $this->assertTrue($this->invitationFor('erin@example.test'), 'and only that one');
            $this->assertSame(['handle' => 'dana', 'email' => 'dana@example.test', 'title' => 'Dana Newcomer', 'admin' => false], $this->accountOf('dana'));
            // the code is of no use again, on the form and on the POST
            $this->visit(['answer' => 'form-closed-used-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->submit(['answer' => 'register-post-closed-used-code', 'user' => 'register-post-closed-used-code-user'], ['handle' => 'dana2'] + self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame(['ada', 'bob', 'carol', 'dana'], $this->handles());

            // an invitation by the address in another letter case
            $this->putBack(false);
            $this->submit(['answer' => 'register-post-closed-valid-code-upper-case-email', 'then' => 'register-post-closed-valid-code-upper-case-email-then', 'after' => 'register-post-closed-valid-code-upper-case-email-after', 'user' => 'register-post-closed-valid-code-upper-case-email-user', 'admin' => 'register-post-closed-valid-code-upper-case-email-admin'], ['email' => 'DANA@example.test'] + self::dana() + ['code' => self::CODE_DANA]);
            // an invitation for an address the blocklist holds
            $this->putBack(false);
            $this->runInSite('$i = new \Idno\Entities\Invitation(); $i->_id = "00000000000040008000000000000103"; $i->email = "blocked@example.test"; $i->code = ' . $this->literal(self::CODE_DANA) . '; $i->created = (int) strtotime("2026-03-01 12:00:00 UTC"); $i->save(true); return true;');
            $before = $this->handles();
            $this->submit(['answer' => 'register-post-closed-blocked-address'], ['email' => 'blocked@example.test'] + self::dana() + ['code' => self::CODE_DANA], ['referer' => '/account/register?email=blocked%40example.test&code=' . self::CODE_DANA]);
            $this->assertSame($before, $this->handles());
            $this->assertTrue($this->invitationFor('blocked@example.test'), 'and its invitation stays');
            $this->putBack(true);
        });
    }

    public function testRegisteringWhileSignedIn(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            // a member who posts the form, with a token, is sent home with a message: no account is made and the browser stays Bob's
            $cookie = $this->bobsSession();
            $this->submit(['answer' => 'register-post-bob-valid', 'then' => 'register-post-bob-valid-then', 'after' => 'register-post-bob-valid-after', 'user' => 'register-post-bob-valid-user', 'admin' => 'register-post-bob-valid-admin'], self::dana(), ['cookie' => $cookie]);
            $this->assertSame([], $this->accountOf('dana'), 'no account was made for a signed-in member');
            $this->answer('register-post-bob-valid-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $this->scrubViewers());

            $this->putBack(true);
            $cookie = $this->bobsSession();
            $this->submit(['answer' => 'register-post-bob-refused', 'then' => 'register-post-bob-refused-then', 'after' => 'register-post-bob-refused-after', 'user' => 'register-post-bob-refused-user'], ['email' => 'not-an-address'] + self::dana(), ['cookie' => $cookie]);
            $this->answer('register-post-bob-refused-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $this->scrubViewers());

            $cookie = $this->bobsSession();
            $this->submit(['answer' => 'register-post-bob-no-token', 'then' => 'register-post-bob-no-token-then', 'after' => 'register-post-bob-no-token-after', 'user' => 'register-post-bob-no-token-user'], self::dana(), ['cookie' => $cookie, 'token' => false]);

            // the administrator, too
            $this->putBack(true);
            $admin = $this->loginCookie('ada', self::PASSWORD);
            $this->submit(['answer' => 'register-post-admin-valid', 'then' => 'register-post-admin-valid-then', 'after' => 'register-post-admin-valid-after', 'user' => 'register-post-admin-valid-user'], self::dana(), ['cookie' => $admin]);
            $this->assertSame([], $this->accountOf('dana'), 'nor for an administrator');
            $this->putBack(true);
        });
    }

    public function testWhatARegisteredAccountCanDo(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            // registering starts a new session: another id and another form token, and the browser's old session is nobody's
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrubWithNonce($token);
            $post = $this->site()->request('POST', '/account/register', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/register'], ['__bTk' => $token] + self::dana());
            $this->assertSame(302, $post->status);
            $new = self::sessionCookieOrNull($post);
            $this->assertNotNull($new, 'the answer sets a session cookie');
            $this->assertNotSame($cookie, $new, 'the session id is not the one the visitor had');
            $this->assertNotSame($token, $this->tokenWith(['Cookie' => $new]), 'nor is the form token');
            $this->answer('register-post-session-old-cookie-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);
            $this->answer('register-post-session-new-cookie-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $new]), $s);
            // the account signs in with the password it was made with, and with no other
            $this->assertTrue($this->canSignIn('dana', self::NEW_PASSWORD), 'the typed password signs in');
            $this->assertFalse($this->canSignIn('dana', 'another-passphrase-1'), 'another does not');
            $this->assertFalse($this->canSignIn('dana', substr(self::NEW_PASSWORD, 0, -1)), 'nor does the password less its last character');

            // a password typed with spaces around it is the trimmed one
            $this->putBack(true);
            $this->register(['password' => '  ' . self::NEW_PASSWORD . '  ', 'password2' => self::NEW_PASSWORD . ' '] + self::dana());
            $this->assertTrue($this->canSignIn('dana', self::NEW_PASSWORD), 'the stored password is the trimmed one');

            // a password over 72 bytes
            $this->putBack(true);
            $long = str_repeat('long-passphrase-', 6);
            $this->submit(['answer' => 'register-post-long-password', 'then' => 'register-post-long-password-then', 'after' => 'register-post-long-password-after'], ['password' => $long, 'password2' => $long] + self::dana());
            $this->assertNotSame([], $this->accountOf('dana'), 'a password of ' . strlen($long) . ' bytes is accepted');
            $this->assertTrue($this->canSignIn('dana', $long), 'and signs in');
            $this->assertSame(false, $this->canSignIn('dana', substr($long, 0, 72)), 'whether its first 72 bytes sign in');

            // a name that holds markup is stored as typed and must be shown escaped
            $this->putBack(true);
            $this->submit(['answer' => 'register-post-markup-name', 'then' => 'register-post-markup-name-then', 'after' => 'register-post-markup-name-after', 'user' => 'register-post-markup-name-user'], ['name' => '<b>x</b> <script>alert(1)</script>'] + self::dana());
            $this->assertSame('<b>x</b> <script>alert(1)</script>', $this->accountOf('dana')['title'], 'the name is stored as typed');
            $member = $this->loginCookie('dana', self::NEW_PASSWORD);
            $home = $this->site()->request('GET', '/', ['Cookie' => $member]);
            $profile = $this->site()->request('GET', '/profile/dana');
            $profileAsMember = $this->site()->request('GET', '/account/settings/', ['Cookie' => $member]);
            $this->answer('register-post-markup-name-home', $home, $this->scrubWithNonce());
            // the profile's Last-Modified is the time the account was made
            $this->answer('register-post-markup-name-profile', self::withoutLastModified($profile), $this->scrubWithNonce());
            $this->answer('register-post-markup-name-settings', $profileAsMember, $this->scrubWithNonce());
            foreach (['home' => $home, 'profile' => $profile, 'settings' => $profileAsMember] as $page => $response) {
                $this->assertStringNotContainsString('<script>alert(1)</script>', $response->body, 'the name is escaped on the ' . $page . ' page');
                $this->assertStringNotContainsString('<b>x</b>', $response->body, 'and its markup too');
            }
            $this->putBack(true);
        });
    }

    public function testTheEventsOfARegistration(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            $this->mode('');
            $this->events();
            // a registration on an open site: the strength and validation events, the new account's, and the login
            $this->register(self::dana());
            $this->assertSame(['user/password/checkstrength', 'user/register/validate:dana', 'site/newuser:dana', 'user/logon:dana'], $this->events(), 'a registration');
            $this->putBack(true);
            $this->events();
            // refusals: how far the page got before it said no
            $this->register(['handle' => 'bob'] + self::dana());
            $this->assertSame(['user/password/checkstrength'], $this->events(), 'a taken handle');
            $this->register(['email' => 'not-an-address'] + self::dana());
            $this->assertSame([], $this->events(), 'an invalid email address');
            $this->register(['email' => 'blocked@example.test'] + self::dana());
            $this->assertSame(['user/password/checkstrength'], $this->events(), 'a blocked address');
            $this->register(['password2' => 'another-passphrase-1'] + self::dana());
            $this->assertSame(['user/password/checkstrength'], $this->events(), 'passwords that differ');
            $this->register(['password' => 'six-pw', 'password2' => 'six-pw'] + self::dana());
            $this->assertSame(['user/password/checkstrength', 'user/password/checkstrength'], $this->events(), 'a short password');

            // a listener that finds the password too weak, and one that vetoes the registration
            $this->mode('weak-password');
            $this->submit(['answer' => 'register-post-event-weak-password'], self::dana());
            $this->assertSame(['ada', 'bob', 'carol'], $this->handles(), 'a listener can refuse the password');
            $this->events();
            $this->mode('veto-validate');
            $this->submit(['answer' => 'register-post-event-vetoed'], self::dana());
            $this->assertSame(['ada', 'bob', 'carol'], $this->handles(), 'a listener can veto the registration');
            $this->assertSame(['user/password/checkstrength', 'user/register/validate:dana', 'user/password/checkstrength'], $this->events(), 'a listener can veto');
            $this->mode('');
        });
    }

    public function testTheUserLimitIsAskedByTheFormAndByThePost(): void
    {
        $this->seedRegistration(false);
        $this->twice(function (): void {
            $this->putBack(false);
            $this->mode('deny-add');
            $this->events();
            // `users/add/check` says no (a full site): the form refuses, with the message of a closed site
            $this->visit(['answer' => 'form-closed-no-room-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->assertSame(['users/add/check'], $this->events(), 'the form asks the limit');
            // and so does the POST: a full site registers nobody, an invitation or not
            $this->register(self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame(['users/add/check'], $this->events(), 'the POST asks the limit');
            $this->assertSame([], $this->accountOf('dana'), 'a full site registers nobody by POST');
            $this->assertTrue($this->invitationFor('dana@example.test'), 'and leaves the invitation');
            $this->submit(['answer' => 'register-post-closed-no-room-valid-code', 'user' => 'register-post-closed-no-room-valid-code-user'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame([], $this->accountOf('dana'));
            $this->putBack(false);
            $this->mode('');
        });
    }

    public function testTheRegistrationPageOfAPrivateSite(): void
    {
        $this->seedRegistration();
        $this->twice(function (): void {
            $this->putBack(true);
            $this->visit(['answer' => 'form-walled-open-html'], '/account/register');
            $this->visit(['answer' => 'form-walled-open-json'], '/account/register?_t=json');
            $this->refused(['answer' => 'register-walled-post-open-mismatched-passwords'], ['password2' => 'another-passphrase-1'], false);
            $this->refused(['answer' => 'register-walled-post-open-valid', 'then' => 'register-walled-post-open-valid-then', 'after' => 'register-walled-post-open-valid-after', 'user' => 'register-walled-post-open-valid-user'], [], true);
            $this->putBack(false);
            $this->visit(['answer' => 'form-walled-closed-no-code'], '/account/register');
            $this->visit(['answer' => 'form-walled-closed-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->submit(['answer' => 'register-walled-post-closed-no-code'], self::dana());
            $this->submit(['answer' => 'register-walled-post-closed-valid-code', 'then' => 'register-walled-post-closed-valid-code-then', 'after' => 'register-walled-post-closed-valid-code-after', 'user' => 'register-walled-post-closed-valid-code-user'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertNotSame([], $this->accountOf('dana'));
            $this->putBack(true);
        });
    }

    public function testRegistrationWhenTheBootConfigurationPinsTheSetting(): void
    {
        // the stored setting says open; the environment says closed, and wins
        $this->seedRegistration(true);
        $this->twice(function (): void {
            $this->putBack(true);
            $this->visit(['answer' => 'form-pinned-closed-no-code'], '/account/register');
            $this->visit(['answer' => 'form-pinned-closed-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->submit(['answer' => 'register-post-pinned-closed-no-code'], self::dana());
            $this->assertSame(['ada', 'bob', 'carol'], $this->handles(), 'the stored setting does not open the site');
            $this->submit(['answer' => 'register-post-pinned-closed-valid-code', 'then' => 'register-post-pinned-closed-valid-code-then', 'after' => 'register-post-pinned-closed-valid-code-after', 'user' => 'register-post-pinned-closed-valid-code-user'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertNotSame([], $this->accountOf('dana'));
            $this->putBack(true);
        });
    }
}
