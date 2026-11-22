<?php

/**
 * Simple timer interface
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Stats {

    class Timer
    {

        private static $timers = [];

        /**
         * Start a timer.
         *
         * @param type $timer
         */
        public static function start($timer)
        {
            self::$timers[$timer] = microtime(true);
        }

        /**
         * Retrieve the current number of seconds (with milliseconds) since $timer was started.
         *
         * @param type $timer
         */
        public static function value($timer)
        {

            $now = microtime(true);

            if (isset(self::$timers[$timer])) {
                return $now - self::$timers[$timer];
            }

            throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_("Timer %s has not been started.", [$timer]));
        }

        /**
         * Shorthand to log a given timer to the debug log.
         *
         * @param type $timer
         */
        public static function logTimer($timer)
        {
            try {
                \Idno\Core\Idno::site()->logging()->debug(get_called_class() . " $timer has been running for " . static::value($timer) . ' seconds.');
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->debug($e->getMessage());
            }
        }

    }
}
