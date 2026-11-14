<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes, \Idno\Kernel $kernel): void {
    $projectDir = $kernel->getProjectDir();

    $dirs = [$projectDir . '/Idno/Legacy'];
    if ($kernel->getEnvironment() === 'test') {
        $dirs[] = $projectDir . '/Tests/Fixtures';
    }

    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $routes->import($dir, 'attribute');
        }
    }
};
