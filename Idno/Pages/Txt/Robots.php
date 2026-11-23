<?php

    /**
     * Robots.txt
     */

namespace Idno\Pages\Txt {

    /**
     * Default class to serve the homepage
     */
    class Robots extends \Idno\Common\Page
    {

        function getContent()
        {
            $t = \Idno\Core\Idno::site()->template();
            header('Content-type: text/plain');
            echo $t->draw('txt/robots');
        }

    }

}

