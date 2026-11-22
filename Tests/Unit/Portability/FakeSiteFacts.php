<?php

declare(strict_types=1);

namespace Tests\Unit\Portability;

use Idno\Portability\SiteFacts;

/**
 * SiteFacts for the unit tier: a site at https://example.test/ whose working files go to a directory the
 * test gives.
 */
final class FakeSiteFacts implements SiteFacts
{
    /** @param list<string> $administrators */
    public function __construct(
        private readonly string $temporaryDirectory,
        private readonly int $maxDownload = 10 * 1024 * 1024,
        private readonly array $administrators = [],
    )
    {
    }

    public function url(): string
    {
        return 'https://example.test/';
    }

    public function displayUrl(): string
    {
        return 'https://example.test/';
    }

    public function title(): string
    {
        return 'Example';
    }

    public function description(): string
    {
        return 'An example site';
    }

    public function pathHost(): string
    {
        return 'example.test';
    }

    public function host(): string
    {
        return 'www.example.test';
    }

    public function version(): string
    {
        return '2.0';
    }

    public function build(): string
    {
        return '2026010100';
    }

    public function temporaryDirectory(): string
    {
        return $this->temporaryDirectory;
    }

    public function maxDownloadBytes(): int
    {
        return $this->maxDownload;
    }

    public function contentTypeClasses(): array
    {
        return [];
    }

    public function isAdministrator(string $userUuid): bool
    {
        return in_array($userUuid, $this->administrators, true);
    }

    public function prepareToDraw(): void
    {
    }
}
