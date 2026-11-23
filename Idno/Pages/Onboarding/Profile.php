<?php

    /**
     * User profile editing for onboarding
     */

namespace Idno\Pages\Onboarding {

    class Profile extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->gatekeeper();

            $user = \Idno\Core\Idno::site()->session()->currentUser();

            $t = \Idno\Core\Idno::site()->template();
            $t->sendSimpleShellHeaders();
            echo $t->__(
                array(

                'title'    => \Idno\Core\Idno::site()->language()->_("Create your profile"),
                'body'     => $t->__(array('user' => $user))->draw('onboarding/profile'),
                'messages' => \Idno\Core\Idno::site()->session()->getAndFlushMessages()

                )
            )->draw('shell/simple');

        }

    }

}

