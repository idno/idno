<?php

    /**
     * User profile editing for onboarding
     */

namespace Idno\Pages\Onboarding {

    class Connect extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->gatekeeper();

            //if ($services = \Idno\Core\Idno::site()->syndication()->getServices()) {
            $user = \Idno\Core\Idno::site()->session()->currentUser();

            $_SESSION['onboarding_passthrough'] = true;

            $t = \Idno\Core\Idno::site()->template();
            $t->sendSimpleShellHeaders();
            echo $t->__(
                array(

                'title'    => \Idno\Core\Idno::site()->language()->_("Connect some networks"),
                'body'     => $t->__(array('user' => $user))->draw('onboarding/connect'),
                'messages' => \Idno\Core\Idno::site()->session()->getAndFlushMessages()

                )
            )->draw('shell/simple');
            //} else {
            //    $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'begin/publish');
            //}

        }

    }

}

