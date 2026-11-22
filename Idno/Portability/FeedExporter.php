<?php

declare(strict_types=1);

namespace Idno\Portability;

use Idno\Storage\Criteria;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Psr\Log\LoggerInterface;

/**
 * The site's posts as an RSS or WXR (WordPress eXtended RSS) document, produced in chunks: the document
 * around the items first, then each item as it is read, a few posts at a time, then the rest of the
 * document. Nothing is written to disk and no list of the posts is held.
 *
 * Who is in it is decided here, not left to what the viewer happens to be allowed to read:
 *  - hiding private posts keeps only public, published posts, for an administrator too;
 *  - with private posts included, the viewer's own rules apply (an administrator reads every post of every
 *    member, which is what an administrator's export is for; a member exports their own);
 *  - a user's UUID restricts it to that user's posts.
 */
final class FeedExporter
{
    /** The line the document's template marks the place of the items with. */
    public const MARKER = '<!--##KNOWNFEEDITEMS##-->';

    /** How many posts are read at a time. */
    private const BATCH = 10;

    /** What is kept in memory of a document being turned into a stream before it spills to a file. */
    private const MEMORY_BYTES = 1048576;

    public function __construct(
        private readonly EntityRepository $entities,
        private readonly SiteFacts $site,
        private readonly FeedShell $shell,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @return \Generator<int, string>
     *
     * @throws \RuntimeException when the site's RSS template has no place for the items
     */
    public function rss(bool $hidePrivate, ?string $userUuid, bool $wxr): \Generator
    {
        $title = $this->site->title();
        $description = $this->site->description();
        $baseUrl = $this->site->displayUrl();
        if ($userUuid !== null && $userUuid !== '') {
            $user = $this->entities->findOneByUuid($userUuid);
            if ($user !== null) {
                $title = (string) $user->getTitle();
                $description = (string) $user->getDescription();
                $baseUrl = $userUuid;
            }
        }

        $document = $this->shell->render($title, $description, $baseUrl, $wxr);
        $marker = strpos($document, self::MARKER);
        if ($marker === false) {
            throw new \RuntimeException('The site\'s RSS template has no place for the items.');
        }
        $lineEnd = strpos($document, "\n", $marker);
        $split = $lineEnd === false ? strlen($document) : $lineEnd + 1;
        yield substr($document, 0, $split);
        $tail = substr($document, $split);
        unset($document);

        $this->site->prepareToDraw();
        $criteria = [];
        if ($userUuid !== null && $userUuid !== '') {
            $criteria['owner'] = $userUuid;
        }
        if ($hidePrivate) {
            // Public and published; a post with no status counts as published.
            $criteria['access'] = 'PUBLIC';
            $criteria['publish_status'] = ['$in' => ['published', '']];
        }
        $types = $this->site->contentTypeClasses();
        $conditions = $criteria === [] ? null : Criteria::fromLegacyArray($criteria);
        $total = $this->entities->count(new EntityQuery($types, $conditions, 1, 0));
        $variables = $wxr ? ['wxr_mode' => true] : [];

        for ($offset = 0; $offset < $total; $offset += self::BATCH) {
            foreach ($this->entities->find(new EntityQuery($types, $conditions, self::BATCH, $offset)) as $post) {
                $item = new \DOMDocument();
                $item->formatOutput = true;
                $node = $post->rssSerialise($variables);
                $xml = $item->saveXML($item->importNode($node, true));
                unset($node, $item);
                if ($xml === false) {
                    $this->logger->warning('A post could not be written to the export.');

                    continue;
                }
                yield $xml . "\n";
            }
            gc_collect_cycles();
        }

        yield $tail;
    }

    /**
     * The document as a readable stream, for a page that needs its size before it sends it. It is
     * held in memory up to a megabyte and in a file PHP removes by itself (when the stream is closed, or
     * the request ends) after that: nothing is left in the temporary directory.
     *
     * @return resource|false false, and one line in the log, when the document could not be made
     */
    public function stream(bool $hidePrivate, ?string $userUuid, bool $wxr)
    {
        $stream = fopen('php://temp/maxmemory:' . self::MEMORY_BYTES, 'w+b');
        if ($stream === false) {
            return false;
        }
        try {
            foreach ($this->rss($hidePrivate, $userUuid, $wxr) as $chunk) {
                if (fwrite($stream, $chunk) === false) {
                    throw new \RuntimeException('The document could not be written.');
                }
            }
        } catch (\Throwable $e) {
            fclose($stream);
            $this->logger->error('The feed export failed: ' . $e::class);

            return false;
        }
        rewind($stream);

        return $stream;
    }
}
