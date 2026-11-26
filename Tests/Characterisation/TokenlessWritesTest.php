<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\MailsAlerts;
use Tests\Support\PrivateSiteTestCase;

/**
 * A PUT or a DELETE with no valid form token is refused with the site's 403 page, whichever legacy page it is sent to (a page with nothing of its own, the
 * home page's shell, a permalink's shell, a plugin's page): no critical line is logged for it and nobody is mailed. A stranger can send it, so it must not
 * make the site alert its administrator.
 */
final class TokenlessWritesTest extends PrivateSiteTestCase
{
    use MailsAlerts;

    public function testAPutOrADeleteWithNoTokenIsTheSites403AndReportsNothing(): void
    {
        $cookie = $this->registerUser('ada');
        $this->mailAlertsToAnAdministrator(); // so that "nobody is mailed" can fail
        $note = (string) parse_url($this->publishStatus($cookie, 'A note to write to.'), PHP_URL_PATH);
        $visitor = ['Cookie' => self::sessionCookie($this->site()->request('GET', '/'))];

        $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json());
        foreach (['a legacy page' => '/_test/legacy-plain', 'the home page' => '/', 'a permalink' => $note, 'a plugin\'s page' => '/status/edit'] as $kind => $path) {
            foreach (['PUT', 'DELETE'] as $method) {
                foreach (['the member' => ['Cookie' => $cookie], 'a visitor' => $visitor] as $who => $headers) {
                    $before = $this->logOffset();
                    $answer = $this->site()->request($method, $path, $headers);
                    $label = $method . ' to ' . $kind . ' by ' . $who;
                    $this->assertSame(403, $answer->status, $label);
                    $this->assertStringContainsString('The page you were on timed out.', $answer->body, $label);
                    $this->assertStringNotContainsString('{main}', $answer->body, $label . ': no exception page');
                    $this->assertDoesNotMatchRegularExpression('/critical|timed out/i', $this->logSince($before), $label . ': nothing is reported');
                    $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json(), $label . ': nobody is mailed');
                }
            }
        }
    }

    /**
     * The same refusal for a script's request: an XHR one, and one that asks for JSON. Neither falls through to the work of the page, and neither is reported.
     */
    public function testAScriptsPutOrDeleteWithNoTokenIsRefusedToo(): void
    {
        $cookie = $this->registerUser('ada');
        $this->mailAlertsToAnAdministrator();
        $note = (string) parse_url($this->publishStatus($cookie, 'A note a script writes to.'), PHP_URL_PATH);

        foreach (['an XHR request' => ['X-Requested-With' => 'XMLHttpRequest'], 'a request for JSON' => ['Accept' => 'application/json']] as $kind => $extra) {
            foreach (['a permalink' => $note, 'the home page' => '/', 'a legacy page' => '/_test/legacy-plain'] as $where => $path) {
                foreach (['PUT', 'DELETE'] as $method) {
                    $before = $this->logOffset();
                    $answer = $this->site()->request($method, $path . ($kind === 'a request for JSON' ? '?_t=json' : ''), ['Cookie' => $cookie] + $extra);
                    $label = $method . ' to ' . $where . ' as ' . $kind;
                    $this->assertSame(403, $answer->status, $label . ' ' . substr($answer->body, 0, 300));
                    $this->assertDoesNotMatchRegularExpression('/critical/i', $this->logSince($before), $label . ': nothing is reported');
                    $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json(), $label . ': nobody is mailed');
                }
            }
        }
        $this->assertSame(200, $this->site()->request('GET', $note, ['Cookie' => $cookie])->status, 'and the note is still there');
    }
}
