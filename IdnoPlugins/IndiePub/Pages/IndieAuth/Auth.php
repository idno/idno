<?php

namespace IdnoPlugins\IndiePub\Pages\IndieAuth {

    use Idno\Entities\User;
    use Idno\Core;

    class Auth extends \Idno\Common\Page
    {
        function __construct()
        {
            parent::__construct();
            header('IndieAuth: authorization_endpoint');
        }

        // GET requests show the login page
        function getContent()
        {
            $headers      = self::getallheaders();
            $me           = $this->getInput('me');
            $client_id    = $this->getInput('client_id');
            $redirect_uri = $this->getInput('redirect_uri');
            $state        = $this->getInput('state');
            $scope        = $this->getInput('scope');

            if (empty($me)) {
                 $this->setResponse(403);
                 echo "\"me\" parameter must be provided.";
                 exit;
            }
            // not logged in? they'll need to enter their password
            if (!($user = \Idno\Core\site()->session()->currentUser())) {
                // Do login and redirect workflow
                $this->forwardToLogin($this->currentUrl());
                exit;
            }

            if (parse_url($me, PHP_URL_HOST) != parse_url($user->getIndieAuthURL(), PHP_URL_HOST)) {
                   $this->setResponse(403);
                   echo "\"$me\" does not match the logged in user \"{$user->getIndieAuthURL()}\".";
                   exit;
            }

            // Validate that redirect_uri belongs to the same domain as client_id
            // per IndieAuth spec: https://indieauth.spec.indieweb.org/#redirect-url
            if (!empty($redirect_uri) && !empty($client_id)) {
                $redirectHost = parse_url($redirect_uri, PHP_URL_HOST);
                $clientHost = parse_url($client_id, PHP_URL_HOST);
                if ($redirectHost !== $clientHost) {
                    $this->setResponse(400);
                    echo 'redirect_uri must be on the same domain as client_id.';
                    exit;
                }
            }

            $me_prime = $user->getIndieAuthURL();
            $t        = \Idno\Core\site()->template();
            $t->body  = $t->__(array(
                'me'           => $me_prime,
                'client_id'    => $client_id,
                'pretty_id'    => preg_replace('/^https?:\/\//', '', $client_id),
                'scope'        => $scope,
                'redirect_uri' => $redirect_uri,
                'state'        => $state,
            ))->draw('indiepub/auth');
             $t->title = empty($scope) ? 'Authenticate' : 'Authorize';
             return $t->drawPage();
        }

        // note post instead of postContent to skip the CSRF token validation
        function post()
        {
            $code         = $this->getInput('code');
            $client_id    = $this->getInput('client_id');
            $redirect_uri = $this->getInput('redirect_uri');

            $headers      = self::getallheaders();

            $verified = Auth::verifyCode($code, $client_id, $redirect_uri);
            if ($verified['valid']) {
                $this->setResponse(200);
                header('Content-Type: application/json');
                echo json_encode(array(
                    'scope'        => $verified['scope'],
                    'me'           => $verified['me'],
                ));
                exit;
            }

            $this->setResponse(400);
            header('Content-Type: application/json');
            echo json_encode(array(
                'error' => $verified['reason'] ? $verified['reason'] : 'Invalid auth code',
            ));
        }

        /**
         * How long an authorization code can be redeemed for, in seconds. The IndieAuth
         * specification allows ten minutes at most.
         */
        const CODE_LIFETIME = 600;

        /**
         * A new authorization code or access token: 256 bits from the system's random
         * source, as 64 hexadecimal characters.
         *
         * @return string
         */
        static function generateSecret()
        {
            return bin2hex(random_bytes(32));
        }

        /**
         * Find the key of $secrets that equals $candidate, comparing in constant time.
         * Tokens and codes are the keys of the arrays they are stored in.
         *
         * @param  mixed $secrets   the stored array, keyed by code or token
         * @param  mixed $candidate the value sent by the client
         * @return string|int|null the matching key, or null
         */
        static function matchingKey($secrets, $candidate)
        {
            if (!is_array($secrets) || !is_string($candidate) || $candidate === '') {
                return null;
            }
            $match = null;
            foreach ($secrets as $key => $data) {
                // PHP turns an all-digit key into an integer, hence the cast
                if (hash_equals((string) $key, $candidate)) {
                    $match = $key;
                }
            }

            return $match;
        }

        /**
         * Compare two request values as strings, in constant time.
         */
        private static function sameString($known, $given)
        {
            if (!is_scalar($known) && $known !== null) {
                return false;
            }
            if (!is_scalar($given) && $given !== null) {
                return false;
            }

            return hash_equals((string) $known, (string) $given);
        }

        /**
         * Whether a stored code is past its lifetime. A code with no issue time counts as expired.
         */
        private static function codeHasExpired($data)
        {
            if (!is_array($data) || empty($data['issued_at'])) {
                return true;
            }

            return (time() - (int) $data['issued_at']) > self::CODE_LIFETIME;
        }

        /**
         * Issue an authorization code to a user who has approved a client, and drop
         * that user's expired codes.
         *
         * @param  \Idno\Entities\User $user
         * @param  array               $data me, redirect_uri, scope, state, client_id
         * @return string the code
         */
        static function issueCode($user, array $data)
        {
            $indieauth_codes = $user->indieauth_codes;
            if (empty($indieauth_codes) || !is_array($indieauth_codes)) {
                $indieauth_codes = array();
            }
            foreach ($indieauth_codes as $old => $details) {
                if (self::codeHasExpired($details)) {
                    unset($indieauth_codes[$old]);
                }
            }

            $code = self::generateSecret();
            $data['issued_at'] = time();
            $indieauth_codes[$code] = $data;
            $user->indieauth_codes = $indieauth_codes;
            $user->save(true);

            return $code;
        }

        static function findUserForCode($code)
        {
            if (!is_string($code) || $code === '') {
                return array();
            }
            // TODO encode user id in the auth code? or otherwise do a reverse lookup
            // to avoid checking every user
            for ($offset = 0 ; ; $offset += 10) {
                $users = \Idno\Entities\User::get(array(), array(), 10, $offset);
                if (empty($users)) {
                    break;
                }
                foreach ($users as $user) {
                    $indieauth_codes = $user->indieauth_codes;
                    $key = self::matchingKey($indieauth_codes, $code);
                    if ($key !== null) {
                        return array(
                            'user' => $user,
                            'data' => $indieauth_codes[$key],
                        );
                    }
                }
            }
            return array();
        }

        static function removeUsedAndExpiredCodes($user, $code)
        {
            $indieauth_codes = $user->indieauth_codes;
            if (!empty($indieauth_codes) && is_array($indieauth_codes)) {
                $used = self::matchingKey($indieauth_codes, $code);
                if ($used !== null) {
                    unset($indieauth_codes[$used]);
                }
                foreach ($indieauth_codes as $other => $data) {
                    if (self::codeHasExpired($data)) {
                        unset($indieauth_codes[$other]);
                    }
                }
                $user->indieauth_codes = $indieauth_codes;
                $user->save(true);
            }
        }

        // verify the code from login; note that this is called from the micropub client, so the session won't have any user data
        static function verifyCode($code, $client_id, $redirect_uri)
        {
            $found = Auth::findUserForCode($code);
            if (empty($found)) {
                return array(
                    'valid'  => false,
                    'reason' => 'unrecognized code.',
                );
            }

            $user = $found['user'];
            $data = $found['data'];

            // codes can only be used once: this one is spent whether or not the rest of the request is right
            Auth::removeUsedAndExpiredCodes($user, $code);

            if (self::codeHasExpired($data)) {

                \Idno\Core\Idno::site()->logging()->error("Auth code has expired");

                return array(
                    'valid'  => false,
                    'reason' => 'authentication code has expired',
                );
            }
            if (!self::sameString($data['redirect_uri'] ?? '', $redirect_uri)) {

                \Idno\Core\Idno::site()->logging()->error("Redirect url does not match the one the auth code was issued for");

                return array(
                    'valid'  => false,
                    'reason' => 'redirect_uri does not match',
                );
            }
            if (!self::sameString($data['client_id'] ?? '', $client_id)) {

                \Idno\Core\Idno::site()->logging()->error("Client ID does not match the one the auth code was issued for");

                return array(
                    'valid'  => false,
                    'reason' => 'client_id does not match',
                );
            }

            return array(
                'valid'  => true,
                'user'   => $user,
                'me'     => $data['me'] ?? '',
                'scope'  => $data['scope'] ?? '',
            );
        }
    }
}
