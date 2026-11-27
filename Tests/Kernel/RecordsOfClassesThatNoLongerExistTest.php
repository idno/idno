<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * Records the feed reader stored, which nothing reads any more: the classes `Idno\Entities\Reader\Feed`, `Subscription` and `FeedItem` are gone, and
 * an upgraded site (or a development database) still holds rows of their types, in `reader` and (where a save went) in `entities`. Such a row stays in
 * the database untouched, is never shown, never breaks a page or a listing that meets it, and is still in the export's SQL dump.
 *
 * The class names are strings on purpose: no class of that name exists to build.
 */
final class RecordsOfClassesThatNoLongerExistTest extends PrivateSiteTestCase
{
    /**
     * @return array{bob: string, ids: array<string, string>}
     */
    private function seed(): array
    {
        return $this->runInSite(<<<'PHP'
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
        $make('ada', true);
        $bob = $make('bob', false);
        $status = static function (string $body, string $publish, int $created) use ($bob): void {
            $entity = new \IdnoPlugins\Status\Status();
            $entity->body = $body;
            $entity->setOwner($bob);
            $entity->setAccess('PUBLIC');
            $entity->setPublishStatus($publish);
            $entity->created = $created;
            $entity->save(true);
        };
        $status('A note that stays visible.', 'published', 1_700_000_000);
        $status('A draft of Bobs own.', 'draft', 1_700_000_100);

        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $ids = [];
        $row = static function (string $collection, int $n, string $class, array $more) use ($store, $bob, &$ids): void {
            $id = '00000000000040008000000000000a' . sprintf('%02d', $n);
            $document = $more + [
                '_id' => $id,
                'uuid' => 'https://removed.example/view/' . $id,
                'entity_subtype' => $class,
                'owner' => $bob->getUUID(),
                'access' => 'PUBLIC',
                'created' => 1_800_000_000 + $n,
                'feed_url' => 'https://feed.example/' . $n,
                'title' => 'A record of the removed feed reader ' . $n,
            ];
            if ($store->save($collection, $document) === false) {
                throw new \RuntimeException('record not stored');
            }
            $ids[$collection . '.' . $class] = $id;
        };
        // where an upgraded site holds them (`reader`) and where a save of the old classes went (`entities`)
        $row('reader', 1, 'Idno\\Entities\\Reader\\Feed', []);
        $row('reader', 2, 'Idno\\Entities\\Reader\\FeedItem', []);
        $row('reader', 3, 'Idno\\Entities\\Reader\\Subscription', []);
        $row('entities', 4, 'Idno\\Entities\\Reader\\Subscription', []);
        $row('entities', 5, 'Idno\\Entities\\Reader\\FeedItem', []);
        $row('entities', 6, 'Idno\\Entities\\Reader\\Feed', ['publish_status' => 'draft']);

        return ['bob' => $bob->getUUID(), 'ids' => $ids];
        PHP);
    }

    public function testARecordOfAFeedReaderClassIsNeverAnEntity(): void
    {
        $seeded = $this->seed();

        $found = $this->runInSite(<<<'PHP'
        $bob = \Idno\Entities\User::getByHandle('bob');
        $classes = static fn ($items): array => array_map(static fn ($e) => get_class($e), $items);
        $site = \Idno\Core\Idno::site();
        $site->session()->logUserOn($bob);

        return [
            'owned' => $classes(\Idno\Common\Entity::getFromAll(['owner' => $bob->getUUID()])),
            'drafts' => $classes(\Idno\Common\Entity::getFromAll(['owner' => $bob->getUUID(), 'publish_status' => 'draft'])),
            'byUuid' => \Idno\Common\Entity::getByUUID('https://removed.example/view/00000000000040008000000000000a05'),
            'byId' => \Idno\Common\Entity::getByID('00000000000040008000000000000a04'),
            'readerCollection' => \Idno\Common\Entity::getFromAll(['entity_subtype' => 'Idno\\Entities\\Reader\\Feed'], [], 10, 0, 'reader'),
        ];
        PHP);

        $this->assertSame(['IdnoPlugins\Status\Status', 'IdnoPlugins\Status\Status'], $found['owned'], 'a listing skips a record whose class is gone');
        $this->assertSame(['IdnoPlugins\Status\Status'], $found['drafts']);
        $this->assertFalse($found['byUuid']);
        $this->assertFalse($found['byId']);
        $this->assertSame([], $found['readerCollection']);
        $this->assertCount(6, $seeded['ids']);
    }

    public function testNoPageBreaksOrShowsARecordOfAFeedReaderClass(): void
    {
        $seeded = $this->seed();
        $cookie = $this->loginCookie('bob', 'correct-horse-battery-9');

        foreach (['/', '/?_t=json', '/feed.xml', '/profile/bob', '/profile/bob?_t=json', '/drafts/', '/drafts/?_t=json', '/search?q=removed', '/tag/removed'] as $path) {
            $answer = $this->site()->request('GET', ltrim($path, '/'), ['Cookie' => $cookie]);
            $this->assertContains($answer->status, [200, 302], $path . ' ' . $this->serverLog());
            $this->assertStringNotContainsString('A record of the removed feed reader', $answer->body, $path);
        }
        $this->assertStringContainsString('A note that stays visible.', $this->site()->request('GET', '', ['Cookie' => $cookie])->body);
        $this->assertStringContainsString('A draft of Bobs own.', $this->site()->request('GET', 'drafts/', ['Cookie' => $cookie])->body);

        foreach ($seeded['ids'] as $id) {
            $answer = $this->site()->request('GET', 'view/' . $id, ['Cookie' => $cookie]);
            $this->assertSame(410, $answer->status, $id . ': the address of a record that is not an entity is the page for content that is not there');
        }
        $this->assertStringNotContainsString('Fatal', $this->serverLog());
    }

    public function testTheRecordsStayInTheDatabaseAndInTheExportsSqlDump(): void
    {
        $seeded = $this->seed();
        $cookie = $this->loginCookie('bob', 'correct-horse-battery-9');
        $this->site()->request('GET', 'drafts/', ['Cookie' => $cookie]);

        $kept = $this->runInSite(<<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $dump = \Idno\Core\Idno::container()->get(\Idno\Storage\CollectionDump::class);
        $sql = '';
        foreach (['entities', 'reader'] as $collection) {
            foreach ($dump->inserts($collection) as $chunk) {
                $sql .= $chunk;
            }
        }

        return [
            'reader' => $store->count('reader', \Idno\Storage\Criteria::all()),
            'sql' => array_map(static fn (int $n): bool => str_contains($sql, 'https://removed.example/view/00000000000040008000000000000a0' . $n), [1, 2, 3, 4, 5, 6]),
            'feed' => $store->findById('reader', '00000000000040008000000000000a01')['feed_url'] ?? null,
            'subscription' => $store->findById('entities', '00000000000040008000000000000a04')['entity_subtype'] ?? null,
        ];
        PHP);

        $this->assertSame(3, $kept['reader']);
        $this->assertSame([true, true, true, true, true, true], $kept['sql'], 'the export archive\'s SQL dump holds every one');
        $this->assertSame('https://feed.example/1', $kept['feed']);
        $this->assertSame('Idno\Entities\Reader\Subscription', $kept['subscription']);
        $this->assertCount(6, $seeded['ids']);
    }
}
