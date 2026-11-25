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

        /**
         * A database file, its write-ahead log and shared memory, the upgrade's lock file and the
         * `data/` directory are never served, whether or not the file exists.
         */
        public function testSqliteFilesAndTheDataDirectoryAreRefused(): void
        {
            foreach (['data/idno.sqlite', 'data/', 'data/readme.txt', 'DATA/idno.sqlite', 'Uploads/x.sqlite-wal', 'x.sqlite-shm', 'anything.upgrade.lock', 'idno.sqlite'] as $path) {
                $r = $this->site()->request('GET', $path);

                $this->assertSame(403, $r->status, $path);
            }
        }

        /**
         * The upload directory under the project (`Uploads/`, where every web installation keeps its files) is never served:
         * a stored file and its metadata, reached by their path, would skip the file page and the post's access rules. The
         * files are written here as the file store lays them out, and asked for in several letter cases (one directory, on a
         * file system that ignores case).
         */
        public function testStoredFilesUnderTheUploadsDirectoryAreRefused(): void
        {
            $root = dirname(__DIR__, 2);
            $id = bin2hex(random_bytes(16));
            $directory = $root . '/Uploads/denied-paths-test/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3];
            $this->assertTrue(is_dir($directory) || mkdir($directory, 0755, true));
            file_put_contents($directory . '/' . $id . '.file', 'the bytes of a private picture');
            file_put_contents($directory . '/' . $id . '.data', '{"filename":"private.jpg","attached_entity":"x"}');
            try {
                $relative = 'denied-paths-test/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id;
                $paths = [
                    'Uploads/' . $relative . '.file',
                    'Uploads/' . $relative . '.data',
                    'uploads/' . $relative . '.file',
                    'UPLOADS/' . $relative . '.data',
                    'Uploads/readme.txt',
                    'uploads/readme.txt',
                    'Uploads/.htaccess',
                    'Uploads/',
                ];
                foreach ($paths as $path) {
                    $r = $this->site()->request('GET', $path);

                    $this->assertSame(403, $r->status, $path);
                    $this->assertStringNotContainsString('private picture', $r->body, $path);
                    $this->assertStringNotContainsString('attached_entity', $r->body, $path);
                    $this->assertStringNotContainsString('uploads directory', $r->body, $path);
                }
            } finally {
                @unlink($directory . '/' . $id . '.file');
                @unlink($directory . '/' . $id . '.data');
                for ($dir = $directory; $dir !== $root . '/Uploads'; $dir = dirname($dir)) {
                    @rmdir($dir);
                }
            }
        }

        /**
         * The site's configuration folder holds the configuration, the setup marker and the code that proves access to the
         * server's files when the installer adopts a database: none of it is ever served, whether or not the file exists.
         */
        public function testTheConfigurationFolderIsRefused(): void
        {
            foreach (['configuration/config.ini', 'configuration/install-code', 'configuration/setup-pending', 'Configuration/install-code', 'configuration/', 'configuration/.htaccess'] as $path) {
                $this->assertSame(403, $this->site()->request('GET', $path)->status, $path);
            }
        }

        /**
         * The installer's check that URL rewriting works asks for `js/canary.js`; the rules answer it with the look's script.
         */
        public function testTheInstallersRewriteCheckIsAnsweredWithTheLooksScript(): void
        {
            $r = $this->site()->request('GET', 'js/canary.js');

            $this->assertSame(200, $r->status);
            $this->assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/js/idno-2026.min.js'), $r->body);
        }

        /**
         * The front-end build's sources (`frontend/`: the package files, the Vite and PostCSS configuration, the
         * scripts and style sheets the built files come from) are never served, whether or not the file exists.
         */
        public function testFrontendIsDenied(): void
        {
            foreach (['frontend/package.json', 'frontend/', 'frontend/src/js/main.js', 'frontend/src/', 'frontend/vite.config.js', 'FRONTEND/package.json', 'frontend/.htaccess'] as $path) {
                $r = $this->site()->request('GET', $path);

                $this->assertSame(403, $r->status, $path);
                foreach (self::LEAKS as $leak) {
                    $this->assertStringNotContainsString($leak, $r->body, $path);
                }
            }
        }

        /**
         * Every web server configuration the project ships (read, not run) and both routers refuse `frontend/`, and the
         * directory denies everything itself.
         */
        public function testEveryShippedConfigurationRefusesTheFrontendDirectory(): void
        {
            $root = dirname(__DIR__, 2);
            $rules = [
                '.htaccess' => '/\|frontend\|/',
                'warmup/webserver-configs/htaccess.dist' => '/\|frontend\|/',
                'warmup/webserver-configs/htaccess-2.4.dist' => '/\|frontend\|/',
                'warmup/webserver-configs/nginx.conf' => '/location ~\* \^\/\(\(var\|config\|bin\|tests\|docs\|data\|frontend\|/',
                'docker/caddy/Caddyfile' => '/@denied path_regexp [^\n]*\|frontend\|/',
                'Tests/rig/router.php' => '/\|frontend\|/',
                'Tests/build/router.php' => '/\|frontend\|/',
            ];
            foreach ($rules as $file => $pattern) {
                $this->assertMatchesRegularExpression($pattern, (string) file_get_contents($root . '/' . $file), $file . ' must match ' . $pattern);
            }
            $this->assertSame("Require all denied\n", (string) file_get_contents($root . '/frontend/.htaccess'), 'the directory denies everything itself');
        }

        /**
         * The web server configurations the project ships (read, not run) refuse the same paths:
         * `data/` among the denied directories and the four file suffixes.
         */
        public function testEveryShippedConfigurationRefusesTheDataDirectoryAndSqliteFiles(): void
        {
            $root = dirname(__DIR__, 2);
            $rules = [
                '.htaccess' => ['/\|data\|/', '/<Files[^>]*"[^"]*sqlite[^"]*"/', '/<Files[^>]*"[^"]*upgrade[^"]*lock[^"]*"/'],
                'warmup/webserver-configs/htaccess.dist' => ['/\|data\|/', '/<Files[^>]*"[^"]*sqlite[^"]*"/', '/<Files[^>]*"[^"]*upgrade[^"]*lock[^"]*"/'],
                'warmup/webserver-configs/htaccess-2.4.dist' => ['/\|data\|/', '/<Files[^>]*"[^"]*sqlite[^"]*"/', '/<Files[^>]*"[^"]*upgrade[^"]*lock[^"]*"/'],
                'warmup/webserver-configs/nginx.conf' => ['/location ~\* \^\/\(\(var\|config\|bin\|tests\|docs\|data\|/', '/location \^~ \/data\//', '/location ~\* [^\n]*sqlite[^\n]*sqlite-wal[^\n]*sqlite-shm/', '/location ~\* [^\n]*lock/'],
                'docker/caddy/Caddyfile' => ['/@denied path_regexp [^\n]*\|data\|/', '/@denied_files path_regexp [^\n]*sqlite[^\n]*sqlite-wal[^\n]*sqlite-shm[^\n]*upgrade[^\n]*lock/'],
            ];
            foreach ($rules as $file => $patterns) {
                $text = (string) file_get_contents($root . '/' . $file);
                foreach ($patterns as $pattern) {
                    $this->assertMatchesRegularExpression($pattern, $text, $file . ' must match ' . $pattern);
                }
            }
            $this->assertSame("Require all denied\n", (string) file_get_contents($root . '/data/.htaccess'), 'the data directory denies everything itself');

            // Both routers, run on a path each would otherwise serve or hand to the front controller.
            foreach (['Tests/rig/router.php', 'Tests/build/router.php'] as $router) {
                $text = (string) file_get_contents($root . '/' . $router);
                $this->assertMatchesRegularExpression('/\|data\|/', $text, $router);
                $this->assertMatchesRegularExpression('/sqlite-wal/', $text, $router);
            }
        }
    }

}
