<?php

namespace Tests\Characterisation {

    final class SiteResponse
    {
        /**
         * @param int                  $status  HTTP status code
         * @param array<string,string> $headers Header name => value
         * @param string               $body    Response body
         */
        public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
        {
        }

        /**
         * Get a response header, case-insensitively.
         */
        public function header(string $name): ?string
        {
            foreach ($this->headers as $key => $value) {
                if (strcasecmp((string)$key, $name) === 0) {
                    return $value;
                }
            }

            return null;
        }

        /**
         * Decode the body as JSON, failing the test if it is not a JSON object or array.
         */
        public function json(): array
        {
            $decoded = json_decode($this->body, true);
            if (!is_array($decoded)) {
                \PHPUnit\Framework\Assert::fail('Response body is not a JSON object or array: ' . substr($this->body, 0, 200));
            }

            return $decoded;
        }
    }

}
