<?php

namespace Idno\Core {

    use Symfony\Component\Mailer\MailerInterface;
    use Symfony\Component\Mime\Address;

    /**
     * A message to send, on Symfony Mime and Symfony Mailer (the container's MailerInterface, built from
     * the mail settings at each send: see Idno\Mail\MailerFactory). send() never throws: it returns the
     * number of To and Bcc recipients, or 0 when the message could not be built or sent. The one line it
     * logs names the exception's class only, because a transport's message can quote the DSN, which holds
     * the SMTP password.
     */
    class Email extends \Idno\Common\Component
    {

        /**
         * @var \Symfony\Component\Mime\Email The message
         */
        public $message;

        /**
         * @var bool Whether an address or name given to a setter was refused (send() then sends nothing)
         */
        private $refused = false;

        /**
         * @var string|null Why the last send() failed, in a fixed vocabulary (see lastFailure())
         */
        private $lastFailure = null;

        /**
         * @var \Symfony\Component\Mailer\MailerInterface|null A mailer to use instead of the container's (tests)
         */
        private static $mailer = null;

        /**
         * Replaces the mailer every Email sends through (null restores the container's). For tests.
         */
        public static function useMailer(?MailerInterface $mailer): void
        {
            self::$mailer = $mailer;
        }

        /**
         * Why the last send() returned 0, in a fixed vocabulary that is safe to show an admin (never an
         * exception's text): `settings: <name>` (a mail setting cannot be used, e.g. `settings: smtp_host`),
         * `message` (an address or name of the message was refused, or there is no sender or body),
         * `transport` (anything else: the mail could not be delivered). Null after a send that worked,
         * or before any.
         *
         * @return string|null
         */
        function lastFailure()
        {
            return $this->lastFailure;
        }

        function init()
        {
            $this->message = new \Symfony\Component\Mime\Email();
        }

        /**
         * Runs a change to the message; an address or name the message refuses (a line break in an
         * address, one that is not an address) is remembered, and send() then sends nothing.
         */
        private function change(callable $change)
        {
            try {
                return $change();
            } catch (\Throwable $e) {
                $this->refused = true;
            }

            return $this->message;
        }

        /**
         * Set the subject of the message
         *
         * @param $subject
         */
        function setSubject($subject)
        {
            // A subject is one line: a line break in it must never start another header.
            return $this->change(fn() => $this->message->subject(trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', (string)$subject))));
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
            return $this->change(fn() => $this->message->addFrom(new Address((string)$email, (string)$name)));
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
            return $this->change(fn() => $this->message->addTo(new Address((string)$email, (string)$name)));
        }

        /**
         * Adds an email to the BCC list
         *
         * @param  $email
         * @return mixed
         */
        function addBcc($email)
        {
            return $this->change(fn() => $this->message->addBcc(new Address((string)$email)));
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
            return $this->change(fn() => $this->message->addReplyTo(new Address((string)$email, (string)$name)));
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

            return $this->message->html($message);
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
            return $this->message->text($body);
        }

        /**
         * Send the message
         *
         * @return int The number of recipients (To and Bcc), or 0 if it could not be sent
         */
        function send()
        {
            $this->lastFailure = null;
            try {
                if ($this->refused) {
                    throw new \InvalidArgumentException('An address or name of the message was refused.');
                }

                // Set the "from" address
                if ($from_email = \Idno\Core\Idno::site()->config()->from_email) {
                    $this->message->from(new Address((string)$from_email, (string)\Idno\Core\Idno::site()->config()->title));
                }

                $message = \Idno\Core\Idno::site()->events()->triggerEvent('email/send', ['email' => $this], $this->message);
                if (!($message instanceof \Symfony\Component\Mime\Email)) {
                    $message = $this->message;
                }

                $mailer = self::$mailer ?? \Idno\Core\Idno::container()->get(MailerInterface::class);
                $mailer->send($message);

                return count($message->getTo()) + count($message->getBcc());

            } catch (\Throwable $e) {
                if ($e instanceof \Idno\Mail\InvalidMailSetting) {
                    $this->lastFailure = 'settings: ' . $e->setting();
                } elseif ($this->refused || $e instanceof \Symfony\Component\Mime\Exception\ExceptionInterface) {
                    $this->lastFailure = 'message';
                } else {
                    $this->lastFailure = 'transport';
                }
                // Lets log errors rather than silently drop them. Only the class: the message of a
                // transport's exception can quote the DSN, and with it the SMTP password. This runs in
                // error handlers, so a logger that is not there must not make it worse.
                try {
                    \Idno\Core\Idno::site()->logging()->error('Error sending mail: ' . get_class($e));
                } catch (\Throwable $ignored) {
                }
            }

            return 0;
        }

    }

}
