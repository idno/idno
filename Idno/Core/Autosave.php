<?php

    /**
     * Site administration
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Autosave extends \Idno\Common\Component
    {

        /**
         * Caches the autosave value for the element $name in the context $context.
         *
         * @param  string $context
         * @param  string $name
         * @param  mixed  $value
         * @return bool
         */
        function setValue($context, $name, $value)
        {

            if (site()->session()->isLoggedOn()) {
                if ($user = site()->session()->currentUser()) {
                    if (!empty($name) && !empty($context)) {
                        $autosave                  = $user->autosave;
                        $autosave[$context][$name] = $value;
                        $user->autosave            = $autosave;
                        if ($user->save()) {
                            \Idno\Core\Idno::site()->session()->refreshSessionUser($user);
                        }
                    }
                }
            }

            return false;

        }

        /**
         * Caches the autosave values for the specified elements in the associative array $elements.
         *
         * @param $context
         * @param $elements
         */
        function setValues($context, $elements)
        {

            if (site()->session()->isLoggedOn()) {
                if ($user = site()->session()->currentUser()) {
                    if (is_array($elements) && !empty($elements) && !empty($context)) {
                        $autosave           = $user->autosave;
                        $autosave[$context] = empty($autosave[$context]) ? $elements : array_merge($autosave[$context], $elements);
                        $user->autosave     = $autosave;
                        if ($user->save()) {
                            \Idno\Core\Idno::site()->session()->refreshSessionUser($user);
                        }
                    }
                }
            }

        }

        /**
         * Retrieves the cached autosave value (if it exists) for $name in the context $context
         *
         * @param  string $context
         * @param  string $name
         * @param  string $default Value to default to if the cache does not exist
         * @return mixed|bool
         */
        function getValue($context, $name, $default = '')
        {

            if (site()->session()->isLoggedOn()) {
                if ($user = site()->session()->currentUser()) {
                    site()->session()->refreshSessionUser($user);
                    if (!empty($user->autosave[$context][$name])) {
                        return $user->autosave[$context][$name];
                    }
                }
            }

            return $default;

        }

        /**
         * Clears the autosave cache for a particular context
         *
         * @param  $context
         * @return bool|false|string|null
         */
        function clearContext($context)
        {

            if (site()->session()->isLoggedOn()) {
                if ($user = site()->session()->currentUser()) {
                    $autosave           = $user->autosave;
                    $autosave[$context] = array();
                    $user->autosave     = $autosave;

                    if ($result = $user->save()) {

                        site()->session()->refreshSessionUser($user);

                        return $result;

                    }
                }
            }

            return false;

        }

    }

}

