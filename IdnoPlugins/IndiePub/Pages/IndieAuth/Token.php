<?php

namespace IdnoPlugins\IndiePub\Pages\IndieAuth {

    use Idno\Core\Webservice;
    use Idno\Entities\User;

    class Token extends \Idno\Common\Page
    {

        // GET requests verify a token
        function get($params = array())
        {
            $headers = self::getallheaders();
            if (!empty($headers['Authorization'])) {
                $token = $headers['Authorization'];
                $token = trim(str_replace('Bearer', '', $token));
                $found = self::findUserForToken($token);

                if (!empty($found)) {
                    $this->setResponse(200);
                    echo http_build_query($found['data']);
                    exit;
                }
            }
            $this->setResponse(404);
            echo "Client mismatch.";
        }

        // POST requests generate a token
        function post()
        {
            // Get parameters
            $code         = $this->getInput('code');
            $redirect_uri = $this->getInput('redirect_uri');
            $state        = $this->getInput('state');
            $client_id    = $this->getInput('client_id');

            $headers      = self::getallheaders();

            $verified = Auth::verifyCode($code, $client_id, $redirect_uri, $state);
            if ($verified['valid']===true) {

                // Generate access token and save it to the user
                $user  = $verified['user'];
                $token = self::issueToken($user, array(
                    'me'           => $verified['me'],
                    'redirect_uri' => $redirect_uri,
                    'scope'        => $verified['scope'],
                    'client_id'    => $client_id,
                ));
                if (\Idno\Core\Idno::site()->session()->isLoggedOn() && $user->getUUID() == \Idno\Core\Idno::site()->session()->currentUser()->getUUID()) {
                    \Idno\Core\Idno::site()->session()->refreshSessionUser($user);
                }

                // Output to the browser
                $this->setResponse(200);
                header('Content-Type: application/json');
                echo json_encode(array(
                    'access_token' => $token,
                    'scope'        => $verified['scope'],
                    'me'           => $verified['me'],
                ));
                exit;

            } else {
                $this->setResponse(400);
                echo $verified['reason'];
            }
        }

        /**
         * Issue an access token to a user and store it.
         *
         * @param  \Idno\Entities\User $user
         * @param  array               $data me, redirect_uri, scope, client_id
         * @return string the token
         */
        static function issueToken($user, array $data)
        {
            $indieauth_tokens = $user->indieauth_tokens;
            if (empty($indieauth_tokens) || !is_array($indieauth_tokens)) {
                $indieauth_tokens = array();
            }

            $token = Auth::generateSecret();
            $data['issued_at'] = time();
            $indieauth_tokens[$token] = $data;
            $user->indieauth_tokens = $indieauth_tokens;
            $user->save(true);

            return $token;
        }

        static function findUserForToken($token)
        {
            if (!is_string($token) || $token === '') {
                return array();
            }
            // find a user by their token
            for ($offset = 0 ; ; $offset += 10) {
                $users = \Idno\Entities\User::get(array(), array(), 10, $offset);
                if (empty($users)) {
                    break;
                }
                foreach ($users as $user) {
                    $indieauth_tokens = $user->indieauth_tokens;
                    $key = Auth::matchingKey($indieauth_tokens, $token);
                    if ($key !== null) {
                        return array(
                            'user' => $user,
                            'data' => $indieauth_tokens[$key],
                        );
                    }
                }
            }
            return array();
        }
    }

}
