<?php

namespace IdnoPlugins\Portfolios\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * The Portfolio entity: a named, ordered list of its owner's posts.
     */
    class PortfolioTest extends \Tests\IdnoTestCase
    {

        private static $alice;
        private static $bob;
        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            foreach (['alice', 'bob'] as $who) {
                $user = new User();
                $user->handle = 'pf' . $who . substr(md5(rand() . microtime(true)), 0, 8);
                $user->email = $user->handle . '@example.com';
                $user->setPassword(bin2hex(random_bytes(12)));
                $user->setTitle(ucfirst($who));
                $user->save(true);
                static::$$who = $user;
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (['alice', 'bob'] as $who) {
                foreach (\Idno\Common\Entity::getFromAll(['owner' => static::$$who->getUUID()], [], 500) ?: [] as $entity) {
                    $entity->delete();
                }
                static::$$who->delete();
                static::$$who = null;
            }
            Idno::site()->db()->setIgnoreAccess(false);
            parent::tearDownAfterClass();
        }

        public function tearDown(): void
        {
            Idno::site()->session()->logUserOff();
        }

        private function portfolio(User $owner, $name = 'Clips', $status = 'published'): Portfolio
        {
            $old = $this->swapUser($owner);
            $portfolio = new Portfolio();
            $this->assertTrue($portfolio->setName($name));
            $portfolio->setPublishStatus($status);
            $this->assertNotEmpty($portfolio->save());
            $this->swapUser($old);

            return $portfolio;
        }

        private function post(User $owner, $access = 'PUBLIC', $status = 'published')
        {
            $old = $this->swapUser($owner);
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'Post ' . md5(rand() . microtime(true));
            $post->setAccess($access);
            $post->setPublishStatus($status);
            $this->assertNotEmpty($post->save());
            $this->swapUser($old);

            return $post;
        }

        private function uuids(array $entities): array
        {
            return array_values(array_map(function ($entity) {
                return $entity->getUUID();
            }, $entities));
        }

        public function testAddressIsUnderTheOwnersProfileWithASlugFromTheName()
        {
            $portfolio = $this->portfolio(static::$alice, 'My Best  Work!');
            $base = Idno::site()->config()->getDisplayURL() . 'profile/' . static::$alice->getHandle();

            $this->assertSame($base . '/portfolio/my-best-work', $portfolio->getURL());
            $this->assertSame($base . '/portfolios', Portfolio::listURL(static::$alice));
            $this->assertSame(Idno::site()->config()->getDisplayURL() . 'portfolio/edit/' . $portfolio->getID(), $portfolio->getEditURL());
            $this->assertSame(Idno::site()->config()->getDisplayURL() . 'portfolio/delete/' . $portfolio->getID(), $portfolio->getDeleteURL());
        }

        public function testSlugStaysWhenTheNameChanges()
        {
            $portfolio = $this->portfolio(static::$alice, 'First name');
            $url = $portfolio->getURL();

            $this->swapUser(static::$alice);
            $portfolio->setName('Second name');
            $portfolio->save();

            $again = Portfolio::findFor(static::$alice, 'first-name');
            $this->assertNotEmpty($again);
            $this->assertSame('Second name', $again->getTitle());
            $this->assertSame($url, $again->getURL());
        }

        public function testSameNameTwiceGetsDistinctSlugsAndOtherMembersDoNotClash()
        {
            $one = $this->portfolio(static::$alice, 'Twins');
            $two = $this->portfolio(static::$alice, 'Twins');
            $other = $this->portfolio(static::$bob, 'Twins');

            $this->assertStringEndsWith('/portfolio/twins', $one->getURL());
            $this->assertStringEndsWith('/portfolio/twins-2', $two->getURL());
            $this->assertStringEndsWith('/portfolio/twins', $other->getURL());

            $this->assertSame($two->getUUID(), Portfolio::findFor(static::$alice, 'twins-2')->getUUID());
            $this->assertSame($other->getUUID(), Portfolio::findFor(static::$bob, 'twins')->getUUID());
        }

        public function testANameWithNoUsableLettersIsReachedByItsId()
        {
            $portfolio = $this->portfolio(static::$alice, "\u{1F3A5}\u{1F3AC}");

            $this->assertStringEndsWith('/portfolio/' . $portfolio->getID(), $portfolio->getURL());
            $this->assertSame($portfolio->getUUID(), Portfolio::findFor(static::$alice, (string) $portfolio->getID())->getUUID());
        }

        public function testAPortfolioIsFoundOnlyUnderItsOwnOwner()
        {
            $portfolio = $this->portfolio(static::$alice, 'Only mine');

            $this->assertNull(Portfolio::findFor(static::$bob, 'only-mine'));
            $this->assertNull(Portfolio::findFor(static::$bob, (string) $portfolio->getID()));
        }

        public function testFindForDoesNotReturnOtherKindsOfEntity()
        {
            $post = $this->post(static::$alice);

            $this->assertNull(Portfolio::findFor(static::$alice, (string) $post->getID()));
        }

        public function testNameAndDescriptionAreTrimmedAndCapped()
        {
            $portfolio = new Portfolio();

            $this->assertFalse($portfolio->setName("  \n "), 'A name is required.');
            $this->assertTrue($portfolio->setName('  ' . str_repeat('é', 150) . '  '));
            $this->assertSame(Portfolio::MAX_NAME, mb_strlen($portfolio->getTitle()));
            $this->assertTrue($portfolio->setName("Two\nlines\tand tabs"));
            $this->assertSame('Two lines and tabs', $portfolio->getTitle());

            $portfolio->setDescriptionText('  ' . str_repeat('x', 3000));
            $this->assertSame(Portfolio::MAX_DESCRIPTION, mb_strlen($portfolio->getDescriptionText()));
        }

        public function testItemsKeepTheOwnersOrderAndCanBeMoved()
        {
            $portfolio = $this->portfolio(static::$alice, 'Ordered');
            $a = $this->post(static::$alice);
            $b = $this->post(static::$alice);
            $c = $this->post(static::$alice);

            $this->swapUser(static::$alice);
            foreach ([$a, $b, $c] as $post) {
                $this->assertTrue($portfolio->addItem($post));
            }
            $this->assertFalse($portfolio->addItem($a), 'A post is in a portfolio once.');
            $portfolio->save();

            $stored = Portfolio::findFor(static::$alice, 'ordered');
            $this->assertSame($this->uuids([$a, $b, $c]), $stored->getItemUUIDs());

            $this->assertTrue($stored->moveItem($c->getUUID(), -1));
            $this->assertSame($this->uuids([$a, $c, $b]), $stored->getItemUUIDs());
            $this->assertFalse($stored->moveItem($a->getUUID(), -1), 'The first item cannot move up.');
            $this->assertTrue($stored->moveItem($a->getUUID(), 1));
            $this->assertSame($this->uuids([$c, $a, $b]), $stored->getItemUUIDs());
            $this->assertFalse($stored->moveItem($b->getUUID(), 1), 'The last item cannot move down.');
            $this->assertFalse($stored->moveItem('https://example.com/nothing', 1));

            $this->assertTrue($stored->removeItem($a->getUUID()));
            $this->assertFalse($stored->removeItem($a->getUUID()));
            $this->assertSame($this->uuids([$c, $b]), $stored->getItemUUIDs());
            $this->assertSame($this->uuids([$c, $b]), $this->uuids($stored->getVisibleItems()));
        }

        public function testOnlyTheOwnersOwnPostsGoIn()
        {
            $portfolio = $this->portfolio(static::$alice, 'Mine only');
            $bobs = $this->post(static::$bob);

            $this->swapUser(static::$alice);
            $this->assertFalse($portfolio->addItem($bobs));
            $this->assertFalse($portfolio->addItem(static::$bob), 'A member is not a post.');
            $this->assertFalse($portfolio->addItem($this->portfolio(static::$alice, 'Nested')), 'A portfolio does not go into a portfolio.');
            $this->assertSame([], $portfolio->getItemUUIDs());
        }

        public function testHiddenPostsAreNotShownOrCounted()
        {
            $portfolio = $this->portfolio(static::$alice, 'Mixed');
            $public = $this->post(static::$alice);
            $private = $this->post(static::$alice, static::$alice->getUUID());
            $draft = $this->post(static::$alice, 'PUBLIC', 'draft');

            $this->swapUser(static::$alice);
            foreach ([$private, $public, $draft] as $post) {
                $this->assertTrue($portfolio->addItem($post));
            }
            $portfolio->save();
            $this->assertSame(3, $portfolio->countVisibleItems(), 'The owner sees everything.');

            foreach ([null, static::$bob] as $viewer) {
                $this->swapUser($viewer);
                $seen = Portfolio::findFor(static::$alice, 'mixed');
                $this->assertSame($this->uuids([$public]), $this->uuids($seen->getVisibleItems()));
                $this->assertSame(1, $seen->countVisibleItems());
            }
        }

        public function testAPostMadePrivateLaterDisappearsForVisitors()
        {
            $portfolio = $this->portfolio(static::$alice, 'Later');
            $post = $this->post(static::$alice);

            $this->swapUser(static::$alice);
            $portfolio->addItem($post);
            $portfolio->save();
            $post->setAccess(static::$alice->getUUID()); // Private
            $post->save();

            $this->swapUser(null);
            $this->assertSame(0, Portfolio::findFor(static::$alice, 'later')->countVisibleItems());
            $this->swapUser(static::$alice);
            $this->assertSame(1, Portfolio::findFor(static::$alice, 'later')->countVisibleItems());
        }

        public function testDraftsAreForTheOwnerAndAdministrators()
        {
            $draft = $this->portfolio(static::$alice, 'Hidden draft', 'draft');
            $published = $this->portfolio(static::$alice, 'Shown');

            foreach ([null, static::$bob] as $viewer) {
                $this->swapUser($viewer);
                $this->assertNull(Portfolio::findFor(static::$alice, 'hidden-draft'));
                $this->assertNull(Portfolio::findFor(static::$alice, (string) $draft->getID()));
                $listed = $this->uuids(Portfolio::forOwner(static::$alice));
                $this->assertContains($published->getUUID(), $listed);
                $this->assertNotContains($draft->getUUID(), $listed);
            }

            foreach ([static::$alice, $this->admin()] as $viewer) {
                $this->swapUser($viewer);
                $this->assertNotNull(Portfolio::findFor(static::$alice, 'hidden-draft'));
                $this->assertContains($draft->getUUID(), $this->uuids(Portfolio::forOwner(static::$alice)));
            }
        }

        public function testAPostCanBeInSeveralPortfoliosAndTheyAreFoundFromThePost()
        {
            $one = $this->portfolio(static::$alice, 'Holder one');
            $two = $this->portfolio(static::$alice, 'Holder two', 'draft');
            $three = $this->portfolio(static::$alice, 'Holder three');
            $post = $this->post(static::$alice);

            $this->swapUser(static::$alice);
            foreach ([$one, $two] as $portfolio) {
                $portfolio->addItem($post);
                $portfolio->save();
            }

            $found = $this->uuids(Portfolio::containing($post->getUUID()));
            sort($found);
            $expected = $this->uuids([$one, $two]);
            sort($expected);
            $this->assertSame($expected, $found);
            $this->assertNotContains($three->getUUID(), $found);
        }

        public function testDeletingAPostRemovesItFromEveryPortfolio()
        {
            $one = $this->portfolio(static::$alice, 'Loses one');
            $two = $this->portfolio(static::$alice, 'Loses two', 'draft');
            $gone = $this->post(static::$alice);
            $stays = $this->post(static::$alice);

            $this->swapUser(static::$alice);
            foreach ([$one, $two] as $portfolio) {
                $portfolio->addItem($gone);
                $portfolio->addItem($stays);
                $portfolio->save();
            }
            $uuid = $gone->getUUID();
            // Deleted by someone who cannot see the draft portfolio: it is cleaned all the same
            $this->swapUser($this->admin());
            $gone->delete();

            $this->swapUser(static::$alice);
            $this->assertSame([], Portfolio::containing($uuid));
            $this->assertSame($this->uuids([$stays]), Portfolio::findFor(static::$alice, 'loses-one')->getItemUUIDs());
            $this->assertSame($this->uuids([$stays]), Portfolio::findFor(static::$alice, 'loses-two')->getItemUUIDs());
        }

        public function testDeletingAPortfolioLeavesItsPosts()
        {
            $portfolio = $this->portfolio(static::$alice, 'Short lived');
            $post = $this->post(static::$alice);

            $this->swapUser(static::$alice);
            $portfolio->addItem($post);
            $portfolio->save();
            $this->assertNotEmpty($portfolio->delete());

            $this->assertNull(Portfolio::findFor(static::$alice, 'short-lived'));
            $this->assertNotEmpty(\Idno\Common\Entity::getByUUID($post->getUUID(), false));
        }

        public function testItIsNotAKindOfPostAndIsNotFederated()
        {
            $portfolio = $this->portfolio(static::$alice, 'Not a post');

            $this->assertSame('entity', $portfolio->getActivityStreamsObjectType(), 'ActivityPub skips entities of this type.');
            $this->assertNotContains(Portfolio::class, array_map(function ($class) {
                return ltrim($class, '\\');
            }, \Idno\Common\ContentType::getRegisteredClasses()));
            $this->assertNotSame('not-a-post', $portfolio->getSlug(), 'A portfolio takes no post slug.');
        }

        public function testJsonHoldsOnlyWhatTheViewerMaySee()
        {
            $portfolio = $this->portfolio(static::$alice, 'As JSON');
            $public = $this->post(static::$alice);
            $private = $this->post(static::$alice, static::$alice->getUUID());

            $this->swapUser(static::$alice);
            $portfolio->addItem($public);
            $portfolio->addItem($private);
            $portfolio->save();

            $this->swapUser(null);
            $json = json_encode(Portfolio::findFor(static::$alice, 'as-json'));
            $this->assertStringContainsString(json_encode($public->getUUID()), $json);
            $this->assertStringNotContainsString(trim(json_encode($private->getUUID()), '"'), $json);
            $this->assertStringNotContainsString(trim(json_encode((string) $private->getID()), '"'), $json);
        }
    }

}
