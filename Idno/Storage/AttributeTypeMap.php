<?php

declare(strict_types=1);

namespace Idno\Storage;

use Idno\Storage\Attribute\AsEntity;

/**
 * The TypeMap that reads each class's #[AsEntity] attribute: the `type` column holds the alias,
 * and a query for a class matches the alias, the class name and the names the class had before.
 *
 * A class with no attribute of its own (it is not inherited), a class that cannot be loaded and a
 * string that is not a class name are all stored under the name they were given, so neither method
 * throws for any string. Each class is looked up once per instance.
 */
final class AttributeTypeMap implements TypeMap
{
    /** @var array<string, AsEntity|null> keyed by class name without a leading backslash */
    private array $attributes = [];

    public function typeFor(string $class): string
    {
        return $this->attribute($class)?->alias ?? $class;
    }

    public function typesFor(string $class): array
    {
        $attribute = $this->attribute($class);
        if ($attribute === null) {
            return [$class];
        }

        return array_values(array_unique([$attribute->alias, ltrim($class, '\\'), ...$attribute->legacy]));
    }

    private function attribute(string $class): ?AsEntity
    {
        $name = ltrim($class, '\\');
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        return $this->attributes[$name] = $this->read($name);
    }

    private function read(string $name): ?AsEntity
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', $name) !== 1) {
            return null;
        }
        try {
            if (!class_exists($name)) {
                return null;
            }
            $attributes = (new \ReflectionClass($name))->getAttributes(AsEntity::class);

            return $attributes === [] ? null : $attributes[0]->newInstance();
        } catch (\Throwable) {
            // A file that fails to load (a syntax error, a warning turned into an exception) or an
            // invalid attribute: the class is stored under its name.
            return null;
        }
    }
}
