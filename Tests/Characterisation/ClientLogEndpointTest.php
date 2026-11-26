<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The page a script of the site's own can send a line of its log to (`/service/system/log/`): a signed-in member's request is written to the log with
 * its control characters replaced and its length bounded, and never mails the administrators; a visitor's is refused. Each test starts a site of its
 * own, because the site's settings are set.
 */
final class ClientLogEndpointTest extends PrivateSiteTestCase
{
    private function send(array $headers, array $fields): SiteResponse
    {
        $token = $this->site()->request('GET', '/service/security/csrftoken/', $headers + ['X-Requested-With' => 'XMLHttpRequest']);
        $value = trim($token->body);
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = (string) ($decoded['token'] ?? $decoded['csrf'] ?? reset($decoded));
        }

        return $this->site()->request('POST', '/service/system/log/', $headers + ['X-Requested-With' => 'XMLHttpRequest'], $fields + ['__bTk' => $value]);
    }

    private function mailSent(): int
    {
        return count($this->site()->request('GET', '/_test/mail/log')->json());
    }

    private function begin(): string
    {
        $this->registerUser('ada');
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->oops_notify = \'ops@example.net\'; $c->save(); return true;');

        $cookie = $this->registerUser('bob');
        $this->mailSent(); // the log of mail is emptied by reading it: nothing from the set-up is counted

        return $cookie;
    }

    public function testAVisitorIsRefused(): void
    {
        $this->begin();
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $answer = $this->send(['Cookie' => $cookie], ['level' => 'ERROR', 'message' => 'visitor-marker']);
        $this->assertSame(403, $answer->status);
        $this->assertStringNotContainsString('visitor-marker', $this->serverLogContents());
        $this->assertSame(0, $this->mailSent());
    }

    public function testAMembersLineIsLoggedSafelyAndMailsNobody(): void
    {
        $cookie = $this->begin();
        $answer = $this->send(['Cookie' => $cookie], ['level' => 'ERROR', 'message' => "member-marker\nIdno (forged): critical - a line nobody wrote"]);
        $this->assertSame(200, $answer->status);
        $log = $this->serverLogContents();
        $this->assertStringContainsString('member-marker', $log);
        $this->assertStringNotContainsString("\nIdno (forged)", $log, 'the message did not start a line of its own');
        $this->assertSame(0, $this->mailSent(), 'the administrators were not mailed');

        $this->send(['Cookie' => $cookie], ['level' => 'WARN', 'message' => 'long-marker' . str_repeat('x', 5000)]);
        $lines = array_values(array_filter(explode("\n", $this->serverLogContents()), static fn (string $line): bool => str_contains($line, 'long-marker')));
        $this->assertCount(1, $lines);
        $this->assertLessThan(800, strlen($lines[0]), 'the line is bounded');

        $answer = $this->send(['Cookie' => $cookie], ['level' => ['x'], 'message' => ['y']]);
        $this->assertLessThan(500, $answer->status, 'a field that is not text is no server error');
    }
}
