<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

/**
 * What the MongoDB backend did to a document's field names, undone. It stored a `.` as `%2E`, a `$`
 * as `%24` and a `%` as `%25` in every key (MongoDB refused both characters in field names); here
 * every key at every depth gets `%2E` → `.`, `%24` → `$`, then `%25` → `%`, each replaced once, in
 * that order, so a literal percent sign is restored once (`a%252Eb` is `a%2Eb`). A key that changes
 * moves after the keys that do not, as the backend's unsanitizeFields() moved it.
 *
 * fileMetadata() is what Idno\Files\MongoDBFileSystem::findOne() made of a GridFS file's document.
 *
 * A static helper, not a container service.
 */
final class MongoDocument
{
    private const ESCAPES = ['%2E' => '.', '%24' => '$', '%25' => '%'];

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException when two keys of one object become the same key (the second
     *                                   would overwrite the first: such a document is not imported)
     */
    public static function unescapeKeys(array $document): array
    {
        // As the backend's unsanitizeFields() left them: the keys that did not change in their places,
        // then each key that did, in the document's order (it set the new key, then unset the old one).
        $kept = [];
        $renamed = [];
        foreach ($document as $key => $value) {
            $value = is_array($value) ? self::unescapeKeys($value) : $value;
            $name = is_string($key) ? self::unescape($key) : $key;
            if (array_key_exists($name, $kept) || array_key_exists($name, $renamed)) {
                throw new \UnexpectedValueException(sprintf('two fields are both named "%s" once their names are unescaped', $name));
            }
            if ($name === $key) {
                $kept[$name] = $value;
            } else {
                $renamed[$name] = $value;
            }
        }

        return $renamed === [] ? $kept : $kept + $renamed;
    }

    /**
     * The metadata MongoDBFileSystem::findOne() gave a GridFS file (`$file->metadata`), from its
     * `fs.files` document with its values already plain (a date as its string): its keys unescaped,
     * then the keys of the `metadata` sub-document but `_id`, then every top-level field but `_id` and
     * `metadata` over them, `filename` reduced to its base name. An old driver's file, with
     * its fields (`mime_type`, `filename`) at the top level and no `metadata`, keeps them.
     *
     * @param array<array-key, mixed> $file
     *
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException when two keys become one (see unescapeKeys())
     */
    public static function fileMetadata(array $file): array
    {
        $file = self::unescapeKeys($file);
        $metadata = [];
        if (isset($file['metadata'])) {
            if (is_array($file['metadata'])) {
                foreach ($file['metadata'] as $key => $value) {
                    if ($key !== '_id') {
                        $metadata[$key] = $value;
                    }
                }
            }
            unset($file['metadata']);
        }
        foreach ($file as $key => $value) {
            if ($key !== '_id') {
                $metadata[$key] = $key === 'filename' && is_string($value) ? basename($value) : $value;
            }
        }

        return $metadata;
    }

    private static function unescape(string $key): string
    {
        if (!str_contains($key, '%')) {
            return $key;
        }
        foreach (self::ESCAPES as $escape => $character) {
            $key = str_replace($escape, $character, $key);
        }

        return $key;
    }
}
