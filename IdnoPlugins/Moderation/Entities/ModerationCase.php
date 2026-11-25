<?php

namespace IdnoPlugins\Moderation\Entities {

    /**
     * One reported thing (a post, a comment or a member) and what was done about it. A case
     * collects every report made about the thing while it is open; once resolved it is the
     * record, and a later report opens a new case.
     *
     * target_type      'post', 'comment' or 'profile'
     * target_uuid      UUID of the post, of the comment's post, or of the member
     * target_local     For a comment: its local address on the post
     * target_key       sha1 of the three above, for lookups
     * subject_uuid     UUID of the member the case is about ('' for a commenter from another site)
     * subject_name, subject_url   Their name and address when the case was opened
     * snapshot         What was reported, as it was: title, text, url, author_name, author_url, time, hash
     * status           'open' or 'resolved'
     * outcome          '' while open; 'dismissed', 'removed', 'banned', 'restored', 'ban_lifted'
     * reasons          slug => number of reports that gave it
     * urgent           Whether any report gave an urgent reason
     * report_count, first_reported, last_reported
     * resolved_at, resolved_by    When, and the UUID of the administrator
     * notes            List of ['time', 'by', 'text']: private to administrators
     * revision         Goes up with every change; an action must name the revision it saw
     * content_removed, content_deleted, banned_here, hid_all   What the actions did
     * kept_comment     A removed comment: ['subtype', 'local', 'annotation']
     * reviewed_mailed  UUIDs of the reporters who have been told the outcome
     */
    class ModerationCase extends Record
    {

        const OPEN = 'open';
        const RESOLVED = 'resolved';

        public static function keyFor($type, $uuid, $local = '')
        {
            return sha1($type . "\n" . $uuid . "\n" . $local);
        }

        public function isOpen()
        {
            return $this->status === self::OPEN;
        }

        /**
         * @return \IdnoPlugins\Moderation\Entities\Report[]
         */
        public function getReports()
        {
            $reports = Report::find(['case_id' => $this->getID()]);
            usort(
                $reports, function ($a, $b) {
                    return $a->created <=> $b->created;
                }
            );

            return $reports;
        }

        /**
         * The member this case is about, if they are a member of this site and still exist
         *
         * @return \Idno\Entities\User|null
         */
        public function getSubject()
        {
            if (empty($this->subject_uuid)) {
                return null;
            }
            $user = \Idno\Entities\User::getByUUID($this->subject_uuid, false);

            return $user instanceof \Idno\Entities\User ? $user : null;
        }

        /**
         * The reported post (for a comment: the post it is on), whatever its state
         *
         * @return \Idno\Common\Entity|null
         */
        public function getTargetEntity()
        {
            if ($this->target_type === 'profile' || empty($this->target_uuid)) {
                return null;
            }
            $uuid = $this->target_uuid;
            $entity = \IdnoPlugins\Moderation\Moderation::unrestricted(
                function () use ($uuid) {
                    return \Idno\Common\Entity::getByUUID($uuid, false);
                }
            );

            return ($entity instanceof \Idno\Common\Entity && !($entity instanceof \Idno\Entities\User)) ? $entity : null;
        }

        /**
         * The address where the reported thing is (or was)
         */
        public function getTargetURL()
        {
            return (string) ($this->snapshot['url'] ?? '');
        }

        public function getAdminURL()
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/case/' . $this->getID();
        }

        /**
         * Has the reported thing changed since it was first reported? Null when it no longer exists.
         *
         * @return bool|null
         */
        public function editedSince()
        {
            $now = \IdnoPlugins\Moderation\Moderation::currentHash($this);
            if ($now === null) {
                return null;
            }

            return $now !== ($this->snapshot['hash'] ?? '');
        }
    }

}
