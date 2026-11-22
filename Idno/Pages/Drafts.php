<?php

namespace Idno\Pages {

    /**
     * The signed-in member's drafts. A legacy page because it needs a logged-in user, which a controller does not have yet.
     */
    class Drafts extends \Idno\Common\Page
    {
        function getContent()
        {
            $this->gatekeeper();

            $user = \Idno\Core\Idno::site()->session()->currentUser();
            $count = 25; // Paginate at 25 drafts per page
            $offset = (int) $this->getInput('offset', 0);

            $drafts = \Idno\Common\Entity::getFromAll(
                ['publish_status' => 'draft', 'owner' => $user->getUUID()],
                [],
                $count,
                $offset
            );
            $total = \Idno\Common\Entity::countFromAll(
                ['publish_status' => 'draft', 'owner' => $user->getUUID()]
            );

            $t = \Idno\Core\Idno::site()->template();
            $t->body = $t->__([
                'drafts' => $drafts,
                'count' => $count,
                'offset' => $offset,
                'total' => $total,
            ])->draw('drafts');
            $t->title = \Idno\Core\Idno::site()->language()->_('Drafts');
            $t->drawPage();
        }
    }

}
