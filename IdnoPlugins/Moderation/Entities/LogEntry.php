<?php

namespace IdnoPlugins\Moderation\Entities {

    /**
     * One line of the moderation log: who did what, to which case and member, and when
     * ('created'). Administrators only.
     *
     * actor_uuid, actor_name   The administrator (or the reporter, for 'report')
     * action                   'report', 'dismiss', 'remove', 'ban', 'reopen', 'restore', 'delete', 'unban', 'note'
     * case_id, subject_uuid, subject_name, detail
     */
    class LogEntry extends Record
    {
    }

}
