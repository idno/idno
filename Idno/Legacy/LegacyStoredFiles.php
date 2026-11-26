<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Http\Controller\File\FileCaching;
use Idno\Http\Controller\File\FileLookup;
use Idno\Http\Controller\File\FileOutcome;
use Idno\Http\Controller\File\ServedFile;
use Idno\Http\Controller\File\StoredFiles;

/**
 * The stored files over the legacy application: the file page's rules of who may have a file, as the legacy page held them.
 *
 * A file that carries `restricted` metadata (an export archive, an upload waiting for its import) is for administrators only, whoever has its address. It
 * fails closed: the key being there restricts, whatever it holds, and without an administrator's session the answer is a refusal, before anything of the
 * file is read or sent. `site_export` is the mark an earlier 2.0 development build put on an archive: it restricts the same way. An archive made by an
 * earlier release has no mark: the one the settings record is an archive, and restricted all the same (the setting is the request's own, loaded from the
 * database, not an entity).
 *
 * A file attached to an entity is for those who may read it (to anyone else it does not exist: the outcome is the one of a missing file): the entity is loaded whether or not the viewer can see it (a lookup that respected access would
 * not find it, and the check would be skipped), and its `canRead()` decides. A file attached to a post that is not public or not published is restricted:
 * no shared cache keeps it.
 */
final class LegacyStoredFiles implements StoredFiles
{
    public function lookUp(string $id): FileLookup
    {
        // (an id of '0' is no id, as the legacy page took it)
        $object = in_array($id, ['', '0'], true) ? false : \Idno\Entities\File::getByID($id);
        if (empty($object)) {
            return new FileLookup(FileOutcome::Missing);
        }

        $adminOnly = is_array($object->file) && (array_key_exists('restricted', $object->file) || array_key_exists('site_export', $object->file));
        if (!$adminOnly) {
            $recorded = Idno::site()->config()->config['export_file_id'] ?? null;
            $adminOnly = is_string($recorded) && $recorded !== '' && strcasecmp($recorded, (string) ($object->file['_id'] ?? '')) === 0;
        }
        if ($adminOnly && !Idno::site()->session()->isAdmin()) {
            return new FileLookup(FileOutcome::AdministratorsOnly);
        }

        $restricted = $adminOnly;
        if (!empty($object->file['attached_entity'])) {
            // Load the entity whether or not the current user can see it: a lookup that
            // respects access would not find it, and the check below would be skipped
            $db = Idno::site()->db();
            $ignoreAccess = $db->setIgnoreAccess(true);
            try {
                $parentEntity = \Idno\Common\Entity::getOneFromAll(['uuid' => $object->file['attached_entity']]);
            } finally {
                $db->setIgnoreAccess($ignoreAccess);
            }
            if ($parentEntity) {
                if (!$parentEntity->canRead()) {
                    // (the file of a post the viewer may not read is a file that is not there: nothing tells them it exists)
                    return new FileLookup(FileOutcome::Missing);
                }
                $publishStatus = $parentEntity->getPublishStatus();
                $restricted = $adminOnly || ($parentEntity->access !== 'PUBLIC') || (!empty($publishStatus) && $publishStatus != 'published');
            }
        }

        // The time the file last changed: the object's `updated`, else its `created`, else the time its stored bytes were written (a file never changes
        // under its id), else unknown
        if (!empty($object->updated)) {
            $changed = (int) $object->updated;
        } elseif (!empty($object->created)) {
            $changed = (int) $object->created;
        } else {
            $changed = $object instanceof \Idno\Legacy\FlysystemFile ? $object->lastModified : null;
        }
        $mimeType = !empty($object->file['mime_type']) ? $object->file['mime_type'] : 'application/octet-stream';
        $mimeType = (string) preg_replace('/[\r\n]+.*/s', '', (string) $mimeType);

        return new FileLookup(FileOutcome::Served, new ServedFile(
            (int) $object->getSize(),
            $mimeType,
            $changed,
            $adminOnly ? FileCaching::AdministratorsOnly : ($restricted ? FileCaching::Restricted : FileCaching::Public),
            static fn (): mixed => $object->getResource(),
            is_callable([$object, 'passThroughBytes']) ? static function () use ($object): void {
                $object->passThroughBytes();
            } : null
        ));
    }

    public function releaseSession(): void
    {
        // Close the session early: the file may be a long time in the sending
        session_write_close();
    }
}
