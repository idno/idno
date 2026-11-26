<?php

namespace Idno\Pages\Robot {

    class Remove extends \Idno\Common\Page
    {

        function getContent()
        {

        }

        function postContent()
        {

            $this->gatekeeper();
            $user              = \Idno\Core\Idno::site()->session()->currentUser();
            $user->robot_state = 0;
            $user->save();
            $this->forwardBack();

        }

    }

}

