<?php

    /**
     * Index for onboarding
     */

namespace Idno\Pages\Onboarding {

    class Begin extends \Idno\Common\Page
    {

        function getContent()
        {

            $set_name = $this->getInput('set_name');
            if (!empty($set_name)) {
                $_SESSION['set_name'] = $set_name;
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->sendSimpleShellHeaders();
            echo $t->__(
                array(
                'body'     => $t->draw('onboarding/begin'),
                'title'    => \Idno\Core\Idno::site()->language()->_('Welcome to Idno'),
                'messages' => \Idno\Core\Idno::site()->session()->getAndFlushMessages()
                )
            )->draw('shell/simple');

        }

        function postContent()
        {

        }

    }

}

