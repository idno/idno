<?php

namespace IdnoPlugins\Bridgy\Pages {

    use Idno\Common\Page;

    /**
     * Checks asynchronously whether the account status has been
     * enabled/disabled since the last time we checked.
     *
     * If it has changed, re-renders the account page and sends
     * it back as part of the JSON response.
     */
    class Check extends Page
    {
        function getContent()
        {
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            $t = \Idno\Core\Idno::site()->template();
            $t->setTemplateType('json');

            $t->changed = false;
            foreach (Account::$SERVICES as $service) {
                if (self::checkService($user, $service)) {
                    $t->changed = true;
                }
            }

            $t->drawPage();
        }

        /**
         * Fetch and parse the Bridgy user page for this service,
         * update its status and return true if it has changed.
         */
        function checkService($user, $service)
        {
            if (!isset($user->bridgy) || !isset($user->bridgy[$service])) {
                return false;
            }

            $bridgy_url = $user->bridgy[$service]['user'];
            if (!empty($bridgy_url) and $resp = \Idno\Core\Webservice::safeSend('get', $bridgy_url)) {
                $parsed = (new \Mf2\Parser($resp['content'], $bridgy_url))->parse();
                $status = 'disabled';
                if (!empty($parsed['items'])) {
                    $hcard = $parsed['items'][0];
                    if (!empty($hcard['properties']['bridgy-account-status'])
                            && $hcard['properties']['bridgy-account-status'][0] == 'enabled'
                            && !empty($hcard['properties']['bridgy-listen-status'])
                            && $hcard['properties']['bridgy-listen-status'][0] == 'enabled') {
                        $status = 'enabled';
                    }
                }

                if ($user->bridgy[$service]['status'] != $status) {
                    $user->bridgy[$service]['status'] = $status;
                    $user->save();
                    return true;
                }

                return false;
            }
        }
    }

}

