<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Idno\Common\Entity;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Criteria;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\IndexedFields;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\TypeMap;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * Every entity class declares the alias its records are stored under, once, and the declaration
 * agrees with the class: the walk covers every PHP file under Idno/ and IdnoPlugins/. The core and
 * bundled classes' #[IndexedField] declarations give each collection the indexed fields listed in
 * testTheCoreAndBundledClassesDeclareTheirIndexedFields().
 */
final class EntityAliasesTest extends TestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            PrivateDatabase::drop($this->connection);
            $this->connection = null;
        }
        parent::tearDown();
    }

    /**
     * @return array<class-string, \ReflectionClass<object>> the entity classes under Idno/ and IdnoPlugins/
     */
    private static function entityClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['Idno', 'IdnoPlugins'] as $top) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $top, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $class = str_replace('/', '\\', $relative);
                // PSR-4: the file must declare the class its path names.
                if (preg_match('/^\s*(?:abstract\s+|final\s+)*class\s+' . preg_quote(basename($relative), '/') . '\b/m', (string) file_get_contents($file->getPathname())) !== 1) {
                    continue;
                }
                try {
                    if (!class_exists($class)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
                $reflection = new \ReflectionClass($class);
                if ($class === Entity::class || $reflection->isSubclassOf(Entity::class)) {
                    $found[$class] = $reflection;
                }
            }
        }
        ksort($found);

        return $found;
    }

    private static function attribute(\ReflectionClass $class): ?AsEntity
    {
        $attributes = $class->getAttributes(AsEntity::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function testTheWalkFindsTheEntityClasses(): void
    {
        $classes = self::entityClasses();

        self::assertArrayHasKey(\Idno\Entities\User::class, $classes);
        self::assertArrayHasKey(\IdnoPlugins\Status\Status::class, $classes);
        self::assertArrayHasKey(\Idno\Common\Entity::class, $classes);
    }

    public function testEveryConcreteEntityClassHasAnAlias(): void
    {
        foreach (self::entityClasses() as $name => $class) {
            if ($class->isAbstract()) {
                continue;
            }
            self::assertNotNull(self::attribute($class), $name . ' has no #[AsEntity].');
        }
    }

    public function testTheBaseEntityClassIsTheEntityType(): void
    {
        $class = new \ReflectionClass(Entity::class);

        self::assertTrue($class->isAbstract());
        self::assertSame('entity', self::attribute($class)?->alias);
        self::assertSame('entity', (new AttributeTypeMap())->typeFor(Entity::class));
    }

    public function testAliasesAreUnique(): void
    {
        $owners = [];
        foreach (self::entityClasses() as $name => $class) {
            $attribute = self::attribute($class);
            if ($attribute === null) {
                continue;
            }
            foreach ([$attribute->alias, ...$attribute->legacy] as $type) {
                self::assertArrayNotHasKey($type, $owners, sprintf('%s and %s both use the type "%s".', $name, $owners[$type] ?? '', $type));
                $owners[$type] = $name;
            }
        }
        self::assertNotEmpty($owners);
    }

    public function testTheCollectionMatchesTheLegacyStatic(): void
    {
        foreach (self::entityClasses() as $name => $class) {
            $attribute = self::attribute($class);
            if ($attribute === null) {
                continue;
            }
            self::assertSame(
                $class->getProperty('retrieve_collection')->getValue(),
                $attribute->collection,
                $name . ': the attribute\'s collection differs from its $retrieve_collection.',
            );
        }
    }

    public function testTheAliasesOfTheCoreAndBundledClasses(): void
    {
        $map = new AttributeTypeMap();
        $expected = [
            \Idno\Core\Site::class => 'site',
            \Idno\Entities\User::class => 'user',
            \Idno\Entities\RemoteUser::class => 'remote_user',
            \Idno\Entities\AccessGroup::class => 'access_group',
            \Idno\Entities\Invitation::class => 'invitation',
            \Idno\Entities\AsynchronousQueuedEvent::class => 'queued_event',
            \Idno\Entities\UnfurledUrl::class => 'unfurled_url',
            \Idno\Entities\Reader\Feed::class => 'feed',
            \Idno\Entities\Reader\FeedItem::class => 'feed_item',
            \Idno\Entities\Reader\Subscription::class => 'feed_subscription',
            \IdnoPlugins\Status\Status::class => 'status',
            \IdnoPlugins\Text\Entry::class => 'article',
            \IdnoPlugins\Photo\Photo::class => 'photo',
            \IdnoPlugins\Media\Media::class => 'media',
            \IdnoPlugins\Event\Event::class => 'event',
            \IdnoPlugins\Event\RSVP::class => 'rsvp',
            \IdnoPlugins\Checkin\Checkin::class => 'checkin',
            \IdnoPlugins\Like\Like::class => 'like',
            \IdnoPlugins\StaticPages\StaticPage::class => 'static_page',
            \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower::class => 'activitypub_follower',
        ];
        foreach ($expected as $class => $alias) {
            self::assertSame($alias, $map->typeFor($class), $class);
        }
    }

    public function testTheContainerResolvesTheTypeMapToTheAttributeMap(): void
    {
        self::assertInstanceOf(AttributeTypeMap::class, Idno::container()->get(TypeMap::class));
    }

    public function testQueryByClassFindsRowsStoredUnderTheAliasAndUnderTheClassName(): void
    {
        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        if (!in_array($family, ['mysql', 'sqlite'], true)) {
            self::markTestSkipped('The site\'s database is not SQL (MySQL or SQLite).');
        }
        $this->connection = $family === 'mysql' ? PrivateDatabase::mysql('aliases') : PrivateDatabase::sqliteMemory();
        $dialect = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? new MySqlDialect()
            : new SqliteDialect($this->connection);
        $make = fn (TypeMap $types): JsonRecordStore => new JsonRecordStore(
            $this->connection,
            $dialect,
            new FixedRecordDefaults(),
            $types,
            new SchemaCatalog($this->connection),
            static fn (string $text): string => $text,
            true,
            false,
        );

        $status = \IdnoPlugins\Status\Status::class;
        $note = \IdnoPlugins\Text\Entry::class;
        $record = static fn (string $id, string $subtype): array => ['_id' => $id, 'uuid' => 'http://aliases.test/' . $id, 'entity_subtype' => $subtype, 'created' => 1700000000, 'owner' => 'o', 'access' => 'PUBLIC'];

        // One row written under the alias (the aliasing store), one under the class name (a store
        // that keeps class names, as the rows of an earlier version are), one of another class.
        $aliased = $make(new AttributeTypeMap());
        self::assertNotFalse($aliased->save('entities', $record('aaaaaaaaaaaaaaaaaaaaaaaa', $status)));
        self::assertNotFalse($make(new IdentityTypeMap())->save('entities', $record('bbbbbbbbbbbbbbbbbbbbbbbb', $status)));
        self::assertNotFalse($aliased->save('entities', $record('cccccccccccccccccccccccc', $note)));

        $types = $this->connection->fetchAllKeyValue('select id, type from idno_entities order by id');
        self::assertSame(['aaaaaaaaaaaaaaaaaaaaaaaa' => 'status', 'bbbbbbbbbbbbbbbbbbbbbbbb' => $status, 'cccccccccccccccccccccccc' => 'article'], $types);

        $ids = static fn (array $rows): array => array_values(array_map(static fn (array $row): string => $row['_id'], $rows));
        $found = $ids($aliased->find('entities', Criteria::fromLegacyArray(['entity_subtype' => $status]), 10, 0));
        sort($found);
        self::assertSame(['aaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbb'], $found);
        self::assertSame(['cccccccccccccccccccccccc'], $ids($aliased->find('entities', Criteria::fromLegacyArray(['entity_subtype' => $note]), 10, 0)));
        self::assertSame([], $aliased->find('entities', Criteria::fromLegacyArray(['entity_subtype' => \IdnoPlugins\Photo\Photo::class]), 10, 0));
    }

    public function testTheCoreAndBundledClassesDeclareTheirIndexedFields(): void
    {
        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        if (!in_array($family, ['mysql', 'sqlite'], true)) {
            self::markTestSkipped('The site\'s database is not SQL (MySQL or SQLite).');
        }
        $this->connection = $family === 'mysql' ? PrivateDatabase::mysql('declared_indexes') : PrivateDatabase::sqliteMemory();
        $dialect = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? new MySqlDialect()
            : new SqliteDialect($this->connection);
        $catalog = new SchemaCatalog($this->connection);
        $indexes = new IndexedFields($this->connection, $dialect, $catalog);
        $root = dirname(__DIR__, 2);

        $indexes->reconcileDirectory($root . '/Idno');
        $indexes->reconcileDirectory($root . '/IdnoPlugins');

        self::assertSame([], $indexes->conflicts());
        $entity = ['access', 'canonical', 'shorturl', 'slug', 'url'];
        $sorted = static function (array $fields): array {
            sort($fields);

            return $fields;
        };
        self::assertSame($sorted([...$entity, 'actor_uri', 'admin', 'category', 'code', 'email', 'handle', 'queue', 'source_url', 'user_uuid']), $catalog->indexedFields('entities'));
        self::assertSame($sorted([...$entity, 'feed_url']), $catalog->indexedFields('reader'));
        self::assertSame($sorted([...$entity, 'domain']), $catalog->indexedFields('site'));
        // A real index of the expected name behind every one (information_schema.statistics on
        // MySQL and MariaDB, pragma_index_list on SQLite), not merely a generated column.
        foreach (['entities', 'reader', 'site'] as $collection) {
            foreach ($catalog->indexedFields($collection) as $field) {
                self::assertSame(['unique' => false, 'length' => $family === 'mysql' ? 255 : null], $dialect->indexedFieldIndex($this->connection, 'idno_' . $collection, $field), $collection . '.' . $field);
            }
            $names = $family === 'mysql'
                ? $this->connection->fetchFirstColumn("select distinct index_name from information_schema.statistics where table_schema = database() and table_name = ? and index_name like 'ix\\_%'", ['idno_' . $collection])
                : $this->connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = ? and name like ? escape '!'", ['idno_' . $collection, 'idno!_' . $collection . '!_ix!_%']);
            $expected = array_map(static fn (string $field): string => $family === 'mysql' ? 'ix_' . $field : 'idno_' . $collection . '_ix_' . $field, $catalog->indexedFields($collection));
            sort($names);
            self::assertSame($expected, $names, $collection);
        }
        self::assertSame([], $indexes->reconcileDirectory($root . '/Idno'), 'idempotent');
    }
}
