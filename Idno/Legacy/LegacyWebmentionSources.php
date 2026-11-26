<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\UrlValidator;
use Idno\Core\Webmention;
use Idno\Core\Webservice;
use Idno\Http\Webmention\WebmentionSources;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * What the webmention endpoint asks about a source, over the legacy application: the address check, the fetch (through the client that refuses private
 * networks on every hop), the microformats parser and the site's own addresses. The fetch is a method of its own so that a test can call it on mock
 * clients (`Webservice::useClient()`).
 */
final class LegacyWebmentionSources implements WebmentionSources
{
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application
     */
    public function __construct(
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
    )
    {
    }

    public function isSafe(string $source): bool
    {
        return UrlValidator::isSafeUrl($source);
    }

    public function fetch(string $source): array|false
    {
        return Webservice::safeSend('get', $source);
    }

    public function parse(string $content, string $source): array
    {
        return Webmention::parseContent($content, $source);
    }

    public function isLocal(string $url): bool
    {
        return Entity::isLocalUUID($url);
    }

    public function log(string $level, string $message): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException('No such log level: ' . $level);
        }
        ($this->logger)()->{$level}($message);
    }
}
