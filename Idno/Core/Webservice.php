<?php

    /**
     * Utility methods for handling external web services
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Idno\Http\Client\BoundedBody;
    use Idno\Http\Client\HttpClientFactory;
    use Symfony\Component\Mime\Part\DataPart;
    use Symfony\Component\Mime\Part\Multipart\FormDataPart;
    use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
    use Symfony\Contracts\HttpClient\HttpClientInterface;
    use Symfony\Contracts\HttpClient\ResponseInterface;

    /**
     * The legacy static HTTP client. It sends its requests through the site's Symfony HttpClient
     * services (`idno.http_client`; `idno.http_client.remote` for safeSend()), fetched from the
     * container at each call, and keeps the old API and result array. New code injects
     * HttpClientInterface instead of calling it.
     */
    class Webservice extends \Idno\Common\Component
    {

        public static $lastRequest = '';
        public static $lastResponse = '';

        /** The redirects followed for one request; a further one is a failure. */
        const MAX_REDIRECTS = HttpClientFactory::MAX_REDIRECTS;

        /** The default for `webservice_max_download`: 10 MB. */
        const DEFAULT_MAX_DOWNLOAD = 10 * 1024 * 1024;

        /** Headers whose value never reaches getLastRequest(). */
        const SECRET_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'signature'];

        private static ?HttpClientInterface $client = null;
        private static ?HttpClientInterface $remoteClient = null;

        /**
         * Replace the HTTP clients (a test's, for instance); null restores the container's.
         *
         * @param HttpClientInterface|null $client The client send() uses
         * @param HttpClientInterface|null $remote The client safeSend() uses
         */
        public static function useClient(?HttpClientInterface $client, ?HttpClientInterface $remote = null): void
        {
            self::$client       = $client;
            self::$remoteClient = $client === null ? null : $remote;
        }

        /**
         * Send a web services POST request to a specified URI endpoint
         *
         * @param  string $endpoint The URI to send the POST request to
         * @param  mixed  $params   Optionally, an array of parameters to send (keys are the parameter names), or the raw body text (depending on Content-Type)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function post($endpoint, $params = null, ?array $headers = null)
        {
            return self::send('post', $endpoint, $params, $headers);
        }

        /**
         * Send a web services request to a specified endpoint
         *
         * @param  string $verb     The verb to send the request with; one of POST, GET, DELETE, PUT
         * @param  string $endpoint The URI to send the request to
         * @param  mixed  $params   Optionally, an array of parameters to send (keys are the parameter names), or the raw body text (depending on Content-Type)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function send($verb, $endpoint, $params = null, ?array $headers = null)
        {
            return self::dispatch(false, $verb, $endpoint, $params, $headers);
        }

        /**
         * Exactly send(), for a URL a remote party supplied (a webmention source, an actor, a feed):
         * it uses the client that refuses every request, and every redirect, whose host resolves to a
         * loopback, private, link-local or reserved address.
         *
         * @param  string $verb     The verb to send the request with; one of POST, GET, DELETE, PUT
         * @param  string $endpoint The URI to send the request to
         * @param  mixed  $params   Optionally, an array of parameters to send, or the raw body text
         * @param  array  $headers  Optionally, an array of headers to send with the request
         * @return array
         */
        public static function safeSend($verb, $endpoint, $params = null, ?array $headers = null): array
        {
            return self::dispatch(true, $verb, $endpoint, $params, $headers);
        }

        /**
         * The result of a request that failed or was refused: nothing arrived to give back.
         *
         * @return array{header: string, content: string, response: int, effective_url: string, error: string}
         */
        private static function failure(int $status, string $url, string $error): array
        {
            return [
                'header'        => '',
                'content'       => '',
                'response'      => $status,
                'effective_url' => $url,
                'error'         => $error,
            ];
        }

        /**
         * Make the request, following redirects one request at a time, and return the result array.
         * It never throws for a transport failure: that is a result with response 0 and the failure's
         * message in `error`.
         */
        private static function dispatch(bool $remote, $verb, $endpoint, $params, ?array $headers): array
        {
            $kind     = strtolower((string)$verb);
            $endpoint = (string)$endpoint;

            // What goes in the query, and what in the body.
            $method  = 'GET';
            $payload = null;
            $hasBody = false;
            switch ($kind) {
                case 'post':
                    $method  = 'POST';
                    $hasBody = true;
                    break;
                case 'put':
                    $method  = 'PUT';
                    $hasBody = true;
                    break;
                case 'delete':
                    $method = 'DELETE';
                    break;
                case 'head':
                    $method = 'HEAD';
                    break;
            }
            if ($hasBody) {
                $payload = $params;
            } else {
                $req = '';
                if ($params && is_array($params)) {
                    $req = http_build_query($params);
                }
                if ($params && !is_array($params)) {
                    $req = (string)$params;
                }
                if (!empty($req)) {
                    $endpoint .= (strpos($endpoint, '?') !== false ? '&' : '?') . $req;
                }
                if ($method === 'DELETE') {
                    $payload = $params; // The parameters are also the body
                    $hasBody = true;
                }
            }

            // Check HSTS - if so, rewrite the endpoint to use HTTPS
            if (static::isHSTS($endpoint)) {
                $endpoint = str_replace('http://', 'https://', $endpoint);
                \Idno\Core\Idno::site()->logging()->debug("HSTS Found, so endpoint call is now going to " . self::loggableUrl($endpoint));
            }

            $sizeLimit = self::DEFAULT_MAX_DOWNLOAD;
            if (!empty(\Idno\Core\Idno::site()->config()->webservice_max_download)) {
                $sizeLimit = (int)\Idno\Core\Idno::site()->config()->webservice_max_download;
            }

            // Allow plugins and other services to extend headers, allowing for plugable authentication methods on calls
            $new_headers = \Idno\Core\Idno::site()->events()->triggerEvent('webservice:headers', array('headers' => $headers, 'verb' => $verb));
            if (!empty($new_headers) && (is_array($new_headers))) {
                if (empty($headers)) {
                    $headers = array();
                }
                $headers = array_merge($headers, $new_headers);
            }
            $noHeaders = empty($headers);

            // The headers as lines; a Content-Type among them is the caller's own.
            $lines       = [];
            $callerType  = null;
            foreach (self::headerPairs($headers) as [$name, $value]) {
                if (strtolower($name) === 'content-type') {
                    $callerType = $value;
                } else {
                    $lines[] = $name . ': ' . $value;
                }
            }

            // The body, and the Content-Type that goes with it. With none of its own, a POST is
            // form-encoded; a PUT or DELETE claims a bare multipart/form-data when the request had no
            // headers at all, and is form-encoded otherwise.
            $body = null;
            $sentType = $callerType;
            $keptType = $callerType;
            if ($hasBody) {
                if ($callerType === null && $method !== 'POST' && $noHeaders) {
                    $callerType = $keptType = 'multipart/form-data';
                }
                $fallbackType = $callerType ?? 'application/x-www-form-urlencoded';
                try {
                    if (is_array($payload) && !empty($payload)) {
                        $form     = new FormDataPart(self::formFields($payload));
                        $body     = $form->bodyToString();
                        $boundary = $form->getPreparedHeaders()->get('Content-Type')->getBodyAsString();
                        // A multipart Content-Type of the caller's gets the body's boundary; any other is theirs.
                        $sentType = ($callerType === null || stripos($callerType, 'multipart/') === 0) ? $boundary : $callerType;
                    } else {
                        if (is_array($payload)) {
                            $payload = null;
                        }
                        $body     = ($payload === null || $payload === false) ? null : (string)$payload;
                        $sentType = $fallbackType;
                    }
                } catch (\Throwable $e) {
                    $message = "The request could not be built: " . $e->getMessage();
                    self::logFailure($endpoint, get_class($e));
                    self::$lastRequest  = '';
                    self::$lastResponse = '';

                    return self::failure(0, $endpoint, $message);
                }
            }

            $url       = $endpoint;
            $redirects = 0;
            $blocks    = [];
            try {
                $client = self::client($remote);
                while (true) {
                    $requestLines = $lines;
                    if ($sentType !== null) {
                        $requestLines[] = 'Content-Type: ' . $sentType;
                    }
                    $options = ['max_redirects' => 0, 'headers' => $requestLines];
                    if ($body !== null) {
                        $options['body'] = $body;
                    }

                    self::$lastRequest = self::describeRequest($method, $url, $requestLines, $body);
                    $response          = $client->request($method, $url, $options);
                    $status            = $response->getStatusCode();
                    $responseHeaders   = $response->getHeaders(false);

                    $declared = $responseHeaders['content-length'][0] ?? null;
                    if ($method !== 'HEAD' && $declared !== null && ctype_digit(trim($declared)) && (int)$declared > $sizeLimit) {
                        $response->cancel();
                        $error = "The response is larger than the download limit of $sizeLimit bytes (webservice_max_download).";
                        self::logFailure($url, $error);
                        self::$lastResponse = '';

                        return self::failure($status, $url, $error);
                    }

                    $blocks[] = self::headerBlock($response, $status);

                    $location = in_array($status, [301, 302, 303, 307, 308], true) ? ($responseHeaders['location'][0] ?? '') : '';
                    if ($location === '') {
                        break;
                    }

                    $response->cancel();
                    if ($redirects >= self::MAX_REDIRECTS) {
                        $error = 'Maximum (' . self::MAX_REDIRECTS . ') redirects followed.';
                        self::logFailure($url, $error);
                        self::$lastResponse = '';

                        return self::failure($status, $url, $error);
                    }
                    ++$redirects;

                    $next = self::resolveRedirect($url, $location);
                    if (!preg_match('#^https?://#i', $next)) {
                        $error = 'The redirect leads to a URL that is not http or https.';
                        self::logFailure($url, $error);
                        self::$lastResponse = '';

                        return self::failure($status, $url, $error);
                    }

                    // What the next request sends: 301, 302 and 303 turn a POST into a GET and send
                    // no body (a PUT or DELETE keeps its method and the caller's own Content-Type);
                    // 307 and 308 send the same again.
                    if ($status !== 307 && $status !== 308) {
                        if ($method === 'POST') {
                            $method = 'GET';
                        }
                        $body     = null;
                        $sentType = $keptType;
                    }
                    if (self::origin($next) !== self::origin($url)) {
                        $lines = array_values(array_filter($lines, static function (string $line): bool {
                            return !in_array(strtolower(trim(explode(':', $line, 2)[0])), ['authorization', 'cookie', 'signature', 'x-idno-service-signature', 'dpop'], true);
                        }));
                    }
                    $url = $next;
                }

                $content = '';
                $cut     = false;
                if ($method !== 'HEAD') {
                    $content = BoundedBody::read($client, $response, $sizeLimit + 1);
                    if (strlen($content) > $sizeLimit) {
                        $content = substr($content, 0, $sizeLimit);
                        $cut     = true;
                    }
                }
            } catch (ExceptionInterface | \InvalidArgumentException $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
                self::logFailure($url, get_class($e));
                self::$lastResponse = '';

                return self::failure(0, $url, $error);
            }

            $header = implode('', $blocks);
            $error  = '';
            if ($cut) {
                $error = "The response was cut off at the download limit of $sizeLimit bytes (webservice_max_download).";
                self::logFailure($url, $error);
            }

            // See if we have a HSTS header and store
            static::checkForHSTSHeader($endpoint, $header);

            self::$lastResponse = $content;

            return [
                'header'        => $header,
                'content'       => $content,
                'response'      => $status,
                'effective_url' => $url,
                'error'         => $error,
            ];
        }

        /**
         * The HTTP client for a request: the replacement given to useClient(), else the container's,
         * fetched now (never kept: the container may rebuild it).
         */
        private static function client(bool $remote): HttpClientInterface
        {
            if ($remote) {
                return self::$remoteClient ?? \Idno\Core\Idno::container()->get('idno.http_client.remote');
            }

            return self::$client ?? \Idno\Core\Idno::container()->get('idno.http_client');
        }

        /**
         * The caller's headers as [name, value] pairs, in order. A string key is a header name
         * ('Content-Type' => 'x'); an integer key is a full line ('Name: value'). A line with no colon,
         * and a header with no value ('Expect:', which curl used to read as "remove this header"), are
         * not sent.
         *
         * @return list<array{string, string}>
         */
        private static function headerPairs($headers): array
        {
            $pairs = [];
            if (empty($headers) || !is_array($headers)) {
                return $pairs;
            }
            foreach ($headers as $key => $value) {
                if (is_int($key)) {
                    if (!is_string($value) || strpos($value, ':') === false) {
                        continue;
                    }
                    [$name, $value] = explode(':', $value, 2);
                    $values         = [$value];
                } else {
                    $name   = (string)$key;
                    $values = is_array($value) ? $value : [$value];
                }
                $name = trim($name);
                foreach ($values as $one) {
                    if (!is_scalar($one) && !($one instanceof \Stringable)) {
                        continue;
                    }
                    $one = trim((string)$one);
                    if ($name !== '' && $one !== '') {
                        $pairs[] = [$name, $one];
                    }
                }
            }

            return $pairs;
        }

        /**
         * The fields of a multipart body: text for a scalar, a part for a WebserviceFile, nested
         * arrays kept (name[key]).
         *
         * @return array<array-key, mixed>
         */
        private static function formFields(array $params): array
        {
            $fields = [];
            foreach ($params as $key => $value) {
                if ($value instanceof \Idno\Core\WebserviceFile) {
                    $fields[$key] = $value->getDataPart();
                } elseif ($value instanceof DataPart) {
                    $fields[$key] = $value;
                } elseif (is_array($value)) {
                    $fields[$key] = self::formFields($value);
                } elseif (is_bool($value)) {
                    $fields[$key] = $value ? '1' : '';
                } elseif ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                    $fields[$key] = (string)$value;
                }
            }

            return $fields;
        }

        /**
         * The status line and headers of a response, as it sent them, ending with a blank line.
         */
        private static function headerBlock(ResponseInterface $response, int $status): string
        {
            $raw   = array_map(static fn($line): string => rtrim((string)$line, "\r\n"), (array)$response->getInfo('response_headers'));
            $start = null;
            foreach ($raw as $index => $line) {
                if (preg_match('#^HTTP/\d#', $line)) {
                    $start = $index;
                }
            }
            if ($start !== null) {
                $lines = array_slice($raw, $start);
            } else {
                $reason = \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? '';
                $lines  = array_merge([rtrim("HTTP/1.1 $status $reason")], $raw);
            }

            return implode("\r\n", $lines) . "\r\n\r\n";
        }

        /**
         * The request line and headers Idno sent, rebuilt for getLastRequest(). The value of a
         * credential header is never in it.
         *
         * @param list<string> $lines
         */
        private static function describeRequest(string $method, string $url, array $lines, ?string $body): string
        {
            $parts  = parse_url($url) ?: [];
            $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $host   = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

            $text = "$method $target HTTP/1.1\r\nHost: $host\r\nUser-Agent: " . HttpClientFactory::USER_AGENT . "\r\n";
            foreach ($lines as $line) {
                [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                if (strtolower($name) === 'user-agent') {
                    $text = preg_replace('/User-Agent: [^\r]*\r\n/', '', $text, 1);
                }
                $text .= $name . ': ' . (in_array(strtolower($name), self::SECRET_HEADERS, true) ? '[redacted]' : $value) . "\r\n";
            }
            if ($body !== null) {
                $text .= 'Content-Length: ' . strlen($body) . "\r\n";
            }

            return $text . "\r\n";
        }

        /**
         * One error line for a failed request: a fixed reason (or the class of the exception, never its
         * message, which can quote the address with its query and credentials) and the URL as scheme,
         * host and path only. Never a header or a body.
         */
        private static function logFailure(string $url, string $reason): void
        {
            \Idno\Core\Idno::site()->logging()->error('Webservice request failed: ' . $reason . ': ' . self::loggableUrl($url));
        }

        /**
         * The URL reduced to scheme, host and path (no port, query, fragment or credentials), control
         * characters replaced, at most 200 bytes; '[unparseable address]' when it has no scheme and host.
         */
        private static function loggableUrl(string $url): string
        {
            $parts = parse_url(trim($url));
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return '[unparseable address]';
            }
            $text = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');

            return substr((string)preg_replace('/[^\x21-\x7E]/', '?', $text), 0, 200);
        }

        /**
         * "scheme://host:port" of a URL, lower case, the port by default for the scheme.
         */
        private static function origin(string $url): string
        {
            $parts  = parse_url($url) ?: [];
            $scheme = strtolower($parts['scheme'] ?? '');

            return $scheme . '://' . strtolower($parts['host'] ?? '') . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        }

        /**
         * The URL a Location header points to, from the URL that answered with it.
         */
        private static function resolveRedirect(string $base, string $location): string
        {
            $location = trim($location);
            if (($hash = strpos($location, '#')) !== false) {
                $location = substr($location, 0, $hash);
            }
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
                return $location;
            }

            $parts     = parse_url($base) ?: [];
            $scheme    = $parts['scheme'] ?? 'http';
            $authority = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (strpos($location, '//') === 0) {
                return $scheme . ':' . $location;
            }

            $basePath = $parts['path'] ?? '/';
            $query    = '';
            if (($mark = strpos($location, '?')) !== false) {
                $query    = substr($location, $mark);
                $location = substr($location, 0, $mark);
            } elseif ($location === '') {
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            }

            if ($location === '') {
                $path = $basePath;
            } elseif ($location[0] === '/') {
                $path = $location;
            } else {
                $path = substr($basePath, 0, (int)strrpos($basePath, '/') + 1) . $location;
            }

            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '..') {
                    if (count($segments) > 1) {
                        array_pop($segments);
                    }
                } elseif ($segment !== '.') {
                    $segments[] = $segment;
                }
            }
            $last = basename($path);
            $path = implode('/', $segments);
            if ($last === '.' || $last === '..') {
                $path .= '/';
            }

            return $scheme . '://' . $authority . ($path === '' ? '/' : $path) . $query;
        }

        /**
         * Send a web services HEAD request to a specified URI endpoint
         *
         * @param  string $endpoint The URI to send the HEAD request to
         * @param  array  $params   Optionally, an array of parameters to send (keys are the parameter names)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function head($endpoint, ?array $params = null, ?array $headers = null)
        {
            return self::send('head', $endpoint, $params, $headers);
        }

        /**
         * Send a web services PUT request to a specified URI endpoint
         *
         * @param  string $endpoint The URI to send the PUT request to
         * @param  mixed  $params   Optionally, an array of parameters to send (keys are the parameter names), or the raw body text (depending on Content-Type)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function put($endpoint, $params = null, ?array $headers = null)
        {
            return self::send('put', $endpoint, $params, $headers);
        }

        /**
         * Send a web services DELETE request to a specified URI endpoint
         *
         * @param  string $endpoint The URI to send the DELETE request to
         * @param  array  $params   Optionally, an array of parameters to send (keys are the parameter names)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function delete($endpoint, ?array $params = null, ?array $headers = null)
        {
            return self::send('delete', $endpoint, $params, $headers);
        }

        /**
         * Replacement for file_get_contents for retrieving remote files.
         * Essentially a wrapper for self::get()
         *
         * @param type $url
         */
        static function file_get_contents($url)
        {
            $result = self::file_get_contents_ex($url);

            if (!empty($result) && ($result['error'] == "")) {
                return $result['content'];
            }

            return false;
        }

        /**
         * Exactly file_get_contents(), for a URL a remote party supplied or a member typed: the request
         * goes through the client that refuses private networks on every redirect (see safeSend()).
         *
         * @param  string $url
         * @return string|false The body, or false when the request failed or was refused
         */
        public static function safeFileGetContents($url)
        {
            $result = self::safeSend('get', $url);

            if (!empty($result) && ($result['error'] == "")) {
                return $result['content'];
            }

            return false;
        }

        /**
         * Identical to Webservice::file_get_contents(), except that this function returns the full context - headers and all.
         * Redirects are followed by send(); a request that fails (too many redirects included) is its
         * failed result, never an exception.
         *
         * @param type $url
         */
        static function file_get_contents_ex($url)
        {
            return self::get($url);
        }

        /**
         * Send a web services GET request to a specified URI endpoint
         *
         * @param  string $endpoint The URI to send the GET request to
         * @param  array  $params   Optionally, an array of parameters to send (keys are the parameter names)
         * @param  array  $headers  Optionally, an array of headers to send with the request: 'Name: value' lines, or Name => value
         * @return array
         */
        static function get($endpoint, ?array $params = null, ?array $headers = null)
        {
            return self::send('get', $endpoint, $params, $headers);
        }

        /**
         * Take a URL, check for a schema and add one if necessary
         *
         * @param  $url
         * @return string|bool
         */
        static function sanitizeURL($url)
        {
            if (!empty($url)) {
                if (!substr_count($url, ':') && !substr_count($url, '//')) {
                    $url = 'http://' . $url;
                }

                return $url;
            }

            return false;
        }

        /**
         * Takes a query array and flattens it for use in a POST request (etc)
         *
         * @param  $params
         * @return string
         */
        static function flattenArrayToQuery($params)
        {
            if (is_array($params) && !empty($params)) {
                return http_build_query($params);
            }

            return $params;
        }

        /**
         * Retrieves the last HTTP request sent by the service client: the request line and the headers
         * Idno sent, rebuilt (not a byte-exact copy of the wire). The value of an Authorization,
         * Proxy-Authorization, Cookie or Signature header is replaced by [redacted].
         *
         * @return string
         */
        static function getLastRequest()
        {
            return self::$lastRequest;
        }

        /**
         * Retrieves the last HTTP response sent to the service client
         *
         * @return string
         */
        static function getLastResponse()
        {
            return self::$lastResponse;
        }

        /**
         * Converts an "@" formatted file string into a CurlFile
         *
         * @param  type $fileuploadstring
         * @return CURLFile|false
         */
        static function fileToCurlFile($fileuploadstring)
        {
            if ($fileuploadstring[0] == '@') {
                $bits = explode(';', $fileuploadstring);

                $file = $name = $mime = null;

                foreach ($bits as $bit) {
                    // File
                    if ($bit[0] == '@') {
                        $file = trim($bit, '@ ;');
                    }
                    if (strpos($bit, 'filename')!==false) {
                        $tmp = explode('=', $bit);
                        $name = trim($tmp[1], ' ;');
                    }
                    if (strpos($bit, 'type')!==false) {
                        $tmp = explode('=', $bit);
                        $mime = trim($tmp[1], ' ;');
                    }

                }

                if ($file) {

                    if (file_exists($file)) {
                        if (class_exists('CURLFile')) {
                            return new \CURLFile($file, $mime, $name);
                        } else {
                            throw new \Idno\Exceptions\ConfigurationException(\Idno\Core\Idno::site()->language()->_("Your version of PHP doesn't support CURLFile."));
                        }
                    }

                }
            }

            return false;
        }

        /**
         * Wrapper function to encode a value for use in web services.
         * This way if we change the algorithm, there's no need to change the whole codebase.
         *
         * @param  $string
         * @return string
         */
        static function encodeValue($string)
        {
            return self::base64UrlEncode($string);
        }

        /**
         * Wrapper function to decode a value for use in web services.
         * This way if we change the algorithm, there's no need to change the whole codebase.
         *
         * @param  $string
         * @return string
         */
        static function decodeValue($string)
        {
            return self::base64UrlDecode($string);
        }

        /**
         * Utility method to produce URL safe base64 encoding.
         *
         * @param  type $string
         * @return string
         */
        static function base64UrlEncode($string)
        {
            return strtr(base64_encode($string), '+/=', '-_,');
        }

        /**
         * Utility method to decode URL safe base64 encoding.
         *
         * @param  type $string
         * @return string
         */
        static function base64UrlDecode($string)
        {
            return base64_decode(strtr($string, '-_,', '+/='));
        }

        /**
         * Check whether a given url has valid HSTS stored for it
         *
         * @todo  Handle includeSubDomains
         * @param type $url
         */
        public static function isHSTS($url)
        {

            // Get the domain
            $domain = parse_url($url, PHP_URL_HOST);

            if (empty($domain)) {
                return false;
            }

            $cache = \Idno\Core\Idno::site()->cache();

            if (!empty($cache)) {

                if ($status = $cache->load($domain)) {

                    if ($status = unserialize($status)) {

                        \Idno\Core\Idno::site()->logging()->debug("$domain has previously had HSTS");

                        $return = true;

                        // Check max-age
                        if (time() > ($status['stored_ts'] + $status['max-age'])) {
                            \Idno\Core\Idno::site()->logging()->debug("HSTS header has expired");
                            $return = false;
                        }

                        return $return;
                    }

                }

            }

            return false;
        }

        /**
         * Parse out HSTS headers, and if a url has HSTS headers, that status is cached.
         *
         * @param string       $url     The endpoint url
         * @param string|array $headers
         */
        public static function checkForHSTSHeader($url, $headers)
        {

            \Idno\Core\Idno::site()->logging()->debug("Checking for HSTS headers");

            if (!is_array($headers)) {
                $headers = explode("\n", $headers);
            }

            if (static::isHSTS($url)) {
                \Idno\Core\Idno::site()->logging()->debug("Valid HSTS found, no need to store");
                return; // Valid HSTS already availible, no need to parse headers
            }

            $status = null;
            \Idno\Core\Idno::site()->logging()->debug("Checking " . count($headers) . " response header lines for HSTS");
            // Parse out
            if (!empty($headers)) {
                foreach ($headers as $line) {

                    if (stripos($line, 'Strict-Transport-Security:')!==false) {

                        \Idno\Core\Idno::site()->logging()->debug("HSTS headers found in response");

                        $max_age = 0;
                        if (preg_match('/max-age=([0-9]+)/', $line, $matches)) {
                            $max_age = (int)$matches[1];
                        }

                        $includesubdomains = false;
                        if (stripos($line, 'includeSubDomains')!==false) {
                            $includesubdomains = true;
                        }

                        $status = [
                            'stored_ts' => time(),
                            'max-age' => $max_age,
                            'includeSubDomains' => $includesubdomains
                        ];

                        \Idno\Core\Idno::site()->logging()->debug("HSTS Headers are", $status);
                    }

                }
            }

            // Cache status
            if (!empty($status)) {

                $cache = \Idno\Core\Idno::site()->cache();

                if (!empty($cache)) {
                    \Idno\Core\Idno::site()->logging()->debug("Caching result for " . parse_url($url, PHP_URL_HOST));

                    $cache->store(parse_url($url, PHP_URL_HOST), serialize($status));
                }
            }
        }
    }

}
