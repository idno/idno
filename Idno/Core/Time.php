<?php

    /**
     * Time and time manipulation functions.
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Time extends \Idno\Common\Component
    {

        /**
         * Convert an epoch timestamp into an RFC2616 (HTTP) compatible date.
         *
         * @param type $timestamp Optionally, an epoch timestamp. Defaults to the current time.
         */
        public static function timestampToRFC2616($timestamp = false)
        {
            if ($timestamp === false) {
                $timestamp = time();
            }

            return \Idno\Http\Page\HttpDate::format((int)$timestamp);
        }

        /**
         * Get the GMT offset from a timezone.
         *
         * @param  string $timezone E.g as returned by $user->getTimezone()
         * @return int Offset in seconds
         */
        public static function timezoneToGMTOffset($timezone)
        {

            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $now->setTimezone(new \DateTimeZone($timezone));

            return $now->getOffset();

        }

        /**
         * Take the offset produced by timezoneToGMTOffset() and display it as a printable version.
         *
         * @param  int $offset
         * @return string
         */
        public static function printTimezoneOffset($offset)
        {
            $hours = intval($offset / 3600);
            $minutes = abs(intval($offset % 3600 / 60));

            return sprintf('%+03d:%02d', $hours, $minutes);
        }

        /**
         * Work out the difference between two timezones.
         *
         * @param  type $timezone1
         * @param  type $timezone2
         * @return type
         */
        public static function timezoneDiff($timezone1, $timezone2)
        {

            if (empty($timezone1)) {
                return false;
            }
            if (empty($timezone2)) {
                return false;
            }

            $offset1 = self::timezoneToGMTOffset($timezone1);
            $offset2 = self::timezoneToGMTOffset($timezone2);

            return $offset1-$offset2;
        }

        /**
         * Print the difference between two timezones in a human readable way.
         *
         * @param  type $diff
         * @return string
         */
        public static function printTimezoneDiff($diff)
        {
            if ($diff == 0) {
                return '';
            }

            $hours = intval($diff / 3600);
            $minutes = abs(intval($diff % 3600 / 60));

            if ($hours!=0) {
                if ($hours == 1) {
                    $hours = abs($hours) . ' ' . \Idno\Core\Idno::site()->language()->_('hour');
                } else {
                    $hours = abs($hours) . ' ' . \Idno\Core\Idno::site()->language()->_('hours');
                }
            } else {
                $hours = '';
            }

            if ($minutes!=0) {
                if ($minutes == 1) {
                    $minutes = abs($minutes) . ' ' . \Idno\Core\Idno::site()->language()->_('minute');
                } else {
                    $minutes = abs($minutes) . ' ' . \Idno\Core\Idno::site()->language()->_('minutes');
                }
            } else {
                $minutes = '';
            }

            $time = ($diff > 0) ? \Idno\Core\Idno::site()->language()->_('ahead') : \Idno\Core\Idno::site()->language()->_('behind');

            return "$hours $minutes $time";
        }

    }

}

