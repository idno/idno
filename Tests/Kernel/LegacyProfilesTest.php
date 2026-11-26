<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the profile page asks the site (`Idno\Legacy\LegacyProfiles`), each method against the legacy expression it wraps, on the same accounts and posts of
 * a site of the test's own: an administrator with posts of several content types (one a draft, one private to her), and a member with none; and the
 * profile's webmention receiver, which the home page of a single-user site hands its mentions to, through the real container.
 */
final class LegacyProfilesTest extends PrivateSiteTestCase
{
    private const SEED = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
    $ada = new \Idno\Entities\User();
    $ada->handle = 'ada';
    $ada->email = 'ada@example.com';
    $ada->setTitle('Ada Admin');
    $ada->setPassword('correct-horse-battery-9');
    $ada->setAdmin(true);
    $ada->created = $at('2026-01-01 09:00:00');
    $ada->save(true);
    $carol = new \Idno\Entities\User();
    $carol->handle = 'carol';
    $carol->email = 'carol@example.com';
    $carol->setTitle('Carol Member');
    $carol->setPassword('correct-horse-battery-9');
    $carol->created = $at('2026-01-02 09:00:00');
    $carol->save(true);
    $make = static function (\Idno\Common\Entity $entity, string $id, string $when, array $fields, string $access = 'PUBLIC', string $status = 'published') use ($ada, $at): \Idno\Common\Entity {
        $entity->_id = $id;
        foreach ($fields as $name => $value) {
            $entity->$name = $value;
        }
        $entity->setOwner($ada);
        $entity->setAccess($access);
        $entity->setPublishStatus($status);
        $entity->created = $at($when);
        $entity->setSlugResilient((string) ($fields['title'] ?? $fields['body']));
        $entity->save(true);

        return $entity;
    };
    $make(new \IdnoPlugins\Status\Status(), '00000000000040008000000000000041', '2026-03-01 12:00:00', ['body' => 'Ada writes a public note.']);
    $make(new \IdnoPlugins\Status\Status(), '00000000000040008000000000000042', '2026-03-02 12:00:00', ['body' => 'Ada writes a note to herself.'], $ada->getUUID());
    $make(new \IdnoPlugins\Status\Status(), '00000000000040008000000000000043', '2026-03-03 12:00:00', ['body' => 'Ada has a draft.'], 'PUBLIC', 'draft');
    $make(new \IdnoPlugins\Text\Entry(), '00000000000040008000000000000044', '2026-03-04 12:00:00', ['title' => 'An article by Ada', 'body' => '<p>Words.</p>']);
    $make(new \IdnoPlugins\Photo\Photo(), '00000000000040008000000000000045', '2026-03-05 12:00:00', ['title' => 'A photo by Ada', 'body' => 'Caption.']);
    PHP;

    /**
     * What a visitor asks: nobody is signed in.
     */
    public function testWhatAVisitorAsksAboutAProfile(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $profiles = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Profile\Profiles::class);
        $out = [];
        $out['byHandle'] = [$profiles->byHandle('ada')?->getUUID(), \Idno\Entities\User::getByHandle('ada')->getUUID(), $profiles->byHandle('nobody'), $profiles->byHandle('')];
        $out['upper'] = [$profiles->byHandle('ADA')?->getUUID(), \Idno\Entities\User::getByHandle('ADA') ? \Idno\Entities\User::getByHandle('ADA')->getUUID() : null];
        $user = $profiles->byHandle('ada');
        $out['title'] = [$profiles->title($user), $user->getTitle()];
        $out['handle'] = [$profiles->handle($user), $user->getHandle()];
        $out['url'] = [$profiles->url($user), $user->getURL()];
        $out['siteTitle'] = [$profiles->siteTitle(), $site->config()->title];
        $out['updated'] = [$profiles->updated($user), (int) $user->updated];

        $out['defaultTypes'] = $profiles->typesFor(null);
        $out['all'] = [$profiles->typesFor('all'), \Idno\Common\ContentType::getRegisteredClasses()];
        $friendly = [];
        foreach (['notes', 'articles', 'photos', 'articles/notes', 'notes/', 'notes/all', 'nonsense', '//', '0', 'ALL', 'places'] as $path) {
            $legacy = [];
            foreach (array_filter(explode('/', $path)) as $part) {
                if ($part == 'all') {
                    $legacy = \Idno\Common\ContentType::getRegisteredClasses();
                    break;
                }
                if ($class = \Idno\Common\ContentType::categoryTitleToClass($part)) {
                    $legacy[] = $class;
                }
            }
            $friendly[$path] = [$profiles->typesFor($path), $legacy];
        }
        $out['friendly'] = $friendly;

        $stream = [];
        foreach ([[['IdnoPlugins\Status\Status', 'IdnoPlugins\Text\Entry'], 0], [\Idno\Common\ContentType::getRegisteredClasses(), 0], [[], 0], [['IdnoPlugins\Status\Status'], 1], [['IdnoPlugins\Status\Status'], 100]] as [$types, $offset]) {
            $asked = $profiles->stream($user, $types, $offset);
            $criteria = ['owner' => $user->getUUID(), 'publish_status' => 'published'];
            $legacyCount = \Idno\Common\Entity::countFromX($types, $criteria);
            $legacyItems = \Idno\Common\Entity::getFromX($types, $criteria, [], $site->config()->items_per_page, $offset);
            $stream[] = [
                'count' => [$asked['count'], $legacyCount],
                'items' => [array_map(static fn ($e) => $e->getUUID(), $asked['items']), array_map(static fn ($e) => $e->getUUID(), $legacyItems)],
            ];
        }
        $out['stream'] = $stream;
        return $out;
        PHP);

        $this->assertNotNull($answers['byHandle'][0]);
        $this->assertSame($answers['byHandle'][1], $answers['byHandle'][0]);
        $this->assertNull($answers['byHandle'][2], 'nobody has the handle');
        $this->assertNull($answers['byHandle'][3]);
        $this->assertSame($answers['upper'][1], $answers['upper'][0], 'the handle is found as the account lookup finds it');
        $this->assertSame($answers['title'][1], $answers['title'][0]);
        $this->assertSame('Ada Admin', $answers['title'][0]);
        $this->assertSame($answers['handle'][1], $answers['handle'][0]);
        $this->assertSame($answers['url'][1], $answers['url'][0]);
        $this->assertSame((string) $answers['siteTitle'][1], $answers['siteTitle'][0]);
        $this->assertSame($answers['updated'][1], $answers['updated'][0]);
        $this->assertSame(['IdnoPlugins\Status\Status', 'IdnoPlugins\Text\Entry'], $answers['defaultTypes'], 'no friendly path: notes and articles');
        $this->assertSame($answers['all'][1], $answers['all'][0]);
        $this->assertNotSame([], $answers['all'][0]);
        foreach ($answers['friendly'] as $path => [$asked, $legacy]) {
            $this->assertSame($legacy, $asked, (string) $path);
        }
        $this->assertSame([], $answers['friendly']['nonsense'][0], 'a path that names no type lists every type');
        $this->assertSame([], $answers['friendly']['//'][0]);
        foreach ($answers['stream'] as $n => $one) {
            $this->assertSame($one['count'][1], $one['count'][0], 'count ' . $n);
            $this->assertSame($one['items'][1], $one['items'][0], 'items ' . $n);
        }
        $this->assertSame(2, $answers['stream'][0]['count'][0], 'a visitor sees the published public posts of the default types: the note and the article');
    }

    /**
     * What the member asks: the account is theirs, so they may save it.
     */
    public function testTheMemberSavesTheirOwnAccountAndNobodyElsesAndTheRefererIsKeptOnTheSite(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $profiles = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Profile\Profiles::class);
        $out = [];
        $ada = \Idno\Entities\User::getByHandle('ada');
        $site->setCurrentPage(new \Idno\Legacy\BarePage());
        $site->session()->logUserOn(\Idno\Entities\User::getByHandle('carol'));
        $out['otherSaved'] = $profiles->saveFromInput($ada);
        $out['otherName'] = \Idno\Entities\User::getByHandle('ada')->getTitle();
        $carol = $profiles->byHandle('carol');
        $out['ownSaved'] = $profiles->saveFromInput($carol);

        $referrer = static function (string $header) use ($profiles): string {
            $request = \Symfony\Component\HttpFoundation\Request::create('/profile/ada', 'POST', [], [], [], $header === '' ? [] : ['HTTP_REFERER' => $header]);

            return $profiles->referrer(new \Idno\Http\Page\PageContext($request, new \Idno\Http\Page\SitePage('Idno\Pages\User\View', shell: true)));
        };
        $base = $site->config()->getDisplayURL();
        $out['base'] = $base;
        $out['referrer'] = [
            'none' => [$referrer(''), \Idno\Pages\Session\Login::sanitizeRedirectUrl('')],
            'own' => [$referrer($base . 'somewhere/else'), \Idno\Pages\Session\Login::sanitizeRedirectUrl($base . 'somewhere/else')],
            'foreign' => [$referrer('https://elsewhere.example/page'), \Idno\Pages\Session\Login::sanitizeRedirectUrl('https://elsewhere.example/page')],
            'protocol-relative' => [$referrer('//elsewhere.example/page'), \Idno\Pages\Session\Login::sanitizeRedirectUrl('//elsewhere.example/page')],
        ];

        return $out;
        PHP);

        $this->assertFalse($answers['otherSaved'], 'another member does not save the account');
        $this->assertSame('Ada Admin', $answers['otherName']);
        $this->assertTrue($answers['ownSaved'], 'the member saves their own account');
        foreach ($answers['referrer'] as $kind => [$asked, $legacy]) {
            $this->assertSame($legacy, $asked, $kind);
        }
        $this->assertSame($answers['base'], $answers['referrer']['foreign'][0], 'a page of another site is not where a request is sent back to');
    }

    /**
     * The receiver of the profile's webmentions, and the home page of a single-user site handing its own to it, as the container wires them.
     */
    public function testTheProfileTakesWebmentionsAndTheHomePageOfASingleUserSiteHandsItsOwnToIt(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $container = \Idno\Core\Idno::container();
        $receiver = $container->get(\Idno\Http\Controller\Profile\ProfilePages::class);
        $log = tempnam(sys_get_temp_dir(), 'idno-log-');
        ini_set('error_log', $log);
        $site->logging()->setLogLevel(\Idno\Core\Logging::LEVEL_DEBUG);
        $source = 'http://foo.bar/mention';
        $target = $site->config()->getDisplayURL() . 'profile/ada';
        $html = '<div class="h-entry"><a class="p-author h-card" href="http://foo.bar">Foo Bar</a><span class="p-name e-content">test mention of ' . $target . '</span></div>';
        $mf2 = (new \Mf2\Parser($html, $source))->parse();
        $mention = new \Idno\Http\Webmention\ReceivedWebmention($source, $target, ['response' => 200, 'content' => $html], $mf2);

        $out = [];
        $out['accepted'] = $receiver->receiveWebmention('idno_profile', ['ada'], $mention);
        $out['deleted'] = $receiver->receiveWebmention('idno_profile', ['ada'], new \Idno\Http\Webmention\ReceivedWebmention($source, $target, ['response' => 410, 'content' => ''], $mf2));
        $out['nobody'] = $receiver->receiveWebmention('idno_profile', ['nowhere'], $mention);
        $out['route'] = $receiver->receiveWebmention('idno_permalink', ['ada'], $mention);

        $site->config()->single_user = true;
        $home = $container->get(\Idno\Http\Controller\Home\HomePages::class);
        $out['single'] = $home->receiveWebmention('idno_home', [], new \Idno\Http\Webmention\ReceivedWebmention($source, $site->config()->getDisplayURL(), ['response' => 200, 'content' => $html], $mf2));
        $site->config()->single_user = false;
        $out['multi'] = $home->receiveWebmention('idno_home', [], new \Idno\Http\Webmention\ReceivedWebmention($source, $site->config()->getDisplayURL(), ['response' => 200, 'content' => $html], $mf2));
        $out['logged'] = file_get_contents($log);
        unlink($log);

        return $out;
        PHP);

        $this->assertTrue($answers['accepted'], 'the profile took the mention');
        $this->assertTrue($answers['deleted'], 'and accepts a non-200 one, doing nothing');
        $this->assertFalse($answers['nobody'], 'nobody has the handle');
        $this->assertFalse($answers['route'], 'a route the profile does not take');
        $this->assertTrue($answers['single'], 'the home page of a single-user site passes the mention on to the profile');
        $this->assertFalse($answers['multi'], 'a multi-user site has no page there');
        $this->assertStringContainsString('received user mention from http://foo.bar/mention', $answers['logged']);
        $this->assertStringContainsString('found target user ada', $answers['logged']);
        $this->assertStringContainsString('pass on webmention to solo user: ada', $answers['logged']);
        $this->assertStringContainsString('could not process user mention, no user for handle nowhere', $answers['logged']);
    }
}
