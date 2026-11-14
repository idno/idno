<?php

declare(strict_types=1);

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Routing\RouteCollection;

/**
 * Attribute routes from Idno/Legacy (the legacy catch-all, Idno\Legacy\LegacyController).
 *
 * $loader is the Symfony PhpFileLoader that includes this file. The collection is built by
 * hand so it can record a FileExistenceResource: if the directory is created or removed,
 * the compiled routes are invalidated in debug mode instead of silently going stale.
 *
 * @var \Symfony\Component\Routing\Loader\PhpFileLoader $loader
 */
$dir = dirname(__DIR__) . '/Idno/Legacy';

$collection = new RouteCollection();
$collection->addResource(new FileExistenceResource($dir));
if (is_dir($dir)) {
    $collection->addCollection($loader->import($dir, 'attribute'));
}

return $collection;
