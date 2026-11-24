<?php

namespace Tests\Pages\User {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Idno\Pages\User\View;

    /**
     * A member's profile lists everything they have published, and links to their posts of each kind (/profile/<handle>/<kind>), so
     * that "this member's videos", say, is a page that can be linked to.
     */
    class ProfileContentTypesTest extends \Tests\IdnoTestCase
    {

        private $previousTemplateType;

        /// Entities to remove after each test
        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // Twenty26 only has templates of the "modern" type
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        }

        public function tearDown(): void
        {
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        public static function lookProvider(): array
        {
            return [
                'no theme' => ['default'],
                'Twenty26' => ['modern'],
            ];
        }

        /**
         * The category of a content type in addresses, or the test is skipped.
         */
        private function slugOf(string $class): string
        {
            $contentType = \Idno\Common\ContentType::getContentTypeObjectFromClass($class);
            if (!$contentType) {
                $this->markTestSkipped("This configuration has no content type for $class.");
            }

            return $contentType->getCategoryTitleSlug();
        }

        /**
         * Give the test user one published status update.
         */
        private function publishStatus(): void
        {
            $old = $this->swapUser($this->user());
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'A status update ' . md5(rand() . microtime(true));
            $status->setAccess('PUBLIC');
            $status->setPublishStatus('published');
            $this->assertNotEmpty($status->save());
            $this->toDelete[] = $status;
            $this->swapUser($old);
        }

        public function testLinksForTheKindsTheMemberHasPosted()
        {
            $user = $this->user();
            $base = Idno::site()->config()->getDisplayURL() . 'profile/' . $user->getHandle();
            $statuses = $this->slugOf('IdnoPlugins\Status\Status');
            $posts = $this->slugOf('IdnoPlugins\Text\Entry');

            $links = View::contentTypeLinks($user);
            $this->assertSame([$base], array_column($links, 'url'), 'A member with no posts has no kinds to link to.');
            $this->assertTrue($links[0]['active'], 'With no kind in the address, the profile itself is the current page.');

            $this->publishStatus();

            $links = View::contentTypeLinks($user);
            $this->assertSame([$base, $base . '/' . $statuses], array_column($links, 'url'));
            $this->assertNotContains($base . '/' . $posts, array_column($links, 'url'));
            $this->assertCount(1, array_filter(array_column($links, 'active')));
        }

        /**
         * Every content type's address leads back to that content type.
         */
        public function testAddressesLeadToTheirContentType()
        {
            $registered = \Idno\Common\ContentType::getRegistered();
            $this->assertNotEmpty($registered, 'The test configuration should have content plugins.');
            foreach ($registered as $contentType) {
                $this->assertSame(
                    $contentType->getEntityClass(),
                    \Idno\Common\ContentType::categoryTitleToClass($contentType->getCategoryTitleSlug())
                );
            }
        }

        public function testCurrentKindIsMarkedAndAlwaysListed()
        {
            $user = $this->user();
            // The member has no posts of this kind
            $slug = $this->slugOf('IdnoPlugins\Text\Entry');

            foreach ([[$slug], [strtoupper($slug)]] as $current) {
                $active = array_values(array_filter(View::contentTypeLinks($user, $current), function ($link) {
                    return $link['active'];
                }));
                $this->assertCount(1, $active);
                $this->assertStringEndsWith('/' . $slug, $active[0]['url']);
            }

            $active = array_values(array_filter(View::contentTypeLinks($user, ['all']), function ($link) {
                return $link['active'];
            }));
            $this->assertCount(1, $active);
            $this->assertSame(Idno::site()->config()->getDisplayURL() . 'profile/' . $user->getHandle(), $active[0]['url'], '/all is the profile itself.');
        }

        public function testNoLinksWithoutAMember()
        {
            $this->assertSame([], View::contentTypeLinks(null));
            $this->assertSame([], View::contentTypeLinks('testuser'));
            $this->assertSame([], View::contentTypeLinks(new \Idno\Entities\User()));
        }

        /**
         * @dataProvider lookProvider
         */
        public function testLinksAreDrawn($look)
        {
            $user = $this->user();
            $this->publishStatus();
            $slug = $this->slugOf('IdnoPlugins\Status\Status');
            $address = Idno::site()->config()->getDisplayURL() . 'profile/' . $user->getHandle() . '/' . $slug;

            $t = Idno::site()->template();
            $t->setTemplateType($look);
            $html = (string) $t->__(['user' => $user, 'content' => [$slug]])->draw('entity/User/profile/types');

            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
            libxml_clear_errors();

            $current = [];
            $hrefs = [];
            foreach ($doc->getElementsByTagName('a') as $a) {
                $hrefs[] = $a->getAttribute('href');
                if ($a->getAttribute('aria-current') === 'page') {
                    $current[] = $a->getAttribute('href');
                }
            }
            $this->assertContains($address, $hrefs);
            $this->assertSame([$address], $current, 'The kind being shown should be marked as the current page.');
            $this->assertSame(array_column(View::contentTypeLinks($user), 'url'), $hrefs);
            $this->assertCount(2, $hrefs);
        }

        /**
         * Over HTTP: the profile has the links, and each of them loads.
         */
        public function testProfilePageLinksToEachKind()
        {
            $user = $this->user();
            $this->publishStatus();
            $links = View::contentTypeLinks($user);

            $profile = Webservice::get($links[0]['url']);
            $this->assertEquals(200, $profile['response'], 'The profile should load. If this is failing, you may need to set IDNO_DOMAIN.');
            foreach ($links as $link) {
                $this->assertStringContainsString('href="' . htmlspecialchars($link['url']) . '"', (string) $profile['content']);
            }

            foreach (array_slice($links, 1) as $link) {
                $page = Webservice::get($link['url']);
                $this->assertEquals(200, $page['response'], $link['url'] . ' should load.');
                $this->assertStringContainsString('aria-current="page"', (string) $page['content']);
            }
        }
    }
}
