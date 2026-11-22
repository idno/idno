<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * Which template type a request may choose with `?_t=`, and what it gets: pinned for every type directory the project
 * has, for a type that does not exist and for names that must never reach a path. A request names the type of the HOME page of
 * a seeded site with no theme (a type named in another letter case is left out: it is a directory on some file systems); the answer is described as `status content-type kind` (the kind from the first bytes of
 * the body).
 */
final class TemplateTypeRequestTest extends RenderedSiteTestCase
{
    /** @var array<string, string> */
    private const EXPECTED = [
        'default' => '200 text/html html',
        'modern' => '200 text/html html',
        'rss' => '200 application/rss+xml xml',
        'json' => '200 application/json json',
        'jsonp' => '200 application/x-javascript text',
        'jsonfeed' => '200 application/json json',
        'kml' => '200 application/vnd.google-earth.kml+xml xml',
        'activitypub' => '200 application/activity+json json',
        'email' => '200 text/html html',
        'email-text' => '200 text/html html',
        // A type that is no directory, a path, a name the `_` rule strips to nothing, a name with a space, and none: the default type.
        'nope' => '200 text/html html',
        '../x' => '200 text/html html',
        '..%2Fx' => '200 text/html html',
        '_ABC' => '200 text/html html',
        'a%20b' => '200 text/html html',
        '' => '200 text/html html',
    ];

    public function testWhichTypesARequestMayChoose(): void
    {
        $this->seedSite();
        $actual = [];
        foreach (['default', 'modern', 'rss', 'json', 'jsonp', 'jsonfeed', 'kml', 'activitypub', 'email', 'email-text', 'nope', '../x', '..%2Fx', '_ABC', 'a%20b', ''] as $type) {
            $actual[$type] = $this->describe('?_t=' . $type);
        }

        $this->assertSame(self::EXPECTED, $actual);
    }

    /**
     * `?_t[]=x` (an array, not a type) asks for no type: the answer is not a server error and the default type is used.
     */
    public function testAnArrayTypeIsNotAServerError(): void
    {
        $this->seedSite();
        $response = $this->site()->request('GET', '?_t[]=x');

        $this->assertSame(200, $response->status);
        $this->assertSame($this->describe(''), $this->describe('?_t[]=x'), 'the default type is used');
    }

    private function describe(string $path): string
    {
        $response = $this->site()->request('GET', $path);
        $body = ltrim($response->body);
        $kind = match (true) {
            $body === '' => 'empty',
            str_starts_with($body, '<!DOCTYPE html') || str_starts_with($body, '<html') => 'html',
            str_starts_with($body, '<?xml') => 'xml',
            str_starts_with($body, '{') || str_starts_with($body, '[') => 'json',
            str_starts_with($body, '<') => 'markup',
            default => 'text',
        };

        return $response->status . ' ' . strtolower((string) preg_replace('/;.*/', '', (string) $response->header('Content-Type'))) . ' ' . $kind;
    }
}
