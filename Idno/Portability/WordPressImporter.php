<?php

declare(strict_types=1);

namespace Idno\Portability;

use Psr\Log\LoggerInterface;

/**
 * Imports the posts of a WordPress export (WXR) file as articles and notes of the importing user.
 *
 *  - Only items of type `post` are imported; a page, an attachment and any other type are skipped, and an
 *    item with no type is skipped without a word.
 *  - A published post is imported public and published. A private one (or one with a password) is imported
 *    private to the importing user, and a draft, a pending post or a scheduled one is imported as a draft:
 *    an import never makes public what was not. A post in the trash is skipped.
 *  - Categories and tags become hashtags in a last paragraph; "general" and "uncategorized" are dropped.
 *  - Only approved comments of type comment are imported (not spam, trash, pending, pingbacks or
 *    trackbacks), as replies; a comment author's address is kept only when it is an http or https one.
 *  - Pictures on wordpress.com (or a subdomain) are fetched and the post is changed to show the site's own
 *    copy; any other picture stays where it is.
 *  - An untitled post of under 600 characters is a note; any other is an article.
 *
 * It reads the file from its path, nothing is announced for what it imports (ImportMode), and a file
 * that cannot be read, or has no post, is an import of nothing (zero posts), never an exception.
 */
final class WordPressImporter
{
    /** The largest export file read. */
    public const MAX_BYTES = 32 * 1024 * 1024;

    private const SOURCE_DOMAIN = 'wordpress.com';

    private const CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';

    private const SHORT_NOTE_LENGTH = 600;

    private readonly ImportedMedia $source;

    /** The pictures' fetcher of the import that is running: it writes in that job's own working directory. */
    private ImportedMedia $media;

    public function __construct(
        ImportedMedia $media,
        private readonly PostWriter $writer,
        private readonly ImportMode $mode,
        private readonly SiteFacts $site,
        private readonly LoggerInterface $logger,
    )
    {
        $this->source = $media->forDomain(self::SOURCE_DOMAIN);
        $this->media = $this->source;
    }

    /**
     * @return array{posts: int, comments: int, media: int, skipped: int}
     */
    public function import(string $path, string $ownerUuid): array
    {
        if (!$this->writer->available()) {
            $this->logger->error('The import needs the Text content type, which is not enabled.');

            return ImportResult::counts();
        }
        try {
            $xml = SafeXml::load($path, self::MAX_BYTES);
        } catch (\RuntimeException $e) {
            $this->logger->error('The WordPress file cannot be imported: ' . $e->getMessage());

            return ImportResult::counts();
        }
        $namespace = self::wordPressNamespace($xml);
        if ($namespace === null || !isset($xml->channel->item)) {
            $this->logger->error('The file is not a WordPress export: it has no posts.');

            return ImportResult::counts();
        }

        $this->media = $this->source->inDirectory(dirname($path));

        return $this->mode->during(function () use ($xml, $namespace, $ownerUuid): array {
            $posts = $comments = $media = $skipped = $failures = 0;
            $stopped = false;
            foreach ($xml->channel->item as $item) {
                try {
                    $outcome = $this->importItem($item, $namespace, $ownerUuid);
                    $failures = 0;
                } catch (\Throwable $e) {
                    // One item must not end the import, nor leave the ones before it unaccounted for; but items that
                    // fail one after another mean the site cannot take them (the database is gone): it stops.
                    $this->logger->warning(ImportResult::failure($e, (string) $item->title));
                    $skipped++;
                    if (++$failures >= ImportResult::MAX_CONSECUTIVE_FAILURES) {
                        $this->logger->error('The import was stopped after ' . $failures . ' items in a row could not be imported.');
                        $stopped = true;

                        break;
                    }

                    continue;
                }
                if ($outcome === null) {
                    $skipped++;

                    continue;
                }
                $posts += $outcome['posts'];
                $comments += $outcome['comments'];
                $media += $outcome['media'];
                $skipped += $outcome['posts'] === 0 ? 1 : 0;
            }

            return ImportResult::counts($posts, $comments, $media, $skipped, $stopped);
        });
    }

    /**
     * @return array{posts: int, comments: int, media: int}|null null for an item that is not imported
     */
    private function importItem(\SimpleXMLElement $item, string $namespace, string $ownerUuid): ?array
    {
        $wp = $item->children($namespace);
        $type = trim((string) $wp->post_type);
        if ($type === '') {
            return null;
        }
        if ($type !== 'post') {
            $this->logger->debug('Skipping a WordPress item of type ' . self::printable($type) . '.');

            return null;
        }

        $status = strtolower(trim((string) $wp->status));
        $private = false;
        $draft = false;
        switch ($status) {
            case 'publish':
                break;
            case 'private':
                $private = true;
                break;
            case 'draft':
            case 'pending':
            case 'future':
            case 'auto-draft':
                $draft = true;
                break;
            default:
                return null; // trash, inherit, anything unknown
        }
        if (trim((string) $wp->post_password) !== '') {
            $private = true;
        }

        $title = (string) $item->title;
        $content = $item->children(self::CONTENT_NAMESPACE);
        $body = (string) $content->encoded;
        if ($body === '') {
            $body = (string) $item->description;
        }
        $created = $this->created($wp, $item);

        $tags = [];
        foreach ($item->category as $category) {
            $name = strtolower(trim((string) $category));
            if ($name === '' || $name === 'general' || $name === 'uncategorized') {
                continue;
            }
            $tag = preg_replace('/\W+/', '', $name);
            // A body that was exported by this site (or by anything that draws the post) already shows its tags:
            // a tag is appended only when the text does not hold that hashtag as a whole tag already.
            if ($tag !== '' && !in_array('#' . $tag, $tags, true) && preg_match('/(?<![\p{L}\p{N}_#])#' . preg_quote($tag, '/') . '(?![\p{L}\p{N}_])/iu', html_entity_decode(strip_tags($body))) !== 1) {
                $tags[] = '#' . $tag;
            }
        }
        if ($tags !== []) {
            $body .= '<p>' . implode(' ', $tags) . '</p>';
        }

        [$body, $fileIds] = ImportedImages::bring($body, $this->media, $this->site->url());

        $note = trim($title) === '' && strlen($body) < self::SHORT_NOTE_LENGTH;
        $post = new ImportedPost(
            $note ? ImportedPost::NOTE : ImportedPost::ARTICLE,
            html_entity_decode($title),
            $body,
            $created,
            $draft,
            $private,
            $fileIds
        );
        $uuid = $this->writer->write($post, $ownerUuid);
        if ($uuid === null) {
            $this->logger->warning('A post could not be saved.');

            return ['posts' => 0, 'comments' => 0, 'media' => 0];
        }

        $comments = [];
        foreach ($wp->comment as $comment) {
            $imported = $this->comment($comment);
            if ($imported !== null) {
                $comments[] = $imported;
            }
        }

        $added = 0;
        if ($comments !== []) {
            try {
                $added = $this->writer->addComments($uuid, $comments);
            } catch (\Throwable $e) {
                // The post is written: it is counted, its comments are not.
                $this->logger->warning(ImportResult::failure($e, 'comments of ' . $title));
            }
        }

        return [
            'posts' => 1,
            'comments' => $added,
            'media' => count($fileIds),
        ];
    }

    private function created(\SimpleXMLElement $wp, \SimpleXMLElement $item): int
    {
        // wp:post_date is in the blog's time zone, which the site's time zone is read as; a draft that was
        // never published has a zero date in the GMT one.
        foreach ([(string) $wp->post_date, (string) $item->pubDate] as $date) {
            $date = trim($date);
            if ($date === '' || str_starts_with($date, '0000')) {
                continue;
            }
            $time = strtotime($date);
            if ($time !== false) {
                return $time;
            }
        }

        return time();
    }

    private function comment(\SimpleXMLElement $comment): ?ImportedComment
    {
        $fields = $comment->children(self::wordPressNamespaceOf($comment));
        if (trim((string) $fields->comment_approved) !== '1') {
            return null; // pending, spam, trash
        }
        $type = trim((string) $fields->comment_type);
        if ($type !== '' && $type !== 'comment') {
            return null; // pingback, trackback
        }
        $content = (string) $fields->comment_content;
        if (trim($content) === '') {
            return null;
        }
        $date = trim((string) $fields->comment_date_gmt);
        $time = $date !== '' && !str_starts_with($date, '0000') ? strtotime($date . ' UTC') : false;

        return new ImportedComment(
            (string) $fields->comment_author,
            ImportedComment::safeUrl((string) $fields->comment_author_url),
            $content,
            $time === false ? time() : $time
        );
    }

    /** The namespace the file's `wp:` elements are in (WXR 1.0, 1.1 and 1.2 differ), or null. */
    private static function wordPressNamespace(\SimpleXMLElement $xml): ?string
    {
        foreach ($xml->getDocNamespaces(true) as $namespace) {
            if (str_starts_with($namespace, 'http://wordpress.org/export/') && !str_contains($namespace, '/excerpt')) {
                return $namespace;
            }
        }

        return null;
    }

    private static function wordPressNamespaceOf(\SimpleXMLElement $element): string
    {
        foreach ($element->getNamespaces(true) as $namespace) {
            if (str_starts_with($namespace, 'http://wordpress.org/export/')) {
                return $namespace;
            }
        }

        return 'http://wordpress.org/export/1.2/';
    }

    private static function printable(string $value): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value), 0, 60);
    }
}
