<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Idno\Entities\User;

    /**
     * A post drawn in a list (the home stream, a member's profile) is drawn as a list item: no
     * comment block and no comment form. Those, and the other things only a post's own page shows,
     * appear on its permalink only.
     *
     * The profile page is a "permalink" of the member, and the page remembers that member as its
     * object. A post must ask whether the page's object is THIS post, not whether the page is a
     * permalink of anything.
     *
     * Needs the site served over HTTP (see AGENTS.md); the pages are requested the way a visitor
     * does, in both looks.
     */
    class ProfileFeedViewTest extends \Tests\IdnoTestCase
    {

        /** @var User */
        private static $member;

        /** @var \IdnoPlugins\Status\Status */
        private static $status;

        /** @var \IdnoPlugins\Text\Entry */
        private static $article;

        private static $replyMarker;
        private static $statusMarker;
        private static $linkMarker;

        /// Theme to put back after the tests
        private static $savedTheme;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$savedTheme = Idno::site()->config()->theme;

            $user = new User();
            $user->handle = 'profilefeed' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(bin2hex(random_bytes(12)));
            $user->setTitle('Profile Feed Test User');
            $user->save(true);
            static::$member = $user;

            $old = Idno::site()->session()->currentUser();
            Idno::site()->session()->logUserOn($user);

            static::$statusMarker = 'statusmarker' . substr(md5(rand() . microtime(true)), 0, 10);
            static::$replyMarker = 'replymarker' . substr(md5(rand() . microtime(true)), 0, 10);
            static::$linkMarker = 'linkmarker' . substr(md5(rand() . microtime(true)), 0, 10);

            // A note, with a reply and a like on it
            $status = new \IdnoPlugins\Status\Status();
            $status->body = static::$statusMarker;
            $status->setAccess('PUBLIC');
            $status->save();
            $status->addAnnotation('reply', 'A Visitor', 'http://example.org/visitor', '', static::$replyMarker, 'http://example.org/reply-' . static::$replyMarker, null, '', [], false);
            $status->addAnnotation('like', 'Another Visitor', 'http://example.org/visitor2', '', '', 'http://example.org/like-' . static::$replyMarker, null, '', [], false);
            static::$status = $status;

            // A long article whose text links to another site
            $article = new \IdnoPlugins\Text\Entry();
            $article->setTitle('A long profile feed article');
            $article->body = '<p>See http://example.org/' . static::$linkMarker . ' ' . implode(' ', array_fill(0, 400, 'wordy')) . '</p>';
            $article->setAccess('PUBLIC');
            $article->save();
            static::$article = $article;

            Idno::site()->session()->logUserOff();
            if ($old) {
                Idno::site()->session()->logUserOn($old);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->config()->theme = static::$savedTheme;
            Idno::site()->config()->save();

            if (static::$member) {
                $old = Idno::site()->session()->currentUser();
                Idno::site()->session()->logUserOn(static::$member);
                Idno::site()->db()->setIgnoreAccess(true);
                foreach (\Idno\Common\Entity::getFromAll(['owner' => static::$member->getUUID()], [], 100) ?: [] as $entity) {
                    $entity->delete();
                }
                Idno::site()->db()->setIgnoreAccess(false);
                Idno::site()->session()->logUserOff();
                if ($old) {
                    Idno::site()->session()->logUserOn($old);
                }
                static::$member->delete();
                static::$member = null;
            }
            parent::tearDownAfterClass();
        }

        /**
         * Each look, with the class names of its comment block and comment form.
         */
        public static function looks()
        {
            return [
                'default look' => ['', 'class="annotations"', 'annotation-add'],
                'Twenty26'     => ['Twenty26', 'class="idno-annotations"', 'idno-comment-form'],
            ];
        }

        private function useLook($theme)
        {
            Idno::site()->config()->theme = $theme;
            Idno::site()->config()->save();
        }

        /**
         * Get a page as the member, who is signed in with the API key, or as a visitor.
         *
         * @return string The page's HTML
         */
        private function page($path, $asMember)
        {
            $headers = [];
            if ($asMember) {
                // A signed request is answered in JSON unless it asks for the look
                $path .= '?_t=' . (Idno::site()->config()->theme === 'Twenty26' ? 'modern' : 'default');
                $headers[] = 'X-IDNO-USERNAME: ' . static::$member->getHandle();
                $headers[] = 'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', $path, static::$member->getAPIkey(), true));
            }
            $result = Webservice::get(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $path, [], $headers);
            $this->assertEquals(200, $result['response'], "$path should load. If this is failing, you may need to set IDNO_DOMAIN.");

            return (string) $result['content'];
        }

        private function profilePath()
        {
            return '/profile/' . static::$member->getHandle();
        }

        private function permalinkPath()
        {
            return parse_url(static::$status->getDisplayURL(), PHP_URL_PATH);
        }

        /**
         * @dataProvider looks
         */
        public function testProfileShowsNoCommentBlock($theme, $block, $form)
        {
            $this->useLook($theme);

            foreach ([false, true] as $asMember) {
                $who = $asMember ? 'the member' : 'a visitor';
                $html = $this->page($this->profilePath(), $asMember);

                $this->assertStringContainsString(static::$statusMarker, $html, "The profile should list the note for $who.");
                $this->assertStringNotContainsString($block, $html, "The profile should not draw the comment block of a post, for $who.");
                $this->assertStringNotContainsString(static::$replyMarker, $html, "The profile should not print the text of a post's replies, for $who.");
                $this->assertStringNotContainsString($form, $html, "The profile should not draw the comment form of a post, for $who.");
            }
        }

        /**
         * @dataProvider looks
         */
        public function testProfileKeepsTheCountsAndLinksOfAListItem($theme, $block, $form)
        {
            $this->useLook($theme);

            $html = $this->page($this->profilePath(), false);

            $this->assertStringContainsString(parse_url(static::$status->getDisplayURL(), PHP_URL_PATH) . '#comments', $html, 'A listed post should link to its comments, which are on its own page.');
        }

        /**
         * @dataProvider looks
         */
        public function testPermalinkShowsTheCommentBlockAndForm($theme, $block, $form)
        {
            $this->useLook($theme);

            $html = $this->page($this->permalinkPath(), false);
            $this->assertStringContainsString($block, $html, 'The post\'s own page should draw its comment block.');
            $this->assertStringContainsString(static::$replyMarker, $html, 'The post\'s own page should print its replies.');

            $html = $this->page($this->permalinkPath(), true);
            $this->assertStringContainsString($form, $html, 'The post\'s own page should draw the comment form for a member.');
        }

        /**
         * @dataProvider looks
         */
        public function testHomeStreamIsDrawnAsAList($theme, $block, $form)
        {
            $this->useLook($theme);

            foreach ([false, true] as $asMember) {
                $html = $this->page('/content/all/', $asMember);

                $this->assertStringContainsString(static::$statusMarker, $html, 'The stream should list the note.');
                $this->assertStringNotContainsString($block, $html, 'The stream should not draw the comment block of a post.');
                $this->assertStringNotContainsString(static::$replyMarker, $html, 'The stream should not print the text of a post\'s replies.');
                $this->assertStringNotContainsString($form, $html, 'The stream should not draw the comment form of a post.');
            }
        }

        /**
         * Links in a post's text are marked as replies (microformats in-reply-to) only on its own page.
         *
         * @dataProvider looks
         */
        public function testLinksInAListedPostAreNotMarkedAsReplyTargets($theme, $block, $form)
        {
            $this->useLook($theme);

            $profile = $this->page($this->profilePath(), false);
            $stream = $this->page('/content/all/', false);
            $permalink = $this->page(parse_url(static::$article->getDisplayURL(), PHP_URL_PATH), false);

            foreach (['the profile' => $profile, 'the stream' => $stream] as $what => $html) {
                $this->assertStringContainsString(static::$linkMarker, $html, "$what should list the note with its link.");
                $this->assertDoesNotMatchRegularExpression('/rel="in-reply-to"[^>]*' . static::$linkMarker . '|' . static::$linkMarker . '[^>]*rel="in-reply-to"/', $html, "A link in a post listed on $what should not be marked as the post's reply target.");
            }

            $this->assertMatchesRegularExpression('/rel="in-reply-to"[^>]*' . static::$linkMarker . '|' . static::$linkMarker . '[^>]*rel="in-reply-to"/', $permalink, 'The article\'s own page should still mark the link, as it does today.');
        }

        /**
         * The page's own description (Open Graph, Dublin Core) describes the member on the profile,
         * not the last post in the list, and the post on its own page.
         *
         * @dataProvider looks
         */
        public function testPageMetadataDescribesThePageNotTheLastPost($theme, $block, $form)
        {
            $this->useLook($theme);

            $profile = $this->page($this->profilePath(), false);
            $this->assertDoesNotMatchRegularExpression('/content="[^"]*' . preg_quote(parse_url(static::$article->getDisplayURL(), PHP_URL_PATH), '/') . '"/', $profile, 'The profile\'s metadata should not take the address of a post in its list.');
            $this->assertStringNotContainsString('content="A long profile feed article"', $profile, 'The profile\'s metadata should not take the title of a post in its list.');
            $this->assertStringContainsString('content="Profile Feed Test User"', $profile, 'The profile\'s metadata should name the member.');

            $permalink = $this->page(parse_url(static::$article->getDisplayURL(), PHP_URL_PATH), false);
            $this->assertStringContainsString('<meta property="og:title" content="A long profile feed article" />', $permalink, 'A post\'s own page should describe the post.');
            $this->assertMatchesRegularExpression('/content="[^"]*' . preg_quote(parse_url(static::$article->getDisplayURL(), PHP_URL_PATH), '/') . '"/', $permalink, 'A post\'s own page should give its address.');
        }

        /**
         * The page of the member is still the member's: the profile's own h-card and title are unchanged.
         *
         * @dataProvider looks
         */
        public function testProfileStillDescribesTheMember($theme, $block, $form)
        {
            $this->useLook($theme);

            $html = $this->page($this->profilePath(), false);

            $this->assertStringContainsString('h-card', $html, 'The profile should still carry the member\'s h-card.');
            $this->assertStringContainsString('Profile Feed Test User', $html, 'The profile should still show the member\'s name.');
        }

    }

}
