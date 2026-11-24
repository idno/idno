<?php

namespace IdnoPlugins\IndiePub\Pages;

use Idno\Core\Idno;
use Idno\Common\Page;

class Account extends Page
{

    function getContent($params=array())
    {
        $this->gatekeeper();
        $t = Idno::site()->template();
        $body = $t->__([])->draw('account/indiepub');
        $t->__([
            'title' => \Idno\Core\Idno::site()->language()->_('IndiePub accounts'),
            'body'  => $body,
        ])->drawPage();
    }

}
