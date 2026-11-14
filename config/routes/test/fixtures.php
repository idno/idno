<?php

declare(strict_types=1);

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Routing\RouteCollection;

/**
 * Test-environment only (Symfony loads config/routes/<env>/*.php for the active environment):
 * attribute routes from Tests/Fixtures (e.g. PingController, ThrowingController). See
 * config/routes.php for why the collection is built by hand.
 *
 * @var \Symfony\Component\Routing\Loader\PhpFileLoader $loader
 */
$dir = dirname(__DIR__, 3) . '/Tests/Fixtures';

$collection = new RouteCollection();
$collection->addResource(new FileExistenceResource($dir));
if (is_dir($dir)) {
    $collection->addCollection($loader->import($dir, 'attribute'));
}

return $collection;
