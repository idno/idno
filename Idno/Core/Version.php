<?php

namespace Idno\Core {

    class Version extends \Idno\Common\Component
    {

        private static $details = [];

        /**
         * Parse version details from version file.
         */
        protected static function parse()
        {

            if (!empty(static::$details)) {
                return static::$details;
            }

            $versionfile = dirname(dirname(dirname(__FILE__))) . '/version.idno';

            if (!file_exists($versionfile)) {
                throw new \Idno\Exceptions\ConfigurationException("The version file (version.idno) could not be found, Idno doesn't appear to be installed correctly.");
            }

            static::$details = @parse_ini_file($versionfile);

            return static::$details;
        }

        /**
         * Retrieve a field from the version file.
         *
         * @param  string $field
         * @return boolean|string
         */
        public static function get($field)
        {

            $version = static::parse();

            if (isset($version[$field])) {
                return $version[$field];
            }

            return false;
        }

        /**
         * Return the human readable version.
         *
         * @return boolean|string
         */
        public static function version()
        {
            return static::get('version');
        }

        /**
         * Return the machine version.
         *
         * @return type
         */
        public static function build()
        {
            return static::get('build');
        }

        /**
         * Retrieve a unique fingerprint for the site and the build version, without
         * giving away the detailed version number
         */
        public static function fingerprint()
        {
            $hmac = hash_hmac('sha256', static::build(), \Idno\Core\Idno::site()->config()->site_secret, true);
            $hmac = hash_hmac('sha256', static::version(), $hmac);

            return $hmac;
        }

    }

}
