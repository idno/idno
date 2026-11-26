<?php

namespace Idno\Pages\Service\System {

    class Log extends \Idno\Common\Page
    {

        function postContent()
        {

            $this->xhrGatekeeper();
            // Only a signed-in member writes to the site's log
            $this->gatekeeper();

            // What the client sent is text: one line, no control characters, a bounded length
            $message = $this->getInput('message');
            $message = is_string($message) ? $message : '';
            $level = $this->getInput('level', 'INFO');
            $level = is_string($level) ? $level : 'INFO';

            $message = 'Client Runtime: ' . substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $message), 0, 500);

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

                    // No mail: the text is the client's, and anyone signed in could send the administrators any number of them
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
