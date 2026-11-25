<?php

namespace IdnoPlugins\Moderation\Entities {

    /**
     * One member's report on a case.
     *
     * case_id        The id of the case
     * reporter_uuid  UUID of the member who reported; reporter_name is their name at the time
     * reason         Slug of the reason given (the latest, if they reported again)
     * description    Their context: plain text, at most Moderation::DESCRIPTION_LENGTH characters
     * entries        Every time they reported this while the case was open: ['time', 'reason', 'description']
     */
    class Report extends Record
    {

        /**
         * @return \Idno\Entities\User|null
         */
        public function getReporter()
        {
            $user = \Idno\Entities\User::getByUUID($this->reporter_uuid, false);

            return $user instanceof \Idno\Entities\User ? $user : null;
        }
    }

}
