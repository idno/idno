<?php

    /**
     * User mentions
     */

namespace Idno\Pages\Search {

    use Idno\Entities\User;

    class Mentions extends \Idno\Common\Page
    {

        function getContent()
        {

            $results  = array();
            $username = $this->getInput('username');
            if ($users = User::get(array(), array(), 9999)) { //User::getByHandle($username)) {

                \Idno\Core\Idno::site()->events()->triggerEvent(
                    'search/mentions', [
                    'username' => $username
                    ], $users
                );

                foreach ($users as $user) {
                    /* @var \Idno\Entities\User $user */
                    if ($user->isClosed()) {
                        continue; // An account that is being deleted is offered to nobody
                    }
                    $results[] = array(
                        'username' => $user->getHandle(),
                        'name'     => $user->getTitle(),
                        'image'    => $user->getIcon()
                    );
                }
            }

            header('Content-type: text/json');
            echo json_encode($results);

        }

    }

}

