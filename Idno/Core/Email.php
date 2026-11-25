<?php

namespace Idno\Core {

    class Email extends \Idno\Common\Component
    {

        public $message;

        function init()
        {
            // Using SwiftMailer to establish a message
            try {
                $this->message = new \Swift_Message();
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Something went wrong and we couldn't create the email message to send."));
            }
        }

        /**
         * Set the subject of the message
         *
         * @param $subject
         */
        function setSubject($subject)
        {
            return $this->message->setSubject(static::headerText($subject));
        }

        /**
         * Set the "From" address of the message
         *
         * @param  $email The email address of the account
         * @param  $name  The name of the account
         * @return mixed
         */
        function setFrom($email, $name = '')
        {
            if (!empty($name)) {
                return $this->message->addFrom(array($name => $email));
            }

            return $this->message->addFrom($email);
        }

        /**
         * Add a recipient
         *
         * @param  string $email The email address of the recipient
         * @param  string $name  The name of the recipient (optional)
         * @return mixed
         */
        function addTo($email, $name = '')
        {
            if (!empty($name)) {
                return $this->message->addTo(array($name => $email));
            }

            return $this->message->addTo($email);
        }

        /**
         * Adds an email to the BCC list
         *
         * @param  $email
         * @return mixed
         */
        function addBcc($email)
        {
            return $this->message->addBcc($email);
        }

        /**
         * Add a "reply to" message
         *
         * @param  $email
         * @param  string $name
         * @return mixed
         */
        function setReplyTo($email, $name = '')
        {
            if (!empty($name)) {
                return $this->message->addReplyTo(array($name => $email));
            }

            return $this->message->addReplyTo($email);
        }

        /**
         * Given the name of a template and a set of variables to include, generates an HTML body and adds it to the message
         *
         * @param  $template_name
         * @param  array $vars
         * @return mixed
         */
        function setHTMLBodyFromTemplate($template_name, $vars = array(), array $shellVars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email');
            $body = $t->__($vars)->draw($template_name);

            return $this->setHTMLBody($body, true, $shellVars);
        }

        /**
         * Sets the HTML body of the message (optionally setting it inside the email pageshell as defined by the email template)
         *
         * @param  $body      The formatted HTML body text of the message
         * @param  bool  $shell     Should the message be placed inside the pageshell? Default: true
         * @param  array $shellVars Variables to pass to the page shell template
         * @return mixed
         */
        function setHTMLBody($body, $shell = true, array $shellVars = [])
        {
            if ($shell) {
                $t = clone \Idno\Core\Idno::site()->template();
                $t->setTemplateType('email');
                $message = $t->__(array_merge(array('body' => $body), $shellVars))->draw('shell');
            } else {
                $message = $body;
            }

            return $this->message->setBody($message, 'text/html');
        }

        /**
         * Set the text only component of an email.
         *
         * @param  type $template_name
         * @param  type $vars
         * @return mixed
         */
        function setTextBodyFromTemplate($template_name, $vars = array())
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email-text');
            $body = $t->__($vars)->draw($template_name);

            return $this->setTextBody($body);
        }

        /**
         * Sets the plain text body of the message
         *
         * @param  string $body The body of the message
         * @return mixed
         */
        function setTextBody($body)
        {
            return $this->message->addPart($body, 'text/plain');
        }

        /**
         * The security choices for the connection to the mail server, with the port each one
         * usually goes with. 'tls' is STARTTLS (the connection starts in plain text and is then
         * encrypted: what hosted mail services expect on port 587); 'ssl' is TLS from the first
         * byte (port 465). The names are SwiftMailer's, and the ones stored in smtp_secure.
         */
        const SECURITY_PORTS = ['tls' => 587, 'ssl' => 465, '' => 25];

        /// Seconds to wait for the mail server before giving up
        const DEFAULT_TIMEOUT = 10;

        /// Why the last send() failed, in plain words for an administrator; empty when it worked
        public $error = '';

        /// The kind of failure: connect, tls, auth, sender, rejected, recipient, temporary, unconfigured or unknown
        public $errorKind = '';

        /**
         * Has the site been given a mail service to send through: a server and an address to
         * send from? Without one, Idno still tries a mail server on this machine (port 25),
         * which is rarely there, so pages that send mail warn when this is false.
         *
         * @return bool
         */
        static function isConfigured()
        {
            $config = \Idno\Core\Idno::site()->config();

            return !empty($config->smtp_host) && filter_var((string) $config->from_email, FILTER_VALIDATE_EMAIL);
        }

        /**
         * The stored security setting as one of the keys of SECURITY_PORTS
         *
         * @return string
         */
        static function security()
        {
            $secure = (string) \Idno\Core\Idno::site()->config()->smtp_secure;

            return in_array($secure, ['tls', 'ssl'], true) ? $secure : '';
        }

        /**
         * The port mail is sent to: the stored one, or the usual port for the security setting
         *
         * @return int
         */
        static function port()
        {
            $port = (int) \Idno\Core\Idno::site()->config()->smtp_port;

            return ($port > 0 && $port < 65536) ? $port : self::SECURITY_PORTS[static::security()];
        }

        /**
         * The name mail is sent from: the from_name setting, or the site's name
         *
         * @return string
         */
        static function fromName()
        {
            $config = \Idno\Core\Idno::site()->config();
            $name = trim((string) $config->from_name);
            if ($name === '') {
                $name = trim((string) $config->title);
            }

            return static::headerText($name);
        }

        /**
         * Text for a mail header (a subject, a name): one line, no control characters, so
         * nothing a person typed can start a header of its own.
         *
         * @param  string $text
         * @return string
         */
        static function headerText($text)
        {
            return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text) ?? '');
        }

        /**
         * The connection to the mail server, from the stored settings
         *
         * @return \Swift_SmtpTransport
         */
        static function transport()
        {
            $config = \Idno\Core\Idno::site()->config();
            if ($smtp_host = $config->smtp_host) {
                $transport = new \Swift_SmtpTransport($smtp_host);
                if ($smtp_username = $config->smtp_username) {
                    $transport->setUsername($smtp_username);
                    if ($smtp_password = $config->smtp_password) {
                        $transport->setPassword($smtp_password);
                    }
                }
            } else {
                $transport = new \Swift_SmtpTransport();
            }
            $transport->setPort(static::port());
            if ($security = static::security()) {
                $transport->setEncryption($security);
            }
            // A wrong host or port must not hold the page for minutes
            $timeout = (int) $config->smtp_timeout;
            $transport->setTimeout($timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT);

            return $transport;
        }

        /**
         * Send the message
         *
         * @return int The number of recipients it was accepted for; 0 when it failed, and then
         *             $error says why
         */
        function send()
        {
            $this->error = '';
            $this->errorKind = '';
            $config = \Idno\Core\Idno::site()->config();
            try {
                $mailer = new \Swift_Mailer(static::transport());

                // Set the "from" address
                if ($from_email = $config->from_email) {
                    $this->message->setFrom($from_email, static::fromName());
                }
                // Replies go to the site's reply address unless the message names one
                $reply_to = (string) $config->reply_to_email;
                if ($reply_to !== '' && filter_var($reply_to, FILTER_VALIDATE_EMAIL) && !$this->message->getReplyTo()) {
                    $this->message->setReplyTo($reply_to);
                }
                if (!$this->message->getFrom()) {
                    $this->errorKind = 'unconfigured';
                    $this->error = \Idno\Core\Idno::site()->language()->_('The site has no address to send mail from. Set one in the email settings.');

                    return 0;
                }

                $sent = $mailer->send(\Idno\Core\Idno::site()->events()->triggerEvent('email/send', ['email' => $this], $this->message));
                if (!$sent) {
                    $this->errorKind = 'recipient';
                    $this->error = \Idno\Core\Idno::site()->language()->_("The mail server did not accept the recipient's address.");
                }

                return $sent;

            } catch (\Throwable $e) {
                list($this->errorKind, $this->error) = static::explain($e);
                // Lets log errors rather than silently drop them. The server's words may repeat
                // the user name, which for some services is the secret itself, so they are scrubbed.
                \Idno\Core\Idno::site()->logging()->error('Error sending mail', ['kind' => $this->errorKind, 'error' => static::scrub($e->getMessage())]);
            }

            return 0;
        }

        /**
         * Take the mail server's user name and password, in every form they travel in, out of
         * a piece of text
         *
         * @param  string $text
         * @return string
         */
        static function scrub($text)
        {
            $config = \Idno\Core\Idno::site()->config();
            $user = (string) $config->smtp_username;
            $pass = (string) $config->smtp_password;
            $secrets = [];
            foreach ([$pass, $user] as $secret) {
                if ($secret !== '') {
                    $secrets[] = $secret;
                    $secrets[] = base64_encode($secret);
                }
            }
            if ($user !== '' || $pass !== '') {
                $secrets[] = base64_encode("\0" . $user . "\0" . $pass);
            }
            usort(
                $secrets, function ($a, $b) {
                    return strlen($b) - strlen($a);
                }
            );
            $text = str_replace($secrets, '[hidden]', (string) $text);

            return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '');
        }

        /**
         * Say in plain words why sending failed.
         *
         * @param  \Throwable $e What SwiftMailer threw
         * @return string[] The kind of failure (connect, tls, auth, sender, rejected, temporary,
         *                  unknown) and a sentence or two for an administrator. Neither holds
         *                  the user name or the password.
         */
        static function explain(\Throwable $e)
        {
            $lang = \Idno\Core\Idno::site()->language();
            $config = \Idno\Core\Idno::site()->config();
            $raw = $e->getMessage();
            $message = static::scrub($raw);
            $host = (string) $config->smtp_host !== '' ? (string) $config->smtp_host : 'localhost';
            $port = static::port();
            $security = static::security();
            $where = $host . ', ' . $lang->_('port %d', [$port]);

            // What the server itself said, when it said something
            $reply = '';
            if (preg_match('/got code "(\d{3})", with message "(.*?)"/s', $message, $match)) {
                $code = (int) $match[1];
                $reply = trim(preg_replace('/\s+/', ' ', $match[2]) ?? '');
                if (mb_strlen($reply) > 300) {
                    $reply = mb_substr($reply, 0, 300) . '...';
                }
            } else {
                $code = 0;
            }
            $said = $reply !== '' ? ' ' . $lang->_('The server said: %s', [$reply]) : '';

            if (stripos($raw, 'Failed to authenticate') !== false || in_array($code, [530, 534, 535], true)) {
                return ['auth', $lang->_('The mail server (%s) did not accept the user name and password. Check both: many services want a fixed user name and an API key as the password.', [$where]) . $said];
            }
            if (preg_match('/certificate|verify failed/i', $raw)) {
                return ['tls', $lang->_("The mail server's security certificate could not be verified (%s). Check that the host name is exactly the one your mail service gives.", [$where])];
            }
            if (preg_match('/SSL operation failed|wrong version number|Unable to connect with TLS|crypto/i', $raw)
                || ($security === 'tls' && $code >= 500 && strpos($raw, 'Expected response code 220') !== false)
            ) {
                if ($security === 'ssl') {
                    return ['tls', $lang->_('A secure connection to the mail server could not be made (%s). This port may expect STARTTLS instead of TLS: port 587 goes with STARTTLS, port 465 with TLS.', [$where])];
                }

                return ['tls', $lang->_('A secure connection to the mail server could not be made (%s). The server may not offer STARTTLS on this port: port 587 goes with STARTTLS, port 465 with TLS.', [$where]) . $said];
            }
            if (preg_match('/getaddrinfo|Name or service not known|nodename nor servname|No such host/i', $raw)) {
                return ['connect', $lang->_('The mail server %s could not be found. Check the spelling of the host name.', [$host])];
            }
            if (stripos($raw, 'Connection refused') !== false) {
                return ['connect', $lang->_('The mail server refused the connection (%s). Check the host name and the port.', [$where])];
            }
            if (stripos($raw, 'Connection could not be established') !== false || preg_match('/timed out/i', $raw)) {
                return ['connect', $lang->_('The mail server did not answer (%s). Check the host name and the port, and that the security setting matches the port: 587 goes with STARTTLS, 465 with TLS. Some web hosts also block outgoing mail ports.', [$where])];
            }
            if ($code >= 500) {
                if (preg_match('/sender|from address|from header|not verified|not authori[sz]ed|identity|domain/i', $reply)) {
                    return ['sender', $lang->_('The mail server would not send from this address. The From address must be one your mail service has verified, or on a domain it is set up to send for.') . $said];
                }

                return ['rejected', $lang->_('The mail server refused the message.') . $said];
            }
            if ($code >= 400) {
                return ['temporary', $lang->_('The mail server could not take the message just now. Try again in a few minutes.') . $said];
            }
            if ($e instanceof \Swift_RfcComplianceException) {
                return ['rejected', $lang->_('One of the addresses is not a valid email address.')];
            }

            return ['unknown', $lang->_('The mail could not be sent.') . ($message !== '' ? ' ' . $message : '')];
        }

    }

}
