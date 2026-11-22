<?php

declare(strict_types=1);

namespace Idno\Portability;

use Idno\Http\Client\BoundedBody;
use Idno\Storage\Files\FileStore;
use Psr\Log\LoggerInterface;

/**
 * The pictures an import brings in from the site it comes from. An address is fetched only when
 *  - its scheme is http or https, on the scheme's own port, with no user name in it,
 *  - its host is the source domain or a subdomain of it (an address that merely ends with the domain, or
 *    has it as a prefix, or as a user name, is another site's),
 *  - its file name ends with an image extension (jpg, jpeg, png, gif, webp),
 * and then through the REMOTE HTTP client (which refuses a host that is, or redirects to, a private address),
 * cut at the site's download limit, and kept only when the bytes are really one of those image types.
 * Anything else is left where it is: one line in the log, null.
 *
 * It is built for one source domain with forDomain(); with none it fetches nothing.
 */
final class ImportedMedia
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * @param \Closure(): \Symfony\Contracts\HttpClient\HttpClientInterface $remoteClient the remote client, fetched at each fetch (it is not shared: its DNS cache must not outlive a fetch in a worker)
     */
    public function __construct(
        private readonly \Closure $remoteClient,
        private readonly FileStore $files,
        private readonly SiteFacts $site,
        private readonly LoggerInterface $logger,
        private readonly string $domain = '',
        private readonly ?string $directory = null,
    )
    {
    }

    /**
     * The same, writing its temporary files in $directory (the private working directory of the job that
     * is running: a job that is killed leaves nothing in the shared temporary directory) when it is one of
     * this install's (`idno-import-` and 32 hex digits); any other directory is ignored.
     */
    public function inDirectory(string $directory): self
    {
        $usable = preg_match('~/idno-import-[0-9a-f]{32}$~', $directory) === 1 && is_dir($directory);

        return new self($this->remoteClient, $this->files, $this->site, $this->logger, $this->domain, $usable ? $directory : null);
    }

    /** The same, for the pictures of the site at $domain (and its subdomains). */
    public function forDomain(string $domain): self
    {
        return new self($this->remoteClient, $this->files, $this->site, $this->logger, strtolower(trim($domain)), $this->directory);
    }

    /** Whether the address is one this would fetch: its scheme, port, host and extension. */
    public function accepts(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80)) {
            return false;
        }
        if ($this->domain === '') {
            return false;
        }
        $host = strtolower($parts['host']);
        if ($host !== $this->domain && !str_ends_with($host, '.' . $this->domain)) {
            return false;
        }
        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true);
    }

    /**
     * Fetches the picture to a temporary file.
     *
     * @return string|null the file's path (the caller removes it), or null: not an address this fetches,
     *                     refused or failed by the client, over the limit, or not an image
     */
    public function fetch(string $url): ?string
    {
        if (!$this->accepts($url)) {
            $this->logger->debug('Not fetching an image on another site or of another kind: ' . self::printable($url));

            return null;
        }
        $limit = $this->site->maxDownloadBytes();
        $path = tempnam($this->directory ?? $this->site->temporaryDirectory(), 'import_');
        if ($path === false) {
            $this->logger->warning('A temporary file for an image cannot be made.');

            return null;
        }
        try {
            $client = ($this->remoteClient)();
            $response = $client->request('GET', $url);
            $status = $response->getStatusCode();
            if ($status < 200 || $status > 299) {
                $this->logger->warning('An image was not fetched (HTTP ' . $status . '): ' . self::printable($url));
                unlink($path);

                return null;
            }
            $declared = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if ($declared > $limit) {
                $response->cancel();
                $this->logger->warning('An image is over the download limit and was not fetched: ' . self::printable($url));
                unlink($path);

                return null;
            }
            // One byte over the limit tells a body that is exactly the limit from a body that was cut.
            $body = BoundedBody::read($client, $response, $limit + 1);
            if ($body === '' || strlen($body) > $limit) {
                $this->logger->warning('An image is empty or over the download limit and was not stored: ' . self::printable($url));
                unlink($path);

                return null;
            }
            if (file_put_contents($path, $body) === false) {
                unlink($path);

                return null;
            }
            unset($body);
            if (self::mimeType($path) === null) {
                $this->logger->warning('A file that is not an image of an allowed kind was not stored: ' . self::printable($url));
                unlink($path);

                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            // The exception's class only: its message can quote an address or a header.
            $this->logger->warning('An image could not be fetched (' . $e::class . '): ' . self::printable($url));
            @unlink($path);

            return null;
        }
    }

    /**
     * Fetches the picture and keeps it as a file of the site.
     *
     * @return string|null the id of the stored file
     */
    public function import(string $url): ?string
    {
        $path = $this->fetch($url);
        if ($path === null) {
            return null;
        }
        try {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                return null;
            }
            try {
                $name = basename((string) parse_url($url, PHP_URL_PATH));

                return $this->files->store($stream, [
                    'filename' => $name === '' ? 'image' : $name,
                    'mime_type' => self::mimeType($path) ?? 'application/octet-stream',
                ]);
            } finally {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('An image could not be stored (' . $e::class . ').');

            return null;
        } finally {
            @unlink($path);
        }
    }

    /** The image type of a file when it is one of the allowed ones, else null. */
    private static function mimeType(string $path): ?string
    {
        $information = @getimagesize($path);

        return is_array($information) ? (self::TYPES[$information[2]] ?? null) : null;
    }

    /** An address for one log line: no query or credentials, no control characters, bounded. */
    private static function printable(string $url): string
    {
        $parts = parse_url($url);
        $text = $parts === false
            ? '[unparseable address]'
            : (($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? ''));

        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text), 0, 200);
    }
}
