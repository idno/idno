<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What the page shells print of values that a visitor or a member supplied: the page's address, a tag, a description, a profile
 * address. Each is drawn on a private site, with hostile values, and the markup is read back.
 */
final class TemplateEscapingTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    /**
     * The path of the request is the visitor's word and ends in the body's classes and the title: a tag page whose tag holds
     * `"><script>` prints no script element, in the title or in the class of the body.
     */
    public function testThePageTitleAndBodyClassesAreEscaped(): void
    {
        $this->seedSite();

        $response = $this->rawGet('/tag/x"><script>alert(1)');

        $this->assertStringContainsString('<body class="', $response, 'the page is drawn: the test is looking at it');
        $this->assertStringNotContainsString('<script>alert(1)', $response, 'a script element the path opened');
        $this->assertSame(1, preg_match('#<title[^>]*>(.*?)</title>#s', $response, $title), 'the page has a title');
        $this->assertStringNotContainsString('<script', $title[1]);
        $this->assertSame(1, preg_match('#<body class="([^"]*)">#', $response, $body), 'the body has one class attribute that the path did not end');
        $this->assertStringContainsString('page-tag-x&quot;&gt;&lt;script&gt;alert(1)', $body[1], 'the path is text in a class');
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response, $jsonLd), 'the structured data is one script');
        $this->assertStringNotContainsString('<script', $jsonLd[1], 'whose JSON holds no tag');
    }

    /**
     * The page description of a page that is not a permalink is the page's word: the Open Graph tags print it as text.
     */
    public function testTheOpenGraphValuesAreEscaped(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['title' => 'A "title"', 'description' => 'Say "hello" <script>alert(1)</script>'])->draw('shell/opengraph');
        PHP);

        $this->assertStringContainsString('<meta property="og:description" content="Say &quot;hello&quot; &lt;script&gt;alert(1)&lt;/script&gt;" />', $drawn);
        $this->assertStringNotContainsString('<script', $drawn);
    }

    /**
     * The environment script holds the page's address, which holds the visitor's words: every value is encoded so that none can end
     * the script or open a tag, and it still reads back as the same values.
     */
    public function testTheEnvironmentScriptIsEncodedForAScript(): void
    {
        $this->seedSite();
        $uri = '/begin/?a=</script><script>alert(1)</script>&b="\'';

        $drawn = (string) $this->runInSite($this->pageCode($uri) . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('js/idno');
        PHP);

        $this->assertSame(1, substr_count($drawn, '<script'), 'one script element');
        $this->assertSame(1, substr_count($drawn, '</script>'), 'which nothing in the address ends');
        $this->assertStringNotContainsString('alert(1)</script>', $drawn);
        $this->assertSame(1, preg_match('/var idno = (\{.*\});/', $drawn, $json));
        $environment = json_decode($json[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($uri, $environment['page']['currentUrlFragments']['path'], 'the same values, read back');
        $this->assertStringEndsWith($uri, $environment['page']['currentUrl']);
    }

    /**
     * A profile address that is not http, https, mailto, sms or tel is no identity link: the shell prints a `rel="me"` link for those only.
     */
    public function testAProfileAddressThatIsNotHttpMailSmsOrTelIsNotLinkedAsAnIdentity(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['https://example.org/ada', '@ada_l', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'mailto:a b@c', '']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('shell/identities');
        PHP);

        $this->assertSame(5, substr_count($drawn, 'rel="me"'));
        $this->assertStringContainsString('href="mailto:ada@example.com"', $drawn);
        $this->assertStringContainsString('href="SMS:+15555550100"', $drawn);
        $this->assertStringContainsString('href="tel:+15555550100"', $drawn);
        $this->assertStringContainsString('href="https://example.org/ada"', $drawn);
        $this->assertStringContainsString('href="https://twitter.com/ada_l"', $drawn, 'a handle is the address of its account');
        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('data:', $drawn);
        $this->assertStringNotContainsString('vbscript:', $drawn);
        $this->assertStringNotContainsString('a b@c', $drawn);
    }

    /**
     * A member's page over HTTPS registers the service worker: the site's address is written for a script (an escape for each
     * character that a script could take for syntax), and the scope is the path of the site.
     */
    public function testTheServiceWorkerScriptIsWrittenForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on";' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString('navigator.serviceWorker.register(\'https' . '\\u003A\/\/', $drawn);
        $this->assertStringContainsString('service-worker.min.js\', {', $drawn);
        $this->assertStringContainsString("scope: '\\/'", $drawn);
        $this->assertStringContainsString('<link rel="manifest" href="https://', $drawn);
    }

    /**
     * The worker's scope is the path of the site's address: `/blog/` for a site in a subdirectory, the path as written when it has no
     * trailing slash, and `/` for a site at the root. This branch is not compared with the original on the http rig, so the exact lines
     * are asserted.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function serviceWorkerScopes(): array
    {
        $bs = chr(92);

        return [
            'a subdirectory' => ['https://example.org/blog/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blog' . $bs . '/service-worker.min.js', $bs . '/blog' . $bs . '/'],
            // the site's own addresses run into the path (`blogservice-worker.min.js`), as they always did: the address needs its slash
            'no trailing slash' => ['https://example.org/blog', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blogservice-worker.min.js', $bs . '/blog'],
            'the root' => ['https://example.org/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/service-worker.min.js', $bs . '/'],
        ];
    }

    /**
     * @dataProvider serviceWorkerScopes
     */
    public function testTheServiceWorkerScopeIsThePathOfTheSite(string $url, string $register, string $scope): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on"; \\Idno\\Core\\Idno::site()->config()->url = ' . $this->literal($url) . ';' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString("            navigator.serviceWorker.register('" . $register . "', {\n                scope: '" . $scope . "'\n            })", $drawn);
    }

    /**
     * A followed site's name is the site's word: the confirmation page for a feed whose name is `</h1><script>x()</script>` prints it as
     * text in the heading. The page cannot be driven over HTTP (the reader fails before it draws: Reader::getFeedDetails() calls
     * DOMDocument::loadHTML() statically, which PHP 8 refuses, whatever the feed holds), so the template is drawn with the feed object
     * the page would give it; the test route of the hostile feed is checked to say what this test says it does.
     */
    public function testAFollowedFeedsTitleIsEscaped(): void
    {
        $this->seedSite();
        $served = $this->site()->request('GET', '_test/hostile/feed');
        $this->assertStringContainsString(htmlspecialchars(\Tests\Fixtures\HostilePageController::NAME, ENT_QUOTES), $served->body, 'the route serves a feed named with a script');

        $drawn = (string) $this->runInSite($this->pageCode('/following/confirm/') . <<<'PHP'
        $feed = new \Idno\Entities\Reader\Feed();
        $feed->setTitle('</h1><script>x()</script>');
        $feed->setURL('https://feed.example/');
        $feed->setFeedURL('https://feed.example/rss"><script>y()</script>');
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['feed' => $feed, 'items' => []])->draw('following/confirm');
        PHP);

        $this->assertStringContainsString('Follow &lt;/h1&gt;&lt;script&gt;x()&lt;/script&gt;?', $drawn, 'the name is text in the heading');
        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertSame(1, substr_count($drawn, '</h1>'), 'the heading is closed once, by the template');
        $this->assertStringContainsString('name="feed" value="https://feed.example/"', $drawn);
    }

    /**
     * A subscription is drawn from the feed it follows, whose name and address came from another site: a name that closes the paragraph
     * and a `javascript:` address print as text, and the address links nowhere. (Drawn with the feed a subscription holds: a feed is
     * found in the database, which the page has none of; the test route of the hostile feed is what the page-level test above fetches.)
     */
    public function testASubscriptionsTitleAndAddressAreEscaped(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/following/') . <<<'PHP'
        $feed = new \Idno\Entities\Reader\Feed();
        $feed->setTitle('</h1><script>x()</script>');
        $feed->setURL('javascript:alert(1)');
        $feed->setFeedURL('javascript:alert(2)"><script>y()</script>');
        $subscription = new class extends \Idno\Entities\Reader\Subscription {
            public $heldFeed = false;

            public function getFeedObject()
            {
                return $this->heldFeed;
            }
        };
        $subscription->heldFeed = $feed;
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['subscriptions' => [$subscription]])->draw('following/home') . $template->__(['object' => $subscription])->draw('entity/Subscription');
        PHP);

        $this->assertStringContainsString('&lt;/h1&gt;&lt;script&gt;x()&lt;/script&gt;', $drawn);
        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertStringNotContainsString('javascript:alert(1)"', $drawn);
        $this->assertStringContainsString('<a href="">', $drawn, 'an address that is not http or https links nowhere');
        $this->assertStringContainsString('javascript:alert(2)&quot;&gt;&lt;script&gt;y()&lt;/script&gt;', $drawn, 'the feed address is text');
    }

    /**
     * A webmention's author is whoever sent it: a name that closes the tag, an address and a picture that are scripts. On every list of
     * annotations (replies, likes, shares, mentions and RSVPs) they print as text, and the author links and shows no picture.
     */
    public function testAHostileWebmentionAuthorRendersInertOnEveryAnnotationList(): void
    {
        $this->seedSite();

        $out = $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = null;
        foreach (\IdnoPlugins\Status\Status::get([], [], 50) as $candidate) {
            if ($status === null && $candidate->countAnnotations('reply') > 0) {
                $status = $candidate;
            }
        }
        $annotations = ['https://elsewhere.example/hostile' => [
            'permalink' => 'javascript:alert(3)',
            'owner_name' => '"><script>x()</script>',
            'owner_url' => 'javascript:alert(1)',
            'owner_image' => 'data:text/html,<script>y()</script>',
            'content' => '<script>z()</script>see https://ok.example/ "quoted" <img src=x onerror=w()>',
            'title' => '"><script>t()</script>',
            'rsvp' => 'yes',
            'time' => 1773000000,
        ]];
        $out = [];
        foreach (['replies', 'likes', 'shares', 'mentions', 'rsvps'] as $name) {
            $template = clone \Idno\Core\Idno::site()->template();
            $out[$name] = (string) $template->__(['annotations' => $annotations, 'object' => $status])->draw('entity/annotations/' . $name);
        }

        return $out;
        PHP);

        $this->assertIsArray($out);
        foreach ($out as $name => $drawn) {
            $this->assertNotSame('', trim((string) $drawn), $name . ' is drawn');
            $this->assertStringNotContainsString('<script', (string) $drawn, $name);
            $this->assertStringNotContainsString('<img src=x', (string) $drawn, $name);
            $this->assertStringNotContainsString('javascript:', (string) $drawn, $name);
            $this->assertStringNotContainsString('data:text', (string) $drawn, $name);
            $this->assertStringNotContainsString('<img class="idno-annotation-avatar"', (string) $drawn, $name . ' shows no picture for an address that is not http or https');
        }
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;x()&lt;/script&gt;', (string) $out['replies'], 'the name is text');
        $this->assertStringContainsString('rel="nofollow"', (string) $out['replies'], 'a link in a comment is nofollow');
    }

    /**
     * A member's note is escaped and its links are made: `see https://a.example/"><b>x` becomes text with a link in it and no `<b>`
     * element, in the feed of the home page and on the note's own page.
     */
    public function testAMembersStatusBodyIsEscapedAndLinked(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();
        $permalink = ltrim((string) parse_url($this->publishStatus($cookie, 'see https://a.example/"><b>x'), PHP_URL_PATH), '/');

        foreach (['' => 'the home page', $permalink => 'the permalink'] as $path => $where) {
            $page = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, $where);
            $this->assertStringContainsString('see <a href="https://a.example/&quot;&gt;&lt;b&gt;x"', $page->body, $where . ': the address is linked, whole, in an attribute');
            $this->assertStringNotContainsString('<b>x', $page->body, $where . ': no b element from the post');
        }
    }

    /**
     * The image picker writes the translated words it puts into the page's script for a script: each character that a script could take
     * for syntax, and each space, is an escape (the old template printed the translation between single quotes as it was, so a
     * translation with an apostrophe ended the string).
     */
    public function testTheImagePickersScriptWritesItsWordsForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/filepicker/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('file/picker/image');
        PHP);

        $this->assertStringContainsString("html('Choose\\u0020a\\u0020different\\u0020image')", $drawn);
        $this->assertStringNotContainsString("html('Choose a different image')", $drawn);
        $this->assertStringContainsString('Select an image', $drawn, 'the words of the page itself are as they were');
    }

    /**
     * What the file picker tells the editor that opened it is JSON for a script: the address of the file has no raw `/` or tag in it,
     * and it reads back as the address.
     */
    public function testTheFilePickersMessageIsWrittenForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/filepicker/') . <<<'PHP'
        $file = new \Idno\Entities\File();
        $file->file = ['_id' => 'abc"</script><script>x()</script>'];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['file' => $file])->draw('file/picker/donejs');
        PHP);

        $this->assertSame(1, substr_count($drawn, '</script>'), 'the id cannot end the script');
        $this->assertStringNotContainsString('<script>x()', $drawn);
        $this->assertSame(1, preg_match('/data: \{url: ("[^\n]*")\}\}, "\*"\);/', $drawn, $url));
        $this->assertSame(rtrim($this->baseUrl(), '/') . '/file/abc"</script><script>x()</script>', json_decode($url[1], true), 'the address, read back');
    }

    /**
     * The address a post replies to is the author's word, or another site's (a webmention or Micropub post names it): only an http or
     * https address is linked.
     */
    public function testAnInReplyToAddressThatIsNotHttpIsNotLinked(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $reply = new \IdnoPlugins\Status\Status();
        $reply->setOwner(\Idno\Entities\User::getByHandle('ada'));
        $reply->body = 'In reply';
        $reply->inreplyto = ['javascript:alert(1)', 'https://elsewhere.example/a?x=1&y=2'];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $reply])->draw('entity/shell');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('href=""', $drawn, 'an address that is refused is not linked: an empty href would be the post itself');
        $this->assertSame(1, substr_count($drawn, 'u-in-reply-to'), 'one link, for the address that is http');
        $this->assertStringContainsString('<a href="https://elsewhere.example/a?x=1&amp;y=2" rel="in-reply-to"', $drawn);
    }

    /**
     * An RSVP whose answer is not yes, maybe or no (a webmention may carry any word) is listed under "Other responses"; the old template
     * stopped with an undefined array key.
     */
    public function testAnRsvpWithAnAnswerThatIsNotYesMaybeOrNoIsListedAsOther(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = current(array_filter(\IdnoPlugins\Status\Status::get([], [], 50), static fn ($post) => $post->countAnnotations('reply') > 0));
        $annotation = static fn (string $name, string $answer, int $time): array => [
            'permalink' => 'https://example.org/' . $name, 'owner_name' => $name, 'owner_url' => 'https://example.org/', 'owner_image' => '',
            'content' => $name . ' says ' . $answer, 'time' => $time, 'rsvp' => $answer,
        ];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $status, 'annotations' => [
            'https://example.org/1' => $annotation('ann', 'perhaps', 1773000100),
            'https://example.org/2' => $annotation('bea', 'no', 1773000200),
            'https://example.org/3' => $annotation('cid', 'yes', 1773000300),
        ]])->draw('entity/annotations/rsvps');
        PHP);

        $this->assertSame(3, preg_match_all("#<h4>(.*?)</h4>#", $drawn, $titles));
        $this->assertSame(['Attending', 'Not attending', 'Other responses'], $titles[1] ?? [], 'the answers in their order, the unknown one last');
        $this->assertGreaterThan(strpos($drawn, 'Other responses'), strpos($drawn, 'ann says perhaps'));
    }

    /**
     * A link to where a post was syndicated is made from what a service returned: its address links only if it is http or https, and
     * its identifier is text.
     */
    public function testASyndicationLinkIsEscapedAndOnlyHttpIsLinked(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = current(array_filter(\IdnoPlugins\Status\Status::get([], [], 50), static fn ($post) => $post->countAnnotations('reply') > 0));
        $status->posse = ['zz' => [
            ['url' => 'javascript:alert(1)', 'identifier' => '<b>x</b> & "y"'],
            ['url' => 'https://x.example/?a=1&b=2', 'identifier' => 'ok'],
        ]];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $status])->draw('content/syndication/links');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('<b>x</b>', $drawn);
        $this->assertStringContainsString('<a href="https://x.example/?a=1&amp;b=2"', $drawn);
        $this->assertSame(1, substr_count($drawn, 'class="u-syndication"'), 'only the http address is a link');
        $this->assertStringNotContainsString('href=""', $drawn);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;', $drawn, 'the identifier of the refused link is still shown, as text');
    }

    /**
     * A member's profile addresses are fields of the profile: the text is escaped (an address with a tag or a quote in it), and an
     * address that is a script links to `http://javascript:...`, as the address fixer has always made it, never to a script.
     */
    public function testAProfileFieldIsEscapedAndNeverLinksToAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['javascript:alert(1)', 'https://x.example/"><script>x()</script>', 'mailto:a@b.example?subject=<i>']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('entity/User/profile/fields');
        PHP);

        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertStringNotContainsString('<i>', $drawn);
        $this->assertStringNotContainsString('href="javascript:', $drawn);
        $this->assertSame(3, substr_count($drawn, 'rel="me"'));
    }

    /**
     * The contact links of a profile are drawn for mailto, sms, tel and facetime addresses only: an address with another scheme (the old
     * template drew it, with an undefined icon, and stopped) is not a contact link.
     */
    public function testTheContactLinksPrintOnlyTheSchemesTheyKnow(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['mailto:a@example.com', 'javascript:alert(1)', 'tel:+15555550100', 'https://example.org/', 'example.org/bare', 'sms:+1555', 'facetime:a@example.com']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('entity/User/profile/contact');
        PHP);

        $this->assertSame(4, substr_count($drawn, 'rel="me"'));
        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringContainsString('class="u-email btn"', $drawn);
        $this->assertStringContainsString('class="p-facetime btn"', $drawn);
    }

    /**
     * The ActivityPub link in the head of a post's page is the actor's address when the last icon the page looked up was drawn as a
     * default avatar (the lookup leaves its user on the page's template, and the head uses it as the page's user), and the post's own
     * address when every picture was a real one (nothing was left). Both branches, with the owner's picture set and not set.
     */
    public function testTheActivityPubLinkOfAPostsHeadFollowsWhetherTheOwnerHasAPicture(): void
    {
        $paths = $this->seedSite();
        $link = static function (string $body): string {
            self::assertSame(1, preg_match('#<link rel="alternate" type="application/activity\+json" href="([^"]*)"#', $body, $match), 'the page has one ActivityPub link');

            return html_entity_decode($match[1], ENT_QUOTES);
        };

        $withoutPicture = $this->site()->request('GET', ltrim($paths['status'], '/'));
        $this->assertSame(200, $withoutPicture->status);
        $this->assertSame(rtrim($this->baseUrl(), '/') . '/actor/ada', $link($withoutPicture->body), 'the owner has no picture: a default avatar is drawn, and its user is the page\'s');

        $this->runInSite(<<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $ada->icon = '0123456789abcdef0123456789abcdef';
        $ada->save(true);

        return true;
        PHP);
        $withPicture = $this->site()->request('GET', ltrim($paths['status'], '/'));
        $this->assertSame(200, $withPicture->status);
        $this->assertStringStartsWith(rtrim($this->baseUrl(), '/') . '/view/', $link($withPicture->body), 'a real picture leaves no user behind: the link is the post\'s own address');
        $this->assertStringNotContainsString('/actor/ada', $link($withPicture->body));
    }

    /**
     * The delete link of an RSVP names the RSVP by its address, as the links of the other annotation lists do: posted by the owner of
     * the post, it removes that RSVP and no other. (The old list renumbered the RSVPs when it sorted them, so its link named "0", which
     * the delete page read as no address, and the delete did nothing.)
     */
    public function testAnRsvpsDeleteLinkRemovesThatRsvpAndNoOther(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();
        $id = $this->literal((string) $paths['ids']['event']);
        $this->runInSite(<<<PHP
        \$event = \\Idno\\Common\\Entity::getByID($id);
        \$make = static fn (string \$name, int \$time): array => ['permalink' => 'https://example.org/' . \$name, 'owner_name' => \$name, 'owner_url' => 'https://example.org/' . \$name, 'owner_image' => '', 'content' => \$name . ' will come', 'time' => \$time, 'title' => '', 'rsvp' => 'yes'];
        \$event->annotations = ['rsvp' => [
            \$event->getURL() . '/annotations/first' => \$make('first', 1773000100),
            \$event->getURL() . '/annotations/second' => \$make('second', 1773000200),
        ]];
        \$event->save(true);

        return true;
        PHP);

        $page = $this->site()->request('GET', ltrim($paths['event'], '/'), ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        $this->assertSame(2, preg_match_all('#<a data-form-id="[^"]*"[^>]*href="([^"]*/annotation/delete\?permalink=([^"]*))"#', $page->body, $links), 'one delete link for each RSVP');
        $decoded = array_map(static fn (string $value): string => base64_decode(strtr($value, '-_,', '+/='), true) ?: '', $links[2]);
        $this->assertStringEndsWith('/annotations/first', $decoded[0]);
        $this->assertStringEndsWith('/annotations/second', $decoded[1], 'the link names the RSVP by its address');

        $action = substr(html_entity_decode($links[1][1], ENT_QUOTES), strlen(rtrim($this->baseUrl(), '/')) + 1);
        $response = (new \Tests\Acceptance\Browser($this->site()))->submit(ltrim($paths['event'], '/'), $action, [], $cookie);
        $this->assertContains($response->status, [200, 302, 303], $this->serverLog());

        $left = $this->runInSite(<<<PHP
        return array_keys(\\Idno\\Common\\Entity::getByID($id)->getAnnotations('rsvp') ?: []);
        PHP);
        $this->assertCount(1, $left, 'one RSVP is left');
        $this->assertStringEndsWith('/annotations/first', $left[0], 'the other one, not the one deleted');
    }

    /**
     * The picture and address of an annotation's author come from whoever sent it: an address that is a script, and a picture with a quote
     * in it, are not printed in the image link.
     */
    public function testAHostileAnnotationImageIsInert(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['annotation' => ['owner_url' => 'javascript:alert(1)', 'owner_image' => 'https://x.example/a.png" onerror="y()', 'owner_name' => '"><script>z()</script>']])->draw('entity/annotations/image');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('onerror', $drawn);
        $this->assertStringNotContainsString('<script', $drawn);
        $this->assertSame(1, substr_count($drawn, '<img'), 'one image');
        $this->assertStringContainsString('src=""', $drawn, 'an address that is not http(s) prints nothing');
        $this->assertStringContainsString('alt="&quot;&gt;&lt;script&gt;z()&lt;/script&gt;"', $drawn);
    }

    /**
     * The onboarding shell's toggle script inserts `data-on` and `data-off` as HTML: the name of a syndication account in them is
     * HTML-escaped inside the attribute, so what the script inserts is the name as text.
     */
    public function testAnAccountNameInASyndicationToggleStaysTextWhenTheToggleInsertsItAsHtml(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/begin/connect/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['service' => 'zz', 'username' => 'u', 'name' => 'Carol <b>"C"</b> & co', 'disabled' => '', 'selected' => false])->draw('content/syndication/account');
        PHP);

        $this->assertSame(1, preg_match('/data-on="([^"]*)"/', $drawn, $match));
        $inserted = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<b>', $inserted, 'what the toggle inserts holds no element made from the name');
        $this->assertStringContainsString('Carol', $inserted);
        $this->assertStringContainsString('&lt;b&gt;', $inserted, 'the name, escaped for HTML');
        $this->assertStringContainsString('<i class="fa fa-share-square-o"></i>', $inserted, 'the icon is the markup it was');
    }

    /**
     * PHP that makes the process a request for $uri served by a page: what the shell's templates read.
     */
    private function pageCode(string $uri): string
    {
        $uri = $this->literal($uri);

        return <<<PHP
        \$uri = $uri;
        \$_SERVER['REQUEST_URI'] = \$uri;
        \\Idno\\Core\\Idno::site()->setCurrentPage(new \\Idno\\Pages\\Onboarding\\Begin());
        \\Idno\\Core\\Idno::container()->get('request_stack')->push(new \\Symfony\\Component\\HttpFoundation\\Request([], [], [], [], [], ['REQUEST_URI' => \$uri]));

        PHP;
    }

    /**
     * A GET with the path as it is written, which a client library would encode: the body of the answer.
     */
    private function rawGet(string $path): string
    {
        $parts = parse_url($this->baseUrl());
        $socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $code, $message, 10);
        $this->assertIsResource($socket, $message);
        fwrite($socket, 'GET ' . $path . " HTTP/1.1\r\nHost: " . $parts['host'] . ':' . $parts['port'] . "\r\nConnection: close\r\n\r\n");
        $answer = (string) stream_get_contents($socket);
        fclose($socket);
        $this->assertStringStartsWith('HTTP/1.1 ', $answer);

        return substr($answer, (int) strpos($answer, "\r\n\r\n") + 4);
    }
}
