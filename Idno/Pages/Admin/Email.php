<?php

    /**
     * Administration page: email settings
     */

namespace Idno\Pages\Admin {

    class Email extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $t        = \Idno\Core\Idno::site()->template();
            // The form shows the stored SMTP user name and password (an administrator's page, as it always did), and the template function
            // that reads settings refuses them: they go to the body's own draw, on a copy of the page's template, so they are never a
            // variable of the page (the JSON and JSONP output of a page prints every variable of its template).
            $config   = \Idno\Core\Idno::site()->config();
            $t->body  = $t->withVars(array_merge($t->vars, [
                'smtp_username' => $config->smtp_username,
                'smtp_password' => $config->smtp_password,
            ]))->draw('admin/email');
            $t->title = \Idno\Core\Idno::site()->language()->_('Email');
            $t->drawPage();

        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $email                                                   = $this->getInput('from_email');
            \Idno\Core\Idno::site()->config()->config['smtp_host']     = $this->getInput('smtp_host');
            \Idno\Core\Idno::site()->config()->config['smtp_username'] = $this->getInput('smtp_username');
            \Idno\Core\Idno::site()->config()->config['smtp_password'] = $this->getInput('smtp_password');
            \Idno\Core\Idno::site()->config()->config['smtp_port']     = (int)$this->getInput('smtp_port');
            \Idno\Core\Idno::site()->config()->config['smtp_secure']   = $this->getInput('smtp_secure');

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                \Idno\Core\Idno::site()->config()->config['from_email'] = $this->getInput('from_email');
            }

            \Idno\Core\Idno::site()->config()->save();
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email');
        }

    }

}

