<?php

namespace Tests {

    /**
     * Starts Tests/Core/fixtures/http_server.php in its own PHP process: a small HTTP (or HTTPS)
     * server on 127.0.0.1, for tests of code that fetches URLs. See that file for the paths it
     * serves.
     *
     * Idno refuses to fetch loopback addresses on behalf of a remote party, so a test that needs
     * a "public" server points the proxy_string setting at this one (see proxy()) and uses
     * addresses in 203.0.113.0/24. That range is reserved for documentation: Idno's URL
     * validator takes it for public, and nothing sent to it could arrive anywhere if the proxy
     * setting were ever ignored.
     */
    class LocalHttpServer
    {

        private $process;
        private $port;
        private $tls;

        /**
         * @param  bool $tls Serve HTTPS with a self-signed certificate
         * @return LocalHttpServer
         */
        public static function start(bool $tls = false): LocalHttpServer
        {
            $server = new LocalHttpServer();
            $server->tls = $tls;

            $command = [PHP_BINARY, __DIR__ . '/Core/fixtures/http_server.php'];
            if ($tls) {
                $command[] = 'tls';
            }
            $server->process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $server->port = (int) trim((string) fgets($pipes[1]));
            if (!$server->port) {
                throw new \RuntimeException('The local test server did not start');
            }

            return $server;
        }

        /**
         * The address of a path on this server
         */
        public function url(string $path = '/'): string
        {
            return ($this->tls ? 'https' : 'http') . '://127.0.0.1:' . $this->port . $path;
        }

        /**
         * This server as a value for Idno's proxy_string setting
         */
        public function proxy(): string
        {
            return '127.0.0.1:' . $this->port;
        }

        /**
         * The requests the server has received since the last reset(), as "METHOD target"
         *
         * @return string[]
         */
        public function requests(): array
        {
            return json_decode(file_get_contents($this->url('/log'), false, $this->context()), true);
        }

        /**
         * Forget the requests received so far
         */
        public function reset(): void
        {
            file_get_contents($this->url('/reset'), false, $this->context());
        }

        /**
         * A stream context that accepts this server's self-signed certificate
         */
        private function context()
        {
            return stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        }

        public function stop(): void
        {
            if ($this->process) {
                proc_terminate($this->process);
                proc_close($this->process);
                $this->process = null;
            }
        }
    }

}
