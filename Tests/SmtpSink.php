<?php

namespace Tests {

    /**
     * Starts Tests/Core/fixtures/smtp_sink.php in its own PHP process: a small SMTP server on
     * 127.0.0.1 that keeps the messages it is sent. Tests point the site's mail settings at
     * it (configure()), so the site under test, in this process or behind the web server,
     * "sends" its mail here and nothing leaves the machine.
     */
    class SmtpSink
    {

        private $process;
        private $port;
        private $directory;

        /// The mail settings as they were before configure(), to put back
        private $before = null;

        const SETTINGS = ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password', 'smtp_timeout', 'from_email', 'from_name', 'reply_to_email'];

        /**
         * @param  string[] $options Options of the sink: "auth=user:password", "rejectfrom=address"
         * @return SmtpSink
         */
        public static function start(array $options = []): SmtpSink
        {
            $sink = new SmtpSink();
            $sink->directory = sys_get_temp_dir() . '/idno-smtp-sink-' . bin2hex(random_bytes(8));
            mkdir($sink->directory, 0700);

            $command = array_merge([PHP_BINARY, __DIR__ . '/Core/fixtures/smtp_sink.php', $sink->directory], $options);
            $sink->process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $sink->port = (int) trim((string) fgets($pipes[1]));
            if (!$sink->port) {
                throw new \RuntimeException('The SMTP sink did not start');
            }

            return $sink;
        }

        public function port(): int
        {
            return $this->port;
        }

        /**
         * Point the site's stored mail settings at this sink (and remember what they were).
         *
         * @param array $settings Settings to store on top of the host and port
         */
        public function configure(array $settings = []): void
        {
            $config = \Idno\Core\Idno::site()->config();
            if ($this->before === null) {
                $this->before = [];
                foreach (self::SETTINGS as $name) {
                    $this->before[$name] = $config->$name;
                }
            }
            $settings += [
                'smtp_host' => '127.0.0.1', 'smtp_port' => $this->port, 'smtp_secure' => '', 'smtp_username' => '',
                'smtp_password' => '', 'smtp_timeout' => 5, 'from_email' => 'site@example.com', 'from_name' => '', 'reply_to_email' => '',
            ];
            foreach ($settings as $name => $value) {
                $config->$name = $value;
            }
            $config->save();
        }

        /**
         * The messages received so far, oldest first: each 'from', 'to' (a list), 'data' (the
         * message as sent), 'headers' (unfolded, by lower-case name) and 'text' (the message
         * with quoted-printable and base64 parts decoded, for looking for words in)
         *
         * @return array[]
         */
        public function messages(): array
        {
            $messages = [];
            $files = glob($this->directory . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $message = json_decode((string) file_get_contents($file), true);
                if (!is_array($message)) {
                    continue;
                }
                list($head) = explode("\r\n\r\n", $message['data'], 2) + ['', ''];
                $message['headers'] = [];
                foreach (preg_split('/\r\n(?![ \t])/', $head) as $line) {
                    if (strpos($line, ':') !== false) {
                        list($name, $value) = explode(':', $line, 2);
                        $value = trim(preg_replace('/\r\n[ \t]+/', ' ', $value));
                        $message['headers'][strtolower($name)] = function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $value;
                    }
                }
                $message['text'] = quoted_printable_decode($message['data']);
                $messages[] = $message;
            }

            return $messages;
        }

        /**
         * The newest message to an address, or null
         */
        public function lastTo(string $address): ?array
        {
            foreach (array_reverse($this->messages()) as $message) {
                if (in_array(strtolower($address), array_map('strtolower', $message['to']), true)) {
                    return $message;
                }
            }

            return null;
        }

        /**
         * Forget the messages received so far
         */
        public function reset(): void
        {
            foreach (glob($this->directory . '/*.json') ?: [] as $file) {
                unlink($file);
            }
        }

        /**
         * Stop the sink and put the site's mail settings back
         */
        public function stop(): void
        {
            if ($this->before !== null) {
                $config = \Idno\Core\Idno::site()->config();
                foreach ($this->before as $name => $value) {
                    $config->$name = $value;
                }
                $config->save();
                $this->before = null;
            }
            if ($this->process) {
                proc_terminate($this->process);
                proc_close($this->process);
                $this->process = null;
            }
            if ($this->directory && is_dir($this->directory)) {
                $this->reset();
                rmdir($this->directory);
            }
        }
    }

}
