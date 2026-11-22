<?php

declare(strict_types=1);

namespace Idno\Portability;

use Psr\Log\LoggerInterface;

/**
 * Imports the posts of a Blogger export (an Atom file) as articles of the importing user. It reads an RSS
 * file too (the feed Idno exports), which is what an item of `rss/channel` is.
 *
 *  - Entries of Blogger's own kinds `settings` and `template` are skipped. A page stays a post: Idno has no
 *    pages, so it is imported as an article with its own date.
 *  - A draft (`app:draft` yes, or `blogger:status` DRAFT) is imported as a draft, never published; a post
 *    that is `SOFT_TRASHED` is skipped. An RSS item that says it is private or a draft (`wp:status`, as
 *    Idno exports it) is imported private or as a draft.
 *  - A comment (kind `comment`) is imported as a reply to the post its `thr:in-reply-to` names, wherever in
 *    the file the two are; a comment whose post was not imported is skipped.
 *  - Categories become hashtags in a last paragraph (case kept, everything but ASCII letters, digits and
 *    underscores dropped).
 *  - Pictures on blogspot.com (or a subdomain) are fetched and the post is changed to show the site's own
 *    copy. Most Blogger pictures are on blogger.googleusercontent.com, which is not a blogspot.com host, so
 *    they stay where they are.
 *
 * Like the WordPress importer it reads the file from its path, announces nothing, and a file that cannot be
 * read, or has no post, is an import of nothing.
 */
final class BloggerImporter
{
    public const MAX_BYTES = WordPressImporter::MAX_BYTES;

    private const SOURCE_DOMAIN = 'blogspot.com';

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const BLOGGER = 'http://schemas.google.com/blogger/2008';

    private const APP = 'http://purl.org/atom/app#';

    private const THREAD = 'http://purl.org/syndication/thread/1.0';

    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';

    private const KIND_SCHEME = 'http://schemas.google.com/g/2005#kind';

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
            $this->logger->error('The file cannot be imported: ' . $e->getMessage());

            return ImportResult::counts();
        }

        if ($xml->getName() === 'feed') {
            $entries = $xml->children(self::ATOM)->entry;
            $atom = true;
        } elseif ($xml->getName() === 'rss' && isset($xml->channel->item)) {
            $entries = $xml->channel->item;
            $atom = false;
        } else {
            $this->logger->error('The file is neither an Atom feed nor an RSS file with posts.');

            return ImportResult::counts();
        }

        $this->media = $this->source->inDirectory(dirname($path));

        return $this->mode->during(function () use ($entries, $atom, $ownerUuid): array {
            $posts = $comments = $media = $skipped = $failures = 0;
            $stopped = false;
            /** @var array<string, string> $imported the UUIDs of the posts written, by the id the file gives them */
            $imported = [];
            /** @var array<string, list<ImportedComment>> $waiting the comments, by the post they reply to, in the order of the file: a comment may come before its post */
            $waiting = [];

            $lastFailed = false;
            foreach ($entries as $entry) {
                // An entry that did not fail ends a run of failures (several `continue`s below skip the end of the body)
                if (!$lastFailed) {
                    $failures = 0;
                }
                $lastFailed = false;
                try {
                    $kind = $atom ? $this->kind($entry) : 'post';
                    if ($kind === 'comment') {
                        $reply = $this->reply($entry);
                        if ($reply === null) {
                            $skipped++;

                            continue;
                        }
                        [$ref, $comment] = $reply;
                        $waiting[$ref][] = $comment;

                        continue;
                    }
                    if ($kind === 'settings' || $kind === 'template') {
                        $skipped++;

                        continue;
                    }

                    $post = $atom ? $this->atomPost($entry) : $this->rssPost($entry);
                    if ($post === null) {
                        $skipped++;

                        continue;
                    }
                    $uuid = $this->writer->write($post, $ownerUuid);
                    if ($uuid === null) {
                        $this->logger->warning('A post could not be saved.');
                        $skipped++;

                        continue;
                    }
                    $posts++;
                    $media += count($post->fileIds);
                    if ($atom) {
                        $id = trim((string) $entry->children(self::ATOM)->id);
                        if ($id !== '') {
                            $imported[$id] = $uuid;
                        }
                    }
                } catch (\Throwable $e) {
                    $lastFailed = true;
                    $this->logger->warning(ImportResult::failure($e, (string) ($atom ? $entry->children(self::ATOM)->title : $entry->title)));
                    $skipped++;
                    if (++$failures >= ImportResult::MAX_CONSECUTIVE_FAILURES) {
                        $this->logger->error('The import was stopped after ' . $failures . ' entries in a row could not be imported.');
                        $stopped = true;

                        break;
                    }
                }
            }

            foreach ($waiting as $ref => $replies) {
                if (isset($imported[$ref])) {
                    try {
                        $comments += $this->writer->addComments($imported[$ref], $replies);
                    } catch (\Throwable $e) {
                        $this->logger->warning(ImportResult::failure($e, 'comments of ' . $ref));
                        $skipped += count($replies);
                    }
                } else {
                    $skipped += count($replies);
                }
            }

            return ImportResult::counts($posts, $comments, $media, $skipped, $stopped);
        });
    }

    /** Blogger's kind of an entry (`post`, `page`, `comment`, `settings`, `template`), or null when it names none. */
    private function kind(\SimpleXMLElement $entry): ?string
    {
        foreach ($entry->children(self::ATOM)->category as $category) {
            if (self::attribute($category, 'scheme') === self::KIND_SCHEME) {
                $term = self::attribute($category, 'term');
                $hash = strrpos($term, '#');

                return strtolower($hash === false ? $term : substr($term, $hash + 1));
            }
        }

        return null;
    }

    private function atomPost(\SimpleXMLElement $entry): ?ImportedPost
    {
        $atom = $entry->children(self::ATOM);
        $blogger = $entry->children(self::BLOGGER);
        if (strtoupper(trim((string) $blogger->status)) === 'SOFT_TRASHED') {
            return null;
        }
        $draft = strtoupper(trim((string) $blogger->status)) === 'DRAFT' || $this->markedDraft($entry);

        $tags = [];
        foreach ($atom->category as $category) {
            if (self::attribute($category, 'scheme') === self::KIND_SCHEME) {
                continue;
            }
            $tag = preg_replace('/\W+/', '', self::attribute($category, 'term'));
            if ($tag !== '') {
                $tags[] = '#' . $tag;
            }
        }

        $date = trim((string) $atom->published) !== '' ? (string) $atom->published : (string) $atom->updated;

        return $this->post((string) $atom->title, $this->atomContent($atom->content), $tags, $date, $draft, false);
    }

    /** Whether the entry carries the Atom Publishing Protocol's draft marker: app:control/app:draft yes. */
    private function markedDraft(\SimpleXMLElement $entry): bool
    {
        foreach ($entry->children(self::APP) as $name => $control) {
            if ($name !== 'control') {
                continue;
            }
            foreach ($control->children(self::APP) as $field => $value) {
                if ($field === 'draft' && strtolower(trim((string) $value)) === 'yes') {
                    return true;
                }
            }
        }

        return false;
    }

    private function rssPost(\SimpleXMLElement $item): ?ImportedPost
    {
        $content = (string) $item->children(self::CONTENT)->encoded;
        if ($content === '') {
            $content = (string) $item->description;
        }
        $tags = [];
        foreach ($item->category as $category) {
            $tag = preg_replace('/\W+/', '', (string) $category);
            if ($tag !== '') {
                $tags[] = '#' . $tag;
            }
        }
        $date = trim((string) $item->pubDate) !== '' ? (string) $item->pubDate : (string) $item->children(self::DUBLIN_CORE)->date;

        $draft = false;
        $private = false;
        $namespace = self::wordPressNamespace($item);
        if ($namespace !== null) {
            $wp = $item->children($namespace);
            $type = trim((string) $wp->post_type);
            if ($type !== '' && $type !== 'post') {
                return null;
            }
            $status = strtolower(trim((string) $wp->status));
            $draft = in_array($status, ['draft', 'pending', 'future', 'auto-draft'], true);
            $private = $status === 'private';
            if ($status === 'trash') {
                return null;
            }
        }

        return $this->post((string) $item->title, $content, $tags, $date, $draft, $private);
    }

    /**
     * @param list<string> $tags
     */
    private function post(string $title, string $body, array $tags, string $date, bool $draft, bool $private): ImportedPost
    {
        if ($tags !== []) {
            $body .= '<p>' . implode(' ', $tags) . '</p>';
        }
        [$body, $fileIds] = ImportedImages::bring($body, $this->media, $this->site->url());
        $time = trim($date) === '' ? false : strtotime($date);

        return new ImportedPost(
            ImportedPost::ARTICLE,
            html_entity_decode($title),
            $body,
            $time === false ? time() : $time,
            $draft,
            $private,
            $fileIds
        );
    }

    /** An Atom content element as HTML: html as it is, xhtml as its markup, text escaped. */
    private function atomContent(\SimpleXMLElement $content): string
    {
        $type = strtolower(self::attribute($content, 'type'));
        if ($type === 'xhtml') {
            $node = dom_import_simplexml($content);
            $html = '';
            if ($node instanceof \DOMElement) {
                foreach ($node->childNodes as $wrapper) {
                    // The content is wrapped in one div, which is not part of it.
                    if ($wrapper instanceof \DOMElement && $wrapper->localName === 'div') {
                        foreach ($wrapper->childNodes as $child) {
                            $html .= (string) $node->ownerDocument?->saveXML($child);
                        }
                    }
                }
            }

            return $html;
        }
        if ($type === 'html') {
            return self::namedEntitiesAsText((string) $content);
        }

        return nl2br(htmlspecialchars((string) $content, ENT_QUOTES));
    }

    /**
     * The HTML with the named character references that are not XML's own (`&mdash;`, `&eacute;`)
     * written as the characters, as a feed reader shows them. `&amp;`, `&lt;`, `&gt;`, `&quot;` and
     * `&apos;` stay as they are: decoding those would turn text into markup.
     */
    private static function namedEntitiesAsText(string $html): string
    {
        if (!str_contains($html, '&')) {
            return $html;
        }

        return (string) preg_replace_callback('/&([A-Za-z][A-Za-z0-9]{1,31});/', static function (array $match): string {
            if (in_array($match[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $match[0];
            }
            $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $decoded === $match[0] ? $match[0] : $decoded;
        }, $html);
    }

    /**
     * @return array{0: string, 1: ImportedComment}|null the id of the post replied to, and the comment
     */
    private function reply(\SimpleXMLElement $entry): ?array
    {
        $ref = trim(self::attribute($entry->children(self::THREAD)->{'in-reply-to'}, 'ref'));
        if ($ref === '') {
            return null;
        }
        $atom = $entry->children(self::ATOM);
        $content = $this->atomContent($atom->content);
        if (trim($content) === '') {
            return null;
        }
        $time = strtotime((string) $atom->published);

        return [$ref, new ImportedComment(
            (string) $atom->author->name,
            ImportedComment::safeUrl((string) $atom->author->uri),
            $content,
            $time === false ? time() : $time
        )];
    }

    /**
     * An element's attribute, or '' when there is none. (An attribute is read with attributes(): the
     * namespace an element was reached through, children($namespace), would otherwise be the attribute's.)
     */
    private static function attribute(\SimpleXMLElement $element, string $name): string
    {
        $attributes = $element->attributes();

        return $attributes === null ? '' : (string) $attributes->{$name};
    }

    private static function wordPressNamespace(\SimpleXMLElement $item): ?string
    {
        foreach ($item->getNamespaces(true) as $namespace) {
            if (str_starts_with($namespace, 'http://wordpress.org/export/') && !str_contains($namespace, '/excerpt')) {
                return $namespace;
            }
        }

        return null;
    }
}
