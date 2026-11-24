<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The session's form token is a secret: it is in the HTML forms of its own session and nowhere else. A page drawn as
 * JSON, JSONP (a script any site can load) or Markdown, and the administrator's diagnostics report, never print it,
 * and no page carries a `csrf` variable.
 */
final class FormTokenNotPrintedTest extends PrivateSiteTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return ['the home page' => [''], 'an edit page' => ['status/edit'], 'the account settings' => ['account/settings/']];
    }

    /**
     * @dataProvider pages
     */
    public function testNoNonHtmlTypeOfAFormPagePrintsTheTokenOrACsrfKey(string $path): void
    {
        $cookie = $this->registerUser('ada');
        $html = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
        $this->assertSame(200, $html->status);
        preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $html->body, $m);
        $this->assertNotEmpty($m, 'the HTML page carries the session\'s token');
        $token = $m[1];

        foreach (['json', 'jsonp', 'markdown'] as $type) {
            $response = $this->site()->request('GET', $path . (str_contains($path, '?') ? '&' : '?') . '_t=' . $type, ['Cookie' => $cookie]);
            $this->assertSame(200, $response->status, $type);
            if ($type === 'jsonp' && str_starts_with($path, 'account/')) {
                // The account and admin pages answer JSONP with the HTML settings shell, which is no script any site can use.
                $this->assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
                continue;
            }
            $this->assertStringNotContainsString($token, $response->body, $type . ' must not print the token');
            $this->assertStringNotContainsString('"csrf"', $response->body, $type . ' must have no csrf key');
        }
        $json = $this->site()->request('GET', $path . (str_contains($path, '?') ? '&' : '?') . '_t=json', ['Cookie' => $cookie])->json();
        $this->assertArrayNotHasKey('csrf', $json);
    }

    public function testTheSettingsShellAnsweredToAJsonpRequestIsHtmlAndNeverASniffableScript(): void
    {
        $cookie = $this->registerUser('ada');

        foreach (['account/settings/', 'account', 'admin', 'admin/email/'] as $path) {
            $response = $this->site()->request('GET', $path . '?_t=jsonp&callback=steal', ['Cookie' => $cookie]);

            $this->assertSame('text/html; charset=utf-8', strtolower((string) $response->header('Content-Type')), $path);
            $this->assertSame('nosniff', strtolower((string) $response->header('X-Content-Type-Options')), $path);
            $this->assertStringStartsNotWith('steal(', $response->body, $path);
        }
    }

    public function testAJsonpCallerGetsNoToken(): void
    {
        $cookie = $this->registerUser('ada');
        $html = $this->site()->request('GET', 'status/edit', ['Cookie' => $cookie]);
        preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $html->body, $m);

        $script = $this->site()->request('GET', 'status/edit?_t=jsonp&callback=steal', ['Cookie' => $cookie]);

        $this->assertStringStartsWith('steal(', $script->body);
        $this->assertStringNotContainsString($m[1], $script->body);
    }

    public function testTheDiagnosticsReportHasNotTheAdministratorsToken(): void
    {
        $cookie = $this->registerUser('ada');
        $html = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie]);
        $this->assertSame(200, $html->status);
        preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->body, $m);
        $this->assertNotEmpty($m);

        $report = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $report->status);
        $this->assertStringContainsString('$_SESSION:', $report->body);
        $this->assertStringNotContainsString($m[1], $report->body);
    }
}
