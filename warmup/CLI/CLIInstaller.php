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
        'mysql_port' => '"Database port (leave empty for 3306)"',
        'upload_path' => '"Upload path"',
    ];

    public function __construct($install_path = null)
    {
        $this->application = new \Symfony\Component\Console\Application('Idno Console Installer', \Idno\Core\Version::version());

        parent::__construct($install_path ?: (getenv('IDNO_INSTALL_PATH') ?: null));
    }

    /**
     * Print the problems the installer found, each with the commands that would fix it
     *
     * @param array $errors Field name => problem, see \Idno\Core\Installer::problem()
     */
    protected function printProblems(array $errors, \Symfony\Component\Console\Output\OutputInterface $output)
    {
        foreach ($errors as $field => $problem) {
            $output->writeln($field . ': ' . $problem['text'], \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            foreach ($problem['commands'] as $command) {
                $output->writeln("\t" . $command, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            }
        }
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
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {

                    $this->requirements($input, $output);

                    return 0;
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
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {

                    if ($filename = $input->getArgument('manifest')) {
                        if (file_exists($filename)) {
                            $this->config = @parse_ini_file($filename) ?: [];
                        }
                    }

                    // The same checks as an installation, without touching the database or the upload folder
                    $checked = $this->normaliseSettings((array) $this->config);
                    if (!empty($checked['errors'])) {
                        $this->printProblems($checked['errors'], $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output);

                        return 1;
                    }
                    $values = $checked['values'];

                    echo $this->buildConfig(
                        [
                        'dbname' => $values['mysql_name'],
                        'dbpass' => $values['mysql_pass'],
                        'dbuser' => $values['mysql_user'],
                        'dbhost' => $values['mysql_host'],
                        'dbport' => $values['mysql_port'],
                        'uploadpath' => $values['upload_path'],
                        'database' => $values['database'],
                        ]
                    );

                    return 0;

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
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {

                    if ($fp = fopen($input->getArgument('manifest'), 'w')) {

                        foreach($this->expected_manifest as $key => $value) {
                            fwrite($fp, "$key = $value\n");
                        }
                        fclose($fp);

                    } else {

                        $output->writeln("Couldn't open " . $input->getArgument('manifest'));

                    }
                }
            );

        $this->application
            ->register('install')
            ->setDescription('Install Idno')
            ->setDefinition(
                [
                new \Symfony\Component\Console\Input\InputArgument('config', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Specify the output config to write, this could be config.ini (default) or my.domain.ini for a domain specific config.', 'config.ini'),
                new \Symfony\Component\Console\Input\InputArgument('manifest', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Configuration manifest. If not provided, you will be prompted for settings.', ''),
                ]
            )
            ->setCode(
                function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {

                    $helper = new Symfony\Component\Console\Helper\QuestionHelper();

                    if ($this->isInstalled()) {
                        throw new \Exception("Idno is already installed.");
                    }

                    // Check requirements
                    $this->requirements($input, $output);

                    // Load manifest if given
                    if ($filename = $input->getArgument('manifest')) {
                        if (file_exists($filename)) {
                            $this->config = @parse_ini_file($filename) ?: [];
                        }
                    }

                    // Load config name
                    $config_name = $input->getArgument('config');
                    if (empty($config_name)) {
                        $config_name = 'config.ini';
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
                    if (!isset($this->config['mysql_pass'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database password: ');
                        $question->setHidden(true);

                        $this->config['mysql_pass'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['mysql_host'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database hostname: ', 'localhost');

                        $this->config['mysql_host'] = $helper->ask($input, $output, $question);
                    }
                    if (!isset($this->config['mysql_port'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the database port (leave empty for 3306): ', '');

                        $this->config['mysql_port'] = $helper->ask($input, $output, $question);
                    }
                    if (empty($this->config['upload_path'])) {
                        $question = new Symfony\Component\Console\Question\Question('Please enter the upload path: ', $this->defaultUploadPath());

                        $this->config['upload_path'] = $helper->ask($input, $output, $question);
                        if (empty($this->config['upload_path'])) {
                            $this->config['upload_path'] = $this->defaultUploadPath();
                        }
                    }

                    // The same checks and the same steps as the web installer
                    $result = $this->install((array) $this->config, $config_name);
                    if (!empty($result['errors'])) {
                        $output->writeln('Idno was not installed, and nothing was changed:');
                        $this->printProblems($result['errors'], $output);

                        return 1;
                    }
                    if (!$result['config_written']) {
                        $output->writeln('The configuration file could not be saved. Save these contents as ' . $result['config_path'] . ':', \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                        $output->writeln($result['config'], \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

                        return 1;
                    }

                    $output->writeln('The configuration was saved as ' . $result['config_path'] . '. Make sure the web server\'s user can read it.', \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                    $output->writeln("Your site should now be installed. Visit your site to create your first user!");

                    return 0;
                }
            );

        $this->application->run();
    }

}

$installer = new CLIInstaller();
$installer->run();
