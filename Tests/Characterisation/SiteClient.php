<?php

namespace Tests\Characterisation {

    interface SiteClient
    {
        /**
         * Make a request to the site under test. Never follows redirects, never keeps cookies.
         *
         * @param string              $method  HTTP method
         * @param string              $path    Path relative to the site root, e.g. 'feed.xml' or 'actor/alice'
         * @param array               $headers Header name => value
         * @param string|array|null   $body    Raw body, or an array to send form-encoded
         */
        public function request(string $method, string $path, array $headers = [], string|array|null $body = null): SiteResponse;

        /**
         * The site's base URL. Always ends in '/'.
         */
        public function baseUrl(): string;
    }

}
