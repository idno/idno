<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Idno;

    /**
     * The page a new administrator lands on does not ask a third party for text, and nothing a third party says is put into an administrator's
     * session or page (a session message is printed as markup).
     *
     * Every request Idno makes goes to a mock client that records it (Webservice::useClient()),
     * so nothing leaves the machine, and the record shows whether any request was made.
     */
    class WelcomePageTest extends \Tests\IdnoTestCase
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

        public function testTheWelcomePageMakesNoRequestToAnotherSiteAndAddsNoMessageFromOne()
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
    }

}
