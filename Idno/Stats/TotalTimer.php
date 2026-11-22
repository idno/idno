<?php

/**
 * Simple totalising timer
 * Useful for loops and calculating how much time is in each section
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Stats {

    class TotalTimer extends Timer
    {

        private static $timerTotals = [];

        public static function stop($timer)
        {

            $value = parent::value($timer);

            if (!isset(static::$timerTotals[$timer])) {
                static::$timerTotals[$timer] = $value;
            } else {
                static::$timerTotals[$timer] += $value;
            }

            return static::$timerTotals[$timer];
        }

        /**
         * Return the TOTAL timer value.
         *
         * @param  type $timer
         * @return type
         * @throws \RuntimeException
         */
        public static function value($timer)
        {

            if (isset(static::$timerTotals[$timer])) {
                return static::$timerTotals[$timer];
            }

            throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_("Timer %s has not been started.", [$timer]));
        }

    }

}
