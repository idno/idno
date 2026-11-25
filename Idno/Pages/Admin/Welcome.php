<?php

    /**
     * The page the person who installed the site lands on once its administrator account exists:
     * the site is ready, and what to do next.
     */

namespace Idno\Pages\Admin {

    /**
     * /admin/welcome/ (admins only; it changes nothing). The first content type that can be created
     * gives the address of the "write your first post" link; a template does not look it up.
     */
    class Welcome extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $compose = '';
            foreach (\Idno\Common\ContentType::getRegistered() as $type) {
                if ($type instanceof \Idno\Common\ContentType && !empty($type->createable)) {
                    $compose = (string) $type->getEditURL();
                    break;
                }
            }

            $site = \Idno\Core\Idno::site();
            $t = $site->template();
            $t->body = $t->__([
                'compose_url' => $compose,
                'mail_is_set_up' => !empty($site->config()->smtp_host),
                'queue_is_on' => $site->queue()->isAsynchronous(),
            ])->draw('admin/welcome');
            $t->title = $site->language()->_('Your site is ready');
            $t->drawPage();
        }

    }

}
