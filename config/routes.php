<?php

declare(strict_types=1);

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Routing\RouteCollection;

/**
 * Attribute routes for the legacy-adapter layer (Idno/Legacy, created in a later task).
 *
 * $loader is the Symfony PhpFileLoader that includes this file. The collection is built by
 * hand so it can record a FileExistenceResource: creating the directory later then
 * invalidates the compiled routes in debug mode, instead of silently missing them.
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
