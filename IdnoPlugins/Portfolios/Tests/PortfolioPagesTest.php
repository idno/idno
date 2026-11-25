<?php

namespace IdnoPlugins\Portfolios\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * Portfolios over HTTP, as the owner, another member, an administrator and a visitor,
     * in the default look and in Twenty26.
     */
    class PortfolioPagesTest extends \Tests\BrowserTestCase
    {

        const HOSTILE = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';

        private static $alice;
        private static $bob;
        private static $admin;
        private static $savedTheme = false;

        public function setUp(): void
        {
            if (!static::$alice) {
                static::$savedTheme = Idno::site()->config()->theme;
                static::$alice = static::member('pfalice');
                static::$bob = static::member('pfbob');
                static::$admin = static::member('pfadmin', true);
            }
        }

        public function tearDown(): void
        {
            Idno::site()->session()->logUserOff();
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$savedTheme !== false) {
                Idno::site()->config()->theme = static::$savedTheme;
                Idno::site()->config()->save();
            }
            parent::tearDownAfterClass();
            static::$alice = static::$bob = static::$admin = null;
            static::$savedTheme = false;
        }

        public static function looks()
        {
            return ['default' => [''], 'Twenty26' => ['Twenty26']];
        }

        private function look($theme)
        {
            if ((string) Idno::site()->config()->theme !== (string) $theme) {
                Idno::site()->config()->theme = $theme;
                Idno::site()->config()->save();
            }
        }

        private function portfolio(User $owner, $name, $status = 'published', array $posts = [], $description = ''): Portfolio
        {
            $old = $this->swapUser($owner);
            $portfolio = new Portfolio();
            $portfolio->setName($name);
            $portfolio->setDescriptionText($description);
            $portfolio->setPublishStatus($status);
            foreach ($posts as $post) {
                $this->assertTrue($portfolio->addItem($post));
            }
            $this->assertNotEmpty($portfolio->save());
            $this->swapUser($old);

            return $portfolio;
        }

        /**
         * A post with a marker in its text. $access: PUBLIC, or "private".
         */
        private function entry(User $owner, $access = 'PUBLIC', $status = 'published')
        {
            $old = $this->swapUser($owner);
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'Entry ' . static::marker();
            $post->setAccess($access === 'private' ? $owner->getUUID() : $access);
            $post->setPublishStatus($status);
            $this->assertNotEmpty($post->save());
            $this->swapUser($old);
            static::$cleanup[] = $post;

            return $post;
        }

        private function markerOf($post)
        {
            return substr($post->body, strlen('Entry '));
        }

        /**
         * The portfolio as stored now, read as its owner
         */
        private function stored(Portfolio $portfolio, ?User $as = null)
        {
            $old = $this->swapUser($as ?? static::$admin);
            $found = Portfolio::findByID((string) $portfolio->getID());
            $this->swapUser($old);

            return $found;
        }

        private function itemsOf(Portfolio $portfolio)
        {
            $stored = $this->stored($portfolio);

            return $stored ? $stored->getItemUUIDs() : null;
        }

        /**
         * A pattern for an attribute that holds an address of this site, whatever host the
         * site gives itself (the server under test may call itself something other than the
         * host the tests reach it by).
         */
        private function attribute($name, $url, $after = '')
        {
            $local = (string) parse_url($url, PHP_URL_PATH);
            if ($query = parse_url($url, PHP_URL_QUERY)) {
                $local .= '?' . $query;
            }

            return '#' . $name . '="(?:https?://[^/"]+)?' . preg_quote(htmlspecialchars($local), '#') . '"' . $after . '#';
        }

        private function path($url)
        {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        // ---------------------------------------------------------------------------------
        // Creating, editing, publishing, deleting
        // ---------------------------------------------------------------------------------

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testAMemberCreatesEditsPublishesAndDeletesAPortfolio($theme)
        {
            $this->look($theme);
            $name = 'Created ' . static::marker();

            // The list of an owner with the form's address
            $list = $this->get(Portfolio::listURL(static::$alice), static::$alice);
            $this->assertEquals(200, $list['status']);
            $this->assertMatchesRegularExpression($this->attribute('href', '/portfolio/edit'), $list['body'], 'The owner is offered a new portfolio.');

            $form = $this->get('/portfolio/edit', static::$alice);
            $this->assertEquals(200, $form['status']);
            $this->assertStringContainsString('name="name"', $form['body']);
            $this->assertStringContainsString('name="description"', $form['body']);
            $this->assertMatchesRegularExpression('/<input[^>]*name="name"[^>]*value=""/', $form['body'], 'The form of a new portfolio starts empty.');

            // Create (a draft unless said otherwise)
            $result = $this->submit('/portfolio/edit', ['name' => $name, 'description' => "What I made\nthis year"], static::$alice);
            $this->assertEquals(302, $result['status']);
            $old = $this->swapUser(static::$alice);
            $mine = array_values(array_filter(Portfolio::forOwner(static::$alice), function ($portfolio) use ($name) {
                return $portfolio->getTitle() === $name;
            }));
            $this->swapUser($old);
            $this->assertCount(1, $mine);
            $portfolio = $mine[0];
            $this->assertSame('draft', $portfolio->getPublishStatus(), 'A new portfolio starts as a draft.');
            $this->assertSame("What I made\nthis year", $portfolio->getDescriptionText());
            $this->assertSame($this->path($portfolio->getURL()), $this->path($result['location']), 'The owner lands on the new portfolio.');

            // Its page tells the owner it is a draft and what to do with an empty one
            $page = $this->get($portfolio->getURL(), static::$alice);
            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString(htmlspecialchars($name), $page['body']);
            $this->assertStringContainsString('data-portfolio-status="draft"', $page['body']);
            $this->assertStringContainsString('data-portfolio-empty', $page['body']);
            $this->assertEquals(404, $this->get($portfolio->getURL())['status']);

            // Edit: the form holds the stored values
            $editPath = $this->path($portfolio->getEditURL());
            $form = $this->get($editPath, static::$alice);
            $this->assertEquals(200, $form['status']);
            $this->assertStringContainsString('value="' . htmlspecialchars($name) . '"', $form['body']);
            $renamed = 'Renamed ' . static::marker();
            $result = $this->submit($editPath, ['name' => $renamed, 'description' => 'Second description', 'publish_status' => 'published'], static::$alice);
            $this->assertEquals(302, $result['status']);
            $stored = $this->stored($portfolio);
            $this->assertSame($renamed, $stored->getTitle());
            $this->assertSame('Second description', $stored->getDescriptionText());
            $this->assertSame('published', $stored->getPublishStatus());
            $this->assertSame($portfolio->getURL(), $stored->getURL(), 'The address stays when the name changes.');
            $this->assertEquals(200, $this->get($portfolio->getURL())['status'], 'A published portfolio is public.');

            // Unpublish and publish again from the portfolio's own page
            $result = $this->submit($editPath, ['publish_status' => 'draft'], static::$alice, $this->path($portfolio->getURL()));
            $this->assertEquals(302, $result['status']);
            $stored = $this->stored($portfolio);
            $this->assertSame('draft', $stored->getPublishStatus());
            $this->assertSame($renamed, $stored->getTitle(), 'Changing the status alone keeps the name.');
            $this->assertSame('Second description', $stored->getDescriptionText());
            $this->assertEquals(404, $this->get($portfolio->getURL())['status']);
            $this->submit($editPath, ['publish_status' => 'published'], static::$alice, $this->path($portfolio->getURL()));
            $this->assertSame('published', $this->stored($portfolio)->getPublishStatus());

            // A name is required
            $this->submit($editPath, ['name' => '   ', 'description' => 'x'], static::$alice);
            $this->assertSame($renamed, $this->stored($portfolio)->getTitle());

            // Delete, from the portfolio's page
            $deletePath = $this->path($portfolio->getDeleteURL());
            $this->get($deletePath, static::$alice);
            $this->assertNotNull($this->stored($portfolio), 'A GET deletes nothing.');
            $result = $this->submit($deletePath, [], static::$alice, $this->path($portfolio->getURL()));
            $this->assertEquals(302, $result['status']);
            $this->assertNull($this->stored($portfolio));
            $this->assertSame($this->path(Portfolio::listURL(static::$alice)), $this->path($result['location']), 'After deleting, the owner is back at the list.');
            $this->assertEquals(404, $this->get($portfolio->getURL(), static::$alice)['status']);
        }

        public function testCreatingNeedsAMember()
        {
            $this->assertEquals(403, $this->get('/portfolio/edit')['status']);
            $before = count($this->allPortfolios());
            $this->request('POST', '/portfolio/edit', ['name' => 'Nobody ' . static::marker()]);
            $this->assertCount($before, $this->allPortfolios());
        }

        /**
         * A page without what differs between any two requests: form tokens, generated ids,
         * times, and the part of the address that was asked for.
         */
        private function withoutWhatChanges($body, $key)
        {
            $body = str_replace($key, '', $body);

            return preg_replace(['/value="[^"]*"/', '/\b(f|tid)?[0-9a-f]{8,}\b/', '/\d{6,}/'], '', $body);
        }

        private function allPortfolios()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $all = Portfolio::get([], [], 1000) ?: [];
            Idno::site()->db()->setIgnoreAccess(false);

            return $all;
        }

        // ---------------------------------------------------------------------------------
        // Who sees what
        // ---------------------------------------------------------------------------------

        public static function viewersAndLooks()
        {
            $cases = [];
            foreach (['default' => '', 'Twenty26' => 'Twenty26'] as $look => $theme) {
                foreach (['a visitor' => null, 'another member' => 'bob', 'the owner' => 'alice', 'an administrator' => 'admin'] as $name => $who) {
                    $cases["$look, $name"] = [$theme, $who];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider viewersAndLooks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewersAndLooks')]
        public function testADraftIsAMissingPageToEveryoneButItsOwnerAndAdministrators($theme, $who)
        {
            $this->look($theme);
            $as = $who ? static::$$who : null;
            $allowed = in_array($who, ['alice', 'admin'], true);
            $name = 'Secret ' . static::marker();
            $post = $this->entry(static::$alice);
            $draft = $this->portfolio(static::$alice, $name, 'draft', [$post], 'Hidden words ' . static::marker());
            static::$cleanup[] = $draft;
            $missing = $this->get('/profile/' . static::$alice->getHandle() . '/portfolio/no-such-portfolio', $as);
            $this->assertEquals(404, $missing['status']);

            foreach (['', '?_t=rss', '?_t=json', '?_t=jsonp', '?_t=jsonfeed', '?_t=activitypub'] as $type) {
                foreach ([$draft->getURL(), Idno::site()->config()->getDisplayURL() . 'profile/' . static::$alice->getHandle() . '/portfolio/' . (string) $draft->getID()] as $address) {
                    $result = $this->get($address . $type, $as);
                    if ($allowed) {
                        $this->assertEquals(200, $result['status'], "The draft is there for its owner and administrators ($type).");
                    } else {
                        $this->assertEquals(404, $result['status'], "A draft answers like a missing page ($type).");
                        $this->assertStringNotContainsString($name, $result['body']);
                        $this->assertStringNotContainsString($this->markerOf($post), $result['body']);
                    }
                }
            }
            if (!$allowed) {
                $this->assertSame(
                    $this->withoutWhatChanges($missing['body'], 'no-such-portfolio'),
                    $this->withoutWhatChanges($this->get($draft->getURL(), $as)['body'], $draft->getAddressKey()),
                    'The page of a draft is the page of a portfolio that does not exist.'
                );
            }

            // The list and the profile
            foreach (['', '?_t=json', '?_t=rss'] as $type) {
                $list = $this->get(Portfolio::listURL(static::$alice) . $type, $as);
                $this->assertEquals(200, $list['status']);
                if ($allowed) {
                    if ($type !== '?_t=rss') {
                        $this->assertStringContainsString($name, $list['body']);
                    }
                } else {
                    $this->assertStringNotContainsString($name, $list['body']);
                    $this->assertStringNotContainsString($draft->getAddressKey(), $list['body']);
                }
            }
            $profile = $this->get(static::$alice->getDisplayURL(), $as);
            $this->assertStringNotContainsString($name, $profile['body']);
        }

        /**
         * @dataProvider viewersAndLooks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewersAndLooks')]
        public function testAPublishedPortfolioShowsEachViewerOnlyThePostsTheyMayRead($theme, $who)
        {
            $this->look($theme);
            $as = $who ? static::$$who : null;
            $seesAll = in_array($who, ['alice', 'admin'], true);
            $public = $this->entry(static::$alice);
            $second = $this->entry(static::$alice);
            $private = $this->entry(static::$alice, 'private');
            $draft = $this->entry(static::$alice, 'PUBLIC', 'draft');
            $members = $this->entry(static::$alice, 'SITE');
            $name = 'Showcase ' . static::marker();
            $about = 'About ' . static::marker();
            $portfolio = $this->portfolio(static::$alice, $name, 'published', [$private, $second, $draft, $public, $members], $about);
            static::$cleanup[] = $portfolio;

            $expected = [$second, $public];
            if ($who) {
                $expected = [$second, $public, $members];
            }
            if ($seesAll) {
                $expected = [$private, $second, $draft, $public, $members];
            }
            $hidden = array_udiff([$private, $second, $draft, $public, $members], $expected, function ($a, $b) {
                return strcmp($a->getUUID(), $b->getUUID());
            });

            $page = $this->get($portfolio->getURL(), $as);
            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString($name, $page['body']);
            $this->assertStringContainsString($about, $page['body']);
            $this->assertStringContainsString('h-feed', $page['body']);
            $this->assertMatchesRegularExpression('/class="[^"]*\bp-name\b[^"]*"[^>]*>\s*' . preg_quote($name, '/') . '/', $page['body'], 'The feed has a name.');
            $this->assertMatchesRegularExpression('/class="[^"]*\bh-card\b/', $page['body'], 'The owner is an h-card.');
            $this->assertStringContainsString(htmlspecialchars(static::$alice->getTitle()), $page['body']);
            $this->assertStringContainsString('data-portfolio-count="' . count($expected) . '"', $page['body'], 'The count is of what this viewer may read.');
            $this->assertMatchesRegularExpression('/<meta property="og:description" content="[^"]*' . preg_quote($about, '/') . '/', $page['body']);
            $this->assertMatchesRegularExpression('/<title>[^<]*' . preg_quote($name, '/') . '/', $page['body']);

            // In the owner's order, each in its feed view (no comment form of a post's own page)
            $positions = [];
            foreach ($expected as $post) {
                $position = strpos($page['body'], $this->markerOf($post));
                $this->assertNotFalse($position, 'A post this viewer may read is shown.');
                $positions[] = $position;
            }
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, 'The posts are in the order the owner gave them.');
            foreach ($hidden as $post) {
                $this->assertStringNotContainsString($this->markerOf($post), $page['body'], 'A post this viewer may not read is not shown.');
                $this->assertStringNotContainsString((string) $post->getID(), $page['body'], 'Nor is anything that names it.');
            }
            $this->assertSame(count($expected), substr_count($page['body'], 'data-idno-more-menu'), 'Each post has its menu.');

            // The same in every other form of the page
            foreach (['?_t=rss', '?_t=json', '?_t=jsonp', '?_t=jsonfeed'] as $type) {
                $feed = $this->get($portfolio->getURL() . $type, $as);
                $this->assertEquals(200, $feed['status'], $type);
                foreach ($expected as $post) {
                    $this->assertStringContainsString($this->markerOf($post), $feed['body'], "$type has the posts this viewer may read.");
                }
                foreach ($hidden as $post) {
                    $this->assertStringNotContainsString($this->markerOf($post), $feed['body'], "$type has no post this viewer may not read.");
                    $this->assertStringNotContainsString((string) $post->getID(), $feed['body'], "$type names no post this viewer may not read.");
                }
            }
            $json = json_decode($this->get($portfolio->getURL() . '?_t=json', $as)['body'], true);
            $this->assertIsArray($json);
            $this->assertSame($name, $json['title']);
            $this->assertCount(count($expected), $json['items']);

            // The list: its card counts the same
            $list = $this->get(Portfolio::listURL(static::$alice), $as);
            $this->assertEquals(200, $list['status']);
            $this->assertMatchesRegularExpression($this->attribute('href', $portfolio->getURL()), $list['body']);
            $this->assertMatchesRegularExpression('/data-portfolio="' . preg_quote((string) $portfolio->getID(), '/') . '"[^>]*data-portfolio-count="' . count($expected) . '"/', $list['body']);
            foreach ($hidden as $post) {
                $this->assertStringNotContainsString((string) $post->getID(), $list['body']);
            }

            // Under another member's name it does not exist
            $this->assertEquals(404, $this->get('/profile/' . static::$bob->getHandle() . '/portfolio/' . $portfolio->getAddressKey(), $as)['status']);
            $this->assertEquals(404, $this->get('/profile/' . static::$bob->getHandle() . '/portfolio/' . (string) $portfolio->getID(), $as)['status']);
            $this->assertEquals(404, $this->get('/profile/nobody' . static::marker() . '/portfolios', $as)['status']);
        }

        /**
         * @dataProvider viewersAndLooks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewersAndLooks')]
        public function testTheProfileHasAPortfoliosButtonWhenThereIsSomethingToSee($theme, $who)
        {
            $this->look($theme);
            $as = $who ? static::$$who : null;
            $owner = static::member('pfbutton');
            $button = $this->attribute('href', Portfolio::listURL($owner), ' data-portfolios-button');

            $profile = $this->get($owner->getDisplayURL(), $as);
            $this->assertEquals(200, $profile['status']);
            $this->assertDoesNotMatchRegularExpression($button, $profile['body'], 'No portfolios, no button for others.');
            $this->assertMatchesRegularExpression($button, $this->get($owner->getDisplayURL(), $owner)['body'], 'The owner always has the button.');

            $this->portfolio($owner, 'Only a draft ' . static::marker(), 'draft');
            $profile = $this->get($owner->getDisplayURL(), $as);
            if ($who === 'admin') {
                // An administrator may read drafts; the button is about what is published
                $this->assertDoesNotMatchRegularExpression($button, $profile['body']);
            } else {
                $this->assertDoesNotMatchRegularExpression($button, $profile['body'], 'A draft does not show the button.');
            }

            $this->portfolio($owner, 'Now published ' . static::marker());
            $profile = $this->get($owner->getDisplayURL(), $as);
            $this->assertMatchesRegularExpression($button, $profile['body'], 'A published portfolio shows the button to everyone.');

            // The button's page
            $list = $this->get(Portfolio::listURL($owner), $as);
            $this->assertEquals(200, $list['status']);
            $this->assertStringContainsString('Now published', $list['body']);
            $this->assertStringNotContainsString('Only a draft', $who === 'admin' ? '' : $list['body']);
        }

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testEmptyStatesSayWhatToDo($theme)
        {
            $this->look($theme);
            $owner = static::member('pfempty');

            $mine = $this->get(Portfolio::listURL($owner), $owner);
            $this->assertStringContainsString('data-portfolios-empty="owner"', $mine['body']);
            $theirs = $this->get(Portfolio::listURL($owner));
            $this->assertEquals(200, $theirs['status']);
            $this->assertStringContainsString('data-portfolios-empty="visitor"', $theirs['body']);
            $this->assertStringNotContainsString('portfolio/edit', $theirs['body']);

            $portfolio = $this->portfolio($owner, 'Nothing yet ' . static::marker());
            $this->assertStringContainsString('data-portfolio-empty="owner"', $this->get($portfolio->getURL(), $owner)['body']);
            $this->assertStringContainsString('data-portfolio-empty="visitor"', $this->get($portfolio->getURL())['body']);
        }

        // ---------------------------------------------------------------------------------
        // Adding and removing posts
        // ---------------------------------------------------------------------------------

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testTheMenuOfYourOwnPostOffersPortfolios($theme)
        {
            $this->look($theme);
            $post = $this->entry(static::$alice);
            $link = $this->attribute('href', '/portfolio/pick?object=' . $post->getID());

            foreach ([$post->getDisplayURL(), static::$alice->getDisplayURL()] as $page) {
                $body = $this->get($page, static::$alice)['body'];
                // The post's menu is the one that copies the post's address (a profile has its own)
                preg_match_all('#<details class="idno-more".*?</details>#s', $body, $menus);
                $copies = $this->attribute('href', $post->getDisplayURL());
                $menu = array_values(array_filter($menus[0], function ($markup) use ($copies) {
                    return (bool) preg_match($copies, $markup);
                }));
                $this->assertCount(1, $menu, "The post has its menu on $page.");
                $this->assertMatchesRegularExpression($link, $menu[0], "The author's menu offers portfolios on $page.");
                $this->assertStringContainsString('data-portfolio-pick', $menu[0]);
                foreach ([null, static::$bob, static::$admin] as $other) {
                    $this->assertStringNotContainsString('portfolio/pick', $this->get($page, $other)['body'], 'Nobody else is offered it.');
                }
            }
        }

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testThePickerPutsAPostIntoSeveralPortfoliosAndTakesItOut($theme)
        {
            $this->look($theme);
            $owner = static::member('pfpicker');
            $post = $this->entry($owner);
            $other = $this->entry($owner);
            $one = $this->portfolio($owner, 'Picker one ' . static::marker());
            $two = $this->portfolio($owner, 'Picker two ' . static::marker(), 'draft', [$other]);
            $three = $this->portfolio($owner, 'Picker three ' . static::marker(), 'published', [$post]);
            $pick = '/portfolio/pick?object=' . $post->getID();

            $page = $this->get($pick, $owner);
            $this->assertEquals(200, $page['status']);
            foreach ([$one, $two, $three] as $portfolio) {
                $this->assertStringContainsString(htmlspecialchars($portfolio->getTitle()), $page['body']);
            }
            $this->assertMatchesRegularExpression('/<input[^>]*name="portfolios\[\]"[^>]*value="' . $three->getID() . '"[^>]*\schecked/', $page['body'], 'The portfolios it is in are ticked.');
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="portfolios\[\]"[^>]*value="' . $one->getID() . '"[^>]*\schecked/', $page['body']);
            $this->assertStringContainsString('name="new_name"', $page['body']);

            // Tick one and two, untick three, and make a new one in the same go
            $newName = 'Made in the picker ' . static::marker();
            $result = $this->submit('/portfolio/pick', ['object' => (string) $post->getID(), 'portfolios' => [(string) $one->getID(), (string) $two->getID()], 'new_name' => $newName], $owner, $pick);
            $this->assertEquals(302, $result['status']);
            $this->assertSame([$post->getUUID()], $this->itemsOf($one));
            $this->assertSame([$other->getUUID(), $post->getUUID()], $this->itemsOf($two), 'A post is added to the end.');
            $this->assertSame([], $this->itemsOf($three));
            $old = $this->swapUser($owner);
            $made = array_values(array_filter(Portfolio::forOwner($owner), function ($portfolio) use ($newName) {
                return $portfolio->getTitle() === $newName;
            }));
            $this->swapUser($old);
            $this->assertCount(1, $made, 'The new portfolio was made.');
            $this->assertSame('draft', $made[0]->getPublishStatus());
            $this->assertSame([$post->getUUID()], $made[0]->getItemUUIDs());

            // Untick everything
            $this->submit('/portfolio/pick', ['object' => (string) $post->getID()], $owner, $pick);
            $this->assertSame([], $this->itemsOf($one));
            $this->assertSame([$other->getUUID()], $this->itemsOf($two));

            // A draft post can be collected too; visitors will not see it
            $draft = $this->entry($owner, 'PUBLIC', 'draft');
            $this->submit('/portfolio/pick', ['object' => (string) $draft->getID(), 'portfolios' => [(string) $one->getID()]], $owner, '/portfolio/pick?object=' . $draft->getID());
            $this->assertSame([$draft->getUUID()], $this->itemsOf($one));
            $this->assertStringContainsString('data-portfolio-count="0"', $this->get($one->getURL())['body']);
        }

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testPostsAreRemovedAndMovedOnThePortfolioPage($theme)
        {
            $this->look($theme);
            $owner = static::member('pfmover');
            $a = $this->entry($owner);
            $b = $this->entry($owner);
            $c = $this->entry($owner);
            $portfolio = $this->portfolio($owner, 'Movable ' . static::marker(), 'published', [$a, $b, $c]);
            $items = $this->path($portfolio->getItemsURL());
            $page = $this->path($portfolio->getURL());

            $body = $this->get($page, $owner)['body'];
            $this->assertMatchesRegularExpression($this->attribute('action', $portfolio->getItemsURL()), $body, 'The owner has the controls.');
            foreach ([null, static::$bob] as $other) {
                $this->assertStringNotContainsString('portfolio/items', $this->get($page, $other)['body'], 'Nobody else has them.');
                $this->assertStringNotContainsString('portfolio/delete', $this->get($page, $other)['body']);
                $this->assertStringNotContainsString('portfolio/edit', $this->get($page, $other)['body']);
            }

            $result = $this->submit($items, ['object' => $c->getUUID(), 'action' => 'up'], $owner, $page);
            $this->assertEquals(302, $result['status']);
            $this->assertSame($page, $this->path($result['location']));
            $this->assertSame([$a->getUUID(), $c->getUUID(), $b->getUUID()], $this->itemsOf($portfolio));
            $this->submit($items, ['object' => $a->getUUID(), 'action' => 'down'], $owner, $page);
            $this->assertSame([$c->getUUID(), $a->getUUID(), $b->getUUID()], $this->itemsOf($portfolio));
            $this->submit($items, ['object' => $a->getUUID(), 'action' => 'remove'], $owner, $page);
            $this->assertSame([$c->getUUID(), $b->getUUID()], $this->itemsOf($portfolio));
            $this->assertNotEmpty(\Idno\Common\Entity::getByUUID($a->getUUID(), false), 'Removing a post from a portfolio does not delete it.');
            $this->submit($items, ['object' => $a->getUUID(), 'action' => 'nonsense'], $owner, $page);
            $this->assertSame([$c->getUUID(), $b->getUUID()], $this->itemsOf($portfolio));

            // An administrator may tidy a member's portfolio
            $this->submit($items, ['object' => $c->getUUID(), 'action' => 'remove'], static::$admin, $page);
            $this->assertSame([$b->getUUID()], $this->itemsOf($portfolio));
        }

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testDeletingAPostOverHttpTakesItOutOfItsPortfolios($theme)
        {
            $this->look($theme);
            $owner = static::member('pfdeleter');
            $stays = $this->entry($owner);
            $goes = $this->entry($owner);
            $portfolio = $this->portfolio($owner, 'Shrinks ' . static::marker(), 'published', [$goes, $stays]);

            $result = $this->submit($this->path($goes->getDeleteURL()), [], $owner, $this->path($goes->getEditURL()));
            $this->assertEquals(302, $result['status']);
            $this->assertSame([$stays->getUUID()], $this->itemsOf($portfolio));
            $this->assertStringContainsString('data-portfolio-count="1"', $this->get($portfolio->getURL())['body']);
        }

        /**
         * A member banned together with everything they posted has no profile; their
         * portfolios go with it, for everyone but administrators.
         *
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testABannedMembersPortfoliosAreGoneWithTheirProfile($theme)
        {
            $this->look($theme);
            $owner = static::member('pfbanned');
            $post = $this->entry($owner);
            $name = 'Of the banned ' . static::marker();
            $portfolio = $this->portfolio($owner, $name, 'published', [$post]);
            $this->assertEquals(200, $this->get($portfolio->getURL())['status']);

            $old = $this->swapUser(static::$admin);
            $stored = static::fresh($owner);
            $this->assertTrue($stored->ban('Testing', static::$admin->getUUID(), true));
            $this->assertNotEmpty($stored->save(true));
            $this->swapUser($old);

            foreach ([null, static::$bob] as $as) {
                foreach (['', '?_t=rss', '?_t=json'] as $type) {
                    foreach ([$portfolio->getURL(), Portfolio::listURL($owner)] as $address) {
                        $result = $this->get($address . $type, $as);
                        $this->assertEquals(404, $result['status'], "$address$type is gone.");
                        $this->assertStringNotContainsString($name, $result['body']);
                    }
                }
            }
            $this->assertEquals(200, $this->get(Portfolio::listURL($owner), static::$admin)['status'], 'An administrator still sees them.');
            $this->assertEquals(200, $this->get($portfolio->getURL(), static::$admin)['status']);
        }

        // ---------------------------------------------------------------------------------
        // Requests that must be refused
        // ---------------------------------------------------------------------------------

        public function testAMemberCannotChangeAnotherMembersPortfolio()
        {
            $post = $this->entry(static::$alice);
            $name = 'Hers ' . static::marker();
            $portfolio = $this->portfolio(static::$alice, $name, 'published', [$post], 'Her words');
            static::$cleanup[] = $portfolio;
            $draft = $this->portfolio(static::$alice, 'Her draft ' . static::marker(), 'draft', [$post]);
            static::$cleanup[] = $draft;
            $bobs = $this->entry(static::$bob);
            $bobsPortfolio = $this->portfolio(static::$bob, 'His ' . static::marker());
            static::$cleanup[] = $bobsPortfolio;
            // A page of Bob's own, for a form token that is valid for him
            $tokenPage = '/portfolio/edit';

            foreach ([$portfolio, $draft] as $target) {
                $edit = $this->path($target->getEditURL());
                $this->assertContains($this->get($edit, static::$bob)['status'], [403, 404], "Another member has no edit form.");
                $this->submit($edit, ['name' => 'Taken over', 'description' => 'By Bob', 'publish_status' => $target === $draft ? 'published' : 'draft'], static::$bob, $tokenPage);
                $stored = $this->stored($target);
                $this->assertNotSame('Taken over', $stored->getTitle());
                $this->assertSame($target->getPublishStatus(), $stored->getPublishStatus());

                $this->submit($this->path($target->getItemsURL()), ['object' => $post->getUUID(), 'action' => 'remove'], static::$bob, $tokenPage);
                $this->assertSame([$post->getUUID()], $this->itemsOf($target));

                $this->submit($this->path($target->getDeleteURL()), [], static::$bob, $tokenPage);
                $this->assertNotNull($this->stored($target));
            }

            // Bob's own post into Alice's portfolio, by the picker and by naming her portfolio
            $this->submit('/portfolio/pick', ['object' => (string) $bobs->getID(), 'portfolios' => [(string) $portfolio->getID(), (string) $draft->getID(), (string) $bobsPortfolio->getID()]], static::$bob, $tokenPage);
            $this->assertSame([$post->getUUID()], $this->itemsOf($portfolio));
            $this->assertSame([$post->getUUID()], $this->itemsOf($draft));
            $this->assertSame([$bobs->getUUID()], $this->itemsOf($bobsPortfolio), 'His own portfolio in the same request is his to change.');
            $this->submit($this->path($portfolio->getItemsURL()), ['object' => $bobs->getUUID(), 'action' => 'add'], static::$bob, $tokenPage);
            $this->assertSame([$post->getUUID()], $this->itemsOf($portfolio));

            // Alice's post into Bob's portfolio
            $this->assertContains($this->get('/portfolio/pick?object=' . (string) $post->getID(), static::$bob)['status'], [403, 404]);
            $this->submit('/portfolio/pick', ['object' => (string) $post->getID(), 'portfolios' => [(string) $bobsPortfolio->getID()], 'new_name' => 'With her post'], static::$bob, $tokenPage);
            $this->assertSame([$bobs->getUUID()], $this->itemsOf($bobsPortfolio));
            $this->submit('/portfolio/pick', ['object' => $post->getUUID(), 'portfolios' => [(string) $bobsPortfolio->getID()]], static::$bob, $tokenPage);
            $this->assertSame([$bobs->getUUID()], $this->itemsOf($bobsPortfolio));
            $old = $this->swapUser(static::$bob);
            foreach (Portfolio::forOwner(static::$bob) as $his) {
                $this->assertNotSame('With her post', $his->getTitle(), 'Nothing is made for a post that is not his.');
            }
            $this->swapUser($old);

            // An administrator may not put someone's post into another member's portfolio either
            $this->submit($this->path($bobsPortfolio->getItemsURL()), ['object' => $post->getUUID(), 'action' => 'add'], static::$admin, $tokenPage);
            $this->assertSame([$bobs->getUUID()], $this->itemsOf($bobsPortfolio));

            // A visitor
            foreach (['/portfolio/pick?object=' . (string) $post->getID(), $this->path($portfolio->getEditURL())] as $address) {
                $this->assertEquals(403, $this->get($address)['status']);
            }
        }

        public function testNothingChangesWithoutAFormToken()
        {
            $post = $this->entry(static::$alice);
            $second = $this->entry(static::$alice);
            $name = 'Tokenless ' . static::marker();
            $portfolio = $this->portfolio(static::$alice, $name, 'published', [$post]);
            static::$cleanup[] = $portfolio;
            $before = count($this->allPortfolios());
            // Make sure the browser is signed in
            $this->assertEquals(200, $this->get('/portfolio/edit', static::$alice)['status']);

            $this->request('POST', '/portfolio/edit', ['name' => 'No token ' . static::marker()], static::$alice);
            $this->request('POST', $this->path($portfolio->getEditURL()), ['name' => 'Changed', 'publish_status' => 'draft'], static::$alice);
            $this->request('POST', $this->path($portfolio->getItemsURL()), ['object' => $post->getUUID(), 'action' => 'remove'], static::$alice);
            $this->request('POST', '/portfolio/pick', ['object' => (string) $second->getID(), 'portfolios' => [(string) $portfolio->getID()], 'new_name' => 'No token'], static::$alice);
            $this->request('POST', $this->path($portfolio->getDeleteURL()), [], static::$alice);

            $stored = $this->stored($portfolio);
            $this->assertNotNull($stored);
            $this->assertSame($name, $stored->getTitle());
            $this->assertSame('published', $stored->getPublishStatus());
            $this->assertSame([$post->getUUID()], $stored->getItemUUIDs());
            $this->assertCount($before, $this->allPortfolios());
        }

        // ---------------------------------------------------------------------------------
        // Escaping
        // ---------------------------------------------------------------------------------

        /**
         * @dataProvider looks
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('looks')]
        public function testAHostileNameAndDescriptionArePrintedAsText($theme)
        {
            $this->look($theme);
            $owner = static::member('pfhostile');
            $post = $this->entry($owner);
            $name = static::HOSTILE . ' ' . static::marker();
            $description = "</textarea>" . static::HOSTILE . "\nsecond line";

            // Typed into the form, as a member would
            $result = $this->submit('/portfolio/edit', ['name' => $name, 'description' => $description, 'publish_status' => 'published'], $owner);
            $this->assertEquals(302, $result['status']);
            $old = $this->swapUser($owner);
            $portfolio = Portfolio::forOwner($owner)[0];
            $portfolio->addItem($post);
            $portfolio->save();
            $this->swapUser($old);
            $this->assertSame($name, $portfolio->getTitle(), 'The name is stored as typed.');
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $portfolio->getAddressKey());

            $pages = [
                $portfolio->getURL() => [null, $owner],
                Portfolio::listURL($owner) => [null, $owner],
                $owner->getDisplayURL() => [null, $owner],
                $portfolio->getEditURL() => [$owner],
                Idno::site()->config()->getDisplayURL() . 'portfolio/pick?object=' . $post->getID() => [$owner],
            ];
            foreach ($pages as $address => $viewers) {
                foreach ($viewers as $as) {
                    $body = $this->get($address, $as)['body'];
                    $this->assertStringNotContainsString('<script>alert(1)', $body, $address);
                    $this->assertStringNotContainsString('<img src=x', $body, $address);
                    $this->assertStringNotContainsString('"><img src', $body, $address);
                    $this->assertStringNotContainsString('</textarea><script', $body, $address);
                }
            }
            $page = $this->get($portfolio->getURL())['body'];
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page, 'The name is there, as text.');

            // JSON carries the text as it is; the JSONP script cannot be closed by it
            $json = json_decode($this->get($portfolio->getURL() . '?_t=json')['body'], true);
            $this->assertSame($name, $json['title']);
            $jsonp = $this->get($portfolio->getURL() . '?_t=jsonp&callback=cb')['body'];
            $this->assertStringNotContainsString('</script>', $jsonp);
            $rss = $this->get($portfolio->getURL() . '?_t=rss')['body'];
            $this->assertNotFalse(@simplexml_load_string($rss), 'The feed is well formed.');
            $this->assertStringNotContainsString('<script>alert(1)', $rss);
        }

        public function testLongInputIsCut()
        {
            $owner = static::member('pflong');
            $this->submit('/portfolio/edit', ['name' => str_repeat('n', 500), 'description' => str_repeat('d', 5000)], $owner);
            $old = $this->swapUser($owner);
            $portfolio = Portfolio::forOwner($owner)[0];
            $this->swapUser($old);
            $this->assertSame(Portfolio::MAX_NAME, mb_strlen($portfolio->getTitle()));
            $this->assertSame(Portfolio::MAX_DESCRIPTION, mb_strlen($portfolio->getDescriptionText()));
        }

        // ---------------------------------------------------------------------------------
        // Navigation
        // ---------------------------------------------------------------------------------

        public function testTheSideNavigationHasPortfoliosForMembersAndMarksItOnTheirOwnPortfolioPages()
        {
            $this->look('Twenty26');
            $post = $this->entry(static::$alice);
            $portfolio = $this->portfolio(static::$alice, 'Navigated ' . static::marker(), 'published', [$post]);
            static::$cleanup[] = $portfolio;
            $bobs = $this->portfolio(static::$bob, 'Bob navigated ' . static::marker());
            static::$cleanup[] = $bobs;
            $item = '#<a href="(?:https?://[^/"]+)?' . preg_quote(htmlspecialchars($this->path(Portfolio::listURL(static::$alice))), '#') . '" class="idno-nav-item( active)?"( aria-current="page")?>#';

            $own = [
                Portfolio::listURL(static::$alice),
                $portfolio->getURL(),
                $portfolio->getEditURL(),
                Idno::site()->config()->getDisplayURL() . 'portfolio/edit',
                Idno::site()->config()->getDisplayURL() . 'portfolio/pick?object=' . (string) $post->getID(),
            ];
            foreach ($own as $address) {
                $body = $this->get($address, static::$alice)['body'];
                $this->assertTrue((bool) preg_match($item, $body, $match), "The navigation has Portfolios on $address.");
                $this->assertStringContainsString('aria-current="page"', $match[0], "Portfolios is the current item on $address.");
                $this->assertSame(1, substr_count($body, 'aria-current="page"') - substr_count($body, 'idno-content-type-tab active'), "Only one item is current on $address.");
            }

            $elsewhere = [
                Idno::site()->config()->getDisplayURL(),
                static::$alice->getDisplayURL(),
                Portfolio::listURL(static::$bob),
                $bobs->getURL(),
                $post->getDisplayURL(),
            ];
            foreach ($elsewhere as $address) {
                $body = $this->get($address, static::$alice)['body'];
                $this->assertTrue((bool) preg_match($item, $body, $match), "The navigation has Portfolios on $address.");
                $this->assertStringNotContainsString('aria-current', $match[0], "Portfolios is not the current item on $address.");
            }

            $visitor = $this->get($portfolio->getURL())['body'];
            $this->assertStringNotContainsString('/portfolios" class="idno-nav-item', $visitor, 'A visitor has no Portfolios item.');

            // A portfolio that does not exist marks nothing
            $missing = $this->get('/profile/' . static::$alice->getHandle() . '/portfolio/nothing-here', static::$alice);
            $this->assertEquals(404, $missing['status']);
            $this->assertStringNotContainsString('aria-current="page"', $missing['body']);
        }

        public function testTheDefaultLookLinksToPortfoliosFromTheMembersMenu()
        {
            $this->look('');
            $link = $this->attribute('href', Portfolio::listURL(static::$alice));

            $this->assertMatchesRegularExpression($link, $this->get('/', static::$alice)['body']);
            $this->assertStringNotContainsString('/portfolios"', $this->get('/')['body']);
        }
    }

}
