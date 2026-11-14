<?php

namespace Tests\Characterisation {

    /**
     * Command-line scripts, kernel configuration, the cache directory and the test suite must
     * never be served or executed over HTTP. The web server's deny rules (here: the test
     * router) refuse them, and the scripts themselves refuse to run outside the command line.
     */
    class DeniedPathsTest extends CharacterisationTestCase
    {
        /**
         * Text that only appears if a script ran (a console command list) or its source was served.
         */
        private const LEAKS = ['Available commands', 'Usage:', '<?php', 'declare(strict_types', '#!/usr/bin'];

        public function testPrivatePathsAreNotServed(): void
        {
            $paths = [
                'idno.php',
                'known.php',
                'bin/console',
                'BIN/console',
                'Tests/Kernel/boot_routes.php',
                'tests/Kernel/boot_routes.php',
                'Tests/_bootstrap.php',
                'config/services.php',
                'Config/services.php',
                'var/',
                'idno.php?x+about',
            ];
            foreach ($paths as $path) {
                $r = $this->site()->request('GET', $path);

                $this->assertNotSame(200, $r->status, $path);
                foreach (self::LEAKS as $leak) {
                    $this->assertStringNotContainsString($leak, $r->body, $path);
                }
            }
        }
    }

}
