<?php

namespace Tests\Pages\Onboarding {

    use Idno\Core\Idno;

    /**
     * A site does not ask a third party for "vendor messages", and nothing a third party says is
     * put into an administrator's session or page. The onboarding pages (since removed) used to POST the version to
     * idno.co and add the body of the reply to the session messages, which the page shell prints
     * as markup; the admin home page fetched the same text with a script and appended it as
     * markup too.
     *
     * Every request Idno makes goes to a mock client that records it (Webservice::useClient()),
     * so nothing leaves the machine, and the record shows whether any request was made.
     */
    class VendorMessagesTest extends \Tests\IdnoTestCase
    {
        /** @var list<string> the method and address of each request Idno made */
        private array $requests = [];

        protected function setUp(): void
        {
            parent::setUp();
            $this->requests = [];
            $record = function (string $method, string $url): \Symfony\Component\HttpClient\Response\MockResponse {
                $this->requests[] = $method . ' ' . $url;

                return new \Symfony\Component\HttpClient\Response\MockResponse('<script>x()</script>');
            };
            \Idno\Core\Webservice::useClient(
                new \Symfony\Component\HttpClient\MockHttpClient($record),
                new \Symfony\Component\HttpClient\MockHttpClient($record)
            );
            Idno::site()->session()->getAndFlushMessages();
        }

        protected function tearDown(): void
        {
            \Idno\Core\Webservice::useClient(null);
            parent::tearDown();
        }

        public function testThePageANewAdministratorLandsOnMakesNoRequestAndAddsNoMessage()
        {
            $admin = $this->admin();
            $previous = $this->swapUser($admin);

            try {
                // The page itself ends the process when it has drawn (Idno\Pages\Admin\Welcome): its body is drawn here.
                Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Welcome());
                $html = (string) Idno::site()->template()->__(['compose_url' => Idno::site()->config()->getDisplayURL() . 'status/edit', 'mail_is_set_up' => false, 'queue_is_on' => false])->draw('admin/welcome');

                $this->assertStringContainsString('Your site is ready', $html);
                $this->assertSame([], $this->requests, 'Finishing the installation must not contact any other site.');
                $this->assertSame([], Idno::site()->session()->getAndFlushMessages(), 'Finishing the installation must not add a message taken from another site.');
            } finally {
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
            $root = dirname(__DIR__, 3);
            foreach (['/templates/default/admin/home.html.twig', '/templates/classic/admin/home.tpl.php'] as $file) {
                $this->assertStringNotContainsString('service/vendor', file_get_contents($root . $file), $file);
            }
        }
    }

}
