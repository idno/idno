<?php

    /**
     * Legacy search
     */

namespace Idno\Pages\Search {

    class Forward extends \Idno\Common\Page
    {

        function getContent()
        {
            $query = $this->getTextInput('q');
            $this->forward(\Idno\Core\Idno::site()->config()->url . '?q=' . urlencode($query));
        }

        function postContent()
        {
            $query = $this->getTextInput('q');
            $this->forward(\Idno\Core\Idno::site()->config()->url . '?q=' . urlencode($query));
        }

    }

}

