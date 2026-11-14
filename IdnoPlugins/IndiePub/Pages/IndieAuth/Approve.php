<?php

namespace IdnoPlugins\IndiePub\Pages\IndieAuth {

    class Approve extends \Idno\Common\Page
    {

        function postContent()
        {
            if (!($user = \Idno\Core\site()->session()->currentUser())) {
                $this->setResponse(403);
                echo 'You must be logged in to approve IndieAuth requests.';
                exit;
            }

            $me           = $this->getInput('me');
            $client_id    = $this->getInput('client_id');
            $redirect_uri = $this->getInput('redirect_uri');
            $state        = $this->getInput('state');
            $scope        = $this->getInput('scope');

            if (!empty($me) && parse_url($me, PHP_URL_HOST) == parse_url($user->getIndieAuthURL(), PHP_URL_HOST)) {

                // Validate redirect_uri belongs to same domain as client_id
                if (!empty($redirect_uri) && !empty($client_id)) {
                    $redirectHost = parse_url($redirect_uri, PHP_URL_HOST);
                    $clientHost = parse_url($client_id, PHP_URL_HOST);
                    if ($redirectHost !== $clientHost) {
                        $this->setResponse(400);
                        echo 'redirect_uri must be on the same domain as client_id.';
                        return;
                    }
                }

                $indieauth_codes = $user->indieauth_codes;
                if (empty($indieauth_codes)) {
                    $indieauth_codes = array();
                }

                $code = md5(rand(0, 99999) . time() . $user->getUUID() . $client_id . $state . rand(0, 999999));
                $indieauth_codes[$code] = array(
                    'me'           => $me,
                    'redirect_uri' => $redirect_uri,
                    'scope'        => $scope,
                    'state'        => $state,
                    'client_id'    => $client_id,
                    'issued_at'    => time(),
                    'nonce'        => mt_rand(1000000, pow(2, 30))
                );
                $user->indieauth_codes = $indieauth_codes;
                $user->save();

                if (strpos($redirect_uri, '?') === false) {
                    $redirect_uri .= '?';
                } else {
                    $redirect_uri .= '&';
                }

                $redirect_uri .= http_build_query(array(
                    'code'  => $code,
                    'state' => $state,
                    'me'    => $me,
                ));

                $this->forward($redirect_uri);
            }
        }
    }
}
