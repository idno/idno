<?php

namespace IdnoPlugins\Moderation {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Moderation\Entities\LogEntry;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Entities\Record;
    use IdnoPlugins\Moderation\Entities\Report;

    /**
     * Reporting and moderation: what the pages of this plugin call.
     *
     * Two rules hold everywhere in here. The member a report is about never learns who
     * reported them, and a reporter never learns which administrator acted: neither identity
     * goes into anything those people can fetch or are sent. And nothing a reporter typed is
     * written to a log.
     */
    class Moderation
    {

        /// Longest context a reporter can add, in characters
        const DESCRIPTION_LENGTH = 1000;

        /// Longest note or ban reason an administrator can write, in characters
        const NOTE_LENGTH = 2000;

        /// How many reports one member can file in RATE_WINDOW seconds
        const RATE_LIMIT = 10;
        const RATE_WINDOW = 3600;

        /// How much of a post's text a case keeps, in characters
        const SNAPSHOT_LENGTH = 5000;

        /**
         * Run a lookup that must find moderation records, or content that is hidden, whoever
         * is signed in. What it returns must never be shown to anyone but an administrator,
         * unless the caller has checked that the viewer may see it.
         */
        public static function unrestricted(callable $lookup)
        {
            $db = Idno::site()->db();
            $before = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                return $lookup();
            } finally {
                $db->setIgnoreAccess($before);
            }
        }

        // -----------------------------------------------------------------
        // Settings
        // -----------------------------------------------------------------

        /**
         * The address a member can write to about a decision
         */
        public static function contactEmail()
        {
            $config = Idno::site()->config();
            $address = trim((string) $config->moderation_contact_email);
            if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $address = trim((string) $config->from_email);
            }

            return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
        }

        public static function notifiesAdmins()
        {
            $setting = Idno::site()->config()->moderation_notify_admins;

            return $setting === null || $setting === '' || !empty($setting) && $setting !== 'false';
        }

        // -----------------------------------------------------------------
        // Text
        // -----------------------------------------------------------------

        /**
         * Make plain text of what a person typed: no tags, no control characters, no more
         * than $length characters. It is still escaped wherever it is printed.
         */
        public static function cleanText($text, $length)
        {
            $text = is_string($text) ? $text : '';
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = strip_tags($text);
            $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
            $text = preg_replace("/\n{3,}/", "\n\n", $text);

            return trim(mb_substr(trim($text), 0, $length));
        }

        public static function cleanDescription($text)
        {
            return static::cleanText($text, self::DESCRIPTION_LENGTH);
        }

        protected static function plain($html, $length = self::SNAPSHOT_LENGTH)
        {
            $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return static::cleanText($text, $length);
        }

        // -----------------------------------------------------------------
        // What can be reported
        // -----------------------------------------------------------------

        /**
         * Find the thing a report form names, as the person now signed in may see it.
         *
         * @param  string $type    'post', 'comment' or 'profile'
         * @param  string $id      The id (not the UUID) of the post, of the comment's post, or of the member
         * @param  string $comment For a comment: the last part of its local address
         * @return array|null 'type', 'uuid', 'local', 'key', 'entity', 'subject' (User|null),
         *                    'subject_uuid', 'subject_name', 'subject_url', 'snapshot'; null if
         *                    there is no such thing or the viewer may not see it
         */
        public static function resolveTarget($type, $id, $comment = '')
        {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]+$/', $id)) {
                return null;
            }
            $entity = Entity::getByID($id);
            if (!($entity instanceof Entity) || $entity instanceof Record) {
                return null;
            }

            if ($type === 'profile') {
                if (!($entity instanceof User) || $entity instanceof \Idno\Entities\RemoteUser) {
                    return null;
                }
                if ($entity->isHiddenByBan() && !Idno::site()->session()->isAdmin()) {
                    return null;
                }

                return static::target('profile', $entity->getUUID(), '', $entity, $entity, static::snapshotOfMember($entity));
            }

            if ($entity instanceof User || !$entity->canRead() || $entity->isModerated()) {
                return null;
            }
            $kind = $entity->getActivityStreamsObjectType();
            if (empty($kind) || $kind === 'entity') {
                return null; // Not a post
            }

            if ($type === 'post') {
                $owner = $entity->getOwner();

                return static::target('post', $entity->getUUID(), '', $entity, $owner instanceof User ? $owner : null, static::snapshotOfPost($entity));
            }

            if ($type === 'comment') {
                if (!is_string($comment) || !preg_match('/^[A-Za-z0-9-]+$/', $comment)) {
                    return null;
                }
                $local = $entity->getURL() . '/annotations/' . $comment;
                $annotation = $entity->getAnnotation($local);
                if (empty($annotation) || !is_array($annotation)) {
                    return null;
                }
                $author = null;
                if (!empty($annotation['owner_url'])) {
                    $found = User::getByProfileURL($annotation['owner_url']);
                    if ($found instanceof User && !($found instanceof \Idno\Entities\RemoteUser)) {
                        $author = $found;
                    }
                }
                $target = static::target('comment', $entity->getUUID(), $local, $entity, $author, static::snapshotOfComment($entity, $local, $annotation));
                if (!$author) {
                    $target['subject_name'] = static::cleanText($annotation['owner_name'] ?? '', 200);
                    $target['subject_url'] = \Idno\Core\UrlValidator::httpUrl((string) ($annotation['owner_url'] ?? ''));
                }

                return $target;
            }

            return null;
        }

        protected static function target($type, $uuid, $local, Entity $entity, ?User $subject, array $snapshot)
        {
            return [
                'type' => $type,
                'uuid' => $uuid,
                'local' => $local,
                'key' => ModerationCase::keyFor($type, $uuid, $local),
                'entity' => $entity,
                'subject' => $subject,
                'subject_uuid' => $subject ? $subject->getUUID() : '',
                'subject_name' => $subject ? static::cleanText($subject->getTitle(), 200) : '',
                'subject_url' => $subject ? $subject->getDisplayURL() : '',
                'snapshot' => $snapshot,
            ];
        }

        protected static function snapshotOfPost(Entity $entity)
        {
            $owner = $entity->getOwner();
            $title = static::cleanText((string) $entity->getTitle(), 300);
            $text = static::plain((string) ($entity->body ?? '') !== '' ? $entity->body : $entity->getDescription());

            return [
                'title' => $title,
                'text' => $text,
                'url' => $entity->getDisplayURL(),
                'author_name' => $owner ? static::cleanText($owner->getTitle(), 200) : '',
                'author_url' => $owner ? $owner->getDisplayURL() : '',
                'time' => (int) $entity->created,
                'hash' => sha1($title . "\n" . $text),
            ];
        }

        protected static function snapshotOfComment(Entity $entity, $local, array $annotation)
        {
            $text = static::plain((string) ($annotation['content'] ?? ''));

            return [
                'title' => '',
                'text' => $text,
                'url' => $entity->getDisplayURL() . '#' . Entity::annotationAnchor($local),
                'author_name' => static::cleanText($annotation['owner_name'] ?? '', 200),
                'author_url' => \Idno\Core\UrlValidator::httpUrl((string) ($annotation['owner_url'] ?? '')),
                'time' => (int) ($annotation['time'] ?? 0),
                'hash' => sha1("\n" . $text),
            ];
        }

        protected static function snapshotOfMember(User $user)
        {
            $title = static::cleanText($user->getTitle(), 200);
            $text = static::plain((string) $user->getDescription());

            return [
                'title' => '@' . $user->getHandle(),
                'text' => $text,
                'url' => $user->getDisplayURL(),
                'author_name' => $title,
                'author_url' => $user->getDisplayURL(),
                'time' => (int) $user->created,
                'hash' => sha1($title . "\n" . $text),
            ];
        }

        /**
         * The hash of the reported thing as it is now; null if it no longer exists
         */
        public static function currentHash(ModerationCase $case)
        {
            if ($case->target_type === 'profile') {
                $user = $case->getSubject();

                return $user ? static::snapshotOfMember($user)['hash'] : null;
            }
            $entity = $case->getTargetEntity();
            if (!$entity) {
                return null;
            }
            if ($case->target_type === 'post') {
                return static::snapshotOfPost($entity)['hash'];
            }
            $annotation = $entity->getAnnotation($case->target_local);

            return is_array($annotation) ? static::snapshotOfComment($entity, $case->target_local, $annotation)['hash'] : null;
        }

        // -----------------------------------------------------------------
        // Reporting
        // -----------------------------------------------------------------

        /**
         * File a report as a member.
         *
         * @return array 'ok' (bool), 'message' (for the reporter), 'code' (an HTTP status),
         *               'case' (ModerationCase|null), 'new' (whether this is the member's first report on the case)
         */
        public static function fileReport(User $reporter, $type, $id, $comment, $reason, $description)
        {
            $language = Idno::site()->language();
            $refuse = function ($message, $code = 400) {
                return ['ok' => false, 'message' => $message, 'code' => $code, 'case' => null, 'new' => false];
            };

            if (!Reasons::isValid($reason)) {
                return $refuse($language->_('Please choose a reason for your report.'));
            }
            $target = static::resolveTarget($type, $id, $comment);
            if (!$target) {
                return $refuse($language->_("We couldn't find what you're reporting. It may have been removed already."), 404);
            }
            if ($target['subject_uuid'] !== '' && $target['subject_uuid'] === $reporter->getUUID()) {
                return $refuse($language->_("You can't report yourself or something you posted."));
            }
            $description = static::cleanDescription($description);
            $now = time();

            $case = static::openCaseFor($target['key']);
            $report = null;
            if ($case) {
                $existing = Report::find(['case_id' => $case->getID(), 'reporter_uuid' => $reporter->getUUID()], 1);
                $report = $existing ? $existing[0] : null;
            }

            if (!$report) {
                $recent = 0;
                foreach (Report::find(['reporter_uuid' => $reporter->getUUID()], 200) as $earlier) {
                    if ($earlier->created > $now - self::RATE_WINDOW) {
                        $recent++;
                    }
                }
                if ($recent >= self::RATE_LIMIT) {
                    return $refuse($language->_("You've sent a lot of reports in the last hour. Please try again later."), 429);
                }
            }

            if (!$case) {
                $case = new ModerationCase();
                $case->target_type = $target['type'];
                $case->target_uuid = $target['uuid'];
                $case->target_local = $target['local'];
                $case->target_key = $target['key'];
                $case->subject_uuid = $target['subject_uuid'];
                $case->subject_name = $target['subject_name'];
                $case->subject_url = $target['subject_url'];
                $case->snapshot = $target['snapshot'];
                $case->status = ModerationCase::OPEN;
                $case->outcome = '';
                $case->reasons = [];
                $case->urgent = false;
                $case->report_count = 0;
                $case->first_reported = $now;
                $case->notes = [];
                $case->revision = 0;
                $case->reviewed_mailed = [];
            }

            $new = !$report;
            if ($new) {
                $report = new Report();
                $report->reporter_uuid = $reporter->getUUID();
                $report->reporter_name = static::cleanText($reporter->getTitle(), 200);
                $report->entries = [];
                $case->report_count = (int) $case->report_count + 1;
            }
            $report->reason = $reason;
            $report->description = $description;
            $entries = is_array($report->entries) ? $report->entries : [];
            $entries[] = ['time' => $now, 'reason' => $reason, 'description' => $description];
            $report->entries = $entries;

            $reasons = is_array($case->reasons) ? $case->reasons : [];
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            $case->reasons = $reasons;
            $case->urgent = !empty($case->urgent) || Reasons::isUrgent($reason);
            $case->last_reported = $now;
            static::saveCase($case);

            $report->case_id = $case->getID();
            $report->save();

            if ($new) {
                static::log($reporter, 'report', $case, Reasons::label($reason));
                if (!empty($reporter->email)) {
                    Mailer::deliver('received', $reporter->email, ['case' => $case]);
                }
                if (static::notifiesAdmins()) {
                    foreach (static::adminsFor($case) as $admin) {
                        if (!empty($admin->email)) {
                            Mailer::deliver('new-report', $admin->email, ['case' => $case, 'reason' => $reason, 'description' => $description]);
                        }
                    }
                }
            }

            return ['ok' => true, 'message' => $language->_("Thanks. We've got your report."), 'code' => 200, 'case' => $case, 'new' => $new];
        }

        protected static function saveCase(ModerationCase $case)
        {
            $case->revision = (int) $case->revision + 1;

            return $case->save();
        }

        /**
         * @return ModerationCase|null
         */
        public static function openCaseFor($key)
        {
            foreach (ModerationCase::find(['target_key' => $key, 'status' => ModerationCase::OPEN], 5) as $case) {
                if ($case->isOpen()) {
                    return $case;
                }
            }

            return null;
        }

        // -----------------------------------------------------------------
        // Who handles what
        // -----------------------------------------------------------------

        /**
         * May this administrator see and act on a case? Not when the case is about them,
         * unless nobody else could.
         */
        public static function canHandle(User $admin, ModerationCase $case)
        {
            if (empty($admin->admin) || $admin->isBanned()) {
                return false;
            }
            if (empty($case->subject_uuid) || $case->subject_uuid !== $admin->getUUID()) {
                return true;
            }

            return User::countUnbannedAdmins() <= 1;
        }

        /**
         * The administrators who handle a case
         *
         * @return User[]
         */
        public static function adminsFor(ModerationCase $case)
        {
            $admins = [];
            foreach (User::get(['admin' => true], [], 1000) ?: [] as $user) {
                if (static::canHandle($user, $case)) {
                    $admins[] = $user;
                }
            }

            return $admins;
        }

        /**
         * The cases an administrator handles, sorted for the queue: open ones with urgent
         * reasons first and then the oldest first; resolved ones newest first.
         *
         * @param  string $status ModerationCase::OPEN or ModerationCase::RESOLVED
         * @return ModerationCase[]
         */
        public static function cases($status, User $viewer)
        {
            $cases = array_values(
                array_filter(
                    ModerationCase::find(['status' => $status], 5000), function ($case) use ($viewer, $status) {
                        return $case->status === $status && static::canHandle($viewer, $case);
                    }
                )
            );
            if ($status === ModerationCase::OPEN) {
                usort(
                    $cases, function ($a, $b) {
                        return [empty($a->urgent) ? 1 : 0, (int) $a->first_reported] <=> [empty($b->urgent) ? 1 : 0, (int) $b->first_reported];
                    }
                );
            } else {
                usort(
                    $cases, function ($a, $b) {
                        return (int) $b->resolved_at <=> (int) $a->resolved_at;
                    }
                );
            }

            return $cases;
        }

        public static function countOpen(User $viewer)
        {
            return count(static::cases(ModerationCase::OPEN, $viewer));
        }

        /**
         * Every case about a member, newest first
         *
         * @return ModerationCase[]
         */
        public static function casesAbout($subject_uuid)
        {
            if (empty($subject_uuid)) {
                return [];
            }
            $cases = ModerationCase::find(['subject_uuid' => $subject_uuid], 1000);
            usort(
                $cases, function ($a, $b) {
                    return (int) $b->first_reported <=> (int) $a->first_reported;
                }
            );

            return $cases;
        }

        /**
         * How a member has used reporting: how many reports they have filed, and how many of
         * those were dismissed
         *
         * @return array 'filed', 'dismissed'
         */
        public static function reporterRecord($reporter_uuid)
        {
            $filed = 0;
            $dismissed = 0;
            foreach (Report::find(['reporter_uuid' => $reporter_uuid], 1000) as $report) {
                $filed++;
                $case = ModerationCase::load((string) $report->case_id);
                if ($case && $case->outcome === 'dismissed') {
                    $dismissed++;
                }
            }

            return ['filed' => $filed, 'dismissed' => $dismissed];
        }

        /**
         * Every banned member, most recently banned first
         *
         * @return User[]
         */
        public static function bannedMembers()
        {
            $banned = [];
            foreach (User::get([], [], 100000) ?: [] as $user) {
                if ($user->isBanned()) {
                    $banned[] = $user;
                }
            }
            usort(
                $banned, function ($a, $b) {
                    return (int) $b->banned <=> (int) $a->banned;
                }
            );

            return $banned;
        }

        // -----------------------------------------------------------------
        // The log
        // -----------------------------------------------------------------

        public static function log(?User $actor, $action, ?ModerationCase $case, $detail = '', ?User $subject = null)
        {
            $entry = new LogEntry();
            $entry->actor_uuid = $actor ? $actor->getUUID() : '';
            $entry->actor_name = $actor ? static::cleanText($actor->getTitle(), 200) : '';
            $entry->action = (string) $action;
            $entry->case_id = $case ? $case->getID() : '';
            $entry->subject_uuid = $subject ? $subject->getUUID() : ($case ? (string) $case->subject_uuid : '');
            $entry->subject_name = $subject ? static::cleanText($subject->getTitle(), 200) : ($case ? (string) $case->subject_name : '');
            $entry->detail = static::cleanText($detail, 500);
            $entry->save();

            return $entry;
        }

        /**
         * @return LogEntry[] Newest first
         */
        public static function logEntries(array $search = [], $limit = 200)
        {
            $entries = LogEntry::find($search, $limit);
            usort(
                $entries, function ($a, $b) {
                    return [(int) $b->created, (string) $b->getID()] <=> [(int) $a->created, (string) $a->getID()];
                }
            );

            return $entries;
        }

        // -----------------------------------------------------------------
        // Taking content down and putting it back
        // -----------------------------------------------------------------

        /**
         * Take a post down: it is hidden from everyone but its author and administrators.
         */
        public static function takedown(Entity $entity)
        {
            if ($entity->isModerated()) {
                return true;
            }
            $entity->moderate(Entity::MODERATION_REMOVED);
            if (!$entity->save(true)) {
                return false;
            }
            Idno::site()->events()->triggerEvent('moderation/takedown', ['object' => $entity]);

            return true;
        }

        /**
         * Put a post that was taken down back as it was.
         */
        public static function restore(Entity $entity)
        {
            if (!$entity->isModerated()) {
                return true;
            }
            $entity->unmoderate();
            if (!$entity->save(true)) {
                return false;
            }
            if ($entity->getPublishStatus() === 'published') {
                Idno::site()->events()->triggerEvent('moderation/restore', ['object' => $entity]);
            }

            return true;
        }

        /**
         * Is this entity a post (and not a member, a file, a follower record...)?
         */
        public static function isPost($entity)
        {
            if (!($entity instanceof Entity) || $entity instanceof User || $entity instanceof Record) {
                return false;
            }
            $kind = $entity->getActivityStreamsObjectType();

            return !empty($kind) && $kind !== 'entity';
        }

        /**
         * Hide every published post of a member. Posts that are drafts, or that were taken
         * down one by one, are left as they are.
         *
         * @return int How many posts were hidden
         */
        public static function hideAllBy(User $member)
        {
            return static::eachPostBy(
                $member, function (Entity $entity) {
                    $status = $entity->getPublishStatus();
                    if ($entity->isModerated() || (!empty($status) && $status !== 'published')) {
                        return false;
                    }
                    $entity->moderate(Entity::MODERATION_HIDDEN);

                    return (bool) $entity->save(true);
                }
            );
        }

        /**
         * Put back the posts that hideAllBy() hid, and only those.
         *
         * @return int How many posts were put back
         */
        public static function unhideAllBy(User $member)
        {
            return static::eachPostBy(
                $member, function (Entity $entity) {
                    if ($entity->getModerationStatus() !== Entity::MODERATION_HIDDEN) {
                        return false;
                    }
                    $entity->unmoderate();

                    return (bool) $entity->save(true);
                }
            );
        }

        protected static function eachPostBy(User $member, callable $change)
        {
            $uuid = $member->getUUID();
            $changed = 0;
            $offset = 0;
            do {
                $batch = static::unrestricted(
                    function () use ($uuid, $offset) {
                        return Entity::getFromAll(['owner' => $uuid], [], 100, $offset) ?: [];
                    }
                );
                foreach ($batch as $entity) {
                    if (static::isPost($entity) && $change($entity)) {
                        $changed++;
                    }
                }
                $offset += 100;
            } while (count($batch) === 100);

            return $changed;
        }

        // -----------------------------------------------------------------
        // Bans
        // -----------------------------------------------------------------

        /**
         * Ban a member.
         *
         * @param  array $options 'reason' (for administrators), 'hide_all' (hide every post of
         *                        theirs), 'notify' (mail them), 'category' (reason slug named in the mail)
         * @return array 'ok', 'message'
         */
        public static function banMember(User $member, User $admin, array $options = [], ?ModerationCase $case = null)
        {
            $language = Idno::site()->language();
            $member = User::getByUUID($member->getUUID(), false);
            if (!($member instanceof User) || $member instanceof \Idno\Entities\RemoteUser) {
                return ['ok' => false, 'message' => $language->_("That member doesn't exist any more.")];
            }
            if ($member->isBanned()) {
                return ['ok' => true, 'message' => $language->_('That member is already banned.'), 'already' => true];
            }
            $hide = !empty($options['hide_all']);
            if (!$member->ban(static::cleanText($options['reason'] ?? '', self::NOTE_LENGTH), $admin->getUUID(), $hide)) {
                if ($member->getUUID() === $admin->getUUID()) {
                    return ['ok' => false, 'message' => $language->_("You can't ban yourself.")];
                }

                return ['ok' => false, 'message' => $language->_("The last administrator can't be banned.")];
            }
            $member->save();
            if ($hide) {
                static::hideAllBy($member);
            }
            static::log($admin, 'ban', $case, $hide ? 'Posts hidden' : '', $member);
            if (!empty($options['notify']) && !empty($member->email)) {
                Mailer::deliver('banned', $member->email, ['category' => $options['category'] ?? '']);
            }

            return ['ok' => true, 'message' => $language->_('%s has been banned.', [$member->getTitle()])];
        }

        /**
         * Lift a member's ban, and put back the posts the ban hid.
         *
         * @return array 'ok', 'message'
         */
        public static function liftBan(User $member, User $admin, ?ModerationCase $case = null)
        {
            $language = Idno::site()->language();
            $member = User::getByUUID($member->getUUID(), false);
            if (!($member instanceof User)) {
                return ['ok' => false, 'message' => $language->_("That member doesn't exist any more.")];
            }
            if (!$member->isBanned()) {
                return ['ok' => true, 'message' => $language->_("That member isn't banned."), 'already' => true];
            }
            $member->unban();
            $member->save();
            static::unhideAllBy($member);
            static::log($admin, 'unban', $case, '', $member);
            if (!empty($member->email)) {
                Mailer::deliver('ban-lifted', $member->email, []);
            }

            return ['ok' => true, 'message' => $language->_("%s's ban has been lifted.", [$member->getTitle()])];
        }

        // -----------------------------------------------------------------
        // Acting on a case
        // -----------------------------------------------------------------

        /**
         * Act on a case as an administrator.
         *
         * @param  string $action  'dismiss', 'remove', 'ban', 'reopen', 'restore', 'delete', 'unban' or 'note'
         * @param  array  $options 'revision' (the case's revision the administrator saw), and for
         *                         'ban': 'reason', 'remove_content', 'hide_all', 'notify'; for 'note': 'text'
         * @return array 'ok' (bool), 'message', 'code' (an HTTP status)
         */
        public static function act(ModerationCase $case, User $admin, $action, array $options = [])
        {
            $language = Idno::site()->language();
            $refuse = function ($message, $code = 409) {
                return ['ok' => false, 'message' => $message, 'code' => $code];
            };
            $done = function ($message) {
                return ['ok' => true, 'message' => $message, 'code' => 200];
            };

            // Work on the case as it is stored now: another administrator may have acted
            $case = ModerationCase::load((string) $case->getID());
            if (!$case) {
                return $refuse($language->_("That report doesn't exist any more."), 404);
            }
            if (!static::canHandle($admin, $case)) {
                return $refuse($language->_("You can't act on a report about yourself."), 403);
            }

            if ($action === 'note') {
                $text = static::cleanText($options['text'] ?? '', self::NOTE_LENGTH);
                if ($text === '') {
                    return $refuse($language->_('Please write a note first.'), 400);
                }
                $notes = is_array($case->notes) ? $case->notes : [];
                $notes[] = ['time' => time(), 'by' => $admin->getUUID(), 'by_name' => static::cleanText($admin->getTitle(), 200), 'text' => $text];
                $case->notes = $notes;
                // A note changes nothing an action depends on, so the revision stays
                $case->save();
                static::log($admin, 'note', $case);

                return $done($language->_('Your note was added.'));
            }

            if ((int) ($options['revision'] ?? -1) !== (int) $case->revision) {
                return $refuse($language->_('Another moderator has already handled this report, or it has changed. Have another look before you act.'));
            }

            $entity = $case->getTargetEntity();

            switch ($action) {
                case 'dismiss':
                    if (!$case->isOpen()) {
                        return $refuse($language->_('This report is already resolved.'));
                    }
                    static::resolve($case, $admin, 'dismissed');
                    static::log($admin, 'dismiss', $case);
                    static::tellReporters($case, false);

                    return $done($language->_('The report was dismissed.'));

                case 'remove':
                    if (!$case->isOpen()) {
                        return $refuse($language->_('This report is already resolved.'));
                    }
                    if ($case->target_type === 'profile') {
                        return $refuse($language->_("A profile can't be removed. You can ban the member instead."), 400);
                    }
                    $removed = static::removeContent($case, $entity);
                    static::resolve($case, $admin, 'removed');
                    static::log($admin, 'remove', $case, $removed ? '' : 'Already gone');
                    static::tellReporters($case, true);
                    if ($removed) {
                        static::tellSubject($case, 'content-removed');
                    }

                    return $done($removed ? $language->_('The content was removed.') : $language->_('The content was already gone. The report is resolved.'));

                case 'ban':
                    if (!$case->isOpen()) {
                        return $refuse($language->_('This report is already resolved.'));
                    }
                    $subject = $case->getSubject();
                    if (!$subject) {
                        return $refuse($language->_("There is no member of this site to ban here."), 400);
                    }
                    $result = static::banMember(
                        $subject, $admin, [
                        'reason' => $options['reason'] ?? '',
                        'hide_all' => !empty($options['hide_all']),
                        'notify' => !empty($options['notify']),
                        'category' => static::mainReason($case),
                        ], $case
                    );
                    if (!$result['ok']) {
                        return $refuse($result['message'], 400);
                    }
                    $case->banned_here = empty($result['already']);
                    $case->hid_all = !empty($options['hide_all']) && empty($result['already']);
                    if (!empty($options['remove_content']) && $case->target_type !== 'profile') {
                        if (static::removeContent($case, $entity)) {
                            static::log($admin, 'remove', $case);
                            static::tellSubject($case, 'content-removed');
                        }
                    }
                    static::resolve($case, $admin, 'banned');
                    static::tellReporters($case, true);

                    return $done($result['message']);

                case 'reopen':
                    if ($case->isOpen() || $case->outcome !== 'dismissed') {
                        return $refuse($language->_('Only a dismissed report can be reopened.'));
                    }
                    if (static::openCaseFor($case->target_key)) {
                        return $refuse($language->_('There is a newer open report about the same thing. Handle that one instead.'));
                    }
                    $case->status = ModerationCase::OPEN;
                    $case->outcome = '';
                    $case->resolved_at = 0;
                    $case->resolved_by = '';
                    static::saveCase($case);
                    static::log($admin, 'reopen', $case);

                    return $done($language->_('The report is back in the unresolved queue.'));

                case 'restore':
                    if ($case->isOpen() || empty($case->content_removed) || !empty($case->content_deleted)) {
                        return $refuse($language->_('There is nothing to restore here.'));
                    }
                    if (!static::restoreContent($case, $entity)) {
                        return $refuse($language->_("The content couldn't be restored: its post no longer exists."));
                    }
                    $case->content_removed = false;
                    if ($case->outcome === 'removed') {
                        $case->outcome = 'restored';
                    }
                    static::saveCase($case);
                    static::log($admin, 'restore', $case);
                    static::tellSubject($case, 'restored');

                    return $done($language->_('The content was restored.'));

                case 'delete':
                    if ($case->isOpen() || empty($case->content_removed) || !empty($case->content_deleted)) {
                        return $refuse($language->_('There is nothing to delete here.'));
                    }
                    if ($case->target_type === 'post' && $entity) {
                        $entity->delete();
                    }
                    $case->kept_comment = null;
                    $case->content_deleted = true;
                    static::saveCase($case);
                    static::log($admin, 'delete', $case);

                    return $done($language->_('The content was deleted permanently.'));

                case 'unban':
                    $subject = $case->getSubject();
                    if ($case->isOpen() || !$subject || !$subject->isBanned()) {
                        return $refuse($language->_("There is no ban to lift here."));
                    }
                    $result = static::liftBan($subject, $admin, $case);
                    if ($case->outcome === 'banned') {
                        $case->outcome = 'ban_lifted';
                    }
                    static::saveCase($case);

                    return $done($result['message']);
            }

            return $refuse($language->_("That isn't something you can do with a report."), 400);
        }

        protected static function resolve(ModerationCase $case, User $admin, $outcome)
        {
            $case->status = ModerationCase::RESOLVED;
            $case->outcome = $outcome;
            $case->resolved_at = time();
            $case->resolved_by = $admin->getUUID();
            $case->resolved_by_name = static::cleanText($admin->getTitle(), 200);
            static::saveCase($case);
        }

        /**
         * The reason most reporters gave (an urgent one wins)
         */
        public static function mainReason(ModerationCase $case)
        {
            $reasons = is_array($case->reasons) ? $case->reasons : [];
            $best = '';
            $score = -1;
            foreach ($reasons as $slug => $count) {
                $weight = $count + (Reasons::isUrgent($slug) ? 100000 : 0);
                if ($weight > $score) {
                    $score = $weight;
                    $best = $slug;
                }
            }

            return $best;
        }

        /**
         * Take down what a case is about. False if it is no longer there.
         */
        protected static function removeContent(ModerationCase $case, ?Entity $entity)
        {
            if (!$entity) {
                return false;
            }
            if ($case->target_type === 'post') {
                if (!static::takedown($entity)) {
                    return false;
                }
                $case->content_removed = true;

                return true;
            }
            if ($case->target_type === 'comment') {
                $local = (string) $case->target_local;
                $annotation = $entity->getAnnotation($local);
                if (!is_array($annotation)) {
                    return false;
                }
                $subtype = $entity->getAnnotationSubtype($local);
                if (!$entity->removeAnnotation($local) || !$entity->save(true)) {
                    return false;
                }
                $case->kept_comment = ['subtype' => $subtype ?: 'reply', 'local' => $local, 'annotation' => $annotation];
                $case->content_removed = true;

                return true;
            }

            return false;
        }

        protected static function restoreContent(ModerationCase $case, ?Entity $entity)
        {
            if (!$entity) {
                return false;
            }
            if ($case->target_type === 'post') {
                return static::restore($entity);
            }
            $kept = $case->kept_comment;
            if (!is_array($kept) || empty($kept['annotation'])) {
                return false;
            }
            if (!$entity->restoreAnnotation($kept['subtype'], $kept['local'], (array) $kept['annotation'])) {
                return false;
            }
            if (!$entity->save(true)) {
                return false;
            }
            $case->kept_comment = null;

            return true;
        }

        /**
         * Tell each reporter on a case that it has been reviewed: once, and again only if the
         * answer changes after a report was reopened. They are told whether something was
         * done, not what, and not by whom.
         */
        protected static function tellReporters(ModerationCase $case, $actionTaken)
        {
            // reporter UUID => what they were last told: 'action' or 'none'
            $told = is_array($case->reviewed_mailed) ? $case->reviewed_mailed : [];
            $outcome = $actionTaken ? 'action' : 'none';
            foreach ($case->getReports() as $report) {
                $key = sha1((string) $report->reporter_uuid);
                if (($told[$key] ?? '') === $outcome) {
                    continue;
                }
                $told[$key] = $outcome;
                $reporter = $report->getReporter();
                if ($reporter && !empty($reporter->email)) {
                    Mailer::deliver('reviewed', $reporter->email, ['case' => $case, 'action_taken' => (bool) $actionTaken]);
                }
            }
            $case->reviewed_mailed = $told;
            $case->save();
        }

        /**
         * Tell the member a case is about what happened to their content. They are told the
         * reason, never who reported them or which administrator acted.
         */
        protected static function tellSubject(ModerationCase $case, $kind)
        {
            $subject = $case->getSubject();
            if ($subject && !empty($subject->email)) {
                Mailer::deliver($kind, $subject->email, ['case' => $case, 'category' => static::mainReason($case)]);
            }
        }
    }

}
