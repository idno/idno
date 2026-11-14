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

        static function findUserForCode($code)
        {
            // TODO encode user id in the auth code? or otherwise do a reverse lookup
            // to avoid checking every user
            for ($offset = 0 ; ; $offset += 10) {
                $users = \Idno\Entities\User::get(array(), array(), 10, $offset);
                if (empty($users)) {
                    break;
                }
                foreach ($users as $user) {
                    $indieauth_codes = $user->indieauth_codes;
                    if (!empty($indieauth_codes) && isset($indieauth_codes[$code])) {
                        return array(
                            'user' => $user,
                            'data' => $indieauth_codes[$code],
                        );
                    }
                }
            }
            return array();
        }

        static function removeUsedAndExpiredCodes($user, $code)
        {
            if (!empty($user->indieauth_codes)) {
                unset($user->indieauth_codes[$code]);
                foreach ($user->indieauth_codes as $code => $data) {
                    $elapsed = time() - $data['issued_at'];
                    if ($elapsed > 10 * 60) {
                        unset($user->indieauth_codes[$code]);
                    }
                }
                $user->save();
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

            // codes can only be used once
            Auth::removeUsedAndExpiredCodes($user, $code);

            $elapsed = time() - $data['issued_at'];
            if ($elapsed > 10 * 60) {

                \Idno\Core\Idno::site()->logging()->error("Auth code (issued at ".date('r', $data['issued_at']).") has expired");

                return array(
                    'valid'  => false,
                    'reason' => 'authentication code has expired',
                );
            }
            if ($redirect_uri != $data['redirect_uri']) {

                \Idno\Core\Idno::site()->logging()->error("Redirect url ($redirect_uri) does not match {$data['redirect_uri']}");

                return array(
                    'valid'  => false,
                    'reason' => 'redirect_uri does not match',
                );
            }
            if ($client_id != $data['client_id']) {

                \Idno\Core\Idno::site()->logging()->error("Client ID ($client_id) does not match {$data['client_id']}");

                return array(
                    'valid'  => false,
                    'reason' => 'client_id does not match',
                );
            }

            return array(
                'valid'  => true,
                'user'   => $user,
                'me'     => $data['me'],
                'scope'  => $data['scope'],
            );
        }
    }
}
