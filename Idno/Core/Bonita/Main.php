<?php

/**
  Bonita static management class file

  @package Bonita
 */

namespace Idno\Core\Bonita {

    class Main
    {

        /**
         * Configuration variables *
         */
        static $path = '';      // The path of the Bonita base
        static $secret = '';     // Site secret

        /**
         * Private helper vars *
         */
        private static $templates = array(); // Template overrides

        /**
         * Useful functions *
         */

        /**
         * Sets an additional path to check for (eg) templates: a directory with a templates/ directory in it.
         * The directory is searched before the ones added earlier, and core's own always last. It forwards to
         * the container's Idno\Templating\TemplateCascade.
         *
         * @param string $path A full path
         */
        static function additionalPath($path)
        {
            \Idno\Core\Idno::container()->get(\Idno\Templating\TemplateCascade::class)->addBase((string)$path);
        }

        /**
         * Get the directories templates are looked for in, apart from the main Bonita path: the added ones, the last
         * added first, and core's directory last
         *
         * @return array
         */
        static function getAdditionalPaths()
        {
            return \Idno\Core\Idno::container()->get(\Idno\Templating\TemplateCascade::class)->bases();
        }

        /**
         * Gets all saved paths, including the main Bonita path
         *
         * @return array
         */
        static function getPaths()
        {
            $paths = self::getAdditionalPaths();
            $paths[] = self::$path;
            return $paths;
        }

        /**
         * Sets the site secret
         */
        public static function siteSecret($secret)
        {
            self::$secret = $secret;
        }

        /**
         * Retrieves the site secret
         *
         * @return string The site secret
         */
        public static function getSiteSecret()
        {
            return self::$secret;
        }

    }

}
