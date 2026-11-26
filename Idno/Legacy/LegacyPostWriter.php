<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Plugins;
use Idno\Entities\File;
use Idno\Entities\User;
use Idno\Portability\ImportedComment;
use Idno\Portability\ImportedPost;
use Idno\Portability\PostWriter;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;
use Psr\Log\LoggerInterface;

/**
 * Writes imported posts as the articles and notes of the Text and Status plugins, saved through
 * Entity::save() as the importing user's.
 */
final class LegacyPostWriter implements PostWriter
{
    public function __construct(
        private readonly Plugins $plugins,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function available(): bool
    {
        return (bool) $this->plugins->get('Text');
    }

    public function write(ImportedPost $post, string $ownerUuid): ?string
    {
        // A note needs the Status plugin; without it the post is an article with no title.
        $note = $post->kind === ImportedPost::NOTE && $this->plugins->get('Status');
        $entity = $note ? new Status() : new Entry();
        if (!$note) {
            $entity->setTitle($post->title);
        }
        $entity->created = $post->created;
        $entity->body = $post->body;

        $owner = User::getByUUID($ownerUuid, false);
        if (!$owner instanceof User) {
            $this->logger->error('The user an import is for no longer exists.');

            return null;
        }
        $entity->setOwner($owner);
        // Public and published unless the source said otherwise: never more public than its source.
        $entity->setAccess($post->private ? $owner->getUUID() : 'PUBLIC');
        $entity->setPublishStatus($post->draft ? 'draft' : 'published');
        foreach ($post->fileIds as $id) {
            $file = File::getByID($id);
            if ($file) {
                $entity->attachFile($file);
            }
        }

        $id = $entity->save();

        return empty($id) ? null : (string) $entity->getUUID();
    }

    public function addComments(string $postUuid, array $comments): int
    {
        $entity = Entity::getByUUID($postUuid, false);
        if (!$entity instanceof Entity) {
            return 0;
        }
        $added = 0;
        foreach ($comments as $comment) {
            if ($entity->addAnnotation('reply', $comment->author, $comment->url, '', $comment->content, null, $comment->time, null, [], false, true)) {
                $added++;
            }
        }
        if ($added > 0) {
            $entity->save();
        }

        return $added;
    }
}
