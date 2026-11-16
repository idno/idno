<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\TypeMap;
use PHPUnit\Framework\TestCase;

#[AsEntity('fixture_thing', legacy: ['Old\Name', 'Older\Name'])]
class AttributeTypeMapFixture
{
}

class AttributeTypeMapFixtureChild extends AttributeTypeMapFixture
{
}

#[AsEntity('fixture_plain')]
class AttributeTypeMapFixturePlain
{
}

final class AttributeTypeMapTest extends TestCase
{
    public function testItIsATypeMap(): void
    {
        self::assertInstanceOf(TypeMap::class, new AttributeTypeMap());
    }

    public function testAliasedClass(): void
    {
        $map = new AttributeTypeMap();

        self::assertSame('fixture_thing', $map->typeFor(AttributeTypeMapFixture::class));
        self::assertSame(
            ['fixture_thing', AttributeTypeMapFixture::class, 'Old\Name', 'Older\Name'],
            $map->typesFor(AttributeTypeMapFixture::class),
        );
    }

    public function testTheTypesOfAClassWithNoLegacyNamesAreItsAliasAndItsName(): void
    {
        self::assertSame(
            ['fixture_plain', AttributeTypeMapFixturePlain::class],
            (new AttributeTypeMap())->typesFor(AttributeTypeMapFixturePlain::class),
        );
    }

    public function testUnknownClass(): void
    {
        $map = new AttributeTypeMap();

        self::assertSame('No\Such\Thing', $map->typeFor('No\Such\Thing'));
        self::assertSame(['No\Such\Thing'], $map->typesFor('No\Such\Thing'));
    }

    public function testUnattributedClass(): void
    {
        $map = new AttributeTypeMap();

        self::assertSame(\stdClass::class, $map->typeFor(\stdClass::class));
        self::assertSame([\stdClass::class], $map->typesFor(\stdClass::class));
    }

    public function testLeadingBackslash(): void
    {
        $map = new AttributeTypeMap();

        self::assertSame('fixture_thing', $map->typeFor('\\' . AttributeTypeMapFixture::class));
        self::assertSame(
            ['fixture_thing', AttributeTypeMapFixture::class, 'Old\Name', 'Older\Name'],
            $map->typesFor('\\' . AttributeTypeMapFixture::class),
        );
        self::assertSame('\No\Such\Thing', $map->typeFor('\No\Such\Thing'));
        self::assertSame(['\No\Such\Thing'], $map->typesFor('\No\Such\Thing'));
    }

    public function testAttributeIsNotInherited(): void
    {
        $map = new AttributeTypeMap();

        self::assertSame(AttributeTypeMapFixtureChild::class, $map->typeFor(AttributeTypeMapFixtureChild::class));
        self::assertSame([AttributeTypeMapFixtureChild::class], $map->typesFor(AttributeTypeMapFixtureChild::class));
    }

    public function testInvalidAliasIsRejected(): void
    {
        foreach (['Bad-Alias', '', '1abc', 'has space', str_repeat('a', 65)] as $alias) {
            try {
                new AsEntity($alias);
                self::fail('The alias "' . $alias . '" should be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidAliasesAreAccepted(): void
    {
        self::assertSame('a', (new AsEntity('a'))->alias);
        self::assertSame('a_b1', (new AsEntity('a_b1'))->alias);
        self::assertSame(str_repeat('a', 64), (new AsEntity(str_repeat('a', 64)))->alias);
        self::assertSame('entities', (new AsEntity('a'))->collection);
        self::assertSame([], (new AsEntity('a'))->legacy);
    }

    public function testItNeverThrowsForAName(): void
    {
        $map = new AttributeTypeMap();

        foreach (['', '\\', 'Not a class', "Bad\0Name", 'Foo\\\\Bar', '1Foo', str_repeat('A', 5000), '../../etc/passwd'] as $name) {
            self::assertSame($name, $map->typeFor($name));
            self::assertSame([$name], $map->typesFor($name));
        }
    }

    public function testAClassWhoseFileFailsToLoadIsNotLoadable(): void
    {
        $dir = sys_get_temp_dir() . '/idno-typemap-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/BrokenFixtureClass.php', "<?php\nclass BrokenFixtureClass {\n");
        $loader = static function (string $class) use ($dir): void {
            if ($class === 'BrokenFixtureClass') {
                require $dir . '/BrokenFixtureClass.php';
            }
        };
        spl_autoload_register($loader);
        try {
            $map = new AttributeTypeMap();
            self::assertSame('BrokenFixtureClass', $map->typeFor('BrokenFixtureClass'));
            self::assertSame(['BrokenFixtureClass'], $map->typesFor('BrokenFixtureClass'));
        } finally {
            spl_autoload_unregister($loader);
            unlink($dir . '/BrokenFixtureClass.php');
            rmdir($dir);
        }
    }

    public function testEachClassIsLoadedOnceByAnInstance(): void
    {
        $loads = [];
        $loader = static function (string $class) use (&$loads): void {
            if ($class === 'Idno\Counted\Missing') {
                $loads[] = $class;
            }
        };
        spl_autoload_register($loader);
        try {
            $map = new AttributeTypeMap();
            $map->typeFor('Idno\Counted\Missing');
            $map->typesFor('Idno\Counted\Missing');
            $map->typeFor('\Idno\Counted\Missing');
            self::assertCount(1, $loads);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
