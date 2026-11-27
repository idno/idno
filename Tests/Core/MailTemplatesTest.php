<?php

namespace Tests\Core {

    use Idno\Core\Email;
    use Idno\Core\Idno;

    /**
     * Every mail of core, put together as the site does it and read as a mail client reads
     * it: a subject with the site's name, an HTML body in the shared shell and the same mail
     * as text, links on the address the site is configured with, everything a person typed
     * escaped, and nothing to fetch. Nothing is sent.
     */
    class MailTemplatesTest extends \Tests\IdnoTestCase
    {

        use \Tests\MailAssertions;

        const SITE_URL = 'https://press.example/';
        const TITLE = 'Press <b>Room</b> & "Co"';
        const HOSTILE = 'Ada <img src=x onerror=alert(1)> "Q" & <script>alert(2)</script>';

        private $before = [];

        protected function setUp(): void
        {
            parent::setUp();
            $config = Idno::site()->config();
            foreach (['url', 'title', 'description', 'host'] as $name) {
                $this->before[$name] = $config->$name;
            }
            // The configured address is https; this process, like the queue's, is not
            $config->url = self::SITE_URL;
            $config->host = 'press.example';
            $config->title = self::TITLE;
            $config->description = 'A newsroom <i>for</i> students';
            unset($_SERVER['HTTPS']);
        }

        protected function tearDown(): void
        {
            $config = Idno::site()->config();
            foreach ($this->before as $name => $value) {
                $config->$name = $value;
            }
            parent::tearDown();
        }

        private function compose($template, array $vars = [])
        {
            $email = new Email();
            $email->addTo('reader@example.com');
            $email->setBodyFromTemplate($template, $vars);

            return $this->mailParts($email);
        }

        public static function mails(): array
        {
            $link = self::SITE_URL . 'account/register/?email=new%40example.com&code=' . str_repeat('ab', 32);
            $invite = ['link' => $link, 'email' => 'new@example.com', 'code' => str_repeat('ab', 32), 'inviter' => self::HOSTILE, 'is_admin' => false, 'expires' => strtotime('2026-12-09 12:00:00')];
            $error = "TypeError: strlen(): Argument #1 must be of type string in /var/www/Idno/Common/Page.php:310 <script>alert(3)</script>\n#0 {main}";

            return [
                'invitation, member' => ['account/invite', $invite, '/^You\'ve been invited to join Press/', [$link], ['invited you to join', 'Role: Member', 'Works until: 9 December 2026', 'new@example.com'], ''],
                'invitation, administrator' => ['account/invite', ['is_admin' => true, 'inviter' => ''] + $invite, '/^You\'ve been invited to join Press/', [$link], ['as an administrator', 'Role: Administrator', 'Works until: 9 December 2026'], ''],
                'password reset' => ['account/password', ['email' => 'sam+tag@example.com', 'code' => 'c0de'], '/^Reset your password for Press/', [self::SITE_URL . 'account/password/reset/?email=sam%2Btag%40example.com&code=c0de'], ['within three hours', "If you didn't ask for this, you can ignore this email"], ''],
                'test mail' => ['admin/emailtest', [], '/^Test email from Press/', [self::SITE_URL . 'admin/email/'], ['can send email'], ''],
                'export ready' => ['admin/export', [], '/^Your export of Press .* is ready$/', [self::SITE_URL . 'admin/export/'], ['one archive', 'keep it somewhere safe'], ''],
                'import done' => ['admin/import', [], '/^Your import into Press .* is complete$/', [self::SITE_URL], ['turned into posts'], ''],
                'import failed' => ['admin/import_failure', [], '/^Your import into Press .* did not finish$/', [self::SITE_URL . 'admin/import/'], ['could not be imported'], ''],
                'error' => [
                    'admin/oops',
                    ['site' => self::SITE_URL, 'title' => 'TypeError', 'message' => $error, 'user' => self::SITE_URL . 'profile/sam', 'agent' => 'Mozilla/5.0 ' . self::HOSTILE . ' AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'qs' => 'q=<script>alert(4)</script>&page=2', 'referrer' => 'https://elsewhere.example/"><script>alert(5)</script>'],
                    '/^Error on press\.example: TypeError$/', [], ['strlen(): Argument #1', 'Signed-in member: ' . self::SITE_URL . 'profile/sam', 'Query string: q=<script>alert(4)</script>&page=2'], $error,
                ],
            ];
        }

        /**
         * @dataProvider mails
         */
        public function testAMailIsWellMadeAndSaysWhatItShould($template, $vars, $subject, $links, $words, $verbatim)
        {
            $mail = $this->compose($template, $vars);

            $this->assertWellMadeMail($mail, self::SITE_URL, $template, $verbatim);
            $this->assertMatchesRegularExpression($subject, $mail['subject']);
            foreach ($links as $link) {
                $this->assertContains($link, $this->mailLinks($mail['html']), 'The HTML body links to ' . $link);
            }
            $flat = preg_replace('/\s+/', ' ', $mail['text']);
            $flatHtml = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags(str_replace(['</td>', '<br>'], [': ', ' '], $this->withoutComments($mail['html'])))), ENT_QUOTES);
            foreach ($words as $phrase) {
                $this->assertStringContainsString($phrase, $flat, 'The text body says: ' . $phrase);
                $this->assertStringContainsString(str_replace(': ', '', $phrase), str_replace(': ', '', $flatHtml), 'The HTML body says: ' . $phrase);
            }
        }

        /**
         * @dataProvider mails
         */
        public function testWhatPeopleTypedIsTextInTheHtmlBody($template, $vars)
        {
            $mail = $this->compose($template, $vars);

            foreach (['<b>', '<i>', '<img', '<script', 'onerror=alert(1)>'] as $markup) {
                $this->assertStringNotContainsString($markup, $this->withoutComments($mail['html']), $template . ': no markup from a value');
            }
            // The site's name, as text, in the header and the footer of the shell at least
            $this->assertGreaterThanOrEqual(2, substr_count($mail['html'], 'Press &lt;b&gt;Room&lt;/b&gt; &amp; &quot;Co&quot;'));
            // In the text body the same values are there as they were typed
            $this->assertStringContainsString(self::TITLE, preg_replace('/\s+/', ' ', $mail['text']));
            if (!empty($vars['inviter'])) {
                $this->assertStringContainsString('Ada &lt;img src=x onerror=alert(1)&gt; &quot;Q&quot; &amp; &lt;script&gt;alert(2)&lt;/script&gt;', $mail['html']);
                $this->assertStringContainsString(self::HOSTILE, preg_replace('/\s+/', ' ', $mail['text']));
            }
        }

        public function testNothingTypedCanStartAHeaderOrALineOfTheMailsOwn()
        {
            Idno::site()->config()->title = "Press\r\nBcc: thief@example.com\r\n\r\nRoom";
            $mail = $this->compose('account/invite', ['link' => self::SITE_URL . 'account/register/?code=1', 'email' => 'new@example.com', 'inviter' => "Ada\nYou got this email because you won", 'is_admin' => false, 'expires' => time()]);

            $this->assertSame("You've been invited to join Press Bcc: thief@example.com Room", $mail['subject']);
            $this->assertSame(1, substr_count($mail['text'], "\n-- \n"), 'One footer');
            $this->assertStringContainsString('Ada You got this email because you won has invited you', preg_replace('/\s+/', ' ', $mail['text']), 'A line break in a name is a space in the mail');
        }

        public function testAnInvitationSaysItsRoleAndWhenItStopsWorkingAndAResetWhatToDoIfYouDidNotAsk()
        {
            $vars = ['link' => self::SITE_URL . 'account/register/?code=1', 'email' => 'new@example.com', 'inviter' => 'Maya', 'expires' => strtotime('2026-12-09 12:00:00')];
            $member = $this->compose('account/invite', $vars + ['is_admin' => false]);
            $admin = $this->compose('account/invite', $vars + ['is_admin' => true]);

            $this->assertStringNotContainsStringIgnoringCase('administrator', $member['text'], 'A member is not told anything about administrators');
            $this->assertStringNotContainsStringIgnoringCase('administrator', strip_tags($this->withoutComments($member['html'])));
            $this->assertStringContainsString('as an administrator', preg_replace('/\s+/', ' ', $admin['text']));
            foreach ([$member, $admin] as $mail) {
                $this->assertStringContainsString('works until 9 December 2026', preg_replace('/\s+/', ' ', $mail['text']));
                $this->assertStringContainsString('The invitation works until 9 December 2026.', $mail['html'], 'The preheader says so too');
            }

            $reset = $this->compose('account/password', ['email' => 'sam@example.com', 'code' => 'c0de']);
            $this->assertStringContainsString('your password stays as it is', preg_replace('/\s+/', ' ', $reset['text']));
            $this->assertStringContainsString('your password stays as it is', $reset['html']);
        }

        public function testLinksUseTheConfiguredAddressNotTheRequest()
        {
            $_SERVER['HTTP_HOST'] = 'attacker.example';
            $_SERVER['REQUEST_URI'] = '/account/password?utm=x';
            try {
                foreach (['account/password' => ['email' => 'sam@example.com', 'code' => 'c0de'], 'admin/export' => []] as $template => $vars) {
                    $mail = $this->compose($template, $vars);
                    $this->assertStringNotContainsString('attacker.example', $mail['html'] . $mail['text']);
                    $this->assertStringNotContainsString('http://', $mail['text'], 'The site is https, whatever the request that sends the mail is');
                    $this->assertStringNotContainsString('utm', $mail['html'] . $mail['text'], 'No campaign or tracking parameter');
                }
            } finally {
                unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
            }
        }

        public function testABodyAPluginDrewItselfIsPutInTheShell()
        {
            $email = new Email();
            $email->setSubject('From a plugin');
            $email->setHTMLBody('<div align="center">An <em>old</em> body</div><br><hr/>');
            $email->setTextBody("An old body\n");
            $mail = $this->mailParts($email);

            $this->assertStringStartsWith('<!DOCTYPE html>', $mail['html']);
            $this->assertStringContainsString('<div align="center">An <em>old</em> body</div><br><hr/>', $mail['html'], 'The body is printed as the plugin drew it');
            $this->assertStringContainsString('<title>From a plugin</title>', $mail['html']);
            $this->assertStringNotContainsString('mso-hide: all', $mail['html'], 'No preheader unless the mail gives one');
            $this->assertSame("An old body\n", $mail['text'], 'A text body is sent as it was given');

            $email = new Email();
            $email->setHTMLBody('<p>Whole</p>', false);
            $this->assertSame('<p>Whole</p>', $this->mailParts($email)['html'], 'And without the shell when the plugin asks');

            // The older way to write a mail still works: a template of each type
            $email = new Email();
            $email->setSubject('Preheader <b>');
            $email->setHTMLBodyFromTemplate('admin/emailtest', [], ['preheader' => 'Seen <first> & "foremost"', 'reason' => 'Because <you> asked']);
            $html = $this->mailParts($email)['html'];
            $this->assertStringContainsString('Seen &lt;first&gt; &amp; &quot;foremost&quot;', $html);
            $this->assertStringContainsString('Because &lt;you&gt; asked', $html);
            $this->assertStringContainsString('<title>Preheader &lt;b&gt;</title>', $html);
        }

        public function testAMailWithAColourThatHasNoDarkOneIsRefused()
        {
            $email = new Email();
            $email->setBodyFromTemplate('admin/emailtest');
            $html = $this->mailParts($email)['html'];
            $this->assertDarkLookIsComplete($html, 'The test mail');

            foreach (['<p style="color: #123456;">', '<td bgcolor="#fafafa">', '<p class="idno-mail-text" style="color: #000000;">', '<td style="border-top: 1px solid #cccccc;">', '<p style="color: red;">'] as $lightOnly) {
                try {
                    $this->assertDarkLookIsComplete(str_replace('<h1 ', $lightOnly . '<h1 ', $html), 'A mail with ' . $lightOnly);
                } catch (\PHPUnit\Framework\ExpectationFailedException $refused) {
                    continue;
                }
                $this->fail('A colour that is light only was let through: ' . $lightOnly);
            }
        }

        public function testEachPartEscapesAndAVariableOfOnePartIsNotReadByTheNext()
        {
            $t = clone Idno::site()->template();
            $t->setTemplateType('email');

            $button = Email::part($t, 'button', ['label' => 'Go <now>', 'url' => self::SITE_URL . 'a?b=1&c=2']);
            $this->assertStringContainsString('Go &lt;now&gt;', $button);
            $this->assertStringContainsString('href="https://press.example/a?b=1&amp;c=2"', $button);
            $this->assertStringContainsString('<v:roundrect', $button, 'A shape for Outlook on Windows');
            $this->assertStringContainsString('Or copy this address into your browser:', $button, 'And the address in words, for any client that shows neither');
            $this->assertSame('', trim(Email::part($t, 'button', ['label' => 'Run', 'url' => 'javascript:alert(1)'])), 'No button for an address that is not http or https');
            $this->assertSame('', trim(Email::part($t, 'link', ['label' => 'Run', 'url' => 'data:text/html,x'])));
            $this->assertSame('', trim(Email::part($t, 'link', [])), 'The address of the button before is not read');

            $this->assertStringContainsString('one<br />' . "\n" . 'two &lt;b&gt;', Email::part($t, 'quote', ['text' => "one\ntwo <b>"]));
            $this->assertStringContainsString('<strong>A &amp; B</strong> said &lt;hello&gt; to <strong>C</strong>', Email::part($t, 'paragraph', ['format' => '%s said <hello> to %s', 'values' => ['A & B', 'C']]));
            $rows = Email::part($t, 'rows', ['rows' => ['Na<me' => 'Val<ue', 'Empty' => '']]);
            $this->assertStringContainsString('Na&lt;me', $rows);
            $this->assertStringContainsString('Val&lt;ue', $rows);
            $this->assertStringNotContainsString('Empty', $rows);
            $this->assertStringContainsString('&lt;pre&gt; a  b', Email::part($t, 'code', ['text' => '<pre> a  b']));

            $t->setTemplateType('email-text');
            $this->assertSame("Go:\nhttps://press.example/x\n\n", Email::part($t, 'button', ['label' => 'Go', 'url' => 'https://press.example/x']));
            $this->assertSame("> one\n> two\n\n", Email::part($t, 'quote', ['text' => "one\ntwo"]));
            $this->assertSame("Title\n=====\n\n", Email::part($t, 'heading', ['text' => "Title"]));
        }

        public function testTextIsWrappedByCharactersAndAnAddressIsLeftWhole()
        {
            $this->assertSame("ünïcödé ünïcödé\nünïcödé", Email::wrapText('ünïcödé ünïcödé ünïcödé', 16));
            $this->assertSame("See\nhttps://press.example/a/very/long/address\nnow", Email::wrapText('See https://press.example/a/very/long/address now', 20));
            $this->assertSame("> one two\n> three\n>\n> four", Email::wrapText("one two three\n\nfour", 11, '> '));
            $this->assertSame('https://press.example/admin/', Email::siteUrl('/admin/'));
        }

        /**
         * An address kept from an earlier request (a reported post's) is mailed with the
         * site's own scheme; a post whose address is on another site keeps its address.
         */
        public function testAnAddressOfThisSiteGetsTheConfiguredSchemeAndAnyOtherIsLeftAlone()
        {
            $this->assertSame('https://press.example/2026/a-post?x=1#annotation-2', Email::onSiteUrl('http://press.example/2026/a-post?x=1#annotation-2'));
            $this->assertSame('http://press.example.elsewhere.example/2026/a-post', Email::onSiteUrl('http://press.example.elsewhere.example/2026/a-post'));
            $this->assertSame('http://other.example/a-post', Email::onSiteUrl('http://other.example/a-post'));
        }
    }

}
