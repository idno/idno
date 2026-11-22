<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use PHPUnit\Framework\Assert;
use Tests\Characterisation\SiteClient;
use Tests\Characterisation\SiteResponse;

/**
 * What a person does in a browser, over HTTP against a test site: read a form and its signed token, post it
 * with the session cookie, log in, upload a file, follow the redirect. It holds no state of its own (no
 * cookie jar): a test keeps each person's session cookie (`name=value`) and passes it, as a browser
 * would send it, so who is acting is always visible in the test.
 */
final class Browser
{
    private const SESSION_NAME = 'known_unittest';

    public function __construct(private readonly SiteClient $site)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, ?string $cookie = null, array $headers = []): SiteResponse
    {
        return $this->site->request('GET', $path, ($cookie === null ? [] : ['Cookie' => $cookie]) + $headers);
    }

    /**
     * Posts the signed form of the page at $path (the form whose action ends with $action) with $fields, as the
     * person whose cookie it is; a visitor's cookie is the one the page started.
     *
     * @param array<string, string> $fields
     */
    public function submit(string $path, string $action, array $fields, ?string $cookie): SiteResponse
    {
        $page = $this->get($path, $cookie);
        Assert::assertSame(200, $page->status, 'the page ' . $path . ' draws: ' . substr($page->body, 0, 500));
        $headers = $cookie === null ? ['Cookie' => self::cookie($page)] : ['Cookie' => $cookie];

        return $this->site->request('POST', $action, $headers, self::token($page->body, $action) + $fields);
    }

    /**
     * As submit(), with a file: a multipart body with the fields and one file part.
     *
     * @param array<string, string>                                         $fields
     * @param array{field: string, name: string, type: string, bytes: string} $file
     */
    public function upload(string $path, string $action, array $fields, array $file, string $cookie): SiteResponse
    {
        $page = $this->get($path, $cookie);
        Assert::assertSame(200, $page->status, 'the page ' . $path . ' draws: ' . substr($page->body, 0, 500));
        $boundary = 'idno' . bin2hex(random_bytes(8));
        $body = '';
        foreach (self::token($page->body, $action) + $fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"{$file['name']}\"\r\nContent-Type: {$file['type']}\r\n\r\n{$file['bytes']}\r\n--$boundary--\r\n";

        return $this->site->request('POST', $action, ['Cookie' => $cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
    }

    /**
     * Logs in through the login form and returns the session cookie of the logged-in person; the test fails
     * when the site does not log them in.
     */
    public function login(string $handle, string $password): string
    {
        $form = $this->get('session/login');
        Assert::assertSame(200, $form->status);
        $cookie = self::cookie($form);
        $response = $this->site->request('POST', 'session/login', ['Cookie' => $cookie], self::token($form->body, 'session/login') + ['email' => $handle, 'password' => $password]);
        Assert::assertSame(302, $response->status, 'the login answers with a redirect');
        $cookie = self::cookieOr($response, $cookie);
        Assert::assertSame(200, $this->get('account/settings/', $cookie)->status, $handle . ' is logged in');

        return $cookie;
    }

    /**
     * Tries the login form: the session cookie of the logged-in person, or null when the site did not log them in.
     */
    public function tryLogin(string $handle, string $password): ?string
    {
        $form = $this->get('session/login');
        Assert::assertSame(200, $form->status);
        $cookie = self::cookie($form);
        $response = $this->site->request('POST', 'session/login', ['Cookie' => $cookie], self::token($form->body, 'session/login') + ['email' => $handle, 'password' => $password]);
        $cookie = self::cookieOr($response, $cookie) ?? $cookie;

        return $this->isLoggedIn($cookie) ? $cookie : null;
    }

    /**
     * Whether the cookie belongs to a logged-in person (the account settings page is only theirs).
     */
    public function isLoggedIn(string $cookie): bool
    {
        return $this->get('account/settings/', $cookie)->status === 200;
    }

    /**
     * The session cookie a response sets, as `name=value`.
     */
    public static function cookie(SiteResponse $response): string
    {
        $found = self::cookieOr($response, null);
        Assert::assertNotNull($found, 'a session cookie: ' . $response->header('Set-Cookie'));

        return $found;
    }

    public static function cookieOr(SiteResponse $response, ?string $default): ?string
    {
        if (preg_match_all('/(?:^|[,;]\s*)(' . self::SESSION_NAME . ')=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m) > 0) {
            return $m[1][0] . '=' . end($m[2]);
        }

        return $default;
    }

    /**
     * The signed-form token fields of the form in $html whose action ends with $action.
     *
     * @return array<string, string>
     */
    public static function token(string $html, string $action): array
    {
        foreach (preg_split('/<form\b/i', $html) ?: [] as $form) {
            if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                continue;
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                    $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                }
            }
            if (count($fields) === 3) {
                return $fields;
            }
        }
        Assert::fail('No signed form posting to ' . $action . ' in the page.');
    }

    /**
     * The text of a page the way a reader sees it: tags gone, entities decoded, white space folded.
     */
    public static function text(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html) ?? $html;

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
    }
}
