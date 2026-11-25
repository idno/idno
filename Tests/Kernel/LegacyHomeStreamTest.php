<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * The home stream (`Idno\Http\Controller\Home\HomeStream`, `Idno\Legacy\LegacyHomeStream`) against what the legacy home page computed for
 * the same input, on a site of the test's own with a seeded set of posts (public, private, a draft, three content types) and three kinds
 * of viewer. The reference in each test is the home page's own code as it was before the stream was extracted, written out in `REFERENCE`:
 * the two are compared for every combination of viewer, friendly path, query, requested types and offset, so the extraction is proven and
 * not assumed. (The whole page, the statements it makes and its feeds are pinned by the golden pages and the page tests.)
 */
final class LegacyHomeStreamTest extends PrivateSiteTestCase
{
    /** The seed: the site's users and posts, written as the rendered-pages fixture writes them, and a function that makes a viewer the session's user. */
    private const SEED = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $make = static function (string $handle, bool $admin): \Idno\Entities\User {
        $user = new \Idno\Entities\User();
        $user->handle = $handle;
        $user->email = $handle . '@example.com';
        $user->setTitle(ucfirst($handle));
        $user->setPassword('correct-horse-battery-9');
        $user->setAdmin($admin);
        $user->save(true);

        return $user;
    };
    $ada = $make('ada', true);
    $bob = $make('bob', false);
    $n = 0;
    $post = static function (\Idno\Common\Entity $entity, $owner, array $fields, string $access = 'PUBLIC', string $status = 'published') use (&$n) {
        foreach ($fields as $name => $value) {
            $entity->$name = $value;
        }
        $entity->setOwner($owner);
        $entity->setAccess($access);
        $entity->setPublishStatus($status);
        $entity->created = 1_700_000_000 + (++$n) * 3600;
        $entity->save(true);

        return $entity;
    };
    for ($i = 1; $i <= 6; $i++) {
        $post(new \IdnoPlugins\Status\Status(), $i % 2 ? $ada : $bob, ['body' => "A note about gardens, number $i #garden"]);
    }
    $post(new \IdnoPlugins\Text\Entry(), $ada, ['title' => 'An article about the garden', 'body' => '<p>Gardens and templates.</p>']);
    $post(new \IdnoPlugins\Like\Like(), $bob, ['body' => 'https://example.org/garden', 'bookmarkof' => 'https://example.org/garden', 'pageTitle' => 'A garden page']);
    $post(new \IdnoPlugins\Status\Status(), $ada, ['body' => 'A private garden note only Ada can read.'], $ada->getUUID());
    $post(new \IdnoPlugins\Status\Status(), $ada, ['body' => 'A garden draft, not yet published.'], 'PUBLIC', 'draft');
    $post(new \IdnoPlugins\Status\Status(), $bob, ['body' => 'Bobs private garden note.'], $bob->getUUID());
    $viewAs = static function (?string $handle) use ($site): void {
        $site->session()->logUserOff();
        if ($handle !== null) {
            $site->session()->logUserOn(\Idno\Entities\User::getByHandle($handle));
        }
    };
    PHP;

    /** The home page's own computation before the extraction: `Homepage::getContent()` from the first line to the template call. */
    private const REFERENCE = <<<'PHP'
    $reference = static function (?string $argument, $query, $requested, int $offset): array {
        $site = \Idno\Core\Idno::site();
        $types = $requested;
        $friendly_types = array();
        if (!empty($argument)) {
            if ($friendly_types = explode('/', $argument)) {
                $friendly_types = array_filter($friendly_types);
                if (empty($friendly_types) && !empty($query)) {
                    $friendly_types = array('all');
                }
                $types = array();
                foreach ($friendly_types as $friendly_type) {
                    if ($friendly_type == 'all') {
                        $types = \Idno\Common\ContentType::getRegisteredClasses();
                        break;
                    }
                    if ($content_type_class = \Idno\Common\ContentType::categoryTitleToClass($friendly_type)) {
                        $types[] = $content_type_class;
                    }
                }
            }
        } else {
            if (empty($query)) {
                $types = $site->config()->getHomepageContentTypes();
            }
        }
        $search = array();
        if (!empty($query)) {
            $search = $site->db()->createSearchArray($query);
        }
        if (empty($types)) {
            $types = \Idno\Common\ContentType::getRegisteredClasses();
        } else {
            $types = (array) $types;
        }
        $search['publish_status'] = 'published';
        $count = \Idno\Common\Entity::countFromX($types, $search);
        $feed = \Idno\Common\Entity::getFromX($types, $search, array(), $site->config()->items_per_page, $offset);
        if ($site->session()->isLoggedIn()) {
            $create = \Idno\Common\ContentType::getRegistered();
            foreach ($create as $key => $obj) {
                $obj->editUrl = $obj->getEditURL();
                if (!$obj->createable) {
                    unset($create[$key]);
                }
            }
        } else {
            $create = false;
        }
        if (!empty($site->config()->description)) {
            $description = $site->config()->description;
        } else {
            $description = 'An independent social website, powered by Idno.';
        }
        if (!empty($site->config()->homepagetitle)) {
            $title = $site->config()->homepagetitle;
        } else {
            $title = $site->config()->title;
        }

        return ['types' => $types, 'friendly' => $friendly_types, 'items' => $feed, 'count' => $count, 'create' => $create, 'title' => $title, 'description' => $description];
    };
    $uuids = static fn ($items): mixed => is_array($items) ? array_map(static fn ($item) => $item->getUUID(), $items) : $items;
    $createKeys = static fn ($create): mixed => is_array($create) ? array_map(static fn ($type) => [get_class($type), $type->editUrl], $create) : $create;
    PHP;

    public function testTheContainerAliasesTheInterfaceToTheAdapter(): void
    {
        $result = $this->runInSite('return get_class(\Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class));');

        $this->assertSame(\Idno\Legacy\LegacyHomeStream::class, $result);
    }

    public function testTheStreamIsTheHomePagesOwnForEveryViewerPathQueryTypeAndOffset(): void
    {
        $result = $this->runInSite(self::SEED . self::REFERENCE . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $differences = [];
        $compared = 0;
        $listed = [];
        foreach ([null, 'ada', 'bob'] as $viewer) {
            $viewAs($viewer);
            foreach ([null, 'all', 'statusupdates', 'posts', 'bookmarkedpages', 'statusupdates/posts', 'posts/statusupdates', 'bogus', 'bogus/statusupdates', '', '/', '//', '0', 'all/statusupdates'] as $argument) {
                foreach ([null, '', 'garden', '#garden', 'private', 'nothing-matches-this', ['garden']] as $query) {
                    foreach ([null, \IdnoPlugins\Like\Like::class, [\IdnoPlugins\Text\Entry::class, \IdnoPlugins\Status\Status::class], ''] as $requested) {
                        foreach ([0, 3, 100] as $offset) {
                            $expected = $reference($argument, $query, $requested, $offset);
                            $resolved = $stream->types($argument, $query, $requested);
                            $page = $stream->page($resolved['types'], $query, $offset);
                            $actual = ['types' => $resolved['types'], 'friendly' => $resolved['friendly'], 'items' => $page['items'], 'count' => $page['count'], 'create' => $stream->creatableTypes()] + $stream->titles();
                            $label = json_encode([$viewer, $argument, $query, $requested, $offset]);
                            foreach (['types', 'friendly', 'count', 'title', 'description'] as $key) {
                                if ($expected[$key] !== $actual[$key]) {
                                    $differences[] = [$label, $key, $expected[$key], $actual[$key]];
                                }
                            }
                            if ($uuids($expected['items']) !== $uuids($actual['items'])) {
                                $differences[] = [$label, 'items', $uuids($expected['items']), $uuids($actual['items'])];
                            }
                            if ($createKeys($expected['create']) !== $createKeys($actual['create'])) {
                                $differences[] = [$label, 'create'];
                            }
                            $compared++;
                            $listed[$viewer ?? 'visitor'][$argument . '|' . json_encode($query) . '|' . json_encode($requested) . '|' . $offset] = $uuids($actual['items']);
                        }
                    }
                }
            }
        }
        // the premise: the matrix reached posts, a draft and a private post were left out for a visitor, and the viewer's own were found
        $flat = static fn (array $lists): array => array_unique(array_merge(...array_values(array_filter($lists, 'is_array'))));
        return ['differences' => array_slice($differences, 0, 3), 'compared' => $compared, 'visitor' => count($flat($listed['visitor'])), 'ada' => count($flat($listed['ada'])), 'bob' => count($flat($listed['bob']))];
        PHP);

        $this->assertSame([], $result['differences']);
        $this->assertSame(3 * 14 * 7 * 4 * 3, $result['compared']);
        // the premise: the matrix reached the posts. Eight are public (six notes, an article, a bookmark); the administrator also reads both private
        // ones; Bob his own; the draft is listed to nobody
        $this->assertSame([8, 10, 9], [$result['visitor'], $result['ada'], $result['bob']]);
    }

    public function testASearchTermThatIsAnArrayIsAQueryThatMatchesNothingNotAnError(): void
    {
        // `?q[]=garden`: the stores read an array operand as a query that matches nothing; the page answers as it always did
        $this->addUser('ada', 'correct-horse-battery-9', null, true);

        $this->assertSame(200, $this->site()->request('GET', '?q[]=garden')->status);
        $this->assertSame(200, $this->site()->request('GET', '?q[]=garden&types[]=x')->status);
        $this->assertSame(200, $this->site()->request('GET', 'content/all?q[]=garden&_t=json')->status);
    }

    public function testASiteHasUsersOnceItHasAny(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $before = $stream->siteHasUsers();
        $user = new \Idno\Entities\User();
        $user->handle = 'ada';
        $user->email = 'ada@example.com';
        $user->setPassword('correct-horse-battery-9');
        $user->save(true);

        return [$before, $stream->siteHasUsers(), (bool) \Idno\Entities\User::get()];
        PHP);

        $this->assertSame([false, true, true], $result);
    }

    public function testASingleUserSitesOwnerIsItsOneAdministrator(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $log = tempnam(sys_get_temp_dir(), 'idno-log-');
        ini_set('error_log', $log);
        $site->config()->single_user = false;
        $multi = $stream->singleUserOwner();
        $site->config()->single_user = true;
        $one = $stream->singleUserOwner();
        $oneLogged = file_get_contents($log);
        $bob->setAdmin(true);
        $bob->save(true);
        $two = $stream->singleUserOwner();
        $twoLogged = file_get_contents($log);
        unlink($log);

        return ['multi' => $multi, 'one' => $one?->handle, 'oneLogged' => $oneLogged, 'two' => $two, 'twoLogged' => $twoLogged];
        PHP);

        $this->assertNull($result['multi'], 'a multi-user site has no owner');
        $this->assertSame('ada', $result['one']);
        $this->assertStringNotContainsString('Expected exactly 1 admin user', $result['oneLogged']);
        $this->assertNull($result['two']);
        $this->assertStringContainsString('Expected exactly 1 admin user for single-user site; got 2', $result['twoLogged']);
    }

    public function testRequestedTypesAreIgnoredWithoutAQuery(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $configured = \Idno\Core\Idno::site()->config()->getHomepageContentTypes();

        return [
            'withoutQuery' => $stream->types(null, null, \IdnoPlugins\Like\Like::class)['types'],
            'withEmptyQuery' => $stream->types(null, '', ['x'])['types'],
            'configured' => $configured ?: \Idno\Common\ContentType::getRegisteredClasses(),
        ];
        PHP);

        $this->assertSame($result['configured'], $result['withoutQuery'], 'the home page\'s configured types, whatever the request asked for');
        $this->assertSame($result['configured'], $result['withEmptyQuery']);
    }

    public function testARequestedTypeFiltersASearch(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $liked = $stream->types(null, 'garden', \IdnoPlugins\Like\Like::class);
        $articles = $stream->types(null, 'garden', [\IdnoPlugins\Text\Entry::class]);
        $everything = $stream->types(null, 'garden', null);
        $bookmarks = $stream->page($liked['types'], 'garden', 0);
        $entries = $stream->page($articles['types'], 'garden', 0);

        return [
            'liked' => $liked, 'articles' => $articles['types'], 'everything' => $everything['types'] === \Idno\Common\ContentType::getRegisteredClasses(),
            'bookmarkClasses' => array_map('get_class', $bookmarks['items']), 'bookmarkCount' => $bookmarks['count'],
            'entryClasses' => array_map('get_class', $entries['items']), 'entryCount' => $entries['count'],
            'friendlyWins' => $stream->types('statusupdates', 'garden', \IdnoPlugins\Like\Like::class),
        ];
        PHP);

        $this->assertSame(['types' => [\IdnoPlugins\Like\Like::class], 'friendly' => []], $result['liked'], 'the request\'s types, as given, in an array');
        $this->assertSame([\IdnoPlugins\Text\Entry::class], $result['articles']);
        $this->assertTrue($result['everything'], 'no types asked for: every registered class');
        $this->assertSame([\IdnoPlugins\Like\Like::class], $result['bookmarkClasses']);
        $this->assertSame(1, $result['bookmarkCount']);
        $this->assertSame([\IdnoPlugins\Text\Entry::class], $result['entryClasses']);
        $this->assertSame(1, $result['entryCount']);
        $this->assertSame([\IdnoPlugins\Status\Status::class], $result['friendlyWins']['types'], 'a friendly path replaces the request\'s types');
        $this->assertSame(['statusupdates'], $result['friendlyWins']['friendly']);
    }

    public function testADraftAndAnotherMembersPrivatePostAreNeverListedButTheCountsAreTheirs(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $all = $stream->types('all', null, null)['types'];
        $seen = static function (?string $handle) use ($stream, $viewAs, $all): array {
            $viewAs($handle);
            $page = $stream->page($all, null, 0);

            return ['bodies' => array_map(static fn ($item) => $item->body ?? $item->title, $page['items']), 'count' => $page['count']];
        };
        return ['visitor' => $seen(null), 'ada' => $seen('ada'), 'bob' => $seen('bob')];
        PHP);

        $text = static fn (string $viewer): string => implode("\n", $result[$viewer]['bodies']);
        foreach (['visitor', 'ada', 'bob'] as $viewer) {
            $this->assertStringNotContainsString('draft', $text($viewer), $viewer . ': a draft is listed to nobody, its owner included');
        }
        $this->assertStringNotContainsString('private', $text('visitor'));
        $this->assertStringContainsString('Bobs private', $text('bob'));
        $this->assertStringNotContainsString('private garden note only Ada', $text('bob'), 'another member\'s private post is not Bob\'s to read');
        $this->assertStringContainsString('private garden note only Ada', $text('ada'));
        $this->assertStringContainsString('Bobs private', $text('ada'), 'an administrator reads every post');
        $this->assertSame([8, 10, 9], [$result['visitor']['count'], $result['ada']['count'], $result['bob']['count']], 'the published posts each may read');
    }

    public function testTheCreatableTypesAreTheLoggedInMembersAndFalseForAVisitor(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $viewAs(null);
        $visitor = $stream->creatableTypes();
        $viewAs('bob');
        $member = $stream->creatableTypes();

        return ['visitor' => $visitor, 'classes' => array_map('get_class', $member), 'urls' => array_map(static fn ($type) => $type->editUrl, $member), 'edit' => array_map(static fn ($type) => $type->getEditURL(), $member), 'createable' => array_map(static fn ($type) => (bool) $type->createable, $member)];
        PHP);

        $this->assertFalse($result['visitor']);
        $this->assertNotEmpty($result['classes']);
        $this->assertSame($result['edit'], $result['urls'], 'each carries the edit address, for API clients');
        $this->assertNotContains(false, $result['createable'], 'a type that cannot be created is left out');
    }

    public function testTheTitlesAreTheConfiguredOnesOrTheDefaults(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $config = \Idno\Core\Idno::site()->config();
        $config->title = 'The Site';
        $config->homepagetitle = '';
        $config->description = '';
        $default = $stream->titles();
        $config->homepagetitle = 'The Home';
        $config->description = 'About the home.';

        return ['default' => $default, 'configured' => $stream->titles()];
        PHP);

        $this->assertSame(['title' => 'The Site', 'description' => 'An independent social website, powered by Idno.'], $result['default']);
        $this->assertSame(['title' => 'The Home', 'description' => 'About the home.'], $result['configured']);
    }

    public function testAnEmptySiteHasNoHomePageAndTheStreamAnswersForIt(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $log = tempnam(sys_get_temp_dir(), 'idno-log-');
        ini_set('error_log', $log);
        $types = $stream->types(null, null, null);
        $page = $stream->page($types['types'], null, 0);
        $result = [
            'users' => $stream->siteHasUsers(),
            'single' => $stream->isSingleUser(),
            'owner' => $stream->singleUserOwner(),
            'administrator' => $stream->firstAdministrator(),
            'items' => $page['items'],
            'count' => $page['count'],
            'creatable' => $stream->creatableTypes(),
            'titles' => array_keys($stream->titles()),
            'logged' => file_get_contents($log),
        ];
        unlink($log);

        return $result;
        PHP);

        $this->assertFalse($result['users']);
        $this->assertTrue($result['single'], 'a site is a single-user one unless it is set otherwise');
        $this->assertNull($result['owner']);
        $this->assertNull($result['administrator']);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['count']);
        $this->assertFalse($result['creatable']);
        $this->assertSame(['title', 'description'], $result['titles']);
        $this->assertStringContainsString('Expected exactly 1 admin user for single-user site; got 0', $result['logged']);
    }

    public function testASingleUserSiteListsItsOneAdministratorsPostsToAVisitor(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $site->config()->single_user = true;
        $viewAs(null);
        $visitor = $stream->page($stream->types(null, null, null)['types'], null, 0);
        $viewAs('ada');
        $member = $stream->creatableTypes();

        return [
            'single' => $stream->isSingleUser(),
            'owner' => $stream->singleUserOwner()?->handle,
            'administrator' => $stream->firstAdministrator()?->handle,
            'count' => $visitor['count'],
            'bodies' => array_map(static fn ($item) => $item->body ?? $item->title ?? null, $visitor['items']),
            'member' => is_array($member),
        ];
        PHP);

        $this->assertTrue($result['single']);
        $this->assertSame('ada', $result['owner']);
        $this->assertSame('ada', $result['administrator']);
        $this->assertSame(8, $result['count'], 'the public posts: six notes, an article and a bookmark: not the private notes or the draft');
        $this->assertNotContains('A private garden note only Ada can read.', $result['bodies']);
        $this->assertNotContains('A garden draft, not yet published.', $result['bodies']);
        $this->assertTrue($result['member']);
    }

    public function testASingleUserSiteWithNoAdministratorHasNoOwner(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $user = new \Idno\Entities\User();
        $user->handle = 'bob';
        $user->email = 'bob@example.com';
        $user->setPassword('correct-horse-battery-9');
        $user->setAdmin(false);
        $user->save(true);
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $log = tempnam(sys_get_temp_dir(), 'idno-log-');
        ini_set('error_log', $log);
        \Idno\Core\Idno::site()->config()->single_user = true;
        $result = ['users' => $stream->siteHasUsers(), 'owner' => $stream->singleUserOwner(), 'administrator' => $stream->firstAdministrator(), 'logged' => file_get_contents($log)];
        unlink($log);

        return $result;
        PHP);

        $this->assertTrue($result['users']);
        $this->assertNull($result['owner']);
        $this->assertNull($result['administrator']);
        $this->assertStringContainsString('Expected exactly 1 admin user for single-user site; got 0', $result['logged']);
    }

    public function testTheLastModifiedIsTheRfc2616Date(): void
    {
        $result = $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class)->lastModified(1_700_000_000);');

        $this->assertSame(\gmdate('D, d M Y H:i:s', 1_700_000_000) . ' GMT', $result);
    }

    public function testTheSiteAddressIsTheConfiguredUrlAsWritten(): void
    {
        $result = $this->runInSite('return [\Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class)->siteAddress(), \Idno\Core\Idno::site()->config()->url];');

        $this->assertSame($result[1], $result[0]);
        $this->assertNotSame('', $result[0]);
    }

    public function testAProfileWebmentionReachesTheProfilePage(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $source = 'http://foo.bar/mention';
        $target = $site->config()->getDisplayURL();
        $html = '<div class="h-entry"><a class="p-author h-card" href="http://foo.bar">Foo Bar</a><span class="p-name e-content">test mention of ' . $target . '</span></div>';
        $mf2 = (new \Mf2\Parser($html, $source))->parse();
        $log = tempnam(sys_get_temp_dir(), 'idno-log-');
        ini_set('error_log', $log);
        $site->logging()->setLogLevel(\Idno\Core\Logging::LEVEL_DEBUG);

        // A script's routes are registered under its own directory (the legacy router reads SCRIPT_NAME), so a profile's address is made under it
        $profile = new class extends \Idno\Entities\User {
            public string $address = '';

            public function getURL()
            {
                return $this->address;
            }
        };
        $profile->handle = 'ada';
        $profile->address = 'http://127.0.0.1' . dirname($_SERVER['SCRIPT_NAME']) . '/profile/ada';
        $accepted = $stream->profilePageWebmention($profile, $source, $target, ['response' => 200, 'content' => $html], $mf2);
        $deleted = $stream->profilePageWebmention($profile, $source, $target, ['response' => 410, 'content' => ''], $mf2);
        $nowhere = clone $profile;
        $nowhere->handle = 'nowhere';
        $nowhere->address = 'https://elsewhere.example/no/such/route/at/all';
        $missing = $stream->profilePageWebmention($nowhere, $source, $target, ['response' => 200, 'content' => $html], $mf2);
        $logged = file_get_contents($log);
        unlink($log);

        return ['accepted' => $accepted, 'deleted' => $deleted, 'missing' => $missing, 'logged' => $logged];
        PHP);

        $this->assertTrue($result['accepted'], 'the profile page took the mention');
        $this->assertTrue($result['deleted'], 'and accepts a non-200 one, doing nothing');
        $this->assertFalse($result['missing'], 'no page for the address: false');
        $this->assertStringContainsString('received user mention from http://foo.bar/mention', $result['logged'], 'the profile page\'s own lines');
        $this->assertStringContainsString('failed to find a Page to serve route https://elsewhere.example/no/such/route/at/all', $result['logged']);
    }
}
