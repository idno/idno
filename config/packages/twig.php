<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('twig', [
        // Compiled templates are PHP files that get included: they live in the kernel's cache directory, trusted
        // exactly as the compiled container is, never in the upload path or a shared temporary directory.
        'cache' => '%kernel.cache_dir%/twig',
        // A template of a plugin or theme edited in place (an update) is compiled again: the compiled files are kept per build and
        // nothing in the code fingerprint covers IdnoPlugins/ or Themes/. The cost is one filemtime for each Twig file loaded.
        // Idno\Templating\CascadeTwigLoader looks at a core template's file only in debug mode: core's templates change with a release, a release
        // changes version.idno, and that moves the kernel's cache directory (and so this one). Templates of plugins and themes are always looked at.
        'auto_reload' => true,
        // Templates that legacy code draws pass sparse variables.
        'strict_variables' => false,
        // The bundle's autoescape strategy is `name`: HTML for *.html.twig, none for *.txt.twig (the extension decides).
        // No paths and no globals: the template directories are the cascade's (Idno\Templating\CascadeTwigLoader),
        // filled at run time, and nothing from the configuration is compiled in.
        'default_path' => '%kernel.project_dir%/templates',
    ]);
};
