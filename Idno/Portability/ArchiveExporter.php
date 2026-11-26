<?php

declare(strict_types=1);

namespace Idno\Portability;

use Idno\Common\Entity;
use Idno\Config\BootConfig;
use Idno\Http\Client\BoundedBody;
use Idno\Storage\CollectionDump;
use Idno\Storage\Criteria;
use Idno\Storage\RecordIndex;
use Idno\Storage\EntityRepository;
use Idno\Storage\Files\FileStore;
use Idno\Storage\RecordStore;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The administrator's complete, restorable copy of the site, as one zip archive:
 *
 *   known.json         the site's address, title and version
 *   config.json        the stored settings, with every secret removed (never the boot configuration)
 *   entities.json      every entity as JSON, in one list that ends with an empty object
 *   json/<id>.json     each entity's JSON, with its access and publish status
 *   html/<id>.html     each entity as the site draws it
 *   exported_data.sql  the stored records, as they are: the file a restore is made from
 *   files/readable/<id>.<ext>                       each attached file
 *   files/uploads/<host>/<a>/<b>/<c>/<d>/<id>.file  each attached file, in the layout of the upload directory
 *                                                   (and its metadata in the .data file beside it)
 *
 * The records include every account's password hash and the ActivityPub private keys: without them the
 * users and the site's identity cannot be restored. So the archive is stored in the file store with
 * metadata that restricts it to administrators (the file page honours it), and must be kept as carefully
 * as a database backup.
 *
 * Nothing is read whole: the entities are read a few at a time and written to files of a working directory
 * as they come, the attached files are copied as streams, and the zip is built from those files. The
 * working directory is made private (mode 0700, an unpredictable name) and removed again whether the
 * export worked or not.
 */
final class ArchiveExporter
{
    /** The metadata the stored archive carries: the file page serves a file with it to administrators only. */
    public const RESTRICTED_TO = 'admin';

    /** The metadata key that says when an archive was made (the time it was stored, as seconds). */
    public const MADE_AT = 'portability_archive';

    /** How many entities are read at a time. */
    private const BATCH = 10;

    /**
     * Settings that describe the server the site runs on (its database, paths, sessions, outbound proxy) and not
     * the site. They belong to the configuration file or the environment, and `config.json` never holds them,
     * whether or not the boot configuration defines them now: `Config::save()` can leave a copy of some in the
     * stored record.
     */
    public const SERVER_SETTINGS = [
        'database', 'dbname', 'dbuser', 'dbpass', 'dbhost', 'dbport', 'dbstring', 'dbauthsrc',
        'path', 'url', 'host', 'feed', 'uploadpath', 'upload_tmp_dir',
        'session_path', 'session_hash_function', 'sessions_database', 'sessions_storage',
        'cookie_jar', 'proxy_string', 'proxy_type', 'disable_ssl_verify', 'http_allow_private', 'webmention_target_hosts',
        'antiplugins', 'alwaysplugins', 'bypass_fulltext_search',
    ];

    public function __construct(
        private readonly EntityRepository $entities,
        private readonly FileStore $files,
        private readonly RecordStore $records,
        private readonly CollectionDump $dump,
        private readonly SiteFacts $site,
        private readonly HttpClientInterface $client,
        private readonly \Closure $remoteClient,
        private readonly BootConfig $boot,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $zipAvailable = null,
    )
    {
    }

    /**
     * Whether the PHP zip extension is there: the archive cannot be made without it.
     */
    public function zipAvailable(): bool
    {
        return $this->zipAvailable !== null ? (bool) ($this->zipAvailable)() : class_exists(\ZipArchive::class);
    }

    /**
     * Makes the archive and stores it.
     *
     * @param ExportReport|null $report told what could not be rendered
     *
     * @return string the id of the stored file
     *
     * @throws \RuntimeException when the user is not an administrator or the archive cannot be made or stored
     */
    public function export(string $requestedByUuid, ?ExportReport $report = null): string
    {
        if (!$this->site->isAdministrator($requestedByUuid)) {
            throw new \RuntimeException('Only an administrator can export the site.');
        }
        $work = $this->makeDirectory($this->site->temporaryDirectory(), 'idno-export-');
        try {
            $folder = $this->buildFolder($work, $report ?? new ExportReport());
            $filename = $this->archiveName();
            $zip = $work . '/' . $filename;
            $this->zip($folder, $zip);

            $stream = fopen($zip, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('The archive cannot be read.');
            }
            try {
                return $this->files->store($stream, [
                    'filename' => $filename,
                    'mime_type' => 'application/zip',
                    'restricted' => self::RESTRICTED_TO,
                    'purpose' => 'export',
                    self::MADE_AT => time(),
                ]);
            } finally {
                fclose($stream);
            }
        } finally {
            self::removeTree($work);
        }
    }

    /**
     * Writes the archive's contents to a new folder (named by 32 hex digits) in $parent, or in the
     * temporary directory.
     *
     * @return string|false the folder, or false (and a log line) when it could not be made; what was
     *                      written is then removed
     */
    public function exportToFolder(?string $parent = null, ?ExportReport $report = null): string|false
    {
        $parent = rtrim($parent ?? $this->site->temporaryDirectory(), '/\\ ');
        try {
            return $this->buildFolder($parent, $report ?? new ExportReport());
        } catch (\Throwable $e) {
            $this->logger->error('The export folder could not be made: ' . $e::class);

            return false;
        }
    }

    /**
     * Builds the zip archive of an export folder at $zipPath, replacing a file already there.
     *
     * @throws \RuntimeException
     */
    public function zip(string $folder, string $zipPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is needed to make the archive.');
        }
        $folder = rtrim($folder, '/\\ ');
        if (!is_dir($folder) || !is_file($folder . '/known.json')) {
            throw new \RuntimeException('That is not an export folder.');
        }
        if (file_exists($zipPath) && !@unlink($zipPath)) {
            throw new \RuntimeException('The old archive cannot be replaced.');
        }

        $archive = new \ZipArchive();
        if ($archive->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('The archive cannot be made.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $path => $info) {
            $name = substr((string) $path, strlen($folder) + 1);
            if ($info->isDir()) {
                $archive->addEmptyDir($name);
            } else {
                $archive->addFile((string) $path, $name);
            }
        }
        // The files are read as the archive is closed, one at a time.
        if (!$archive->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('The archive cannot be written.');
        }
        @chmod($zipPath, 0600);
    }

    /** The archive's file name: the host, with dots as underscores. */
    public function archiveName(): string
    {
        $name = str_replace('.', '_', $this->site->host());

        return ($name === '' ? 'export' : $name) . '.zip';
    }

    private function buildFolder(string $parent, ExportReport $report): string
    {
        $folder = $this->makeDirectory($parent, '');
        try {
            $this->site->prepareToDraw();
            foreach (['json', 'html', 'files', 'files/readable', 'files/uploads'] as $directory) {
                $this->makeDirectory($folder . '/' . $directory, null);
            }

            $this->write($folder . '/known.json', json_encode([
                'url' => $this->site->url(),
                'title' => $this->site->title(),
                // Version information, in case the export format changes
                'version' => $this->site->version(),
                'build' => $this->site->build(),
            ], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

            // The stored settings, never the boot configuration (database password, site secret, cloud keys),
            // and none of the values that look like secrets.
            // Settings the boot configuration defines (and Config::save() copied into the stored ones) are not
            // the stored settings' own: dropped, whatever they are.
            $stored = array_diff_key($this->records->findLatest('config') ?? [], $this->boot->all(), array_flip(self::SERVER_SETTINGS));
            $this->write($folder . '/config.json', json_encode(['config' => SettingsRedactor::redact($stored, null)], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

            $this->writeEntities($folder, $report);
            $this->writeRecords($folder);

            return $folder;
        } catch (\Throwable $e) {
            self::removeTree($folder);

            throw $e;
        }
    }

    private function writeEntities(string $folder, ExportReport $report): void
    {
        $list = fopen($folder . '/entities.json', 'wb');
        if ($list === false) {
            throw new \RuntimeException('entities.json cannot be written.');
        }
        @chmod($folder . '/entities.json', 0600);
        $copied = [];
        try {
            fwrite($list, '[');
            // Paged by keyset on the store's own order (created, then id, newest first), not by offset: a post
            // published or deleted meanwhile neither repeats nor skips one, and a late page costs what the first did.
            $after = null;
            while (true) {
                $records = $this->nextRecords($after);
                if ($records === []) {
                    break;
                }
                foreach ($records as $record) {
                    $entity = $this->entities->hydrate($record);
                    if ($entity === null) {
                        continue;
                    }
                    try {
                        $this->writeEntity($folder, $entity, $list, $copied, $report);
                    } catch (\Throwable $e) {
                        // One entity must not end the export: it is still in the records of exported_data.sql.
                        $report->unrendered++;
                        $this->logger->warning('An entity could not be exported as JSON (' . $e::class . '): ' . self::printable((string) ($record['uuid'] ?? '')));
                    }
                }
                $last = end($records);
                $after = [RecordIndex::created($last), (string) ($last['_id'] ?? '')];
                gc_collect_cycles();
                if (count($records) < self::BATCH) {
                    break;
                }
            }
            fwrite($list, '{}]'); // So that the list can be written without knowing which entity is the last
        } finally {
            fclose($list);
        }
    }

    /**
     * The next page of records, newest first, after the last one of the previous page.
     *
     * @param array{0: int, 1: string}|null $after its `created` and `_id`
     *
     * @return list<array<string, mixed>>
     */
    private function nextRecords(?array $after): array
    {
        $zone = date_default_timezone_get();
        // A `created` operand is a date read in PHP's time zone: UTC, which has no hour that happens twice.
        date_default_timezone_set('UTC');
        try {
            if ($after === null) {
                return array_values($this->records->find('entities', Criteria::all(), self::BATCH, 0));
            }
            $time = gmdate('Y-m-d H:i:s', $after[0]);
            // The rest of the second the last record was made in (by `_id`, descending: the store's own order),
            // then the seconds before it. Two queries, because a search array cannot say "and" inside an "or".
            $page = array_values($this->records->find('entities', Criteria::fromLegacyArray(['created' => $time, '_id' => ['$lt' => $after[1]]]), self::BATCH, 0));
            if (count($page) < self::BATCH) {
                $older = $this->records->find('entities', Criteria::fromLegacyArray(['created' => ['$lt' => $time]]), self::BATCH - count($page), 0);
                $page = [...$page, ...array_values($older)];
            }

            return $page;
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /**
     * @param resource              $list
     * @param array<string, string> $copied the ids of the files copied so far, with the path of the copy
     */
    private function writeEntity(string $folder, Entity $entity, $list, array &$copied, ExportReport $report): void
    {
        $id = (string) $entity->getID();
        if ($id === '') {
            return;
        }

        $attachments = is_array($entity->attachments) ? $entity->attachments : [];
        foreach (['thumbnail', 'thumbnail_large', 'thumbs_large'] as $thumbnail) {
            if (!empty($entity->$thumbnail) && is_string($entity->$thumbnail) && preg_match('/file\/([a-zA-Z0-9]+)\//', $entity->$thumbnail, $matches)) {
                $attachments[] = ['url' => $entity->$thumbnail, '_id' => $matches[1]];
            }
        }
        if ($attachments !== []) {
            foreach ($attachments as $key => $attachment) {
                $attachments[$key]['url'] = $this->archiveAttachment($folder, $attachment, $copied) ?? ($attachment['url'] ?? '');
            }
            $entity->attachments = $attachments;
        }

        $json = $entity->jsonSerialize();
        if (is_array($json)) {
            // So that whoever handles the files can tell what was private from what was public.
            $json['access'] = $entity->access ?? 'PUBLIC';
            $json['publish_status'] = $entity->getPublishStatus() ?: 'published';
        }
        $text = json_encode($json, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($text === false) {
            throw new \RuntimeException('An entity cannot be written as JSON.');
        }
        $this->write($folder . '/json/' . self::safeName($id) . '.json', $text);
        fwrite($list, $text . ',');

        if (is_callable([$entity, 'draw'])) {
            try {
                $html = trim((string) $entity->draw());
                if ($html !== '') {
                    $this->write($folder . '/html/' . self::safeName($id) . '.html', self::microformatted($entity, $html));
                }
            } catch (\Throwable $e) {
                // Drawing sometimes needs context a job does not have, or a template throws: it must not end the
                // whole export. The entity is in the archive as data (JSON and the records).
                $report->unrendered++;
                $this->logger->warning('An entity could not be drawn for the export (' . $e::class . '): ' . self::printable((string) $entity->getUUID()));
            }
        }
    }

    /**
     * Copies the bytes of an attachment into the folder, in both places the archive keeps them, and returns
     * the address the entity's JSON gives it: relative to json/, and the file is there.
     *
     * @param array<string, mixed>  $attachment
     * @param array<string, string> $copied
     */
    private function archiveAttachment(string $folder, array $attachment, array &$copied): ?string
    {
        $id = (string) ($attachment['_id'] ?? '');
        if (preg_match('/^[a-f0-9]{24}(?:[a-f0-9]{8})?$/i', $id) !== 1) {
            return null;
        }
        $host = $this->site->pathHost();
        $extension = pathinfo((string) ($attachment['url'] ?? ''), PATHINFO_EXTENSION);
        $extension = preg_replace('/[^A-Za-z0-9]/', '', (string) $extension);
        $filename = $id . ($extension !== '' ? '.' . $extension : '');
        $directory = 'files/uploads/' . self::safeName($host) . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3];
        $relative = $directory . '/' . $id . '.file';

        if (!isset($copied[$id])) {
            $readable = $folder . '/files/readable/' . $filename;
            if (!$this->copyAttachment($id, (string) ($attachment['url'] ?? ''), $readable)) {
                return null;
            }
            $this->makeDirectories($folder . '/' . $directory);
            // The same bytes in the second place: a link when the file system has them, else a copy.
            if (!@link($readable, $folder . '/' . $relative) && !@copy($readable, $folder . '/' . $relative)) {
                throw new \RuntimeException('A file cannot be copied into the archive.');
            }
            $this->write($folder . '/' . $directory . '/' . $id . '.data', json_encode([
                'filename' => $filename,
                'mime_type' => !empty($attachment['mime-type']) ? $attachment['mime-type'] : 'application/octet-stream',
            ], JSON_INVALID_UTF8_SUBSTITUTE));
            $copied[$id] = $relative;
        }

        return '../' . $relative;
    }

    /**
     * The file's bytes into $target: streamed from the file store, else (an attachment the store does not
     * have) fetched from its address with the site's size limit.
     */
    private function copyAttachment(string $id, string $url, string $target): bool
    {
        $source = null;
        try {
            if ($this->files->has($id)) {
                $source = $this->files->readStream($id);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('An attached file could not be read for the export: ' . $e::class);

            return false;
        }
        if ($source !== null) {
            try {
                $out = fopen($target, 'wb');
                if ($out === false) {
                    throw new \RuntimeException('A file cannot be written into the archive.');
                }
                @chmod($target, 0600);
                try {
                    return stream_copy_to_stream($source, $out) !== false;
                } finally {
                    fclose($out);
                }
            } finally {
                fclose($source);
            }
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }
        try {
            $limit = $this->site->maxDownloadBytes();
            // The site's own client only for the site's own origin; any other address (an attachment can name
            // anything) goes through the remote client, which refuses private and reserved addresses.
            $client = $this->isOwnOrigin($url) ? $this->client : ($this->remoteClient)();
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
                return false;
            }
            $body = BoundedBody::read($client, $response, $limit + 1);
            if ($body === '' || strlen($body) > $limit) {
                return false;
            }
            $this->write($target, $body);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('An attached file could not be fetched for the export: ' . $e::class);

            return false;
        }
    }

    private function isOwnOrigin(string $url): bool
    {
        $own = parse_url($this->site->url());
        $other = parse_url($url);
        if (!is_array($own) || !is_array($other) || empty($own['host']) || empty($other['host'])) {
            return false;
        }
        $port = static fn (array $parts): int => $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        return strtolower($own['scheme'] ?? '') === strtolower($other['scheme'] ?? '')
            && strtolower($own['host']) === strtolower($other['host'])
            && $port($own) === $port($other);
    }

    /**
     * The entity's drawn content as a microformats2 object on its own: the h-entry (or h-event) with its address,
     * its date and its author as an h-card, as the site's own pages carry them (the page's shell, which holds
     * the edit controls and their tokens, is not drawn for an archive).
     */
    private static function microformatted(Entity $entity, string $html): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = method_exists($entity, 'getMicroformats2ObjectType') ? (string) $entity->getMicroformats2ObjectType() : 'h-entry';
        $type = preg_match('/^h-[a-z]+$/', $type) === 1 ? $type : 'h-entry';
        $owner = $entity->getOwner();
        $author = '';
        if ($owner instanceof \Idno\Entities\User) {
            $author = '<a class="p-author h-card" href="' . $escape((string) $owner->getURL()) . '">' . $escape((string) $owner->getName()) . '</a>';
        }
        $created = (int) $entity->created;

        return '<article class="' . $type . '">' . "\n"
            . ($author !== '' ? $author . "\n" : '')
            . '<a class="u-url" href="' . $escape((string) $entity->getURL()) . '">' . ($created > 0 ? '<time class="dt-published" datetime="' . gmdate('c', $created) . '">' . gmdate('Y-m-d H:i', $created) . ' UTC</time>' : 'permalink') . "</a>\n"
            . $html . "\n</article>\n";
    }

    /** A value for one log line: no control characters, bounded. */
    private static function printable(string $value): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value), 0, 200);
    }

    private function writeRecords(string $folder): void
    {
        $out = fopen($folder . '/exported_data.sql', 'wb');
        if ($out === false) {
            throw new \RuntimeException('exported_data.sql cannot be written.');
        }
        @chmod($folder . '/exported_data.sql', 0600);
        try {
            foreach ($this->dump->inserts('entities') as $inserts) {
                fwrite($out, $inserts);
            }
        } finally {
            fclose($out);
        }
    }

    private function write(string $path, string|false $contents): void
    {
        if ($contents === false || file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('A file of the archive cannot be written.');
        }
        @chmod($path, 0600);
    }

    /**
     * A new directory in $parent, private to the user running the site (mode 0700). With a prefix, its name
     * is the prefix and 32 random hex digits (the hash-like names of old); with null, $parent is the
     * directory to make.
     *
     * @throws \RuntimeException
     */
    private function makeDirectory(string $parent, ?string $prefix): string
    {
        $path = $prefix === null ? $parent : $parent . '/' . $prefix . bin2hex(random_bytes(16));
        if (!@mkdir($path, 0700, $prefix !== null) && !is_dir($path)) {
            throw new \RuntimeException('A working directory cannot be made.');
        }
        @chmod($path, 0700);

        return $path;
    }

    private function makeDirectories(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('A directory of the archive cannot be made.');
        }
    }

    /** An id or host as one path segment. */
    private static function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
    }

    /**
     * Removes a directory and everything in it (links, not what they point to).
     */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
