<?php

declare(strict_types=1);

namespace Tests\Templating;

use Symfony\Component\Yaml\Yaml;
use Tests\Characterisation\SiteResponse;

/**
 * What the tests of Markdown over HTTP share: asking for a page and reading the Markdown document that comes back.
 */
abstract class MarkdownSiteTestCase extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $headers = []): SiteResponse
    {
        return $this->site()->request('GET', $path, $headers);
    }

    protected function kind(SiteResponse $response): string
    {
        $type = strtolower((string) $response->header('Content-Type'));

        return match (true) {
            str_starts_with($type, 'text/markdown') => 'markdown',
            str_starts_with($type, 'text/html') => 'html',
            default => $type,
        };
    }

    protected function assertMarkdown(SiteResponse $response, string $canonical, bool $variesByAccept = true): void
    {
        $this->assertSame(200, $response->status, substr($response->body, 0, 300));
        $this->assertSame('text/markdown; charset=utf-8', strtolower((string) $response->header('Content-Type')));
        if ($variesByAccept) {
            $this->assertStringContainsStringIgnoringCase('accept', (string) $response->header('Vary'));
        }
        $this->assertSame('nosniff', strtolower((string) $response->header('X-Content-Type-Options')));
        $this->assertStringContainsString('<' . $canonical . '>; rel="canonical"', (string) $response->header('Link'));
        $this->assertStringStartsWith("---\n", $response->body);
        $this->assertStringEndsWith("\n", $response->body);
        $this->assertTrue(mb_check_encoding($response->body, 'UTF-8'));
    }

    /**
     * @return array{array<string, mixed>, string} the front matter, read by a YAML parser, and the Markdown after it
     */
    protected function parse(string $document): array
    {
        $this->assertSame(1, preg_match('/\A---\n(.*?\n)---\n(.*)\z/s', $document, $match), 'front matter between two --- lines: ' . substr($document, 0, 200));
        $fields = Yaml::parse($match[1]);
        $this->assertIsArray($fields);

        return [$fields, $match[2]];
    }

    /**
     * @return list<string> the addresses of the Markdown links and pictures of a document
     */
    protected function addresses(string $markdown): array
    {
        preg_match_all('/\]\(([^)\s]+)/', $markdown, $matches);

        return $matches[1];
    }
}
