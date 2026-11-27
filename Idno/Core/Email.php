<?php

namespace Idno\Core {

    use Symfony\Component\Mailer\MailerInterface;
    use Symfony\Component\Mime\Address;

    /**
     * A message to send, on Symfony Mime and Symfony Mailer (the container's MailerInterface, built from
     * the mail settings at each send: see Idno\Mail\MailerFactory). send() never throws: it returns the
     * number of To and Bcc recipients, or 0 when the message could not be built or sent. The one line it
     * logs names the exception's class (and the kind of failure) only, because a transport's message can quote the
     * DSN, which holds the SMTP password.
     *
     * A mail is written with the parts of templates/email and templates/email-text (see setBodyFromTemplate()), which
     * give every mail one shell and one set of parts. What a failure to send says about itself is explanation(): one
     * fixed sentence for its kind, and the mail server's own reply with the credentials taken out.
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
         * @var string The kind of the last failure of a transport (see failureKind()), empty when there was none
         */
        private $failureKind = '';

        /**
         * @var string A sentence for an administrator about the last failure (see explanation())
         */
        private $explanation = '';

        /**
         * @var array{subject?: string, preheader?: string, reason?: string} What the HTML template of this mail said about it (see describe())
         */
        private $about = [];

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

        /**
         * What kind of failure the last send() was, when the mail server or the connection to it failed: `auth` (the user name
         * or password was refused), `tls` (a secure connection could not be made), `connect` (the server was not found or did
         * not answer), `sender` (it would not send from the From address), `rejected` (it refused the message), `temporary`
         * (try again later) or `unknown`; `unconfigured` when there is no From address to send from, and `message` when an
         * address or name of the message was refused. Empty after a send that worked, and for a refused setting.
         *
         * @return string
         */
        function failureKind()
        {
            return $this->failureKind;
        }

        /**
         * Why the last send() returned 0, in plain words for an administrator: one fixed sentence for the kind of failure, and
         * the mail server's own reply when it gave one, with the user name and password taken out and cut short. It never holds
         * the text of an exception, a user name, a password or the connection string. Show it to administrators only. Empty
         * after a send that worked.
         *
         * @return string
         */
        function explanation()
        {
            return $this->explanation;
        }

        /**
         * The security choices for the connection to the mail server with the usual port of each (the form fills the port in from
         * this when it is left empty)
         */
        const SECURITY_PORTS = \Idno\Mail\MailSettingsInput::SECURITY_PORTS;

        /**
         * Has the site been given a mail service to send through: a server and an address to send from? Without a server Idno
         * still tries one on this machine (port 25), which is rarely there, so pages that send mail warn when this is false.
         *
         * @return bool
         */
        static function isConfigured()
        {
            $config = \Idno\Core\Idno::site()->config();

            return !empty($config->smtp_host) && filter_var((string)$config->from_email, FILTER_VALIDATE_EMAIL) !== false;
        }

        /**
         * The stored security setting as one of the keys of SECURITY_PORTS
         *
         * @return string
         */
        static function security()
        {
            $secure = (string)\Idno\Core\Idno::site()->config()->smtp_secure;

            return in_array($secure, ['tls', 'ssl'], true) ? $secure : '';
        }

        /**
         * The name mail is sent from: the from_name setting, or the site's name
         *
         * @return string
         */
        static function fromName()
        {
            $config = \Idno\Core\Idno::site()->config();
            $name   = \Idno\Mail\MailDesign::headerText($config->from_name);

            return $name !== '' ? $name : \Idno\Mail\MailDesign::headerText($config->title);
        }

        /**
         * The address the links of a mail are built from: the site's configured `url` as written (its own scheme), never the
         * request's: a mail is read later, by someone else, and a reset link must not go out as http because whoever asked for the
         * reset did so over http. With no `url` configured the site detects its address from the request and that is used, as it
         * always was (set `url` in config.ini for the guarantee).
         *
         * @return string with a trailing slash
         */
        static function siteAddress()
        {
            $config     = \Idno\Core\Idno::site()->config();
            $configured = !empty($config->ini_config['url']);

            return rtrim((string)($configured ? $config->getURL() : $config->getDisplayURL()), '/') . '/';
        }

        /**
         * Text for a mail header (a subject, a name): one line, no control characters, so nothing a person typed can start a
         * header of its own.
         *
         * @param  mixed $text
         * @return string
         */
        static function headerText($text)
        {
            return \Idno\Mail\MailDesign::headerText($text);
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
            return $this->change(fn() => $this->message->subject(trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', static::headerText($subject)))));
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
         * Given the name of a template and a set of variables to include, generates an HTML body and adds it to the message.
         * The template is one of templates/email/, written with the parts of templates/email/parts/. What it says about the
         * mail with mail_describe() (the line a mail client shows beside the subject, and why the reader got it) goes to the
         * shell; setTextBodyFromTemplate() then gives the text the same reason.
         *
         * @param  $template_name
         * @param  array $vars
         * @param  array $shellVars Variables for the shell; they win over what the template says
         * @return mixed
         */
        function setHTMLBodyFromTemplate($template_name, $vars = array(), array $shellVars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email');
            \Idno\Mail\MailDesign::beginDescribing();
            try {
                $body        = $t->__($vars + ['mail_address' => static::siteAddress()])->draw($template_name);
                $this->about = \Idno\Mail\MailDesign::takeDescription();
            } finally {
                \Idno\Mail\MailDesign::beginDescribing();
            }

            return $this->setHTMLBody($body, true, $shellVars + $this->about);
        }

        /**
         * Sets the HTML body of the message (optionally setting it inside the email pageshell as defined by the email template)
         *
         * @param  $body      The formatted HTML body text of the message
         * @param  bool  $shell     Should the message be placed inside the pageshell? Default: true
         * @param  array $shellVars Variables to pass to the page shell template: 'title' (the subject, when it is not set on the
         *                          message yet), 'preheader' and 'reason', all plain text
         * @return mixed
         */
        function setHTMLBody($body, $shell = true, array $shellVars = [])
        {
            if ($shell) {
                $message = static::wrapHtml((string)$body, $shellVars + ['title' => (string)$this->message->getSubject()]);
            } else {
                $message = $body;
            }

            return $this->message->html($message);
        }

        /**
         * Set the text only component of an email, from a template of templates/email-text/. It gets the text footer
         * (templates/email-text/shell) with the reason the HTML template gave, when setHTMLBodyFromTemplate() drew it first.
         *
         * @param  type $template_name
         * @param  type $vars
         * @return mixed
         */
        function setTextBodyFromTemplate($template_name, $vars = array(), array $shellVars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email-text');
            $body = $t->__($vars + ['mail_address' => static::siteAddress()])->draw($template_name);

            return $this->setTextBody($body, true, $shellVars + $this->about);
        }

        /**
         * Sets the plain text body of the message
         *
         * @param  string $body      The body of the message
         * @param  bool   $shell     Add the text footer (the reason, the site's name and address)? Default: false
         * @param  array  $shellVars 'reason', plain text
         * @return mixed
         */
        function setTextBody($body, $shell = false, array $shellVars = [])
        {
            if ($shell) {
                $body = static::wrapText((string)$body, $shellVars);
            }

            return $this->message->text($body);
        }

        /**
         * Writes the whole mail from one name of the templates of templates/email/ (the HTML body) and templates/email-text/
         * (the plain text): both bodies in their shells, and the subject when the template names one. The two templates are
         * written with the same parts, so the two bodies of a mail say the same thing.
         *
         * @param  string $template_name
         * @param  array  $vars
         * @return $this
         */
        function setBodyFromTemplate($template_name, array $vars = [])
        {
            $mail = static::drawMail($template_name, $vars);
            if ($mail['subject'] !== '') {
                $this->setSubject($mail['subject']);
            }
            $this->message->html($mail['html']);
            if ($mail['text'] !== '') {
                $this->message->text($mail['text']);
            }

            return $this;
        }

        /**
         * Draws a mail without a message: its subject (what the template names, else empty), its HTML body in the shell and its
         * plain-text body in the shell. For a mail that is put together now and sent later, and for tests.
         *
         * @param  string $template_name
         * @param  array  $vars
         * @return string[] 'subject', 'html' and 'text'
         */
        static function drawMail($template_name, array $vars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email');
            \Idno\Mail\MailDesign::beginDescribing();
            try {
                $html  = (string)$t->__($vars + ['mail_address' => static::siteAddress()])->draw($template_name);
                $about = \Idno\Mail\MailDesign::takeDescription();
            } finally {
                \Idno\Mail\MailDesign::beginDescribing();
            }
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email-text');
            $text = (string)$t->__($vars + ['mail_address' => static::siteAddress()])->draw($template_name);

            return [
                'subject' => $about['subject'],
                'html'    => static::wrapHtml($html, ['title' => $about['subject'], 'preheader' => $about['preheader'], 'reason' => $about['reason']]),
                'text'    => $text === '' ? '' : static::wrapText($text, ['reason' => $about['reason']]),
            ];
        }

        /**
         * A body inside the HTML shell (templates/email/shell). Template variables stay set between draws, so every one the
         * shell reads is given.
         */
        private static function wrapHtml(string $body, array $shellVars): string
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email');
            // The layout is the site's own (its `language` setting, English when it has none), never the browser's of whoever asked for the mail:
            // a stranger who asks for someone's reset must not choose how that person's mail is laid out
            $code = preg_replace('/[^A-Za-z0-9-]/', '', str_replace('_', '-', (string)\Idno\Core\Idno::site()->config()->language));
            $code = $code ?: 'en';
            $vars = $shellVars + [
                'title'     => '',
                'preheader' => '',
                'reason'    => '',
                'lang'      => $code,
                'dir'       => in_array(strtolower(substr($code, 0, 2)), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr',
            ];

            return (string)$t->__(array_merge(['body' => $body, 'mail_address' => static::siteAddress()], $vars))->draw('shell');
        }

        /**
         * A plain-text body followed by the text footer (templates/email-text/shell): why the reader got the mail, and the
         * site's name and address.
         */
        private static function wrapText(string $body, array $shellVars): string
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email-text');

            return (string)$t->__(['body' => $body, 'mail_address' => static::siteAddress(), 'reason' => (string)($shellVars['reason'] ?? '')])->draw('shell');
        }

        /**
         * Send the message
         *
         * @return int The number of recipients (To and Bcc), or 0 if it could not be sent: lastFailure() and explanation() then say why
         */
        function send()
        {
            $this->lastFailure = null;
            $this->failureKind = '';
            $this->explanation = '';
            try {
                if ($this->refused) {
                    throw new \InvalidArgumentException('An address or name of the message was refused.');
                }

                $config = \Idno\Core\Idno::site()->config();

                // Set the "from" address
                if ($from_email = $config->from_email) {
                    $this->message->from(new Address((string)$from_email, static::fromName()));
                }
                // Replies go to the site's reply address unless the message names one
                $reply_to = (string)$config->reply_to_email;
                if ($reply_to !== '' && filter_var($reply_to, FILTER_VALIDATE_EMAIL) && !$this->message->getReplyTo()) {
                    $this->message->addReplyTo(new Address($reply_to));
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
                $this->explain($e);
                // Lets log errors rather than silently drop them. Only the class and the kind: the message of a
                // transport's exception can quote the DSN, and with it the SMTP password. This runs in
                // error handlers, so a logger that is not there must not make it worse.
                try {
                    \Idno\Core\Idno::site()->logging()->error('Error sending mail: ' . get_class($e) . ($this->failureKind !== '' ? ' (' . $this->failureKind . ')' : ''));
                } catch (\Throwable $ignored) {
                }
            }

            return 0;
        }

        /**
         * Works out the kind and the explanation of a failure of send(). It must never throw: it runs where a mail is sent from
         * an error handler.
         */
        private function explain(\Throwable $e): void
        {
            try {
                $language = \Idno\Core\Idno::site()->language();
                if ($e instanceof \Idno\Mail\InvalidMailSetting) {
                    $this->explanation = $language->_('The mail setting %s cannot be used. Check it on this page.', [$e->setting()]);

                    return;
                }
                if ($this->lastFailure === 'message') {
                    if (!$this->refused && !$this->message->getFrom() && !$this->message->getSender()) {
                        $this->failureKind = 'unconfigured';
                        $this->explanation = $language->_('The site has no address to send mail from. Set one in the email settings.');
                    } else {
                        $this->failureKind = 'message';
                        $this->explanation = $language->_('The message could not be sent: one of the addresses in it is not a valid email address.');
                    }

                    return;
                }

                $config = \Idno\Core\Idno::site()->config();
                $user   = (string)$config->smtp_username;
                $pass   = (string)$config->smtp_password;
                $found  = \Idno\Mail\MailFailure::classify($e, static::security());
                $this->failureKind = $found['kind'];
                $where  = $this->serverAddress();
                $host   = $where[0];
                $at     = $host . ', ' . $language->_('port %d', [$where[1]]);
                $reply  = \Idno\Mail\MailFailure::reply($e, $user, $pass);
                $said   = $reply !== '' ? ' ' . $language->_('The server said: %s', [$reply]) : '';

                $this->explanation = match ($found['kind'] . ':' . $found['variant']) {
                    'auth:' => $language->_('The mail server (%s) did not accept the user name and password. Check both: many services want a fixed user name and an API key as the password.', [$at]) . $said,
                    'tls:certificate' => $language->_("The mail server's security certificate could not be verified (%s). Check that the host name is exactly the one your mail service gives.", [$at]),
                    'tls:handshake' => $language->_('A secure connection to the mail server could not be made (%s). This port may expect STARTTLS instead of TLS: port 587 goes with STARTTLS, port 465 with TLS.', [$at]),
                    'tls:starttls' => $language->_('A secure connection to the mail server could not be made (%s). The server may not offer STARTTLS on this port: port 587 goes with STARTTLS, port 465 with TLS.', [$at]) . $said,
                    'connect:unknown-host' => $language->_('The mail server %s could not be found. Check the spelling of the host name.', [$host]),
                    'connect:refused' => $language->_('The mail server refused the connection (%s). Check the host name and the port.', [$at]),
                    'connect:no-answer' => $language->_('The mail server did not answer (%s). Check the host name and the port, and that the security setting matches the port: 587 goes with STARTTLS, 465 with TLS. Some web hosts also block outgoing mail ports.', [$at]),
                    'sender:' => $language->_('The mail server would not send from this address. The From address must be one your mail service has verified, or on a domain it is set up to send for.') . $said,
                    'rejected:' => $language->_('The mail server refused the message.') . $said,
                    'temporary:' => $language->_('The mail server could not take the message just now. Try again in a few minutes.') . $said,
                    default => $language->_('The mail could not be sent.'),
                };
            } catch (\Throwable $ignored) {
                $this->explanation = '';
            }
        }

        /**
         * The host and port mail is sent to, as the settings give them (what the DSN the mailer is built from holds)
         *
         * @return array{0: string, 1: int}
         */
        private function serverAddress(): array
        {
            $config = \Idno\Core\Idno::site()->config();
            $dsn    = parse_url(\Idno\Mail\MailTransportDsn::fromSettings([
                'smtp_host' => $config->smtp_host, 'smtp_port' => $config->smtp_port, 'smtp_secure' => $config->smtp_secure,
            ]));

            return [rawurldecode((string)($dsn['host'] ?? 'localhost')), (int)($dsn['port'] ?? 25)];
        }

    }

}
