<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * Which of the records an account owns are ITS content, and go when the account is deleted, and the mode that keeps the removal quiet.
 *
 * The rule is one list of what is NOT a member's content: every record the account owns goes with it except a record of one of these classes (or a
 * subclass of one). They belong to the site, not to whoever made them: the entity constructor makes the signed-in user the owner, so an administrator
 * "owns" the static pages they wrote, the access groups they made, the invitations they sent and the link previews their visit unfurled, and a member's
 * posts may be visible to a group someone else needs. A record whose class no longer exists cannot be one of them, so it is content (a record of a
 * removed plugin's post type is the member's) and is removed as a record. Another account is never content, whoever its `owner` names.
 *
 * A plugin whose entity class is site content calls AccountContent::keep() with the class name when it loads (its `registerEventHooks()`): the class
 * stays as it is when an account that made some is deleted, its owner unchanged.
 *
 * Removal is also a mode of the process (whileRemoving(), removalInProgress()): what the removal of an account deletes is not announced one post at a
 * time (Idno::announcementsSuppressed() asks it, with the import mode).
 */
final class AccountContent
{
    /** @var array<string, true> lower-case class names, without a leading backslash */
    private static array $kept = [];

    private static int $removing = 0;

    /** @var list<class-string|string> */
    private const CORE = [
        \Idno\Entities\User::class,
        \Idno\Entities\RemoteUser::class,
        \Idno\Entities\RemoteObject::class,
        \Idno\Entities\AccessGroup::class,
        \Idno\Entities\Invitation::class,
        \Idno\Entities\UnfurledUrl::class,
        \Idno\Entities\AsynchronousQueuedEvent::class,
        \Idno\Entities\GenericDataItem::class,
        // a bundled plugin's, named here and not registered by the plugin, so that a site that has the plugin switched off keeps its pages
        'IdnoPlugins\\StaticPages\\StaticPage',
    ];

    /**
     * Marks an entity class (and its subclasses) as the site's content: the records of it that an account owns stay when the account is deleted.
     */
    public static function keep(string $class): void
    {
        self::$kept[self::normalise($class)] = true;
    }

    /**
     * Whether the record stays when its owner is deleted.
     *
     * @param array<string, mixed> $record the raw record
     */
    public static function keeps(array $record): bool
    {
        $class = $record['entity_subtype'] ?? '';
        if (!is_string($class) || $class === '') {
            return false;
        }
        $class = self::normalise($class);
        foreach ([...array_map(self::normalise(...), self::CORE), ...array_keys(self::$kept)] as $kept) {
            if ($class === $kept || (class_exists($class) && is_a($class, $kept, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an account is being removed in this process: what its removal deletes is not announced to other servers one post at a time.
     */
    public static function removalInProgress(): bool
    {
        return self::$removing > 0;
    }

    /**
     * Runs $work with the mode on, and puts it back however $work ends.
     *
     * @template T
     * @param callable(): T $work
     *
     * @return T
     */
    public static function whileRemoving(callable $work): mixed
    {
        self::$removing++;
        try {
            return $work();
        } finally {
            self::$removing--;
        }
    }

    private static function normalise(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
