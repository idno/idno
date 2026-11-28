<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the password-reset pages (`/account/password`, which asks for a reset, and `/account/password/reset`, which sets the new password) answer,
 * recorded over HTTP (first from the pages as they were, then again when the reset flow changed on purpose: a refused reset is the form in the answer, an accepted one
 * ends at the sign-in page, a blocked address is sent no mail) on sites of the test's own (a reset changes an account, so the site is not shared): each page in each
 * template type and for each way of asking, to a visitor, a member and a signed request; the request's POST for each kind of address it can be given,
 * with the mail it sends (recipient, sender, subject, both parts); the reset form's GET for each state of a recovery code (valid, wrong, expired, another
 * member's, none, used); and the reset's POST for each field it validates, with the page the same browser loads next, whether the old and the new
 * password sign in, whether the member's other session survives and what the login throttle then counts. The answers are
 * `Tests/Characterisation/fixtures/answers/password-reset/`.
 *
 * The site is `SignInSiteCase`'s (Ada the administrator, Bob a member, Carol) with no posts of Bob's, a sender address, a member Erin whose recovery
 * code is four hours old, and a member Frank whose address is on the blocklist. A scenario arms the site before each case (it runs twice when a capture
 * is verified): Bob has his password and a recovery code of a fixed value dated now, Carol has none, Erin's is expired. A recovery code and a form
 * token are secrets: no recording holds one (a code is `{code}`), and the mail's link is recorded with the code replaced.
 */
final class PasswordResetAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'password-reset';

    private const HOST_WHY = 'a request with a malformed Host is served by the legacy router, which has no page for the address now: its 404 page (the legacy page drew the form)';

    private const XHR_WHY = 'an XHR GET gets the headers of the same request that is not XHR';

    /**
     * Cases the pages now answer differently on purpose, each asserted in `testWhatTheControllersAnswerDifferentlyFromTheLegacyPages`: an address that is an
     * array found the newest member (the lookup read it as no condition), issued that member a new recovery code and then failed to draw the mail; it is no
     * address, so the answer is the one every unknown address gets. The others are what every page that moved to a controller answers differently.
     */
    private const CHANGED = [
        'request-post-array' => 'an address that is not text finds no member: the redirect every address gets, with no code issued and no mail',
        'request-xhr' => self::XHR_WHY,
        'reset-form-valid-xhr' => self::XHR_WHY,
        'request-malformed-host' => self::HOST_WHY,
        'reset-form-valid-malformed-host' => self::HOST_WHY,
        'reset-post-no-token-xhr' => 'a script\'s POST with a missing or wrong form token is the site\'s 403 page: the lifecycle\'s rule for every site page (the legacy page forwarded, which a script\'s request cannot follow, so it answered an empty 200)',
    ];

    private const NEW_PASSWORD = 'a-new-passphrase-77';

    private const CODE_BOB = '0123456789abcdef0123456789abcd11';

    private const CODE_CAROL = '0123456789abcdef0123456789abcd12';

    private const CODE_ERIN = '0123456789abcdef0123456789abcd13';

    private const WRONG_CODE = 'ffffffffffffffffffffffffffffffff';

    /** What the site stores of a recovery code: its hash. */
    private static function stored(string $code): string
    {
        return hash('sha256', $code);
    }

    /** @var list<array<string, mixed>> the mail the last recording of mail read */
    private array $lastMails = [];

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

    // ------------------------------------------------------------------ the site

    private function seedReset(): void
    {
        $this->seedSharedSite();
        $this->addUser('erin', self::PASSWORD, 'erin@example.com');
        $this->addUser('frank', self::PASSWORD, 'blocked@example.test');
        $this->assertTrue($this->runInSite(<<<'PHP'
        $config = \Idno\Core\Idno::site()->config();
        $config->from_email = 'site@example.test';
        $config->smtp_host = 'mail.example.test';
        $config->title = 'Reset Test Site';
        $config->blocked_emails = ['blocked@example.test'];
        $config->save();

        return true;
        PHP));
    }

    /**
     * Puts the accounts as every case starts from them: Bob's password and a recovery code dated now (valid for three hours from the moment of seeding),
     * Carol none, Erin's four hours old; the throttle's counts and the mail the site has sent emptied.
     *
     * @param bool $carolHasACode Carol too has a valid code
     */
    private function arm(bool $carolHasACode = false): void
    {
        $code = str_replace(
            ['%PASSWORD%', '%BOB%', '%CAROL%', '%ERIN%', '%CAROL_CODE%'],
            [$this->literal(self::PASSWORD), $this->literal(self::CODE_BOB), $this->literal(self::CODE_CAROL), $this->literal(self::CODE_ERIN), $carolHasACode ? 'true' : 'false'],
            <<<'PHP'
            \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
            $set = static function (string $handle, $code, int $at): void {
                $user = \Idno\Entities\User::getByHandle($handle);
                $user->setPassword(%PASSWORD%);
                $user->password_recovery_code = $code === false ? false : \Idno\Entities\User::hashPasswordRecoveryCode($code);
                $user->password_recovery_code_time = $at;
                $user->save(true);
            };
            $set('bob', %BOB%, time());
            $set('carol', %CAROL_CODE% ? %CAROL% : false, time());
            $set('erin', %ERIN%, time() - 4 * 3600);

            return true;
            PHP
        );
        $this->assertTrue($this->runInSite($code));
        $this->clearTheThrottle();
        $this->site()->request('GET', '_test/mail/log');
    }

    /** @return array{code: string, hasCode: bool} what the site stores of an account's recovery code */
    private function recoveryOf(string $handle): array
    {
        $found = $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $u = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . '); return ["code" => (string) $u->password_recovery_code, "hasCode" => (bool) $u->getPasswordRecoveryCode()];');
        $this->assertIsArray($found);

        return $found;
    }

    /** Whether the login form signs a member in with a password (one attempt, counted by the throttle). */
    private function signsIn(string $handle, string $password): bool
    {
        [$cookie, $token] = $this->visitorSession();
        $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => $handle, 'password' => $password]);
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;

        return $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie])->status === 200;
    }

    // ------------------------------------------------------------------ scrubbing and recording

    /** The scrub of this page's answers: form tokens, a recovery code wherever a page repeats it, a nonce, the address a redirect carries as base64. */
    private function scrubbed(string ...$tokens): \Closure
    {
        $scrub = $this->scrub(...$tokens);
        $swap = static fn (string $text): string => (string) preg_replace(
            ['/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/', '/nonce-[A-Za-z0-9_\/+=-]{16,}/', '/nonce="[A-Za-z0-9_\/+=-]{16,}"/'],
            ['{code}', 'nonce-{nonce}', 'nonce="{nonce}"'],
            $text
        );

        return static function (SiteResponse $r) use ($scrub, $swap): SiteResponse {
            $r = $scrub($r);

            return new SiteResponse($r->status, array_map($swap, $r->headers), $swap($r->body), array_map($swap, $r->lines));
        };
    }

    /** The scrub with the answer's trace and (for a script's request) the time of the request taken out. */
    private function stripped(\Closure $scrub, bool $xhr): \Closure
    {
        return static fn (SiteResponse $r): SiteResponse => $xhr ? self::withoutLastModified(self::withoutTheTrace($scrub($r))) : self::withoutTheTrace($scrub($r));
    }

    /** The session and token of a viewer: a visitor's, or Bob's. @return array{string, string} */
    private function sessionOf(?string $viewer): array
    {
        if ($viewer === null) {
            return $this->visitorSession();
        }
        $cookie = $this->bobsSession();

        return [$cookie, $this->tokenWith(['Cookie' => $cookie])];
    }

    /**
     * A request for a page (GET, HEAD or OPTIONS) and what follows an answer that is a redirect: the page the browser is sent to and the home page
     * after it (where a message that was waiting shows). The recordings' names are literals at each call.
     *
     * @param array{answer: string, then?: string, after?: string} $names
     * @param array<string, string> $headers
     */
    private function get(array $names, string $path, array $headers = [], ?string $viewer = null, string $method = 'GET'): SiteResponse
    {
        [$cookie, $token] = $this->sessionOf($viewer);
        $s = $this->scrubbed($token);
        $answer = $this->site()->request($method, $path, ['Cookie' => $cookie] + $headers);
        $this->answer($names['answer'], $answer, $this->stripped($s, isset($headers['X-Requested-With'])));
        $this->afterwards($names, $answer, $cookie, $s);

        return $answer;
    }

    /** A request signed with Bob's API key (no session). @param array{answer: string} $names */
    private function signed(array $names, string $path, string $method = 'GET', array $fields = []): SiteResponse
    {
        $answer = $this->site()->request($method, $path, self::signedHeadersFor('bob', self::KEY_BOB, $path), $fields);
        $this->answer($names['answer'], $answer, $this->stripped($this->scrubbed($this->tokenOf('member')), false));

        return $answer;
    }

    /**
     * A POST and what follows it: the answer, the page the browser is sent to, the home page after that, who is signed in and the mail the site sent.
     * With a `cookie` option the request is made in that session (Bob's, say).
     *
     * @param array{answer: string, then?: string, after?: string, user?: string, mail?: string} $names
     * @param array<string, mixed> $fields
     * @param array{path?: string, headers?: array<string, string>, token?: bool, cookie?: string, referer?: string|false} $options
     */
    private function post(array $names, string $path, array $fields, array $options = []): SiteResponse
    {
        if (isset($options['cookie'])) {
            $cookie = $options['cookie'];
            $token = $this->tokenWith(['Cookie' => $cookie]);
        } else {
            [$cookie, $token] = $this->visitorSession();
        }
        $s = $this->scrubbed($token);
        $xhr = isset($options['headers']['X-Requested-With']);
        $headers = ['Cookie' => $cookie] + ($options['headers'] ?? []);
        $referer = $options['referer'] ?? $this->baseUrl() . ltrim(explode('?', $path)[0], '/');
        if ($referer !== false) {
            $headers += ['Referer' => $referer];
        }
        $body = (($options['token'] ?? true) ? ['__bTk' => $token] : []) + $fields;
        $answer = $this->site()->request('POST', $path, $headers, $body);
        $this->answer($names['answer'], $answer, $this->stripped($s, $xhr));
        $after = $this->afterwards($names, $answer, $cookie, $s);
        if (isset($names['user'])) {
            $this->answer($names['user'], $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $after]), $s);
        }
        if (isset($names['mail'])) {
            $this->recordMail($names['mail']);
        }

        return $answer;
    }

    /**
     * The page a redirect sends the browser to and the home page after it; the cookie the browser holds then.
     *
     * @param array{answer: string, then?: string, after?: string} $names
     */
    private function afterwards(array $names, SiteResponse $answer, string $cookie, \Closure $s): string
    {
        $after = self::sessionCookieOrNull($answer) ?? $cookie;
        if (isset($names['then']) && $answer->header('Location') !== null) {
            $then = $this->follow($answer, $cookie);
            $this->answer($names['then'], $then, $s);
            $after = self::sessionCookieOrNull($then) ?? $after;
            if (isset($names['after'])) {
                $this->answer($names['after'], $this->site()->request('GET', '/', ['Cookie' => $after]), $s);
            }
        }

        return $after;
    }

    /** The mail the site has sent since it was last read, recorded as a document (recipient, sender, subject, both parts), or that there was none. @return list<array<string, mixed>> */
    private function recordMail(string $case): array
    {
        $mails = $this->site()->request('GET', '_test/mail/log')->json();
        $text = '';
        foreach ($mails as $i => $mail) {
            $list = static fn (array $addresses): string => implode(', ', array_map(static fn (array $a): string => ($a['name'] !== '' ? $a['name'] . ' ' : '') . '<' . $a['address'] . '>', $addresses));
            $text .= 'mail ' . ($i + 1) . "\nto: " . $list($mail['to']) . "\nfrom: " . $list($mail['from']) . "\nbcc: " . $list($mail['bcc']) . "\nreply-to: " . $list($mail['reply_to'])
                . "\nsubject: " . $mail['subject'] . "\n\n-- text --\n" . $mail['text'] . "\n-- html --\n" . $mail['html'] . "\n";
        }
        $this->lastMails = $mails;
        $record = new SiteResponse(200, ['content-type' => 'text/plain'], $mails === [] ? "[no mail was sent]\n" : $text);
        $this->assertRecordedAnswer(static::GROUP, $case, $this->scrubbed()($record));

        return $mails;
    }

    /** The code in the link of the mail that was last recorded. */
    private function codeInTheMail(): string
    {
        $this->assertCount(1, $this->lastMails);
        $this->assertSame(1, preg_match('#code=([0-9a-f]{32})#', (string) $this->lastMails[0]['text'], $m));

        return $m[1];
    }

    // ------------------------------------------------------------------ the request page

    public function testTheRequestPage(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $this->arm();
            $this->get(['answer' => 'request-html'], '/account/password');
            $this->get(['answer' => 'request-json'], '/account/password?_t=json');
            $this->get(['answer' => 'request-jsonp'], '/account/password?_t=jsonp&callback=f');
            $this->get(['answer' => 'request-markdown'], '/account/password?_t=markdown');
            $this->get(['answer' => 'request-rss'], '/account/password?_t=rss');
            $this->get(['answer' => 'request-slash'], '/account/password/');
            $this->get(['answer' => 'request-double-slash'], '//account/password');
            $this->get(['answer' => 'request-head'], '/account/password', [], null, 'HEAD');
            $this->get(['answer' => 'request-options'], '/account/password', [], null, 'OPTIONS');
            $this->get(['answer' => 'request-xhr'], '/account/password', ['X-Requested-With' => 'XMLHttpRequest']);
            $this->get(['answer' => 'request-malformed-host'], '/account/password', ['Host' => 'bad host!']);
            $this->get(['answer' => 'request-sent'], '/account/password?sent=true');
            $this->get(['answer' => 'request-sent-json'], '/account/password?sent=true&_t=json');
            $this->get(['answer' => 'request-sent-zero'], '/account/password?sent=0');
            $this->get(['answer' => 'request-sent-array'], '/account/password?sent[]=1');
            $this->get(['answer' => 'request-bob-html'], '/account/password', [], 'bob');
            $this->get(['answer' => 'request-bob-json'], '/account/password?_t=json', [], 'bob');
            $this->get(['answer' => 'request-bob-sent'], '/account/password?sent=true', [], 'bob');
            $this->signed(['answer' => 'request-signed-html'], '/account/password');
            $this->signed(['answer' => 'request-signed-json'], '/account/password?_t=json');
        });
    }

    /** The pages that ask for a link warn a visitor when the site has no mail service to send it through, and say nothing about any account. */
    public function testTheRequestPageOfASiteWithNoMailService(): void
    {
        $this->seedReset();
        $this->assertTrue($this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->smtp_host = ""; $config->save(); return true;'));
        $this->twice(function (): void {
            $this->arm();
            $this->get(['answer' => 'request-no-mail'], '/account/password');
            $this->get(['answer' => 'request-no-mail-sent'], '/account/password?sent=true');
            $this->post(['answer' => 'request-post-no-mail', 'then' => 'request-post-no-mail-then'], '/account/password', ['email' => 'nobody@example.com']);
        });
    }

    /**
     * Catches: an XHR GET answered with the legacy router's second `Cache-Control` line, a request with a malformed Host served by a page that does not
     * exist for it, and a script's POST with no form token forwarded where a script cannot follow (an empty 200 that looks like success) instead of refused,
     * with the password changed behind it.
     */
    public function testWhatTheControllersAnswerDifferentlyFromTheLegacyPages(): void
    {
        $this->seedReset();
        $this->arm();
        $valid = '/account/password/reset?email=bob%40example.com&code=' . self::CODE_BOB;
        foreach (['request' => ['/account/password', 'request-xhr'], 'reset' => [$valid, 'reset-form-valid-xhr']] as [$path, $case]) {
            [$cookie, $token] = $this->visitorSession();
            $answer = $this->site()->request('GET', $path, ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);
            $plain = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
            $s = $this->scrubbed($token);
            [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($s($answer)), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($s($plain)), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $case);
            $this->assertSame(explode("\n\n", (string) file_get_contents(self::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt'), 2)[1], $answeredBody, $case . ': the page is what it was');
            $this->assertCount(1, $answer->lines('cache-control'));
        }

        foreach ([$valid, '/account/password'] as $path) {
            $this->assertSame(404, $this->site()->request('GET', $path, ['Host' => 'bad host!'])->status, $path);
        }

        $before = $this->recoveryOf('bob');
        $refused = $this->site()->request('POST', '/account/password/reset', ['Cookie' => $this->visitorSession()[0], 'X-Requested-With' => 'XMLHttpRequest'], self::reset());
        $this->assertSame(403, $refused->status);
        $this->assertSame($before, $this->recoveryOf('bob'), 'no token, no reset');
        $this->assertTrue($this->signsIn('bob', self::PASSWORD));
    }

    public function testRequestingAReset(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $this->arm();
            $before = $this->recoveryOf('bob');
            $mails = $this->post(['answer' => 'request-post-known', 'then' => 'request-post-known-then', 'after' => 'request-post-known-after', 'user' => 'request-post-known-user', 'mail' => 'request-post-known-mail'], '/account/password', ['email' => 'bob@example.com']);
            $this->assertSame(302, $mails->status);
            $this->assertCount(1, $this->lastMails, 'one mail was sent');
            $this->assertSame([['address' => 'bob@example.com', 'name' => '']], $this->lastMails[0]['to'], 'to the member');
            $after = $this->recoveryOf('bob');
            $this->assertNotSame($before['code'], $after['code'], 'the request issues a new code');
            $this->assertTrue($after['hasCode']);

            $this->arm();
            $this->post(['answer' => 'request-post-upper-case', 'then' => 'request-post-upper-case-then', 'user' => 'request-post-upper-case-user', 'mail' => 'request-post-upper-case-mail'], '/account/password', ['email' => 'BOB@Example.com']);

            $this->arm();
            $this->post(['answer' => 'request-post-padded', 'then' => 'request-post-padded-then', 'mail' => 'request-post-padded-mail'], '/account/password', ['email' => ' bob@example.com ']);

            $this->arm();
            $this->post(['answer' => 'request-post-unknown', 'then' => 'request-post-unknown-then', 'after' => 'request-post-unknown-after', 'mail' => 'request-post-unknown-mail'], '/account/password', ['email' => 'nobody@example.com']);

            $this->arm();
            $this->post(['answer' => 'request-post-handle', 'then' => 'request-post-handle-then', 'mail' => 'request-post-handle-mail'], '/account/password', ['email' => 'bob']);
            $this->assertSame(self::stored(self::CODE_BOB), $this->recoveryOf('bob')['code'], 'a handle is no address: Bob has the code he had');

            $this->arm();
            $this->post(['answer' => 'request-post-empty', 'then' => 'request-post-empty-then', 'mail' => 'request-post-empty-mail'], '/account/password', ['email' => '']);

            $this->arm();
            $this->post(['answer' => 'request-post-missing', 'then' => 'request-post-missing-then', 'mail' => 'request-post-missing-mail'], '/account/password', []);

            $this->arm();
            $answer = $this->post(['answer' => 'request-post-array'], '/account/password', ['email' => ['bob@example.com']]);
            $this->assertSame(302, $answer->status);
            $this->assertSame($this->baseUrl() . 'account/password/?sent=true', $answer->header('Location'));
            $this->recordMail('request-post-array-mail');
            $this->assertSame(self::stored(self::CODE_BOB), $this->recoveryOf('bob')['code'], 'no member was issued a new code');
            $this->assertFalse($this->recoveryOf('carol')['hasCode'], 'no member was issued a new code');

            $this->arm();
            $this->post(['answer' => 'request-post-blocked', 'then' => 'request-post-blocked-then', 'mail' => 'request-post-blocked-mail'], '/account/password', ['email' => 'blocked@example.test']);
            $this->assertSame([], $this->lastMails, 'a blocked address is sent no mail');

            $this->arm();
            $this->post(['answer' => 'request-post-no-token', 'then' => 'request-post-no-token-then', 'after' => 'request-post-no-token-after', 'mail' => 'request-post-no-token-mail'], '/account/password', ['email' => 'bob@example.com'], ['token' => false]);
            $this->assertSame(self::stored(self::CODE_BOB), $this->recoveryOf('bob')['code'], 'no token, no new code');

            $this->arm();
            $this->post(['answer' => 'request-post-json', 'then' => 'request-post-json-then', 'mail' => 'request-post-json-mail'], '/account/password?_t=json', ['email' => 'bob@example.com']);

            $this->arm();
            $this->post(['answer' => 'request-post-xhr', 'mail' => 'request-post-xhr-mail'], '/account/password', ['email' => 'bob@example.com'], ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

            $this->arm();
            $this->post(['answer' => 'request-post-no-referer', 'then' => 'request-post-no-referer-then', 'mail' => 'request-post-no-referer-mail'], '/account/password', ['email' => 'bob@example.com'], ['referer' => false]);

            // a member who asks: the page is for visitors
            $this->arm();
            $this->post(['answer' => 'request-post-bob', 'then' => 'request-post-bob-then', 'mail' => 'request-post-bob-mail'], '/account/password', ['email' => 'bob@example.com'], ['cookie' => $this->bobsSession()]);
            $this->arm();
            $this->post(['answer' => 'request-post-bob-for-carol', 'then' => 'request-post-bob-for-carol-then', 'mail' => 'request-post-bob-for-carol-mail'], '/account/password', ['email' => 'carol@example.com'], ['cookie' => $this->bobsSession()]);

            $this->arm();
            $this->signed(['answer' => 'request-post-signed'], '/account/password', 'POST', ['email' => 'bob@example.com']);
            $this->recordMail('request-post-signed-mail');

            // repeated requests: each issues a new code, and the earlier one stops working
            $this->arm();
            $this->post(['answer' => 'request-post-repeat-first', 'mail' => 'request-post-repeat-first-mail'], '/account/password', ['email' => 'bob@example.com']);
            $first = $this->codeInTheMail();
            $this->post(['answer' => 'request-post-repeat-second', 'mail' => 'request-post-repeat-second-mail'], '/account/password', ['email' => 'bob@example.com']);
            $second = $this->codeInTheMail();
            $this->assertNotSame($first, $second);
            $this->get(['answer' => 'request-post-repeat-first-code', 'then' => 'request-post-repeat-first-code-then'], '/account/password/reset?email=bob%40example.com&code=' . $first);
            $this->get(['answer' => 'request-post-repeat-second-code'], '/account/password/reset?email=bob%40example.com&code=' . $second);
        });
    }

    public function testTheLinkInTheMailSetsANewPassword(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $this->arm();
            $this->post(['answer' => 'round-trip-request'], '/account/password', ['email' => 'bob@example.com']);
            $mails = $this->site()->request('GET', '_test/mail/log')->json();
            $this->assertCount(1, $mails);
            $this->assertSame([['address' => 'bob@example.com', 'name' => '']], $mails[0]['to']);
            $this->assertSame(1, preg_match('#account/password/reset/\?email=([^&\s"]+)&code=([0-9a-f]+)#', $mails[0]['text'], $m));
            $this->assertSame('bob%40example.com', $m[1]);
            $path = '/account/password/reset/?email=' . $m[1] . '&code=' . $m[2];
            $this->get(['answer' => 'round-trip-link'], $path);
            $this->get(['answer' => 'round-trip-link-json'], $path . '&_t=json');
            $bobs = $this->bobsSession();
            $this->post(['answer' => 'round-trip-reset', 'then' => 'round-trip-reset-then', 'after' => 'round-trip-reset-after', 'user' => 'round-trip-reset-user'], '/account/password/reset', ['email' => 'bob@example.com', 'code' => $m[2], 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD]);
            $this->answer('round-trip-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $bobs]), $this->scrubbed());
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD), 'the new password signs in');
            $this->assertFalse($this->signsIn('bob', self::PASSWORD), 'the old one does not');
            $this->get(['answer' => 'round-trip-link-again', 'then' => 'round-trip-link-again-then', 'after' => 'round-trip-link-again-after'], $path);
        });
    }

    // ------------------------------------------------------------------ the reset form

    public function testTheResetFormForEachStateOfACode(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $this->arm(true);
            $valid = '/account/password/reset?email=bob%40example.com&code=' . self::CODE_BOB;
            $this->get(['answer' => 'reset-form-valid'], $valid);
            $this->get(['answer' => 'reset-form-valid-json'], $valid . '&_t=json');
            $this->get(['answer' => 'reset-form-valid-jsonp'], $valid . '&_t=jsonp&callback=f');
            $this->get(['answer' => 'reset-form-valid-markdown'], $valid . '&_t=markdown');
            $this->get(['answer' => 'reset-form-valid-rss'], $valid . '&_t=rss');
            $this->get(['answer' => 'reset-form-valid-slash'], '/account/password/reset/?email=bob%40example.com&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-valid-double-slash'], '//account/password/reset?email=bob%40example.com&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-valid-head'], $valid, [], null, 'HEAD');
            $this->get(['answer' => 'reset-form-valid-options'], $valid, [], null, 'OPTIONS');
            $this->get(['answer' => 'reset-form-valid-xhr'], $valid, ['X-Requested-With' => 'XMLHttpRequest']);
            $this->get(['answer' => 'reset-form-valid-malformed-host'], $valid, ['Host' => 'bad host!']);
            $this->get(['answer' => 'reset-form-valid-upper-case-email'], '/account/password/reset?email=BOB%40example.com&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-valid-upper-case-code'], '/account/password/reset?email=bob%40example.com&code=' . strtoupper(self::CODE_BOB));
            $this->get(['answer' => 'reset-form-valid-hostile-email'], '/account/password/reset?email=' . rawurlencode('"><x-canary a=\'1') . '&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-valid-bob', 'then' => 'reset-form-valid-bob-then'], $valid, [], 'bob');
            $this->signed(['answer' => 'reset-form-valid-signed'], $valid);
            $this->signed(['answer' => 'reset-form-valid-signed-json'], $valid . '&_t=json');

            $this->get(['answer' => 'reset-form-wrong-code', 'then' => 'reset-form-wrong-code-then', 'after' => 'reset-form-wrong-code-after'], '/account/password/reset?email=bob%40example.com&code=' . self::WRONG_CODE);
            $this->get(['answer' => 'reset-form-wrong-code-json', 'then' => 'reset-form-wrong-code-json-then'], '/account/password/reset?_t=json&email=bob%40example.com&code=' . self::WRONG_CODE);
            $this->get(['answer' => 'reset-form-prefix-of-code', 'then' => 'reset-form-prefix-of-code-then'], '/account/password/reset?email=bob%40example.com&code=' . substr(self::CODE_BOB, 0, 16));
            $this->get(['answer' => 'reset-form-no-code', 'then' => 'reset-form-no-code-then'], '/account/password/reset?email=bob%40example.com');
            $this->get(['answer' => 'reset-form-no-email', 'then' => 'reset-form-no-email-then'], '/account/password/reset?code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-nothing', 'then' => 'reset-form-nothing-then', 'after' => 'reset-form-nothing-after'], '/account/password/reset');
            $this->get(['answer' => 'reset-form-empty-values', 'then' => 'reset-form-empty-values-then'], '/account/password/reset?email=&code=');
            $this->get(['answer' => 'reset-form-zero-values', 'then' => 'reset-form-zero-values-then'], '/account/password/reset?email=0&code=0');
            $this->get(['answer' => 'reset-form-unknown-email', 'then' => 'reset-form-unknown-email-then'], '/account/password/reset?email=nobody%40example.com&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-another-members-email', 'then' => 'reset-form-another-members-email-then'], '/account/password/reset?email=carol%40example.com&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-another-members-valid-code'], '/account/password/reset?email=carol%40example.com&code=' . self::CODE_CAROL);
            $this->get(['answer' => 'reset-form-expired-code', 'then' => 'reset-form-expired-code-then'], '/account/password/reset?email=erin%40example.com&code=' . self::CODE_ERIN);
            $this->get(['answer' => 'reset-form-handle-for-email', 'then' => 'reset-form-handle-for-email-then'], '/account/password/reset?email=bob&code=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-array-code', 'then' => 'reset-form-array-code-then'], '/account/password/reset?email=bob%40example.com&code[]=' . self::CODE_BOB);
            $this->get(['answer' => 'reset-form-array-email', 'then' => 'reset-form-array-email-then'], '/account/password/reset?email[]=bob%40example.com&code=' . self::CODE_BOB);
        });
    }

    // ------------------------------------------------------------------ the reset's POST

    /**
     * The fields of a reset of Bob's password with the given changes.
     *
     * @param array<string, mixed> $changed
     * @return array<string, mixed>
     */
    private static function reset(array $changed = []): array
    {
        return $changed + ['email' => 'bob@example.com', 'code' => self::CODE_BOB, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD];
    }

    public function testResettingAPassword(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $path = '/account/password/reset';
            // a valid reset: the answer, the page the browser lands on, the next page, who is signed in; the old session, the new password, the old one, the code
            $this->arm();
            $other = $this->bobsSession();
            $this->post(['answer' => 'reset-post-valid', 'then' => 'reset-post-valid-then', 'after' => 'reset-post-valid-after', 'user' => 'reset-post-valid-user'], $path, self::reset());
            $this->answer('reset-post-valid-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $other]), $this->scrubbed());
            $this->assertFalse($this->recoveryOf('bob')['hasCode'], 'the code is used up');
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD), 'the new password signs in');
            $this->assertFalse($this->signsIn('bob', self::PASSWORD), 'the old password does not');

            // the same code again: used
            $this->post(['answer' => 'reset-post-used-code', 'then' => 'reset-post-used-code-then', 'after' => 'reset-post-used-code-after'], $path, self::reset(['password' => 'another-passphrase-88', 'password2' => 'another-passphrase-88']));
            $this->assertFalse($this->signsIn('bob', 'another-passphrase-88'));

            // a reset clears the account's count of failed sign-ins
            $this->arm();
            for ($i = 0; $i < 5; $i++) {
                $this->signsIn('bob', 'not the password');
            }
            $this->assertFalse($this->signsIn('bob', self::PASSWORD), 'locked out');
            $this->post(['answer' => 'reset-post-valid-after-lockout'], $path, self::reset());
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD), 'signs in at once: the count was cleared');

            $this->arm();
            $this->post(['answer' => 'reset-post-valid-json', 'then' => 'reset-post-valid-json-then', 'user' => 'reset-post-valid-json-user'], $path . '?_t=json', self::reset());
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-valid-xhr'], $path, self::reset(), ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-valid-no-referer', 'then' => 'reset-post-valid-no-referer-then'], $path, self::reset(), ['referer' => false]);

            $this->arm();
            $this->post(['answer' => 'reset-post-valid-padded', 'then' => 'reset-post-valid-padded-then'], $path, self::reset(['password' => '  ' . self::NEW_PASSWORD . '  ', 'password2' => self::NEW_PASSWORD]));
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD), 'spaces round a password are not part of it');

            $this->arm();
            $this->post(['answer' => 'reset-post-valid-upper-case-email', 'then' => 'reset-post-valid-upper-case-email-then'], $path, self::reset(['email' => 'BOB@Example.com']));
            $this->assertTrue($this->signsIn('bob', self::NEW_PASSWORD));

            $this->arm();
            $long = str_repeat('ab', 40);
            $this->post(['answer' => 'reset-post-valid-long-password', 'then' => 'reset-post-valid-long-password-then'], $path, self::reset(['password' => $long, 'password2' => $long]));
            $this->answer('reset-post-valid-long-password-signs-in', new SiteResponse(200, [], ($this->signsIn('bob', $long) ? 'the password of eighty bytes signs in' : 'the password of eighty bytes does not sign in') . "\n"), $this->scrubbed());
            $this->answer('reset-post-valid-long-password-prefix-signs-in', new SiteResponse(200, [], ($this->signsIn('bob', substr($long, 0, 72)) ? 'its first seventy-two bytes sign in' : 'its first seventy-two bytes do not sign in') . "\n"), $this->scrubbed());

            // passwords the page refuses: the form again in the answer (no redirect, so no code in an address), the code is kept
            $this->arm();
            $this->post(['answer' => 'reset-post-mismatch', 'user' => 'reset-post-mismatch-user'], $path, self::reset(['password2' => self::NEW_PASSWORD . 'x']), ['referer' => $this->baseUrl() . 'account/password/reset?email=bob%40example.com&code=' . self::CODE_BOB]);
            $this->assertTrue($this->recoveryOf('bob')['hasCode'], 'a refused reset keeps the code');
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-mismatch-xhr'], $path, self::reset(['password2' => 'different']), ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

            $this->arm();
            $this->post(['answer' => 'reset-post-mismatch-json'], $path . '?_t=json', self::reset(['password2' => 'different']));

            $this->arm();
            $this->post(['answer' => 'reset-post-empty-passwords'], $path, self::reset(['password' => '', 'password2' => '']));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), 'empty passwords change nothing');

            $this->arm();
            $this->post(['answer' => 'reset-post-short'], $path, self::reset(['password' => 'abc123', 'password2' => 'abc123']));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-seven-characters', 'then' => 'reset-post-seven-characters-then'], $path, self::reset(['password' => 'abc1234', 'password2' => 'abc1234']));
            $this->assertTrue($this->signsIn('bob', 'abc1234'), 'seven characters are strong enough');

            $this->arm();
            $this->post(['answer' => 'reset-post-spaces-only'], $path, self::reset(['password' => '        ', 'password2' => '        ']));
        });
    }

    public function testResettingWithACodeThatIsNotRight(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $path = '/account/password/reset';
            $this->arm(true);
            $this->post(['answer' => 'reset-post-wrong-code', 'then' => 'reset-post-wrong-code-then', 'after' => 'reset-post-wrong-code-after', 'user' => 'reset-post-wrong-code-user'], $path, self::reset(['code' => self::WRONG_CODE]));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), 'a wrong code changes nothing');
            $this->assertTrue($this->recoveryOf('bob')['hasCode'], 'and does not use the code up');

            $this->arm(true);
            $this->post(['answer' => 'reset-post-wrong-code-json', 'then' => 'reset-post-wrong-code-json-then'], $path . '?_t=json', self::reset(['code' => self::WRONG_CODE]));

            $this->arm(true);
            $this->post(['answer' => 'reset-post-no-code', 'then' => 'reset-post-no-code-then'], $path, self::reset(['code' => '']));

            $this->arm(true);
            $this->post(['answer' => 'reset-post-no-email', 'then' => 'reset-post-no-email-then'], $path, self::reset(['email' => '']));

            $this->arm(true);
            $this->post(['answer' => 'reset-post-no-fields', 'then' => 'reset-post-no-fields-then'], $path, []);

            $this->arm(true);
            $this->post(['answer' => 'reset-post-unknown-email', 'then' => 'reset-post-unknown-email-then'], $path, self::reset(['email' => 'nobody@example.com']));

            // another member's address with Bob's code, and with its own valid code
            $this->arm(true);
            $this->post(['answer' => 'reset-post-another-members-email', 'then' => 'reset-post-another-members-email-then'], $path, self::reset(['email' => 'carol@example.com']));
            $this->assertTrue($this->signsIn('carol', self::PASSWORD), "Bob's code does not change Carol's password");
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), "nor does it change Bob's");

            $this->arm();
            $this->post(['answer' => 'reset-post-another-members-email-no-code', 'then' => 'reset-post-another-members-email-no-code-then'], $path, self::reset(['email' => 'carol@example.com', 'code' => '']));
            $this->assertTrue($this->signsIn('carol', self::PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-expired-code', 'then' => 'reset-post-expired-code-then'], $path, self::reset(['email' => 'erin@example.com', 'code' => self::CODE_ERIN]));
            $this->assertTrue($this->signsIn('erin', self::PASSWORD), 'an expired code changes nothing');

            $this->arm();
            $this->post(['answer' => 'reset-post-handle-for-email', 'then' => 'reset-post-handle-for-email-then'], $path, self::reset(['email' => 'bob']));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            // a wrong code is not told apart from a wrong address, and strong passwords are not asked for first
            $this->arm();
            $this->post(['answer' => 'reset-post-wrong-code-weak-password', 'then' => 'reset-post-wrong-code-weak-password-then'], $path, self::reset(['code' => self::WRONG_CODE, 'password' => 'abc', 'password2' => 'abc']));

            $this->arm();
            $this->post(['answer' => 'reset-post-no-token', 'then' => 'reset-post-no-token-then', 'after' => 'reset-post-no-token-after'], $path, self::reset(), ['token' => false]);
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), 'no token, no reset');
            $this->assertTrue($this->recoveryOf('bob')['hasCode']);

            $this->arm();
            $this->post(['answer' => 'reset-post-no-token-json', 'then' => 'reset-post-no-token-json-then'], $path . '?_t=json', self::reset(), ['token' => false]);

            $this->arm();
            $this->post(['answer' => 'reset-post-no-token-xhr'], $path, self::reset(), ['token' => false, 'headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            $this->arm();
            $this->signed(['answer' => 'reset-post-signed'], $path, 'POST', self::reset());
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), 'a signed request is refused');
            $this->assertTrue($this->recoveryOf('bob')['hasCode']);
        });
    }

    public function testResettingWithFieldsThatAreNotText(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $path = '/account/password/reset';
            $this->arm();
            $this->post(['answer' => 'reset-post-array-password'], $path, self::reset(['password' => [self::NEW_PASSWORD]]));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-array-password2'], $path, self::reset(['password2' => [self::NEW_PASSWORD]]));

            $this->arm();
            $this->post(['answer' => 'reset-post-array-code'], $path, self::reset(['code' => [self::CODE_BOB]]));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));

            $this->arm();
            $this->post(['answer' => 'reset-post-array-email'], $path, self::reset(['email' => ['bob@example.com']]));
            $this->assertTrue($this->signsIn('bob', self::PASSWORD));
        });
    }

    public function testResettingWhileSignedIn(): void
    {
        $this->seedReset();
        $this->twice(function (): void {
            $path = '/account/password/reset';
            // Bob resets his own password with his own code while signed in
            $this->arm();
            $cookie = $this->bobsSession();
            $this->post(['answer' => 'reset-post-bob-own', 'then' => 'reset-post-bob-own-then', 'after' => 'reset-post-bob-own-after', 'user' => 'reset-post-bob-own-user'], $path, self::reset(), ['cookie' => $cookie]);
            $this->answer('reset-post-bob-own-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $this->scrubbed());
            $this->assertTrue($this->signsIn('bob', self::PASSWORD), 'a member who is signed in resets nothing: the page is for visitors');
            $this->assertTrue($this->recoveryOf('bob')['hasCode']);

            // Bob resets Carol's password with Carol's valid code, while signed in as himself
            $this->arm(true);
            $cookie = $this->bobsSession();
            $this->post(['answer' => 'reset-post-bob-for-carol', 'then' => 'reset-post-bob-for-carol-then', 'after' => 'reset-post-bob-for-carol-after', 'user' => 'reset-post-bob-for-carol-user'], $path, self::reset(['email' => 'carol@example.com', 'code' => self::CODE_CAROL]), ['cookie' => $cookie]);
            $this->answer('reset-post-bob-for-carol-bobs-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $this->scrubbed());
            $this->assertTrue($this->signsIn('carol', self::PASSWORD), "a signed-in member's reset of another account changes nothing");

            // Bob with a wrong code
            $this->arm();
            $this->post(['answer' => 'reset-post-bob-wrong-code', 'then' => 'reset-post-bob-wrong-code-then'], $path, self::reset(['code' => self::WRONG_CODE]), ['cookie' => $this->bobsSession()]);

            // Bob with no token
            $this->arm();
            $this->post(['answer' => 'reset-post-bob-no-token', 'then' => 'reset-post-bob-no-token-then'], $path, self::reset(), ['cookie' => $this->bobsSession(), 'token' => false]);

            // the administrator resets a member's password: the administrator's own session
            $this->arm(true);
            $admin = $this->loginCookie('ada', self::PASSWORD);
            $this->post(['answer' => 'reset-post-admin-for-carol', 'then' => 'reset-post-admin-for-carol-then', 'user' => 'reset-post-admin-for-carol-user'], $path, self::reset(['email' => 'carol@example.com', 'code' => self::CODE_CAROL]), ['cookie' => $admin]);
        });
    }
}
