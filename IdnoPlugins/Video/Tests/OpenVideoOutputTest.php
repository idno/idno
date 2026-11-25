<?php

namespace IdnoPlugins\Video\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Video\OpenVideo;
    use IdnoPlugins\Video\Pages\Admin;
    use IdnoPlugins\Video\Video;

    /**
     * Posting and drawing videos from PeerTube and Loops sites: the host is asked once,
     * when the post is saved; the player is sandboxed; and the site's admin can turn these
     * services off or block a host, which takes the player away from posts already made.
     */
    class OpenVideoOutputTest extends \Tests\IdnoTestCase
    {

        const UUID = '913b145c-ab72-4bea-a2a0-6ee692616414';
        const SHORT = 'iWa2a6GGoZqnLinqgeaYR3';

        private $toDelete = [];
        private $previousTemplateType;
        private $previousPage;
        private $settings = [];

        /// The addresses asked for, in order
        private $asked = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Video');
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->previousPage = Idno::site()->currentPage();
            foreach (['video_open_services', 'video_blocked_hosts'] as $name) {
                $this->settings[$name] = Idno::site()->config()->$name;
                Idno::site()->config()->$name = null;
            }
            $this->swapUser($this->admin());

            // A PeerTube site and a Loops site, and nothing else
            $pages = [
                'https://tube.example.org/api/v1/videos/' . self::SHORT => [
                    'uuid' => self::UUID, 'shortUUID' => self::SHORT, 'name' => 'Studio <b>log</b> 33',
                    'embedPath' => '/videos/embed/' . self::SHORT, 'aspectRatio' => 1.7778,
                ],
                'https://loops.example.org/.well-known/nodeinfo' => [
                    'links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => 'https://loops.example.org/nodeinfo/2.1']],
                ],
                'https://loops.example.org/nodeinfo/2.1' => ['software' => ['name' => 'loops']],
            ];
            $this->asked = [];
            OpenVideo::$fetch = function (string $url) use ($pages) {
                $this->asked[] = $url;

                return isset($pages[$url]) ? ['response' => 200, 'content' => json_encode($pages[$url])] : ['response' => 404, 'content' => ''];
            };
        }

        public function tearDown(): void
        {
            OpenVideo::$fetch = null;
            foreach ($this->settings as $name => $value) {
                Idno::site()->config()->$name = $value;
            }
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            Idno::site()->session()->getAndFlushMessages();
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
        }

        public static function lookProvider(): array
        {
            return [
                'no theme' => ['default'],
                'Twenty26' => ['modern'],
            ];
        }

        private function draw(Video $video, string $templateType): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType($templateType);
            $html = $t->__(['object' => $video, 'feed_view' => false])->draw('entity/Video');
            unset($t->vars['object'], $t->vars['feed_view']);

            return (string) $html;
        }

        private function iframes(string $html): array
        {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
            libxml_clear_errors();

            return array_values(array_filter(
                iterator_to_array($doc->getElementsByTagName('iframe')),
                function ($iframe) {
                    return $iframe->hasAttribute('src');
                }
            ));
        }

        private function page(array $input): void
        {
            $page = new \Idno\Pages\Homepage();
            foreach ($input + ['access' => 'PUBLIC'] as $name => $value) {
                $page->setInput($name, $value);
            }
            Idno::site()->setCurrentPage($page);
        }

        private function peertube(): Video
        {
            $video = new Video();
            $this->assertTrue($video->setVideoAddress('https://tube.example.org/w/' . self::SHORT));
            $video->title = 'A film';

            return $video;
        }

        public function testPostingAPeerTubeVideo()
        {
            $this->page(['video' => 'https://tube.example.org/w/' . self::SHORT . '?start=30', 'body' => 'From our own PeerTube']);

            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $this->assertSame(['https://tube.example.org/api/v1/videos/' . self::SHORT], $this->asked);
            $saved = Video::getByID($video->getID());
            $this->assertSame('peertube', $saved->video_provider);
            $this->assertSame('tube.example.org', $saved->video_host);
            $this->assertSame(self::UUID, $saved->video_id);
            $this->assertSame('Studio log 33', $saved->getTitle(), 'A post with no title takes the one its host has, as plain text.');
            $this->assertSame('https://tube.example.org/videos/embed/' . self::UUID . '?start=30s', $saved->getVideo()->getEmbedUrl());

            // Saving again with the same address asks nothing, and keeps the member's title
            $this->asked = [];
            OpenVideo::$fetch = function (string $url) {
                $this->asked[] = $url;

                return ['response' => 500, 'content' => ''];
            };
            $this->page(['video' => 'https://tube.example.org/w/' . self::SHORT . '?start=30', 'title' => 'My title']);
            $this->assertTrue($saved->saveDataFromInput(), 'A post can be edited while its host is down.');
            $this->assertSame([], $this->asked);
            $this->assertSame('My title', Video::getByID($video->getID())->getTitle());
        }

        public function testPostingALoopsVideo()
        {
            $this->page(['video' => 'https://loops.example.org/v/gm_hz4W_iG', 'title' => 'A loop']);

            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $this->assertSame('loops', $video->video_provider);
            $this->assertSame('loops.example.org', $video->video_host);
            $this->assertSame('https://loops.example.org/embed/gm_hz4W_iG', $video->getVideo()->getEmbedUrl());
            $this->assertTrue($video->getVideo()->isPortrait());
        }

        public function testAHostThatDoesNotHaveTheVideoIsRefused()
        {
            $this->page(['video' => 'https://social.example.org/w/' . self::SHORT, 'title' => 'A film']);

            $video = new Video();
            $this->assertFalse($video->saveDataFromInput());
            $this->assertEmpty($video->getID());

            $messages = Idno::site()->session()->getAndFlushMessages();
            $this->assertCount(1, $messages);
            $this->assertStringContainsString('social.example.org', $messages[0]['message'], 'The message should say which site was asked.');
            $this->assertStringNotContainsString('<', $messages[0]['message']);
        }

        public function testNothingIsAskedWhenTheAdminHasTurnedOpenServicesOff()
        {
            Idno::site()->config()->video_open_services = false;
            $this->page(['video' => 'https://tube.example.org/w/' . self::SHORT, 'title' => 'A film']);

            $video = new Video();
            $this->assertFalse($video->saveDataFromInput());
            $this->assertSame([], $this->asked);

            $message = Idno::site()->session()->getAndFlushMessages()[0]['message'];
            $this->assertStringContainsString('YouTube', $message);
            $this->assertStringNotContainsString('PeerTube', $message);
        }

        public function testNothingIsAskedOfABlockedHost()
        {
            Idno::site()->config()->video_blocked_hosts = ['example.org'];
            $this->page(['video' => 'https://tube.example.org/w/' . self::SHORT, 'title' => 'A film']);

            $video = new Video();
            $this->assertFalse($video->saveDataFromInput());
            $this->assertSame([], $this->asked, 'A host under a blocked domain is blocked too.');
        }

        public function testServicesNamedToMembers()
        {
            $this->assertSame(['YouTube', 'Vimeo', 'Dailymotion', 'TikTok', 'Bluesky', 'PeerTube', 'Loops'], Video::serviceNames(), 'Open services are allowed unless the admin has turned them off.');

            Idno::site()->config()->video_open_services = false;
            $this->assertSame(['YouTube', 'Vimeo', 'Dailymotion', 'TikTok', 'Bluesky'], Video::serviceNames());
        }

        /**
         * @dataProvider lookProvider
         */
        public function testPlayerOnAnOpenServiceIsSandboxed($look)
        {
            $html = $this->draw($this->peertube(), $look);

            $iframes = $this->iframes($html);
            $this->assertCount(1, $iframes);
            $iframe = $iframes[0];
            $this->assertSame('https://tube.example.org/videos/embed/' . self::UUID, $iframe->getAttribute('src'));

            $sandbox = explode(' ', $iframe->getAttribute('sandbox'));
            $this->assertContains('allow-scripts', $sandbox);
            foreach (['allow-top-navigation', 'allow-top-navigation-by-user-activation', 'allow-popups', 'allow-forms', 'allow-modals', 'allow-downloads'] as $token) {
                $this->assertNotContains($token, $sandbox, 'A player on a host nobody has vouched for must not be able to do this.');
            }
            $this->assertSame('no-referrer', $iframe->getAttribute('referrerpolicy'));
            $this->assertSame('lazy', $iframe->getAttribute('loading'));
            $this->assertStringNotContainsString('autoplay', $iframe->getAttribute('allow'));

            $this->assertStringContainsString('Watch on tube.example.org', $html, 'The caption should say where the player comes from.');
            $this->assertMatchesRegularExpression('~<a href="https://tube.example.org/w/' . self::SHORT . '"[^>]*class="[^"]*\bu-video\b~', $html);
        }

        /**
         * @dataProvider lookProvider
         */
        public function testPlayerOnAServiceWithItsOwnHostsIsNotSandboxed($look)
        {
            $video = new Video();
            $video->setVideoAddress('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

            $iframe = $this->iframes($this->draw($video, $look))[0];

            $this->assertFalse($iframe->hasAttribute('sandbox'));
            $this->assertSame('strict-origin-when-cross-origin', $iframe->getAttribute('referrerpolicy'));
            $this->assertSame([], $this->asked);
        }

        /**
         * @dataProvider lookProvider
         */
        public function testTurningOpenServicesOffTakesThePlayerAway($look)
        {
            $video = $this->peertube();
            $this->assertCount(1, $this->iframes($this->draw($video, $look)));

            Idno::site()->config()->video_open_services = false;
            $html = $this->draw($video, $look);
            $this->assertCount(0, $this->iframes($html));
            $this->assertStringContainsString('href="https://tube.example.org/w/' . self::SHORT . '"', $html, 'The link remains.');

            Idno::site()->config()->video_open_services = true;
            Idno::site()->config()->video_blocked_hosts = ['tube.example.org'];
            $this->assertCount(0, $this->iframes($this->draw($video, $look)));

            Idno::site()->config()->video_blocked_hosts = ['othertube.example.org', 'ube.example.org'];
            $this->assertCount(1, $this->iframes($this->draw($video, $look)), 'Only the blocked host and hosts under it are blocked.');
        }

        public static function tamperedHostProvider(): array
        {
            $values = [
                'with a path'       => ['evil.example/x'],
                'with a handler'    => ['tube.example.org" onload="alert(1)'],
                'with markup'       => ['"><script>alert(1)</script>'],
                'with userinfo'     => ['tube.example.org@evil.example'],
                'an IP address'     => ['127.0.0.1'],
                'a fixed service'   => ['www.youtube.com'],
                'nothing'           => [''],
            ];
            $cases = [];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($values as $name => $value) {
                    $cases["$lookName, $name"] = array_merge($look, $value);
                }
            }

            return $cases;
        }

        /**
         * @dataProvider tamperedHostProvider
         */
        public function testTamperedHostHasNoPlayer($look, $host)
        {
            $video = $this->peertube();
            $video->video_host = $host;

            $html = $this->draw($video, $look);

            $this->assertCount(0, $this->iframes($html));
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onload="', $html);
        }

        public function testBlockedHostsFromWhatAnAdminTypes()
        {
            $this->assertSame(
                ['tube.example.org', 'bad.example', 'other.example'],
                Admin::cleanHosts("Tube.Example.org\nhttps://bad.example/w/abc?x=1 , other.example;tube.example.org\n127.0.0.1\nlocalhost\n<script>\n\"x\".example")
            );
            $this->assertSame([], Admin::cleanHosts(''));
            $this->assertSame([], Admin::cleanHosts(['tube.example.org']));
        }

        /**
         * @dataProvider lookProvider
         */
        public function testAdminFormIsDrawn($look)
        {
            // The admin menu marks the page that was asked for
            $previous = $_SERVER['REQUEST_URI'] ?? null;
            $_SERVER['REQUEST_URI'] = '/admin/video/';
            Idno::site()->template()->setTemplateType($look);
            try {
                $html = (string) Idno::site()->template()->__(['open' => true, 'blocked' => ['bad.example', '"><script>alert(1)</script>']])->draw('video/admin');
            } finally {
                if ($previous === null) {
                    unset($_SERVER['REQUEST_URI']);
                } else {
                    $_SERVER['REQUEST_URI'] = $previous;
                }
            }

            $this->assertMatchesRegularExpression('~<input[^>]*name="video_open_services"[^>]*checked~', $html);
            $this->assertStringContainsString('bad.example', $html);
            $this->assertStringNotContainsString('<script>alert(1)', $html);
        }
    }

}
