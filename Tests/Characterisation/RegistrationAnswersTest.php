<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the registration page (`/account/register`) answers, recorded from the legacy page over HTTP on sites of the test's own (a POST makes an
 * account, so the site is not shared): the form to a visitor of a site open to registration and of one that asks for an invitation (a valid code, a
 * wrong one, one for another address, one already used), in each template type and for each way of asking; the form to a member, to a signed request
 * and on a site with no account; the POST for each field the page validates, with the page the same browser loads next, who is signed in and whether an
 * account now exists; and the POSTs that are no form. The answers are `Tests/Characterisation/fixtures/answers/registration/`.
 *
 * The site is `SignInSiteCase`'s (Ada the administrator, Bob a member, Carol), with no posts of Bob's, an invitation for `dana@example.test` and one for
 * `erin@example.test` (each with a fixed id, code and time) and the address `blocked@example.test` on the blocklist. A site is open to registration
 * (the setting's default) unless the scenario closes it, and a scenario that makes an account puts the accounts, the invitations and the settings back
 * as they were before it runs again (it runs twice when a capture is verified). A form token and a session id are secrets of a session: no recording
 * holds one; the address a redirect carries as base64 is recorded decoded.
 *
 * A site closed to new users with no invitation to give (`users/add/check` answering false: nothing in core listens to it) is not recorded: nothing
 * of core or the bundled plugins can make a site say so.
 */
final class RegistrationAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'registration';

    private const NEW_PASSWORD = 'a-new-passphrase-77';

    private const CODE_DANA = '0123456789abcdef0123456789abcd01';

    private const CODE_ERIN = '0123456789abcdef0123456789abcd02';

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
            ['%OPEN%', '%DANA%', '%ERIN%'],
            [$open ? 'true' : 'false', $this->literal(self::CODE_DANA), $this->literal(self::CODE_ERIN)],
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
            $make = static function (string $id, string $email, string $code): void {
                $invitation = new \Idno\Entities\Invitation();
                $invitation->_id = $id;
                $invitation->email = $email;
                $invitation->code = $code;
                $invitation->created = (int) strtotime('2026-03-01 12:00:00 UTC');
                if (!$invitation->save(true)) {
                    throw new \RuntimeException('invitation not saved');
                }
            };
            $make('00000000000040008000000000000101', 'dana@example.test', %DANA%);
            $make('00000000000040008000000000000102', 'erin@example.test', %ERIN%);
            $site->config()->open_registration = %OPEN%;
            $site->config()->blocked_emails = ['blocked@example.test'];
            $site->config()->save();

            return true;
            PHP
        );
        $this->assertTrue($this->runInSite($code));
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
     * @param array{path?: string, headers?: array<string, string>, token?: bool, cookie?: string, referer?: bool, chain?: bool} $options
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
        $s = $this->scrubWithNonce($token);
        $xhr = isset($options['headers']['X-Requested-With']);
        // the legacy router's lines for a script's request hold the time of the request
        $strip = static fn (SiteResponse $r): SiteResponse => $xhr ? self::withoutLastModified(self::withoutTheTrace($s($r))) : self::withoutTheTrace($s($r));
        $headers = ['Cookie' => $cookie] + ($options['headers'] ?? []);
        if (($options['referer'] ?? true) === true) {
            $headers += ['Referer' => $this->baseUrl() . 'account/register'];
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
     */
    private function refused(array $names, array $changed, bool $accepted): void
    {
        $this->putBack(true);
        $made = $this->attempt($names, $changed + self::dana(), [], true);
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
            // a member is sent away (the reverse gatekeeper), whatever the form's type
            $this->visit(['answer' => 'form-open-bob-html'], '/account/register', [], 'bob');
            $this->visit(['answer' => 'form-open-bob-json'], '/account/register?_t=json', [], 'bob');
            // a signed request is refused: it is an account page
            $this->answer('form-open-signed-html', $this->site()->request('GET', '/account/register', self::signedHeadersFor('bob', self::KEY_BOB, '/account/register')), $s);
            $this->answer('form-open-signed-json', $this->site()->request('GET', '/account/register?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/account/register?_t=json')), $s);
        });
    }

    public function testTheFormOnASiteThatAsksForAnInvitation(): void
    {
        $this->seedRegistration(false);
        $this->twice(function (): void {
            $this->visit(['answer' => 'form-closed-no-code', 'then' => 'form-closed-no-code-then'], '/account/register');
            $this->visit(['answer' => 'form-closed-no-code-json', 'then' => 'form-closed-no-code-json-then'], '/account/register?_t=json');
            $this->visit(['answer' => 'form-closed-wrong-code', 'then' => 'form-closed-wrong-code-then'], '/account/register?email=dana%40example.test&code=ffffffffffffffffffffffffffffffff');
            $this->visit(['answer' => 'form-closed-code-no-email', 'then' => 'form-closed-code-no-email-then'], '/account/register?code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-email-no-code', 'then' => 'form-closed-email-no-code-then'], '/account/register?email=dana%40example.test');
            $this->visit(['answer' => 'form-closed-code-of-another-address', 'then' => 'form-closed-code-of-another-address-then'], '/account/register?email=dana%40example.test&code=' . self::CODE_ERIN);
            $this->visit(['answer' => 'form-closed-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-upper-case-email'], '/account/register?email=DANA%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-json'], '/account/register?_t=json&email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-markdown'], '/account/register?_t=markdown&email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-valid-code-slash'], '/account/register/?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->visit(['answer' => 'form-closed-array-code'], '/account/register?email=dana%40example.test&code[]=x');
            $this->visit(['answer' => 'form-closed-bob-valid-code'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA, [], 'bob');
            $this->answer('form-closed-signed-valid-code', $this->site()->request('GET', '/account/register?email=dana%40example.test&code=' . self::CODE_DANA, self::signedHeadersFor('bob', self::KEY_BOB, '/account/register?email=dana%40example.test&code=' . self::CODE_DANA)), $this->scrubViewers());
        });
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

            $this->putBack(true);
            $this->submit(['answer' => 'register-post-open-with-a-code', 'then' => 'register-post-open-with-a-code-then', 'after' => 'register-post-open-with-a-code-after', 'user' => 'register-post-open-with-a-code-user', 'admin' => 'register-post-open-with-a-code-admin'], self::dana() + ['code' => 'ignored on an open site']);
            $this->assertNotSame([], $this->accountOf('dana'));

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
            $this->refused(['answer' => 'register-post-mismatched-passwords', 'then' => 'register-post-mismatched-passwords-then', 'after' => 'register-post-mismatched-passwords-after'], ['password2' => 'another-passphrase-1'], false);
            $this->refused(['answer' => 'register-post-taken-handle', 'then' => 'register-post-taken-handle-then', 'after' => 'register-post-taken-handle-after', 'user' => 'register-post-taken-handle-user'], ['handle' => 'bob'], false);
            $this->refused(['answer' => 'register-post-taken-handle-other-case', 'then' => 'register-post-taken-handle-other-case-then', 'after' => 'register-post-taken-handle-other-case-after'], ['handle' => 'BOB'], false);
            $this->refused(['answer' => 'register-post-taken-handle-padded', 'then' => 'register-post-taken-handle-padded-then', 'after' => 'register-post-taken-handle-padded-after'], ['handle' => ' bob '], false);
            $this->refused(['answer' => 'register-post-taken-email', 'then' => 'register-post-taken-email-then', 'after' => 'register-post-taken-email-after'], ['email' => 'bob@example.com'], false);
            $this->refused(['answer' => 'register-post-taken-email-other-case', 'then' => 'register-post-taken-email-other-case-then', 'after' => 'register-post-taken-email-other-case-after'], ['email' => 'BOB@Example.com'], false);
            $this->refused(['answer' => 'register-post-taken-handle-and-email', 'then' => 'register-post-taken-handle-and-email-then', 'after' => 'register-post-taken-handle-and-email-after'], ['handle' => 'bob', 'email' => 'bob@example.com'], false);
            $this->refused(['answer' => 'register-post-blocked-email', 'then' => 'register-post-blocked-email-then', 'after' => 'register-post-blocked-email-after'], ['email' => 'blocked@example.test'], false);
            $this->refused(['answer' => 'register-post-blocked-email-other-case', 'then' => 'register-post-blocked-email-other-case-then', 'after' => 'register-post-blocked-email-other-case-after'], ['email' => 'Blocked@Example.test'], false);
            $this->refused(['answer' => 'register-post-invalid-handle', 'then' => 'register-post-invalid-handle-then', 'after' => 'register-post-invalid-handle-after'], ['handle' => 'not valid!'], false);
            $this->refused(['answer' => 'register-post-handle-with-a-slash', 'then' => 'register-post-handle-with-a-slash-then', 'after' => 'register-post-handle-with-a-slash-after'], ['handle' => 'da/na'], false);
            $this->refused(['answer' => 'register-post-handle-with-a-dot', 'then' => 'register-post-handle-with-a-dot-then', 'after' => 'register-post-handle-with-a-dot-after'], ['handle' => 'da.na'], false);
            $this->refused(['answer' => 'register-post-handle-too-long', 'then' => 'register-post-handle-too-long-then', 'after' => 'register-post-handle-too-long-after'], ['handle' => str_repeat('d', 33)], false);
            $this->refused(['answer' => 'register-post-handle-of-32', 'then' => 'register-post-handle-of-32-then', 'after' => 'register-post-handle-of-32-after', 'user' => 'register-post-handle-of-32-user', 'admin' => 'register-post-handle-of-32-admin'], ['handle' => str_repeat('d', 32)], true);
            $this->refused(['answer' => 'register-post-empty-handle', 'then' => 'register-post-empty-handle-then', 'after' => 'register-post-empty-handle-after'], ['handle' => ''], false);
            $this->refused(['answer' => 'register-post-spaces-for-a-handle', 'then' => 'register-post-spaces-for-a-handle-then', 'after' => 'register-post-spaces-for-a-handle-after'], ['handle' => '   '], false);
            $this->refused(['answer' => 'register-post-invalid-email', 'then' => 'register-post-invalid-email-then', 'after' => 'register-post-invalid-email-after', 'user' => 'register-post-invalid-email-user'], ['email' => 'not-an-address'], false);
            $this->refused(['answer' => 'register-post-empty-email', 'then' => 'register-post-empty-email-then', 'after' => 'register-post-empty-email-after'], ['email' => ''], false);
            $this->refused(['answer' => 'register-post-no-handle-and-no-email', 'then' => 'register-post-no-handle-and-no-email-then', 'after' => 'register-post-no-handle-and-no-email-after'], ['handle' => '', 'email' => ''], false);
            $this->refused(['answer' => 'register-post-short-password', 'then' => 'register-post-short-password-then', 'after' => 'register-post-short-password-after'], ['password' => 'six-pw', 'password2' => 'six-pw'], false);
            $this->refused(['answer' => 'register-post-seven-character-password', 'then' => 'register-post-seven-character-password-then', 'after' => 'register-post-seven-character-password-after', 'user' => 'register-post-seven-character-password-user', 'admin' => 'register-post-seven-character-password-admin'], ['password' => 'sevenpw', 'password2' => 'sevenpw'], true);
            $this->refused(['answer' => 'register-post-empty-passwords', 'then' => 'register-post-empty-passwords-then', 'after' => 'register-post-empty-passwords-after'], ['password' => '', 'password2' => ''], false);
            $this->refused(['answer' => 'register-post-blank-passwords', 'then' => 'register-post-blank-passwords-then', 'after' => 'register-post-blank-passwords-after'], ['password' => '       ', 'password2' => '       '], false);
            $this->refused(['answer' => 'register-post-no-second-password', 'then' => 'register-post-no-second-password-then', 'after' => 'register-post-no-second-password-after'], ['password2' => ''], false);
            $this->refused(['answer' => 'register-post-no-name', 'then' => 'register-post-no-name-then', 'after' => 'register-post-no-name-after', 'user' => 'register-post-no-name-user', 'admin' => 'register-post-no-name-admin'], ['name' => ''], true);
            $this->refused(['answer' => 'register-post-every-fault-at-once', 'then' => 'register-post-every-fault-at-once-then', 'after' => 'register-post-every-fault-at-once-after'], ['handle' => 'bob/!', 'email' => 'bob@example.com', 'password' => 'x', 'password2' => 'y'], false);
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
            // today's behaviour: an array is a name (the title is whatever it holds) and a code (an open site asks for none), and a POST a script sends is
            // registered as any other
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
            $try(['answer' => 'register-post-closed-no-code', 'then' => 'register-post-closed-no-code-then', 'after' => 'register-post-closed-no-code-after'], self::dana());
            $try(['answer' => 'register-post-closed-wrong-code', 'then' => 'register-post-closed-wrong-code-then', 'after' => 'register-post-closed-wrong-code-after'], self::dana() + ['code' => 'ffffffffffffffffffffffffffffffff']);
            $try(['answer' => 'register-post-closed-code-of-another-address', 'then' => 'register-post-closed-code-of-another-address-then', 'after' => 'register-post-closed-code-of-another-address-after'], self::dana() + ['code' => self::CODE_ERIN]);
            $try(['answer' => 'register-post-closed-code-no-email', 'then' => 'register-post-closed-code-no-email-then', 'after' => 'register-post-closed-code-no-email-after'], ['email' => ''] + self::dana() + ['code' => self::CODE_DANA]);
            $try(['answer' => 'register-post-closed-no-code-json', 'then' => 'register-post-closed-no-code-json-then', 'after' => 'register-post-closed-no-code-json-after'], self::dana(), ['path' => '/account/register?_t=json']);
            $try(['answer' => 'register-post-closed-array-code', 'then' => 'register-post-closed-array-code-then', 'after' => 'register-post-closed-array-code-after', 'user' => 'register-post-closed-array-code-user', 'admin' => 'register-post-closed-array-code-admin'], self::dana() + ['code' => ['x']]);
            $try(['answer' => 'register-post-closed-no-token', 'then' => 'register-post-closed-no-token-then', 'after' => 'register-post-closed-no-token-after'], self::dana() + ['code' => self::CODE_DANA], ['token' => false]);
            // today's behaviour: an array in place of the code finds an invitation (a defect: the lookup reads it as a list of values)
            $this->assertSame(['register-post-closed-array-code'], array_keys(array_filter($made)), 'the POSTs that make an account');
            $this->assertSame($before, $this->handles());
            $this->assertTrue($this->invitationFor('dana@example.test'), 'a refusal leaves the invitation');

            // the invitation is right, the details are not: the invitation stays
            $this->submit(['answer' => 'register-post-closed-valid-code-taken-handle', 'then' => 'register-post-closed-valid-code-taken-handle-then', 'after' => 'register-post-closed-valid-code-taken-handle-after'], ['handle' => 'bob'] + self::dana() + ['code' => self::CODE_DANA]);
            $this->submit(['answer' => 'register-post-closed-valid-code-mismatched-passwords', 'then' => 'register-post-closed-valid-code-mismatched-passwords-then', 'after' => 'register-post-closed-valid-code-mismatched-passwords-after'], ['password2' => 'another-passphrase-1'] + self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame($before, $this->handles());
            $this->assertTrue($this->invitationFor('dana@example.test'), 'a failed registration leaves the invitation');

            // the invitation is right: the account is made and the invitation is gone
            $this->submit(['answer' => 'register-post-closed-valid-code', 'then' => 'register-post-closed-valid-code-then', 'after' => 'register-post-closed-valid-code-after', 'user' => 'register-post-closed-valid-code-user', 'admin' => 'register-post-closed-valid-code-admin'], self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame(['ada', 'bob', 'carol', 'dana'], $this->handles());
            $this->assertFalse($this->invitationFor('dana@example.test'), 'the invitation is used up');
            $this->assertTrue($this->invitationFor('erin@example.test'), 'and only that one');
            $this->assertSame(['handle' => 'dana', 'email' => 'dana@example.test', 'title' => 'Dana Newcomer', 'admin' => false], $this->accountOf('dana'));
            // the code is of no use again, on the form and on the POST
            $this->visit(['answer' => 'form-closed-used-code', 'then' => 'form-closed-used-code-then'], '/account/register?email=dana%40example.test&code=' . self::CODE_DANA);
            $this->submit(['answer' => 'register-post-closed-used-code', 'then' => 'register-post-closed-used-code-then', 'after' => 'register-post-closed-used-code-after', 'user' => 'register-post-closed-used-code-user'], ['handle' => 'dana2'] + self::dana() + ['code' => self::CODE_DANA]);
            $this->assertSame(['ada', 'bob', 'carol', 'dana'], $this->handles());

            // an invitation by the address in another letter case
            $this->putBack(false);
            $this->submit(['answer' => 'register-post-closed-valid-code-upper-case-email', 'then' => 'register-post-closed-valid-code-upper-case-email-then', 'after' => 'register-post-closed-valid-code-upper-case-email-after', 'user' => 'register-post-closed-valid-code-upper-case-email-user', 'admin' => 'register-post-closed-valid-code-upper-case-email-admin'], ['email' => 'DANA@example.test'] + self::dana() + ['code' => self::CODE_DANA]);
            // an invitation for an address the blocklist holds
            $this->putBack(false);
            $this->runInSite('$i = new \Idno\Entities\Invitation(); $i->_id = "00000000000040008000000000000103"; $i->email = "blocked@example.test"; $i->code = ' . $this->literal(self::CODE_DANA) . '; $i->created = (int) strtotime("2026-03-01 12:00:00 UTC"); $i->save(true); return true;');
            $before = $this->handles();
            $this->submit(['answer' => 'register-post-closed-blocked-address', 'then' => 'register-post-closed-blocked-address-then', 'after' => 'register-post-closed-blocked-address-after'], ['email' => 'blocked@example.test'] + self::dana() + ['code' => self::CODE_DANA]);
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
            // a member who posts the form with a token is registered as a new account and the browser is switched to it: today's behaviour
            $cookie = $this->bobsSession();
            $this->submit(['answer' => 'register-post-bob-valid', 'then' => 'register-post-bob-valid-then', 'after' => 'register-post-bob-valid-after', 'user' => 'register-post-bob-valid-user', 'admin' => 'register-post-bob-valid-admin'], self::dana(), ['cookie' => $cookie]);
            $this->assertNotSame([], $this->accountOf('dana'), 'an account was made for a signed-in member');
            // the session Bob had before: still his, or not
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
            $this->submit(['answer' => 'register-post-admin-valid', 'then' => 'register-post-admin-valid-then', 'after' => 'register-post-admin-valid-after', 'user' => 'register-post-admin-valid-user', 'admin' => 'register-post-admin-valid-admin'], self::dana(), ['cookie' => $admin]);
            $this->assertSame(['handle' => 'dana', 'email' => 'dana@example.test', 'title' => 'Dana Newcomer', 'admin' => false], $this->accountOf('dana'));
            $this->putBack(true);
        });
    }
}
