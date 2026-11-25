<?php

    /**
     * Administration page: email settings
     */

namespace Idno\Pages\Admin {

    class Email extends \Idno\Common\Page
    {

        /// The settings this page stores, apart from the password
        const FIELDS = ['from_email', 'from_name', 'reply_to_email', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username'];

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $this->draw(static::storedValues(), []);
        }

        /**
         * Draw the page.
         *
         * @param array    $values The values of the fields (never the password)
         * @param string[] $errors What is wrong with each field, by field name
         */
        protected function draw(array $values, array $errors)
        {
            $config = \Idno\Core\Idno::site()->config();
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            $t = \Idno\Core\Idno::site()->template();
            // The stored password is never among the template variables: only whether there is one
            $t->body = $t->__(
                [
                    'values'       => $values,
                    'errors'       => $errors,
                    'has_password' => (string) $config->smtp_password !== '',
                    'configured'   => \Idno\Core\Email::isConfigured(),
                    'test_to'      => $user ? (string) $user->email : '',
                    'fixed'        => static::settingsFromFile(),
                ]
            )->draw('admin/email');
            $t->title = \Idno\Core\Idno::site()->language()->_('Email');
            $t->drawPage();
        }

        /**
         * The mail settings that the site's configuration file or environment sets. Those win
         * over what this page stores, so the page says which they are.
         *
         * @return string[] Setting names
         */
        static function settingsFromFile()
        {
            $fixed = array_keys((array) \Idno\Core\Idno::site()->config()->ini_config);

            return array_values(array_intersect(array_merge(self::FIELDS, ['smtp_password']), $fixed));
        }

        /**
         * The stored settings as the form shows them
         *
         * @return array
         */
        static function storedValues()
        {
            $config = \Idno\Core\Idno::site()->config();
            $port = (int) $config->smtp_port;

            return [
                'from_email'     => (string) $config->from_email,
                'from_name'      => (string) $config->from_name,
                'reply_to_email' => (string) $config->reply_to_email,
                'smtp_host'      => (string) $config->smtp_host,
                'smtp_port'      => $port > 0 ? (string) $port : '',
                // Nothing set up yet: suggest what mail services expect
                'smtp_secure'    => (string) $config->smtp_host === '' ? 'tls' : \Idno\Core\Email::security(),
                'smtp_username'  => (string) $config->smtp_username,
            ];
        }

        /**
         * Check what the form sent.
         *
         * @param  array $input Field values as typed
         * @return array[] The values to store, and what is wrong with each field that is wrong
         */
        static function validate(array $input)
        {
            $lang = \Idno\Core\Idno::site()->language();
            $values = [];
            $errors = [];
            foreach (self::FIELDS as $field) {
                $values[$field] = \Idno\Core\Email::headerText(is_scalar($input[$field] ?? '') ? (string) ($input[$field] ?? '') : '');
            }

            if (!in_array($values['smtp_secure'], ['tls', 'ssl'], true)) {
                $values['smtp_secure'] = '';
            }

            // A host name or an IP address: no scheme, no path, no port
            $host = $values['smtp_host'];
            if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $errors['smtp_host'] = $lang->_('Enter the name of the mail server only, such as smtp.example.com: no "https://" and no port.');
            }

            if ($values['smtp_port'] === '') {
                $values['smtp_port'] = (string) \Idno\Core\Email::SECURITY_PORTS[$values['smtp_secure']];
            }
            if (!ctype_digit($values['smtp_port']) || (int) $values['smtp_port'] < 1 || (int) $values['smtp_port'] > 65535) {
                $errors['smtp_port'] = $lang->_('The port is a number between 1 and 65535. Mail services usually use 587 or 465.');
            }

            if ($values['from_email'] === '') {
                if ($host !== '') {
                    $errors['from_email'] = $lang->_('Enter the address mail is sent from.');
                }
            } else if (!filter_var($values['from_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['from_email'] = $lang->_("That doesn't look like an email address.");
            }
            if ($values['reply_to_email'] !== '' && !filter_var($values['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['reply_to_email'] = $lang->_("That doesn't look like an email address.");
            }
            if (mb_strlen($values['from_name']) > 100) {
                $errors['from_name'] = $lang->_('Keep the name under 100 characters.');
            }

            return [$values, $errors];
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $input = [];
            foreach (self::FIELDS as $field) {
                $input[$field] = $this->getInput($field);
            }
            list($values, $errors) = static::validate($input);

            if ($errors) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('The email settings were not saved. Check the marked fields.'));
                $this->setResponse(400);
                http_response_code(400);
                $this->draw($values, $errors);

                return;
            }

            $config = \Idno\Core\Idno::site()->config();
            foreach ($values as $field => $value) {
                $config->config[$field] = $field === 'smtp_port' ? (int) $value : $value;
            }

            // The password is never shown, so an empty field means "keep the one you have"
            $password = (string) $this->getInput('smtp_password');
            if ($password !== '') {
                $config->config['smtp_password'] = $password;
            } else if ($this->getInput('smtp_password_remove') || $values['smtp_username'] === '') {
                $config->config['smtp_password'] = '';
            }

            $config->save();
            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('The email settings were saved. Send a test email to check them.'));
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email#test');
        }

    }

}
