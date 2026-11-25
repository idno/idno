<?php

namespace Tests\Characterisation {

    class HttpSiteClient implements SiteClient
    {
        private string $baseUrl;

        public function __construct(?string $baseUrl = null)
        {
            $baseUrl = $baseUrl ?? \Idno\Core\Idno::site()->config()->getURL();
            $this->baseUrl = rtrim($baseUrl, '/') . '/';
        }

        public function baseUrl(): string
        {
            return $this->baseUrl;
        }

        public function request(string $method, string $path, array $headers = [], string|array|null $body = null): SiteResponse
        {
            $headerLines = [];
            $ch              = curl_init($this->baseUrl . ltrim($path, '/'));

            $requestHeaders = [];
            foreach ($headers as $name => $value) {
                $requestHeaders[] = $name . ': ' . $value;
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEFILE     => '',
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $requestHeaders,
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headerLines) {
                    $headerLines[] = $line;

                    return strlen($line);
                },
            ]);

            if (strtoupper($method) === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
            }

            $content = curl_exec($ch);
            if ($content === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException("Request $method $path failed: $error");
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return new SiteResponse($status, self::parseHeaderLines($headerLines), (string)$content, self::finalLines($headerLines));
        }

        /**
         * The header lines of the final response, trimmed, in order (a new status line, as after '100 Continue', discards those before it).
         *
         * @param string[] $lines
         * @return list<string>
         */
        public static function finalLines(array $lines): array
        {
            $final = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, 'HTTP/')) {
                    $final = [];
                    continue;
                }
                $line = trim($line);
                if (str_contains($line, ':')) {
                    $final[] = $line;
                }
            }

            return $final;
        }

        /**
         * Parse raw header lines (as curl delivers them) into a lowercase-keyed map.
         * Repeated headers are joined with ', '. A new status line (e.g. after
         * '100 Continue') discards headers gathered so far.
         *
         * @param string[] $lines
         * @return array<string,string>
         */
        public static function parseHeaderLines(array $lines): array
        {
            $headers = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $name  = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }

            return $headers;
        }
    }

}
