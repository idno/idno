<?php

namespace Idno\Pages\Service\System {

    class Log extends \Idno\Common\Page
    {

        /// The most characters of a client's message that are logged
        const MAX_LENGTH = 500;

        /**
         * A client's message as one line of bounded length: control characters (a line break
         * would start a log line of its own) become "?".
         *
         * @param  mixed $message
         * @return string
         */
        public static function clean($message)
        {
            $message = is_string($message) ? $message : '';

            return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $message), 0, static::MAX_LENGTH);
        }

        function postContent()
        {

            $this->xhrGatekeeper();
            // Only a logged-in member writes to the site's log
            $this->gatekeeper();

            // What the client sent is text: one line, no control characters, a bounded length
            $message = $this->getInput('message');
            $message = is_string($message) ? $message : '';
            $level = $this->getInput('level', 'INFO');
            $level = is_string($level) ? $level : 'INFO';

            $message = 'Client Runtime: ' . static::clean($message);

            $stats = \Idno\Core\Idno::site()->statistics();

            switch (strtoupper($level)) {

                case 'ALERT' :
                case 'EXCEPTION':
                case 'ERROR':
                    \Idno\Core\Idno::site()->logging()->error($message);

                    // Lets log an explicit stat
                    if (!empty($stats)) {
                        $stats->increment("javascript.error");
                    }

                    // No mail to the administrators: the text is the client's, and a member
                    // could send any number of them
                    break;

                case 'WARN':
                case 'WARNING':
                    \Idno\Core\Idno::site()->logging()->warning($message);

                    // Lets log an explicit stat
                    if (!empty($stats)) {
                        $stats->increment("javascript.warning");
                    }

                    break;

                default:

                    // Lets log an explicit stat
                    if (!empty($stats)) {
                        $stats->increment("javascript.info");
                    }

                    \Idno\Core\Idno::site()->logging()->info($message);
            }

        }

    }

}
