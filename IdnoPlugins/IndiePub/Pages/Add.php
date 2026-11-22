<?php

namespace IdnoPlugins\IndiePub\Pages;

use Idno\Core\Idno;
use Idno\Common\Page;

class Add extends Page
{

    function postContent()
    {
        $this->gatekeeper();

        $user = Idno::site()->session()->currentUser();
        $client_id = $this->getInput('client_id');
        $redirect_uri = $this->getInput('redirect_uri');
        $valid = true;

        if (empty($client_id)) {
            Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Client ID can't be blank."));
            $valid = false;
        }
        if (empty($redirect_uri)) {
            Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Redirect URI can't be blank."));
            $valid = false;
        }

        if (!$valid) return $this->forward(Idno::site()->config()->getDisplayURL().'account/indiepub/');

        // Generate a new IndieAuth token
        \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken($user, array(
            'me'           => '',
            'redirect_uri' => $redirect_uri,
            'scope'        => 'create media',
            'client_id'    => $client_id,
        ));

        \Idno\Core\Idno::site()->session()->refreshSessionUser($user);

        $this->forward(Idno::site()->config()->getDisplayURL().'account/indiepub/');
    }

}