<?php

    /**
     * Action management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Actions extends \Idno\Core\Bonita\Forms
    {

        /**
         *  Gatekeeper function that validates input forms and prevents csrf attacks.
         *  Call this from your form action code.
         *
         * @param  string  $targetURL                 The URL of the form action that brought us here.
         * @param  boolean $haltExecutionOnBadRequest If set to true, the function halts all execution if the form doesn't validate. (True by default.)
         * @return true|false
         */
        public static function validateToken($action = '', $haltExecutionOnBadRequest = true)
        {
            if (Idno::site()->session()->isAPIRequest()) {
                return true;
            }

            return parent::validateToken($action, $haltExecutionOnBadRequest);
        }

        /**
         * Creates an action link that will submit via POST to the page
         * specified at $pageurl with the data specified in $data
         *
         * @param  string $pageurl URL of the page to point to
         * @param  string $label   The text of the link
         * @param  array  $data    Array of name:value pairs that will be submitted to $pageurl
         * @param  array  $options Array of options for future use (optional)
         * @return string
         */
        function createLink($pageurl, $label, $data = array(), $options = array())
        {
            $params = array('url' => $pageurl, 'label' => $label, 'data' => $data, 'class' => '', 'confirm' => false, 'confirm-text' => 'Are you sure?', 'title' => '');
            $params = array_merge($params, $options);

            return site()->template()->__($params)->draw('forms/link');
        }

        /**
         * How a link drawn by createLink() is sent: 'POST' (a hidden form that holds the form
         * token) or 'GET' (a plain link, with no form and no token).
         *
         * A browser sends a form as GET or POST only, and sends one that names any other method
         * as a GET, with every field, the form token included, in the address. So a link asked
         * for with PUT, DELETE or anything else is drawn as a POST, and that is logged once for
         * each method in a process.
         *
         * @param  mixed $method The 'method' option given to createLink()
         * @return string 'POST' or 'GET'
         */
        static function linkMethod($method)
        {
            static $logged = [];

            $method = is_string($method) ? strtoupper(trim($method)) : '';
            if ($method === 'GET') {
                return 'GET';
            }
            if ($method !== '' && $method !== 'POST' && empty($logged[$method])) {
                $logged[$method] = true;
                site()->logging()->warning('An action link was asked for with the method ' . preg_replace('/[^A-Z]/', '?', substr($method, 0, 16)) . ', which a browser cannot send from a link or a form. It was drawn as a POST.');
            }

            return 'POST';
        }

        /**
         * Creates a properly-signed POST form
         *
         * @param  string $pageurl URL of the page to point to
         * @param  string $body    The body for the form
         * @return type
         */
        function createForm($pageurl, $body)
        {
            return site()->template()->__(array('body' => $body))->draw('forms/action');
        }

        /**
         * Signs forms so that you don't need to use createForm if you don't want to.
         *
         * @param string $pageurl The URL of the page we're signing for
         */
        function signForm($pageurl)
        {
            return site()->template()->__(array('action' => $pageurl, 'time' => time()))->draw('forms/token');
        }

    }

}

