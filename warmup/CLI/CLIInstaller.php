#!/usr/bin/php -q
<?php

// Load external libraries
if (file_exists(dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php')) {
    include_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';
} else {
    die('Could not find autoload.php, did you run "composer install" ..?');
}

class CLIInstaller extends \Idno\Core\Installer
{

    private $application;
    private $config = [];
    private $expected_manifest = [
        'site_title' => '"Site title"',
        'database' => '"Database type, usually \'MySQL\'"',
        'mysql_name' => '"Database name"',
        'mysql_user' => '"Database username"',
        'mysql_pass' => '"Database password"',
        'mysql_host' => '"Database host (usually \'localhost\')"',
        'upload_path' => '"Upload path"',
    ];

    public function __construct()
    {
        $this->application = new \Symfony\Component\Console\Application('Idno Console Installer', \Idno\Core\Version::version());

        parent::__construct();
    }

    protected function requirements(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output)
    {
        $output->writeln("Checking requirements...");

        $phpversion = Idno\Core\Installer::checkPHPVersion();
        if ($phpversion == 'ok') {
            $output->writeln("\tYou are running PHP version " . phpversion() . '.');
        } else if ($phpversion == 'warn') {
            $output->writeln("\tYou are running Idno using a very old version of PHP (" . phpversion() . '), which is no longer actively supported. Although Idno will currently still install, some features may not work, so you should upgrade soon. You may need to ask your server administrator to upgrade PHP for you.');
        } else {
            throw new \Exception('You are running PHP version ' . phpversion() . ', which cannot run Idno. You may need to ask your server administrator to upgrade PHP for you.');
        }

        if (function_exists('apache_get_modules')) {
            if (Idno\Core\Installer::rewriteAvailable()) {
                $output->writeln("\tmod_rewrite is installed and enabled.");
            } else {
                throw new \Exception('mod_rewrite is not installed. Idno cannot process page URLs without it.');
            }
        } else {
            $output->writeln("\tWe couldn't detect if mod_rewrite was installed, probably because you're using the CLI installer. Idno cannot process page URLs without it, so take care!");
        }

        $output->writeln("\tChecking extensions...");
        $extensions = Idno\Core\Installer::requiredModules();
        asort($extensions);
        foreach($extensions as $extension) {
            if (extension_loaded($extension)) {
                $output->writeln("\t\t$extension extension is installed.");
            } else {
                throw new \Exception("$extension extension is not installed.");

            }
        }

        $output->writeln(" ");
    }

    public function run()
    {

        $this->application
            ->register('check-requirements')
            ->setDescription('Check system requirements')
            ->setDefinition(
                [
                ]
            )
            ->setCode(
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {

                    $this->requirements($input, $output);

                    return \Symfony\Component\Console\Command\Command::SUCCESS;
                }
            );

        $this->application
            ->register('makeconfig')
            ->setDescription('Write a configuration file from a manifest')
            ->setDefinition(
                [
                new \Symfony\Component\Console\Input\InputArgument('manifest', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'Manifest to use as a template'),
                ]
            )
            ->setCode(
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {

                    if ($filename = $input->getArgument('manifest')) {
                        if (file_exists($filename)) {
                            $this->config = @parse_ini_file($filename);
                        }
                    }

                    echo $this->buildConfig(
                        [
                        'dbname' => $this->config['mysql_name'],
                        'dbpass' => $this->config['mysql_pass'],
                        'dbuser' => $this->config['mysql_user'],
                        'dbhost' => $this->config['mysql_host'],
                        'uploadpath' => $this->config['upload_path'],
                        'database' => $this->config['database'],
                        ]
                    );

                    return \Symfony\Component\Console\Command\Command::SUCCESS;
                }
            );

        $this->application
            ->register('generate-manifest')
            ->setDescription('Generate a template install manifest')
            ->setDefinition(
                [
                new \Symfony\Component\Console\Input\InputArgument('manifest', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'File to write the template to'),
                ]
            )
            ->setCode(
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {

                    if ($fp = fopen($input->getArgument('manifest'), 'w')) {

                        foreach($this->expected_manifest as $key => $value) {
                            fwrite($fp, "$key = $value\n");
                        }
                        fclose($fp);

                    } else {

                        $output->writeln("Couldn't open " . $input->getArgument('manifest'));

                        return \Symfony\Component\Console\Command\Command::FAILURE;

                    }

                    return \Symfony\Component\Console\Command\Command::SUCCESS;
                }
            );

        $this->application
            ->register('install')
            ->setDescription('Install Idno')
            ->setDefinition(
                [
                new \Symfony\Component\Console\Input\InputArgument('config', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'The config file to write in configuration/. Only config.ini is accepted (kept as an argument so that a manifest can still follow it).', 'config.ini'),
                new \Symfony\Component\Console\Input\InputArgument('manifest', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Configuration manifest. If not provided, you will be prompted for settings.', ''),
                ]
            )
            ->setCode(
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int {

                    $helper = new Symfony\Component\Console\Helper\QuestionHelper();

                    // Idno reads configuration/config.ini only: per-domain files (my.domain.ini)
                    // are ignored, so writing one would leave a site that looks uninstalled.
                    $config_name = $input->getArgument('config');
                    if (empty($config_name)) {
                        $config_name = 'config.ini';
                    }
                    if ($config_name !== 'config.ini') {
                        $output->writeln("Cannot write $config_name: per-domain configuration files are no longer read. The installer writes configuration/config.ini only.");

                        return \Symfony\Component\Console\Command\Command::FAILURE;
                    }

                    if ($this->isInstalled()) {
                        throw new \Exception("Idno is already installed.");
                    }

                    // Check requirements
                    $this->requirements($input, $output);

                    // Load manifest if given
                    if ($filename = $input->getArgument('manifest')) {
                        if (file_exists($filename)) {
                            $this->config = @parse_ini_file($filename);
                        }
                    }

                    // Gather settings
                    if (empty($this->config['site_title'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the name of the site: ');

                        $this->config['site_title'] = $helper->ask($input, $output, $question);
                    }

                    if (empty($this->config['database'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database type: ', 'MySQL');

                        $this->config['database'] = $helper->ask($input, $output, $question);
                    }

                    if (empty($this->config['mysql_name'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the name of the database: ', 'known');

                        $this->config['mysql_name'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['mysql_user'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the username: ');

                        $this->config['mysql_user'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['mysql_pass'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database password: ');
                        $question->setHidden(true);

                        $this->config['mysql_pass'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['mysql_host'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database hostname: ', 'localhost');

                        $this->config['mysql_host'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['upload_path'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the upload path: ', $this->root_path . '/Uploads/');

                        $this->config['upload_path'] = $helper->ask($input, $output, $question);
                        if (empty($this->config['upload_path'])) {
                            $this->config['upload_path'] = $this->root_path . '/Uploads/';
                        }
                    }

                    // Check upload path
                    if (!empty($this->config['upload_path'])) {
                        $this->checkUploadDirectory($this->config['upload_path']);
                    }

                    $this->installSchema(
                        $this->config['mysql_host'],
                        $this->config['mysql_name'],
                        $this->config['mysql_user'],
                        $this->config['mysql_pass'],
                        $this->config['database']
                    );

                    $this->writeApacheConfig();

                    $ini_file = $this->buildConfig(
                        [
                        'dbname' => $this->config['mysql_name'],
                        'dbpass' => $this->config['mysql_pass'],
                        'dbuser' => $this->config['mysql_user'],
                        'dbhost' => $this->config['mysql_host'],
                        'uploadpath' => $this->config['upload_path'],
                        'database' => $this->config['database'],
                        ]
                    );

                    $this->writeConfig($ini_file, $config_name);

                    $output->writeln("Your site's configuration is written. Its database is set up when the site is first loaded in a browser, or by running, from " . $this->root_path . ":");
                    $output->writeln("    php bin/console idno:upgrade");
                    $output->writeln("Then visit your site to create your first user!");

                    return \Symfony\Component\Console\Command\Command::SUCCESS;
                }
            );

        $this->application->run();
    }

}

$installer = new CLIInstaller();
$installer->run();
