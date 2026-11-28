<?php

namespace Idno\Core {

    /**
     * Which of the records an account owns are ITS content, and go when the account is
     * deleted, and the mode that keeps that removal quiet.
     *
     * The rule is one list of what is NOT a member's content: every record the account owns
     * goes with it, except a record of one of these classes or of a subclass of one. Those
     * belong to the site, not to whoever made them: the entity constructor makes the
     * signed-in member the owner, so an administrator "owns" the static pages they wrote, the
     * access groups they made, the invitations they sent and the link previews their visit
     * unfurled. A record whose class no longer exists cannot be one of them, so it is content
     * (a post of a plugin that was taken away is the member's). Another account is never
     * content, whoever its owner field names.
     *
     * A plugin whose entity class is the site's calls AccountContent::keep() with the class
     * name from its registerEventHooks().
     */
    class AccountContent
    {

        /// The classes plugins registered, by lower-case name without a leading backslash
        private static $kept = [];

        /// How deep in whileRemoving() this process is
        private static $removing = 0;

        /// The core's own, and the bundled StaticPages plugin's by name, so that a site that has the plugin switched off keeps its pages
        const CORE = [
            'Idno\\Entities\\User',
            'Idno\\Entities\\RemoteUser',
            'Idno\\Entities\\RemoteObject',
            'Idno\\Entities\\AccessGroup',
            'Idno\\Entities\\Invitation',
            'Idno\\Entities\\UnfurledUrl',
            'Idno\\Entities\\AsynchronousQueuedEvent',
            'Idno\\Entities\\GenericDataItem',
            'Idno\\Entities\\ClosedAccount',
            'Idno\\Entities\\ReservedHandle',
            'IdnoPlugins\\StaticPages\\StaticPage',
        ];

        /**
         * Marks an entity class (and its subclasses) as the site's: the records of it that an
         * account owns stay, with their owner unchanged, when that account is deleted.
         *
         * @param string $class
         */
        static function keep($class)
        {
            self::$kept[self::normalise($class)] = ltrim((string) $class, '\\');
        }

        /**
         * The names of the classes that are kept, as they are stored with a record: the
         * core's, the registered ones, and the subclasses of both that are loaded (a stored
         * class name never matches a parent class).
         *
         * @return string[]
         */
        static function classes()
        {
            $names = array_merge(self::CORE, array_values(self::$kept));
            foreach (get_declared_classes() as $declared) {
                foreach ($names as $kept) {
                    if (is_subclass_of($declared, $kept)) {
                        $names[] = $declared;
                        break;
                    }
                }
            }

            return array_values(array_unique($names));
        }

        /**
         * Does this record stay when the account that owns it is deleted?
         *
         * @param  array|string $record A raw record, or the name of its class
         * @return bool
         */
        static function keeps($record)
        {
            $class = is_array($record) ? ($record['entity_subtype'] ?? '') : $record;
            if (!is_string($class) || $class === '') {
                return false;
            }
            $class = self::normalise($class);
            foreach (array_merge(array_map([self::class, 'normalise'], self::CORE), array_keys(self::$kept)) as $kept) {
                if ($class === $kept || (class_exists($class) && is_a($class, $kept, true))) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Is an account being removed in this process? What its removal deletes is not
         * announced to anybody one post at a time: no syndication is undone, no ActivityPub
         * activity and no AT Protocol write is made for each post. A listener of the delete
         * and deleted events that tells another server about a post asks this first.
         *
         * @return bool
         */
        static function removalInProgress()
        {
            return self::$removing > 0;
        }

        /**
         * Runs $work with the removal mode on, and puts it back however $work ends.
         *
         * @param  callable $work
         * @return mixed What $work returned
         */
        static function whileRemoving(callable $work)
        {
            self::$removing++;
            try {
                return $work();
            } finally {
                self::$removing--;
            }
        }

        private static function normalise($class)
        {
            return strtolower(ltrim((string) $class, '\\'));
        }

    }

}
