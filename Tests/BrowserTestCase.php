<?php

namespace Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * A test case for tests that use the site over HTTP as several people at once: each
     * member made with member() can be given a browser (a cookie jar that has logged in), and
     * everything the members own is deleted when the class is done.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    abstract class BrowserTestCase extends IdnoTestCase
    {

        /// @var User[] Members made by this class, by handle
        protected static $members = [];

        /// @var string[] The members' passwords, by handle
        protected static $passwords = [];

        /// @var string[] Cookie jar files, by handle
        protected static $jars = [];

        /// @var \Idno\Common\Entity[] Other entities to delete when the class is done
        protected static $cleanup = [];

        public static function tearDownAfterClass(): void
        {
            $site = Idno::site();
            $old = $site->session()->currentUser();
            $site->db()->setIgnoreAccess(true);
            foreach (static::$cleanup as $entity) {
                try {
                    $entity->delete();
                } catch (\Throwable $e) {
                    // Already gone
                }
            }
            foreach (static::$members as $member) {
                foreach (\Idno\Common\Entity::getFromAll(['owner' => $member->getUUID()], [], 500) ?: [] as $entity) {
                    $entity->delete();
                }
                if ($fresh = User::getByUUID($member->getUUID(), false)) {
                    $fresh->delete();
                }
            }
            $site->db()->setIgnoreAccess(false);
            foreach (static::$jars as $jar) {
                if (file_exists($jar)) {
                    unlink($jar);
                }
            }
            static::$members = [];
            static::$passwords = [];
            static::$jars = [];
            static::$cleanup = [];
            $site->session()->logUserOff();
            if ($old) {
                $site->session()->logUserOn($old);
            }
            parent::tearDownAfterClass();
        }

        /**
         * Make a member with a random handle and a known password.
         *
         * @param  string $prefix Start of the handle and of the name
         * @param  bool   $admin
         * @return User
         */
        protected static function member($prefix, $admin = false)
        {
            $user = new User();
            $user->handle = strtolower($prefix) . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $password = bin2hex(random_bytes(12));
            $user->setPassword($password);
            $user->setTitle(ucfirst($prefix) . ' ' . substr($user->handle, -4));
            if ($admin) {
                $user->setAdmin(true);
            }
            $user->save(true);
            $user->getAPIkey();
            static::$members[$user->handle] = $user;
            static::$passwords[$user->handle] = $password;

            return $user;
        }

        /**
         * The member as stored now
         */
        protected static function fresh(User $user)
        {
            return User::getByUUID($user->getUUID(), false);
        }

        /**
         * Publish a status update as a member (in this process, not over HTTP).
         *
         * @return \IdnoPlugins\Status\Status
         */
        protected function post(User $author, $text = null, $access = 'PUBLIC')
        {
            $old = $this->swapUser($author);
            $entity = new \IdnoPlugins\Status\Status();
            $entity->body = $text ?? ('Post ' . static::marker());
            $entity->setAccess($access);
            $entity->save();
            $this->swapUser($old);
            static::$cleanup[] = $entity;

            return $entity;
        }

        /**
         * Add a comment by a member to a post (in this process). Returns the comment's local address.
         */
        protected function comment(\Idno\Common\Entity $post, User $author, $text = null)
        {
            $text = $text ?? ('Comment ' . static::marker());
            $post = \Idno\Common\Entity::getByUUID($post->getUUID());
            $before = array_keys($post->getAnnotations('reply'));
            $old = $this->swapUser($author);
            $post->addAnnotation('reply', $author->getTitle(), $author->getURL(), $author->getIcon(), $text, null, null, '', [], false);
            $this->swapUser($old);
            $after = array_keys(\Idno\Common\Entity::getByUUID($post->getUUID())->getAnnotations('reply'));
            $new = array_values(array_diff($after, $before));

            return $new[0];
        }

        protected static function marker()
        {
            return 'marker' . md5(rand() . microtime(true));
        }

        /**
         * Make a request to the site.
         *
         * @param  string     $method
         * @param  string     $uri     Path from the root of the site (with any query string), or a full address on this site
         * @param  array|null $body    Form fields
         * @param  User|null  $as      The member whose logged-in browser makes the request; null for a visitor
         * @param  string[]   $headers
         * @return array 'status', 'headers', 'body' and 'location'
         */
        protected function request($method, $uri, $body = null, ?User $as = null, array $headers = [])
        {
            return $this->requestWithJar($as ? $this->jar($as) : null, $method, $uri, $body, $headers);
        }

        /**
         * The cookie jar of a browser that nobody is logged in with (one per test class)
         */
        protected function visitorJar()
        {
            if (empty(static::$jars[' visitor'])) {
                static::$jars[' visitor'] = tempnam(sys_get_temp_dir(), 'idno-browser-test-');
            }

            return static::$jars[' visitor'];
        }

        /**
         * The form token fields the site gives the browser with this cookie jar for an action,
         * so that a request passes the form token check and only what comes after is tested.
         *
         * @param  string $jar
         * @param  string $path Path of the action from the root of the site
         * @return array
         */
        protected function tokenFields($jar, $path)
        {
            $action = rtrim(Idno::site()->config()->getDisplayURL(), '/') . $path;
            $issued = $this->requestWithJar($jar, 'GET', '/service/security/csrftoken/?' . http_build_query(['url' => $action]), null, ['X-Requested-With: XMLHttpRequest']);
            $token = json_decode($issued['body'], true);
            $this->assertNotEmpty($token['token'] ?? null, 'The site should issue a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            return ['__bTs' => $token['time'], '__bTk' => $token['token'], '__bTa' => $action];
        }

        /**
         * Make a request with the cookies of one browser.
         *
         * @param  string|null       $jar     A cookie jar file; null for a request without cookies
         * @param  string            $method
         * @param  string            $uri
         * @param  array|null        $body    Form fields; a \CURLFile among them makes the request multipart
         * @param  string[]          $headers
         * @return array 'status', 'headers', 'body' and 'location'
         */
        protected function requestWithJar($jar, $method, $uri, $body = null, array $headers = [])
        {
            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            if (strpos($uri, 'http') !== 0) {
                $uri = $base . $uri;
            }
            $curl = curl_init($uri);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            if ($jar) {
                curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
                curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            }
            if ($body !== null) {
                $multipart = (bool) array_filter($body, function ($value) {
                    return $value instanceof \CURLFile;
                });
                curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $body : http_build_query($body));
                if ($multipart) {
                    $headers[] = 'Expect:';
                }
            }
            if ($headers) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar
            $head = substr($response, 0, $headerSize);
            $location = preg_match('/^Location:\s*(\S+)/mi', $head, $match) ? $match[1] : '';

            return ['status' => $status, 'headers' => $head, 'body' => substr($response, $headerSize), 'location' => $location];
        }

        protected function get($uri, ?User $as = null, array $headers = [])
        {
            return $this->request('GET', $uri, null, $as, $headers);
        }

        /**
         * Post a form as a member with a valid form token: the token is read from $page (a
         * path that shows a form for $action), or made for $action when $page is null.
         *
         * @param  string      $action Path the form posts to
         * @param  array       $fields
         * @param  User|null   $as
         * @param  string|null $page   Path of the page that holds the form
         * @return array
         */
        protected function submit($action, array $fields, ?User $as = null, $page = null)
        {
            $form = $this->get($page ?? $action, $as);
            $tokens = $this->formFields($form['body'], $action);
            $this->assertCount(3, $tokens, 'The page ' . ($page ?? $action) . ' should hold a form token (status ' . $form['status'] . '). If this is failing, you may need to set IDNO_DOMAIN.');

            return $this->request('POST', $action, $tokens + $fields, $as);
        }

        /**
         * The form token fields of a page: those of the form whose action ends with $action
         * when there is one, the first on the page otherwise.
         */
        protected function formFields($page, $action = null)
        {
            if ($action !== null && preg_match_all('#<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>#s', $page, $forms, PREG_SET_ORDER)) {
                $want = rtrim((string) parse_url($action, PHP_URL_PATH), '/');
                foreach ($forms as $form) {
                    if (rtrim((string) parse_url(html_entity_decode($form[1]), PHP_URL_PATH), '/') === $want && strpos($form[2], '__bTk') !== false) {
                        $page = $form[2];
                        break;
                    }
                }
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $page, $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }

            return $fields;
        }

        /**
         * The headers that sign a request for a path (with any query string) with a member's API key
         */
        protected function signature(User $user, $uri)
        {
            return [
                'X-IDNO-USERNAME: ' . $user->getHandle(),
                'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', $uri, static::fresh($user)->getAPIkey(), true)),
            ];
        }

        /**
         * The cookie jar of a member's browser, logged in on first use
         */
        protected function jar(User $user)
        {
            $handle = $user->getHandle();
            if (empty(static::$jars[$handle])) {
                static::$jars[$handle] = tempnam(sys_get_temp_dir(), 'idno-browser-test-');
                $this->logIn($user);
            }

            return static::$jars[$handle];
        }

        /**
         * Log a member's browser in (again)
         */
        protected function logIn(User $user)
        {
            $form = $this->request('GET', '/session/login', null, $user);
            $fields = $this->formFields($form['body']);
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            return $this->request('POST', '/session/login', $fields + ['email' => $user->getHandle(), 'password' => static::$passwords[$user->getHandle()]], $user);
        }
    }

}
