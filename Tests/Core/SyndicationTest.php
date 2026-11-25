<?php

namespace Tests\Core {

    use Idno\Core\Idno;

    /**
     * Publishing an entity syndicates it to the services chosen in the request for the current
     * page. Code that publishes outside a page (a script, a cron job, a queue worker) has no
     * current page: nothing is chosen, so nothing is syndicated, and publishing must still work.
     */
    class SyndicationTest extends \Tests\IdnoTestCase
    {

        /// The name of a syndication service registered for the tests
        const SERVICE = 'syndicationtest';

        /// The event names of the syndication requests made while a test ran
        public static $syndicated = [];

        private static $listening = false;

        private $services;
        private $currentPage;
        private $toDelete = [];

        /**
         * The property of the site that holds the current page.
         */
        private function currentPageProperty()
        {
            $property = new \ReflectionProperty(\Idno\Core\Idno::class, 'currentPage');
            $property->setAccessible(true);

            return $property;
        }

        protected function setUp(): void
        {
            $this->services = Idno::site()->syndication()->services;
            $this->currentPage = $this->currentPageProperty()->getValue(Idno::site());

            Idno::site()->syndication()->registerService(
                self::SERVICE, function () {
                    return true;
                }, ['note']
            );

            if (!self::$listening) {
                Idno::site()->events()->addListener(
                    'post/note/' . self::SERVICE, function (\Idno\Core\Event $event) {
                        SyndicationTest::$syndicated[] = 'post/note/' . SyndicationTest::SERVICE;
                    }
                );
                self::$listening = true;
            }
            self::$syndicated = [];
        }

        protected function tearDown(): void
        {
            Idno::site()->syndication()->services = $this->services;
            $this->currentPageProperty()->setValue(Idno::site(), $this->currentPage);

            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        /**
         * A note that is ready to be published.
         */
        private function note()
        {
            $entity = new \IdnoPlugins\Status\Status();
            $entity->body = 'Syndication test ' . md5(rand() . microtime(true));
            $entity->setAccess('PUBLIC');
            $this->toDelete[] = $entity;

            return $entity;
        }

        public function testPublishWithoutCurrentPage()
        {
            $old = $this->swapUser($this->user());
            $this->currentPageProperty()->setValue(Idno::site(), null);
            $this->assertFalse(Idno::site()->currentPage(), 'There should be no current page.');

            $this->assertTrue($this->note()->publish(), 'An entity should be published when there is no current page.');
            $this->assertSame([], self::$syndicated, 'Nothing should be syndicated when there is no page to choose services on.');

            $this->swapUser($old);
        }

        public function testPublishSyndicatesToServicesChosenOnCurrentPage()
        {
            $old = $this->swapUser($this->user());

            $page = new \Idno\Legacy\BarePage();
            $page->setInput('syndication', [self::SERVICE]);
            Idno::site()->setCurrentPage($page);

            $this->assertTrue($this->note()->publish(), 'The entity should be published.');
            $this->assertSame(['post/note/' . self::SERVICE], self::$syndicated, 'The entity should be syndicated to the service chosen on the page.');

            $this->swapUser($old);
        }

        public function testPublishSyndicatesNowhereWhenNothingIsChosen()
        {
            $old = $this->swapUser($this->user());

            Idno::site()->setCurrentPage(new \Idno\Legacy\BarePage());

            $this->assertTrue($this->note()->publish(), 'The entity should be published.');
            $this->assertSame([], self::$syndicated, 'Nothing should be syndicated when no service is chosen.');

            $this->swapUser($old);
        }

    }

}
