<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Config;
use Idno\Core\Version;
use Idno\Entities\User;
use Idno\Portability\SiteFacts;

/**
 * The import and export services' SiteFacts over the legacy configuration and content types.
 */
final class LegacySiteFacts implements SiteFacts
{
    /** The default for `webservice_max_download`: 10 MB, as Idno\Core\Webservice has it. */
    private const DEFAULT_MAX_DOWNLOAD = 10 * 1024 * 1024;

    public function __construct(private readonly Config $config)
    {
    }

    public function url(): string
    {
        return $this->config->getURL();
    }

    public function displayUrl(): string
    {
        return $this->config->getDisplayURL();
    }

    public function title(): string
    {
        return (string) $this->config->getTitle();
    }

    public function description(): string
    {
        return (string) $this->config->getDescription();
    }

    public function pathHost(): string
    {
        return (string) $this->config->pathHost();
    }

    public function host(): string
    {
        return (string) $this->config->host;
    }

    public function version(): string
    {
        return (string) Version::version();
    }

    public function build(): string
    {
        return (string) Version::build();
    }

    public function temporaryDirectory(): string
    {
        return rtrim((string) $this->config->getTempDir(), '/\\ ');
    }

    public function maxDownloadBytes(): int
    {
        $limit = (int) ($this->config->webservice_max_download ?? 0);

        return $limit > 0 ? $limit : self::DEFAULT_MAX_DOWNLOAD;
    }

    public function contentTypeClasses(): array
    {
        return array_values(array_map('strval', \Idno\Common\ContentType::getRegisteredClasses()));
    }

    public function isAdministrator(string $userUuid): bool
    {
        if ($userUuid === '') {
            return false;
        }
        $user = User::getByUUID($userUuid, false);

        return $user instanceof User && $user->isAdmin();
    }

    public function prepareToDraw(): void
    {
        $page = \Idno\Core\Idno::site()->currentPage();
        if (!empty($page)) {
            $page->setPermalink(true);
        }
    }
}
