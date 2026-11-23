<?php

namespace IdnoPlugins\Bridgy\Pages {

    use Idno\Common\Page;

    class Account extends Page
    {
        public static $SERVICES = array('twitter', 'facebook');

        function getContent()
        {
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            $vars = array();
            foreach (self::$SERVICES as $service) {
                if ($user && isset($user->bridgy[$service])) {
                    $bdata = $user->bridgy[$service];
                    $vars[$service.'_enabled'] = isset($bdata['status'])
                        && $bdata['status'] == 'enabled';
                    if (isset($bdata['user'])) {
                        $vars[$service.'_user'] = $bdata['user'];
                    }
                    if (isset($bdata['key'])) {
                        $vars[$service.'_key'] = $bdata['key'];
                    }
                } else {
                    $vars[$service.'_enabled'] = false;
                }
            }

            // The Bridgy keys are secrets: they go to the body's own draw, on a copy of the page's template, so they are never a variable of
            // the page (the JSON output of a page prints every variable of it).
            $keys = [];
            foreach (self::$SERVICES as $service) {
                if (array_key_exists($service . '_key', $vars)) {
                    $keys[$service . '_key'] = $vars[$service . '_key'];
                    unset($vars[$service . '_key']);
                }
            }
            $t = \Idno\Core\Idno::site()->template();
            $t->__($vars);
            $t->body = $t->withVars(array_merge($t->vars, $keys))->draw('bridgy/account');
            $t->title = \Idno\Core\Idno::site()->language()->_('Interactions');
            $t->drawPage();
        }

    }

}

