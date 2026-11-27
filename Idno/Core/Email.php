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
         * The template is one of templates/email/, written with the parts of templates/email/parts/
         * (see part()). What it says about itself with describe() goes to the shell: the
         * preheader (the line a mail client shows beside the subject) and the reason (the
         * footer's "You got this email because ...").
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
            static::$described = [];
            $body = $t->__($vars)->draw($template_name);
            $shellVars += static::$described;
            static::$described = [];

            return $this->setHTMLBody($body, true, $shellVars);
        }

        /**
         * Sets the HTML body of the message (optionally setting it inside the email pageshell as defined by the email template)
         *
         * @param  $body      The formatted HTML body text of the message
         * @param  bool  $shell     Should the message be placed inside the pageshell? Default: true
         * @param  array $shellVars Variables to pass to the page shell template: 'title' (the
         *                          subject, when it is not set on the message yet), 'preheader'
         *                          and 'reason', all plain text
         * @return mixed
         */
        function setHTMLBody($body, $shell = true, array $shellVars = [])
        {
            if ($shell) {
                $t = clone \Idno\Core\Idno::site()->template();
                $t->setTemplateType('email');
                // Template variables stay set between draws, so every one the shell reads is given
                $shellVars += ['title' => (string) $this->message->getSubject(), 'preheader' => '', 'reason' => ''];
                $message = $t->__(array_merge(array('body' => $body), $shellVars))->draw('shell');
            } else {
                $message = $body;
            }

            return $this->message->setBody($message, 'text/html');
        }

        /**
         * Set the text only component of an email, from a template of templates/email-text/.
         * It gets the text footer (templates/email-text/shell) with the reason the template
         * gave with describe().
         *
         * @param  type $template_name
         * @param  type $vars
         * @return mixed
         */
        function setTextBodyFromTemplate($template_name, $vars = array(), array $shellVars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email-text');
            static::$described = [];
            $body = $t->__($vars)->draw($template_name);
            $shellVars += static::$described;
            static::$described = [];

            return $this->setTextBody($body, true, $shellVars);
        }

        /**
         * Sets the plain text body of the message
         *
         * @param  string $body      The body of the message
         * @param  bool   $shell     Add the text footer (the reason and the site's name and address)? Default: false
         * @param  array  $shellVars 'reason', plain text
         * @return mixed
         */
        function setTextBody($body, $shell = false, array $shellVars = [])
        {
            if ($shell) {
                $t = clone \Idno\Core\Idno::site()->template();
                $t->setTemplateType('email-text');
                $body = $t->__(array_merge(['body' => $body, 'reason' => ''], $shellVars))->draw('shell');
            }

            return $this->message->addPart($body, 'text/plain');
        }

        /**
         * Write the whole mail from one template of templates/email/: the HTML body, the text
         * body and, when the template names one with describe(), the subject.
         *
         * The template is drawn twice, and the parts it is written with (see part()) come out
         * as HTML the first time and as plain text the second, so the two bodies of a mail
         * always say the same. The template must therefore print nothing but parts.
         *
         * @param  string $template_name
         * @param  array  $vars
         * @return $this
         */
        function setBodyFromTemplate($template_name, array $vars = [])
        {
            $mail = static::drawBodies($template_name, $vars);
            if ((string) $mail['subject'] !== '') {
                $this->setSubject($mail['subject']);
            }
            $this->setHTMLBody($mail['html'], true, ['preheader' => $mail['preheader'], 'reason' => $mail['reason']]);
            $this->setTextBody($mail['text'], true, ['reason' => $mail['reason']]);

            return $this;
        }

        /**
         * Draw a template of templates/email/ as both bodies of a mail, without their shells
         * (see setBodyFromTemplate()): for a mail that is put together now and sent later.
         *
         * @param  string $template_name
         * @param  array  $vars
         * @return string[] 'html', 'text', and what the template said with describe(): 'subject', 'preheader', 'reason'
         */
        static function drawBodies($template_name, array $vars = [])
        {
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('email');

            static::$described = [];
            static::$partType = 'email';
            try {
                $html = $t->__($vars)->draw($template_name);
                $about = static::$described;
                static::$partType = 'email-text';
                $text = $t->draw($template_name);
            } finally {
                static::$partType = null;
                static::$described = [];
            }

            return ['html' => $html, 'text' => $text] + $about + ['subject' => '', 'preheader' => '', 'reason' => ''];
        }

        /// The template type parts are drawn with while setBodyFromTemplate() is at work
        protected static $partType = null;

        /**
         * The colours of a mail, by what they are for, in the light look and in the dark one.
         * The light ones are the modern look's (Themes/Twenty26/src/css/tokens.css); that
         * look has no dark variant of its own, so the dark ones are made here, from its dark
         * surface (the administration bar, #111111) and the same greys turned over.
         *
         * A mail client shows one of four things: the light colours; the dark ones (it
         * honours prefers-color-scheme); the light ones with every colour turned over; or the
         * light ones with only light backgrounds and dark text turned over. All four must be
         * readable, so: no colour is pure black or pure white on its own (a label and its
         * background are always set together, on the same element), every text sits on a
         * background this palette names, and the button has a border that is still there when
         * its background meets a card that has turned dark.
         *
         * An element is given its colours inline (colour()) and the class of its role
         * (idno-mail-<role>), and the shell writes the dark colours of every role into its
         * style block (darkCss()). Tests\MailAssertions refuses a mail with a colour that
         * has no role. Each role lists the CSS properties it sets.
         */
        const PALETTE = [
            'bg'        => ['background-color' => ['#f3f4f6', '#121214']],
            'card'      => ['background-color' => ['#ffffff', '#1d1d20'], 'border-color' => ['#e5e7eb', '#3a3a40']],
            'strong'    => ['color' => ['#111111', '#f4f4f5']],
            'text'      => ['color' => ['#374151', '#d4d4d8']],
            'muted'     => ['color' => ['#6b7280', '#a1a1aa']],
            'footer'    => ['color' => ['#4b5563', '#a1a1aa']],
            // Darker than the look's own link blue (#2563eb), which turned over is too dim on a card turned black
            'link'      => ['color' => ['#1e40af', '#93b4fd']],
            'button'    => ['background-color' => ['#111111', '#f4f4f5'], 'border-color' => ['#6b7280', '#f4f4f5'], 'color' => ['#ffffff', '#111111']],
            'box'       => ['background-color' => ['#f9fafb', '#27272b'], 'border-color' => ['#d1d5db', '#4b4b52'], 'color' => ['#374151', '#e4e4e7']],
            'rule'      => ['border-color' => ['#e5e7eb', '#3a3a40']],
            'preheader' => ['color' => ['#f3f4f6', '#121214']],
        ];

        /**
         * Which text is read on which background, by role: what must have enough contrast, in
         * both looks (Tests/Core/MailDarkModeTest.php)
         */
        const READ_ON = [
            'strong' => ['bg', 'card'], 'text' => ['card'], 'muted' => ['card'], 'footer' => ['bg'],
            'link' => ['card'], 'button' => ['button'], 'box' => ['box'],
        ];

        /**
         * A light colour of the palette, for an inline style
         *
         * @param  string $role
         * @param  string $property 'color', 'background-color' or 'border-color'
         * @return string
         */
        static function colour($role, $property = 'color')
        {
            return self::PALETTE[$role][$property][0];
        }

        /**
         * The style rules that give every role its dark colours: for mail clients that honour
         * prefers-color-scheme, and for Outlook.com and the Outlook apps, which rewrite a
         * mail's colours themselves and mark the elements they changed (data-ogsc for text,
         * data-ogsb for backgrounds), so that there too the mail has the colours chosen here.
         *
         * @return string
         */
        static function darkCss()
        {
            $media = '';
            $outlook = '';
            foreach (self::PALETTE as $role => $properties) {
                $rules = '';
                foreach ($properties as $property => $colours) {
                    $rules .= ' ' . $property . ': ' . $colours[1] . ' !important;';
                    $hook = $property === 'color' ? 'data-ogsc' : 'data-ogsb';
                    $outlook .= '    [' . $hook . '] .idno-mail-' . $role . ' { ' . $property . ': ' . $colours[1] . " !important; }\n";
                }
                $media .= '        .idno-mail-' . $role . ' {' . $rules . " }\n";
            }

            return "    @media (prefers-color-scheme: dark) {\n" . $media . "    }\n" . $outlook;
        }

        /// The typefaces of a mail: the ones the modern look uses, as far as mail clients have them
        const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

        /// The typefaces of preformatted text in a mail
        const FONT_MONO = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";

        /// The width plain-text mail is wrapped to
        const TEXT_WIDTH = 72;

        /// What the template being drawn said about its mail (see describe())
        protected static $described = [];

        /**
         * Called by a mail's template to say what the shell should know about the mail.
         *
         * @param array $about 'subject' (used by setBodyFromTemplate()); 'preheader': a line shown beside the subject in the list of a mail
         *                     client, and hidden in the mail itself; 'reason': why the reader
         *                     got the mail, for the footer. Both plain text.
         */
        static function describe(array $about)
        {
            static::$described = array_intersect_key($about, ['subject' => 1, 'preheader' => 1, 'reason' => 1]) + static::$described;
        }

        /**
         * Draw one part of a mail: templates/email/parts/<name> for the HTML body and
         * templates/email-text/parts/<name> for the text body, which take the same variables,
         * all of them plain text that the part escapes:
         *
         * - heading: 'text'
         * - paragraph: 'text', or 'format' and 'values' (a sentence with %s for each value; the
         *   values are set in bold); 'muted' for a smaller, quieter paragraph
         * - button: 'label', 'url' (the main thing to do; the address is also printed, to copy)
         * - link: 'label', 'url' (a second, quieter link)
         * - quote: 'text' (words somebody typed)
         * - rows: 'rows', a list of label => value
         * - code: 'text' (preformatted: an error message)
         *
         * Each part is drawn with a copy of the template, so a variable given to one part is
         * never read by the next.
         *
         * @param  \Idno\Core\Bonita\Templates $t    The template that is drawing the mail ($t in a template)
         * @param  string                      $name
         * @param  array                       $vars
         * @return string
         */
        static function part($t, $name, array $vars = [])
        {
            $part = clone $t;
            if (static::$partType !== null) {
                $part->setTemplateType(static::$partType);
            }
            $defaults = ['text' => '', 'format' => '', 'values' => [], 'muted' => false, 'label' => '', 'url' => '', 'rows' => []];

            return $part->__($vars + $defaults)->draw('parts/' . $name);
        }

        /**
         * An address on this site, from the address the site is configured with (never from
         * the request that happens to be sending the mail, which may be the queue's).
         *
         * @param  string $path A path on the site, without the first slash
         * @return string
         */
        static function siteUrl($path = '')
        {
            return rtrim((string) \Idno\Core\Idno::site()->config()->getURL(), '/') . '/' . ltrim((string) $path, '/');
        }

        /**
         * An address that was built somewhere else (the address of a post, kept when
         * something was reported, say), as a mail gives it: an address of this site gets the
         * scheme the site is configured with, because it may have been built during a
         * request with another one. An address that is not on this site is returned as it is.
         *
         * @param  string $url
         * @return string
         */
        static function onSiteUrl($url)
        {
            $url = (string) $url;
            $site = static::siteUrl();
            if (!preg_match('#^https?:(//.+)$#i', $site, $configured) || !preg_match('#^https?:(//.+)$#i', $url, $given)) {
                return $url;
            }
            if (stripos($given[1], $configured[1]) !== 0) {
                return $url;
            }

            return $site . substr($given[1], strlen($configured[1]));
        }

        /**
         * The name of the site for a mail: its title, or its host name when it has none
         *
         * @return string
         */
        static function siteName()
        {
            $config = \Idno\Core\Idno::site()->config();
            $name = static::oneLine($config->title);

            return $name !== '' ? $name : (string) $config->host;
        }

        /**
         * A value as one line of plain text: no line breaks or control characters, so nothing
         * a person typed can look like a line of the mail's own.
         *
         * @param  string $text
         * @return string
         */
        static function oneLine($text)
        {
            return trim(preg_replace('/\s+/u', ' ', static::headerText($text)) ?? '');
        }

        /**
         * Put values into a sentence. In HTML the sentence and the values are escaped and the
         * values are set in bold; in text they are put in as they are.
         *
         * @param  string   $format A sentence with %s for each value
         * @param  string[] $values
         * @param  bool     $html
         * @return string
         */
        static function emphasise($format, array $values, $html = true)
        {
            $values = array_map([static::class, 'oneLine'], $values);
            if (!$html) {
                return vsprintf((string) $format, $values);
            }
            $values = array_map(
                function ($value) {
                    return '<strong>' . htmlspecialchars($value) . '</strong>';
                }, $values
            );

            return vsprintf(htmlspecialchars((string) $format, ENT_QUOTES), $values);
        }

        /**
         * Wrap plain text for a mail. Lines are counted in characters, not bytes, and a word
         * longer than a line (an address) is left whole on a line of its own.
         *
         * @param  string $text
         * @param  int    $width
         * @param  string $prefix Put before every line ("> " for a quotation)
         * @return string
         */
        static function wrapText($text, $width = self::TEXT_WIDTH, $prefix = '')
        {
            $width -= mb_strlen($prefix);
            $lines = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $paragraph) {
                $line = '';
                foreach (preg_split('/[ \t]+/', trim($paragraph)) as $word) {
                    if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($word) > $width) {
                        $lines[] = $line;
                        $line = $word;
                    } else {
                        $line = $line === '' ? $word : $line . ' ' . $word;
                    }
                }
                $lines[] = $line;
            }

            return implode(
                "\n", array_map(
                    function ($line) use ($prefix) {
                        return rtrim($prefix . $line);
                    }, $lines
                )
            );
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
