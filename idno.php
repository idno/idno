#!/usr/bin/php -q
<?php

define('IDNO_CONSOLE', 'true');
if (!defined('KNOWN_CONSOLE')) {
    define('KNOWN_CONSOLE', IDNO_CONSOLE);
}

// Load external libraries
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    include_once dirname(__FILE__) . '/vendor/autoload.php';
} else {
    die('Could not find autoload.php, did you run "composer install" ..?');
}

// Register console namespace
use Symfony\Component\Console\Application;

// Create new console application
global $console;
$console = new Application('Idno Console', \Idno\Core\Version::version());

function &application()
{
    global $console;

    return $console;
}


// Boot Idno
try {
    $idno = new Idno\Core\Idno();
    $account = new Idno\Core\Account();
    $admin = new Idno\Core\Admin();
    $webfinger = new Idno\Core\Webfinger();
    $webmention = new Idno\Core\Webmention();
    $websub = new Idno\Core\WebSub();
} catch (\Exception $e) {
    error_log($e->getMessage());
}

// Load any plugin functions
$directory = dirname(__FILE__) . '/ConsolePlugins/';
if ($scanned_directory = array_diff(scandir($directory), array('..', '.'))) {
    foreach ($scanned_directory as $file) {
        if (is_dir($directory . $file)) {
            $class = "ConsolePlugins\\$file\\Main";
            if (class_exists($class)) {
                $c = new $class();
                if ($c instanceof \Idno\Common\ConsolePlugin) {
                    $console->register($c->getCommand())
                        ->setDescription($c->getDescription())
                        ->setDefinition($c->getParameters())
                        ->setCode([$c, 'execute']);
                }
            }
        } else {
            $stubclass = "ConsolePlugins\\$file";
            $stubclass = str_replace('.php', '', $stubclass);
            if (class_exists($stubclass)) {
                $c = new $stubclass();
                if ($c instanceof \Idno\Common\ConsolePlugin) {
                    $console->register($c->getCommand())
                        ->setDescription($c->getDescription())
                        ->setDefinition($c->getParameters())
                        ->setCode([$c, 'execute']);
                }
            }
        }
    }
}

// Allow regular plugins to contain a ConsoleMain.php
$directory = dirname(__FILE__) . '/IdnoPlugins/';
if ($scanned_directory = array_diff(scandir($directory), array('..', '.'))) {
    foreach ($scanned_directory as $file) {
        if (is_dir($directory . $file)) {
            $class = "IdnoPlugins\\$file\\ConsoleMain";
            if (class_exists($class)) {
                $c = new $class();
                if ($c instanceof \Idno\Common\ConsolePlugin) {
                    $console->register($c->getCommand())
                        ->setDescription($c->getDescription())
                        ->setDefinition($c->getParameters())
                        ->setCode([$c, 'execute']);
                }
            }

            // See if there's a console subdir, for multiple sub commands
            $scanned = @scandir($directory . $file . '/Console/');
            if (!empty($scanned) && $scanned_sub_directory = array_diff($scanned, array('..', '.'))) {
                foreach ($scanned_sub_directory as $file2) {
                    $class2 = "IdnoPlugins\\$file\\Console\\$file2";
                    $class2 = str_replace('.php', '', $class2);
                    if (class_exists($class2)) {
                        $c = new $class2();
                        if ($c instanceof \Idno\Common\ConsolePlugin) {
                            $console->register($c->getCommand())
                                ->setDescription($c->getDescription())
                                ->setDefinition($c->getParameters())
                                ->setCode([$c, 'execute']);
                        }
                    }
                }
            }
        }
    }
}

$console
    ->register('version')
    ->setDescription(\Idno\Core\Idno::site()->language()->_('Returns the current Idno version as defined in version.idno'))
    ->setDefinition([])
    ->setCode(
        function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
            $output->writeln(file_get_contents(dirname(__FILE__) . '/version.idno'));

            $remoteVersion = \Idno\Core\RemoteVersion::build();
            if (\Idno\Core\Version::build() < $remoteVersion) {
                $version = \Idno\Core\RemoteVersion::version();
                $output->writeln(\Idno\Core\Idno::site()->language()->_("WARNING: Your build of Idno is behind the latest version from Github (%s - %s). If you're having problems, you may want to try updating to the latest version.\nUpdate now: https://github.com/idno/idno\n", [$version, $remoteVersion]));
            }
        }
    );


// Run the application
$console->run();
