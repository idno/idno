<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * The home stream (`Idno\Http\Controller\Home\HomeStream`, `Idno\Legacy\LegacyHomeStream`), on a site of the test's own with a seeded set of
 * posts (public, private, a draft, three content types) and three kinds of viewer: what each friendly path, search, requested type and offset gives,
 * as pinned values. (The whole page, the statements it makes and its feeds are pinned by the golden pages and the page tests.)
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

    public function testTheContainerAliasesTheInterfaceToTheAdapter(): void
    {
        $result = $this->runInSite('return get_class(\Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class));');

        $this->assertSame(\Idno\Legacy\LegacyHomeStream::class, $result);
    }

    /**
     * What each friendly path of the home page's `/content/<types>` resolves to, with and without a search (viewer-independent): the pinned results of
     * the matrix the stream was first proven on against the home page's own code. `ALL` is every registered content type.
     */
    public function testEachFriendlyPathResolvesToItsTypes(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $all = \Idno\Common\ContentType::getRegisteredClasses();
        $short = static fn (array $types): array|string => $types === $all ? 'ALL' : array_map(static fn (string $c): string => substr($c, strrpos($c, '\\') + 1), $types);
        $out = [];
        foreach ([null, 'all', 'statusupdates', 'posts', 'bookmarkedpages', 'statusupdates/posts', 'posts/statusupdates', 'bogus', 'bogus/statusupdates', '', '/', '//', '0', 'all/statusupdates'] as $argument) {
            foreach ([null, 'garden'] as $query) {
                $resolved = $stream->types($argument, $query, null);
                $out[json_encode([$argument, $query])] = [$short($resolved['types']), array_values($resolved['friendly'])];
            }
        }

        return $out;
        PHP);

        $expected = [];
        foreach ([
            'null' => ['ALL', []], 'all' => ['ALL', ['all']], 'statusupdates' => [['Status'], ['statusupdates']], 'posts' => [['Entry'], ['posts']],
            'bookmarkedpages' => [['Like'], ['bookmarkedpages']], 'statusupdates/posts' => [['Status', 'Entry'], ['statusupdates', 'posts']],
            'posts/statusupdates' => [['Entry', 'Status'], ['posts', 'statusupdates']], 'bogus' => ['ALL', ['bogus']], 'bogus/statusupdates' => [['Status'], ['bogus', 'statusupdates']],
            '' => ['ALL', []], '0' => ['ALL', []], 'all/statusupdates' => ['ALL', ['all', 'statusupdates']],
        ] as $argument => $answer) {
            foreach ([null, 'garden'] as $query) {
                $expected[json_encode([$argument === 'null' ? null : (string) $argument, $query])] = $answer;
            }
        }
        // A path of slashes alone names no type, so with a search it is every type (`all`), and without one the home page's own
        foreach (['/', '//'] as $argument) {
            $expected[json_encode([$argument, null])] = ['ALL', []];
            $expected[json_encode([$argument, 'garden'])] = ['ALL', ['all']];
        }
        ksort($expected);
        ksort($result);

        $this->assertSame($expected, $result);
    }

    /**
     * The pages of the whole stream for a visitor, the administrator and a member, without a search and for a hashtag, at three offsets: the pinned
     * results of the same matrix (`n1` to `n6` the six public notes, oldest first; the article, the bookmark and the two private notes by name). A draft is
     * listed to nobody; the counts are of the published posts each may read.
     */
    public function testThePagesOfTheStreamForEachViewerAreThePinnedOnes(): void
    {
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $stream = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Home\HomeStream::class);
        $all = \Idno\Common\ContentType::getRegisteredClasses();
        $key = static function ($item): string {
            $text = (string) ($item->body ?? $item->title);
            return match (true) {
                preg_match('/number (\d)/', $text, $m) === 1 => 'n' . $m[1],
                str_contains($text, 'Gardens and templates') => 'article',
                str_contains($text, 'example.org') => 'bookmark',
                str_contains($text, 'only Ada') => 'ada-private',
                str_contains($text, 'Bobs private') => 'bob-private',
                default => $text,
            };
        };
        $out = [];
        foreach ([null, 'ada', 'bob'] as $viewer) {
            $viewAs($viewer);
            foreach ([null, '#garden', 'nothing-matches-this'] as $query) {
                foreach ([0, 3, 100] as $offset) {
                    $page = $stream->page($all, $query, $offset);
                    $out[($viewer ?? 'visitor') . ' ' . json_encode($query) . ' ' . $offset] = [$page['count'], array_map($key, $page['items'])];
                }
            }
        }

        return $out;
        PHP);

        $public = ['bookmark', 'article', 'n6', 'n5', 'n4', 'n3', 'n2', 'n1'];
        $tagged = ['n6', 'n5', 'n4', 'n3', 'n2', 'n1'];
        $streams = ['visitor' => $public, 'ada' => ['bob-private', 'ada-private', ...$public], 'bob' => ['bob-private', ...$public]];
        $expected = [];
        foreach ($streams as $viewer => $items) {
            foreach ([0, 3, 100] as $offset) {
                $expected[$viewer . ' null ' . $offset] = [count($items), array_slice($items, $offset)];
                $expected[$viewer . ' "#garden" ' . $offset] = [6, array_slice($tagged, $offset)];
                $expected[$viewer . ' "nothing-matches-this" ' . $offset] = [0, []];
            }
        }
        ksort($expected);
        ksort($result);

        $this->assertSame($expected, $result);
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
