<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('twig', [
        // Compiled templates are PHP files that get included: they live in the kernel's cache directory, trusted
        // exactly as the compiled container is, never in the upload path or a shared temporary directory.
        'cache' => '%kernel.cache_dir%/twig',
        // Templates that legacy code draws pass sparse variables.
        'strict_variables' => false,
        // The bundle's autoescape strategy is `name`: HTML for *.html.twig, none for *.txt.twig (the extension decides).
        // No paths and no globals: the template directories are the cascade's (Idno\Templating\CascadeTwigLoader),
        // filled at run time, and nothing from the configuration is compiled in.
        'default_path' => '%kernel.project_dir%/templates',
    ]);
};
