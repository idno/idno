<?php

namespace Tests\Core {

    /**
     * A site that serves several domains from one installation keeps the settings of each in
     * configuration/<host>.ini. Idno is started in its own process as such a host.
     */
    class PerDomainConfigTest extends \Tests\IdnoTestCase
    {

        private $file;

        protected function tearDown(): void
        {
            if ($this->file && file_exists($this->file)) {
                unlink($this->file);
            }
            parent::tearDown();
        }

        /**
         * Catches the per-domain file being looked for anywhere but the configuration folder,
         * which leaves a domain with the settings of the main site.
         */
        public function testTheFileForTheHostInTheConfigurationFolderIsRead()
        {
            $host = 'per-domain-' . bin2hex(random_bytes(4)) . '.test';
            $this->file = dirname(__DIR__, 2) . '/configuration/' . $host . '.ini';
            if (@file_put_contents($this->file, "title = 'Set for this domain'\n") === false) {
                $this->markTestSkipped('The configuration folder can not be written to.');
            }

            // 22527 is E_ALL without deprecations, which pedantic mode would turn into errors
            $command = 'IDNO_DOMAIN=' . escapeshellarg($host) . ' IDNO_PORT=80 ' . escapeshellarg(PHP_BINARY)
                . ' -d error_reporting=22527 -d display_errors=0 ' . escapeshellarg(__DIR__ . '/fixtures/per_domain_config.php') . ' 2>/dev/null';
            $answer = json_decode((string) shell_exec($command), true);

            $this->assertSame('Set for this domain', $answer['title'] ?? null);
        }
    }
}
