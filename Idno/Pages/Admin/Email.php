<?php

    /**
     * Administration page: email settings
     */

namespace Idno\Pages\Admin {

    use Idno\Mail\MailSettingsInput;

    class Email extends \Idno\Common\Page
    {

        /// The settings this page stores, apart from the password
        const FIELDS = MailSettingsInput::FIELDS;

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $this->drawForm(static::storedValues(), []);
        }

        /**
         * Draw the page.
         *
         * The stored password is never printed: the form has only whether there is one. The stored user name is a form value, and
         * like every value of the form it goes to the body's own draw, on a copy of the page's template, so that it is never a
         * variable of the page (the JSON and JSONP output of a page prints every variable of its template).
         *
         * @param array    $values The values of the fields (never the password)
         * @param string[] $errors What is wrong with each field, by field name
         */
        protected function drawForm(array $values, array $errors)
        {
            $config = \Idno\Core\Idno::site()->config();
            $user   = \Idno\Core\Idno::site()->session()->currentUser();
            $t      = \Idno\Core\Idno::site()->template();
            $t->body  = $t->withVars(
                array_merge(
                    $t->vars, [
                        'values'       => $values,
                        'errors'       => $errors,
                        'has_password' => (string)$config->smtp_password !== '',
                        'configured'   => \Idno\Core\Email::isConfigured(),
                        'sender'       => \Idno\Core\Email::fromName() . ' <' . \Idno\Core\Email::headerText($config->from_email) . '>',
                        'test_to'      => $user ? (string)$user->email : '',
                        'fixed'        => static::settingsFromFile(),
                    ]
                )
            )->draw('admin/email');
            $t->title = \Idno\Core\Idno::site()->language()->_('Email');
            $t->drawPage();
        }

        /**
         * The mail settings that the site's configuration file or environment sets. Those win over what this page stores, so the
         * page says which they are.
         *
         * @return string[] Setting names
         */
        static function settingsFromFile()
        {
            $fixed = array_keys((array)\Idno\Core\Idno::site()->config()->ini_config);

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
            $port   = (int)$config->smtp_port;

            return [
                'from_email'     => (string)$config->from_email,
                'from_name'      => (string)$config->from_name,
                'reply_to_email' => (string)$config->reply_to_email,
                'smtp_host'      => (string)$config->smtp_host,
                'smtp_port'      => $port > 0 ? (string)$port : '',
                // Nothing set up yet: suggest what mail services expect
                'smtp_secure'    => (string)$config->smtp_host === '' ? 'tls' : \Idno\Core\Email::security(),
                'smtp_username'  => (string)$config->smtp_username,
            ];
        }

        /**
         * What is wrong with a field, in words
         *
         * @param string $code One of the codes of MailSettingsInput
         */
        protected static function sentence($code)
        {
            $lang = \Idno\Core\Idno::site()->language();

            return match ($code) {
                MailSettingsInput::HOST => $lang->_('Enter the name of the mail server only, such as smtp.example.com: no "https://" and no port.'),
                MailSettingsInput::PORT => $lang->_('The port is a number between 1 and 65535. Mail services usually use 587 or 465.'),
                MailSettingsInput::FROM_REQUIRED => $lang->_('Enter the address mail is sent from.'),
                MailSettingsInput::NAME_TOO_LONG => $lang->_('Keep the name under 100 characters.'),
                default => $lang->_("That doesn't look like an email address."),
            };
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $input = [];
            foreach (self::FIELDS as $field) {
                $input[$field] = $this->getInput($field);
            }
            list($values, $codes) = MailSettingsInput::validate($input);

            if ($codes) {
                $errors = [];
                foreach ($codes as $field => $code) {
                    $errors[$field] = static::sentence($code);
                }
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('The email settings were not saved. Check the marked fields.'));
                $this->setResponse(400);
                http_response_code(400);
                $this->drawForm($values, $errors);

                return;
            }

            $config = \Idno\Core\Idno::site()->config();
            foreach ($values as $field => $value) {
                $config->config[$field] = $field === 'smtp_port' ? (int)$value : $value;
            }

            // The password is never shown, so an empty field means "keep the one you have"
            $password = $this->getInput('smtp_password');
            $password = is_string($password) ? $password : '';
            if ($password !== '') {
                $config->config['smtp_password'] = $password;
            } elseif ($this->getInput('smtp_password_remove') || $values['smtp_username'] === '') {
                $config->config['smtp_password'] = '';
            }

            $config->save();
            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('The email settings were saved. Send a test email to check them.'));
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email#test');
        }

    }

}
