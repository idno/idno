<?php

namespace Tests\Characterisation {

    final class SiteResponse
    {
        /**
         * @param int                  $status  HTTP status code
         * @param array<string,string> $headers Header name => value
         * @param string               $body    Response body
         * @param list<string>         $lines   The header lines of the final response as sent, in order, each `Name: value` (the
         *                                      names as the server wrote them): what `headers` joins by name
         */
        public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body, public readonly array $lines = [])
        {
        }

        /**
         * The values of the lines of a header, one for each line sent, in the order sent (case-insensitive name).
         *
         * @return list<string>
         */
        public function lines(string $name): array
        {
            $values = [];
            foreach ($this->lines as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                    $values[] = trim($parts[1]);
                }
            }

            return $values;
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
