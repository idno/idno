<?php

namespace Tests\Pages\Onboarding {

    use Idno\Core\Idno;

    /**
     * A site does not ask a third party for "vendor messages", and nothing a third party says is
     * put into an administrator's session or page. Onboarding used to POST the version to
     * idno.co and add the body of the reply to the session messages, which the page shell prints
     * as markup; the admin home page fetched the same text with a script and appended it as
     * markup too.
     *
     * Every request Idno makes is sent to a local server (proxy_string), so nothing leaves the
     * machine, and the server's log shows whether any request was made.
     */
    class VendorMessagesTest extends \Tests\OutboundHttpTestCase
    {

        protected function setUp(): void
        {
            parent::setUp();
            $this->useLocalServerAsProxy();
            Idno::site()->session()->getAndFlushMessages();
        }

        public function testFinishingOnboardingMakesNoRequestAndAddsNoMessage()
        {
            $admin = $this->admin();
            $previous = $this->swapUser($admin);
            $noping = Idno::site()->config()->noping ?? null;
            Idno::site()->config()->noping = false;

            try {
                $page = new \Idno\Pages\Onboarding\Publish();
                Idno::site()->setCurrentPage($page);
                // Don't send a redirect from inside PHPUnit
                $forward = new \ReflectionProperty(\Idno\Common\Page::class, 'forward');
                $forward->setAccessible(true);
                $forward->setValue($page, false);
                $page->getContent();

                $this->assertSame([], static::$server->requests(), 'Onboarding must not contact any other site.');
                $this->assertSame([], Idno::site()->session()->getAndFlushMessages(), 'Onboarding must not add a message taken from another site.');
            } finally {
                Idno::site()->config()->noping = $noping;
                $this->swapUser($previous);
            }
        }

        public function testThereIsNoVendorMessageClassOrRoute()
        {
            $this->assertFalse(class_exists('\Idno\Core\Vendor'), 'The vendor messages client has been removed.');
            $this->assertFalse(class_exists('\Idno\Pages\Service\Vendor\Messages'), 'The vendor messages page has been removed.');
        }

        public function testAdminHomeDoesNotFetchVendorMessages()
        {
            $template = file_get_contents(dirname(__DIR__, 3) . '/templates/default/admin/home.tpl.php');
            $this->assertStringNotContainsString('service/vendor', $template);
        }
    }

}
