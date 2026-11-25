<?php

namespace Tests {

    /**
     * @TODO This isn't a unit test and I'm not sure we need this file.
     */

    class EnvironmentTest extends IdnoTestCase
    {

        /**
         * Assert a compatible version of PHP
         */
        function testPHPVersion()
        {
            $this->assertTrue(version_compare(phpversion(), '5.4', '>='), 'PHP should be at least version 5.4.');
        }

        /**
         * Assert that required extension modules are present
         */
        function testExtensions()
        {
            echo "Checking extensions\n";
            foreach (
                     //    'curl','date','dom','gd','json','libxml','mbstring','mysql','reflection','session','simplexml', 'openssl'
                     //'curl','date','dom','gd','json','libxml','mbstring','pdo','pdo_mysql','reflection','session','simplexml', 'openssl'
                     \Idno\Install\ServerChecks::EXTENSIONS
                      as $extension) {
                echo "$extension " . (extension_loaded($extension) ? 'true' : 'false') . "\n";
                $this->assertTrue(extension_loaded($extension));
            }

            echo "Checking available DB (mysql, sqlite, pgsql)\n";
            $this->assertTrue(extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite') || extension_loaded('pdo_pgsql'));
        }

        /**
         * Assert that the configuration has been loaded correctly
         */
        function testIdnoConfig()
        {
            $this->assertFalse(\Idno\Core\Idno::site()->config()->isDefaultConfig());
        }
    }

}
