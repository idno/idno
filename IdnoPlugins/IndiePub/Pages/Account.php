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
        // The access tokens are secrets: they go to the body's own draw, on a copy of the page's template, so they are never a variable
        // of the page (the JSON output of a page prints every variable of it).
        $tokens = Idno::site()->session()->currentUser()->indieauth_tokens;
        $body = $t->withVars(array_merge($t->vars, ['tokens' => empty($tokens) ? [] : (array) $tokens]))->draw('account/indiepub');
        $t->__([
            'title' => \Idno\Core\Idno::site()->language()->_('IndiePub Accounts'),
            'body'  => $body,
        ])->drawPage();
    }

}
