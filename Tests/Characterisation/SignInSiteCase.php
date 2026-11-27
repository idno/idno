<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The site the sign-in pages' recordings are made on, and what they share: `ProfileSiteCase`'s (Ada the administrator, Bob a member, Carol) with the
 * API keys of Ada and Bob fixed, the scrub that keeps a form token and the machine's address out of a recording, the helpers that sign in, follow a
 * redirect and empty the login throttle's counts.
 */
abstract class SignInSiteCase extends ProfileSiteCase
{
    protected const KEY_ADA = 'ada-fixed-api-key-for-the-sign-in-recordings';

    protected const KEY_BOB = 'bob-fixed-api-key-for-the-sign-in-recordings';

    protected function seedSharedSite(): void
    {
        $this->seedProfiles();
        $fixed = $this->runInSite(str_replace(['%ADA%', '%BOB%'], [$this->literal(self::KEY_ADA), $this->literal(self::KEY_BOB)], <<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $at = (int) strtotime('2026-03-20 12:00:00 UTC');
        foreach (['ada' => %ADA%, 'bob' => %BOB%] as $handle => $key) {
            $user = \Idno\Entities\User::getByHandle($handle);
            $record = $store->findById('entities', (string) $user->getID());
            $record['apikey'] = $key;
            $record['updated'] = $at;
            $store->save('entities', $record);
        }

        return true;
        PHP));
        $this->assertTrue($fixed);
    }

    /**
     * The answer of a page that threw, with the exception's page (it holds the server's paths and a trace) replaced by the exception's message with the
     * project's directory taken off.
     */
    protected static function withoutTheTrace(SiteResponse $r): SiteResponse
    {
        if (preg_match('/<p class="p-summary">([^<]*)<\/p>/', $r->body, $m) !== 1) {
            return $r;
        }
        // neither the file nor the line of the error: they move with any edit of the page's code
        $message = (string) preg_replace('/ in \S+:\d+$/', '', html_entity_decode($m[1], ENT_QUOTES));

        return new SiteResponse($r->status, $r->headers, '[the error page of an exception: ' . $message . "]\n", $r->lines);
    }

    /**
     * The scrub of an answer: the form tokens it was asked with, and the page to go to that a `fwd` field of an address holds as base64.
     *
     * @return \Closure(SiteResponse): SiteResponse
     */
    protected function scrub(string ...$tokens): \Closure
    {
        $base = $this->baseUrl();
        $swap = static function (string $text) use ($tokens, $base): string {
            foreach ($tokens as $token) {
                $text = str_replace($token, '{form-token}', $text);
            }

            return (string) preg_replace_callback('/fwd=([A-Za-z0-9_,%-]+)/', static function (array $m) use ($base): string {
                $decoded = \Idno\Core\Webservice::base64UrlDecode(urldecode($m[1]));

                return 'fwd=<' . str_replace($base, '{site}/', $decoded) . '>';
            }, $text);
        };

        return static fn (SiteResponse $r): SiteResponse => new SiteResponse($r->status, array_map($swap, $r->headers), $swap($r->body), array_map($swap, $r->lines));
    }

    /** The answers of a member's request, scrubbed of the tokens of the viewers' sessions. */
    protected function scrubViewers(): \Closure
    {
        return $this->scrub($this->tokenOf('owner'), $this->tokenOf('admin'), $this->tokenOf('member'));
    }

    /** Runs the scenario as many times as a capture needs (twice when it is verified), each with no failed login counted. */
    protected function twice(\Closure $scenario): void
    {
        foreach (range(1, static::passes()) as $_) {
            $this->clearTheThrottle();
            $scenario();
        }
        $this->clearTheThrottle();
        $this->addToAssertionCount(1);
    }

    /** Empties the site's cache pool (where the throttle keeps its counts), as the shared site does after each test. */
    protected function clearTheThrottle(): void
    {
        $directory = $this->cachePoolDirectory();
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }

    protected function answer(string $case, SiteResponse $response, ?\Closure $scrub = null): SiteResponse
    {
        $this->assertRecordedAnswer(static::GROUP, $case, $scrub === null ? $response : $scrub($response));

        return $response;
    }

    /** The login page as a visitor, for its session cookie and its form token. @return array{string, string} */
    protected function visitorSession(): array
    {
        $form = $this->site()->request('GET', 'session/login');
        $this->assertSame(200, $form->status);

        return [self::sessionCookie($form), self::formToken($form->body, 'session/login')['__bTk']];
    }

    /** A request for the page a response sends the browser to, with the session cookie the response set (else the one it had). */
    protected function follow(SiteResponse $response, string $cookie): SiteResponse
    {
        $location = (string) $response->header('Location');
        $this->assertStringStartsWith($this->baseUrl(), $location);

        return $this->site()->request('GET', substr($location, strlen($this->baseUrl())), ['Cookie' => self::sessionCookieOrNull($response) ?? $cookie]);
    }

    protected function bobsSession(): string
    {
        return $this->loginCookie('bob', self::PASSWORD);
    }
}
