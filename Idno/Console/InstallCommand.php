<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Config\BootConfig;
use Idno\Exceptions\ConfigurationException;
use Idno\Install\AdministratorDetails;
use Idno\Install\CheckResult;
use Idno\Install\ConfigFile;
use Idno\Install\DatabaseChoice;
use Idno\Install\DatabaseFinding;
use Idno\Install\InstallPaths;
use Idno\Install\ServerChecks;
use Idno\Install\SiteAddress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installs Idno from the command line, with the checks and the result of the web installer: it
 * checks the server, checks or creates the database, writes configuration/config.ini (never over a
 * configuration that exists), creates the tables (`idno:upgrade`) and the administrator
 * (`idno:admin:create`), each in a process of its own, since this one booted without a configuration.
 *
 * Every setting can be an option or a line of a manifest (an ini file); what is missing is asked for
 * unless `--no-interaction` is given. Passwords are never options: the database's comes from the
 * manifest, a file or a prompt, the administrator's from a file, standard input or a prompt.
 * Unlike the web installer, it takes a path for an SQLite database and for uploads, and an http
 * address (a site on a private network): whoever runs it has the server already.
 */
#[AsCommand('idno:install', 'Installs Idno: writes the configuration, creates the database tables and the administrator account')]
final class InstallCommand extends Command
{
    /** Manifest key => option. */
    private const SETTINGS = [
        'site_title' => 'site-title',
        'url' => 'url',
        'database' => 'database',
        'sqlite_path' => 'sqlite-path',
        'mysql_name' => 'db-name',
        'mysql_user' => 'db-user',
        'mysql_host' => 'db-host',
        'upload_path' => 'upload-path',
        'admin_name' => 'admin-name',
        'admin_handle' => 'admin-handle',
        'admin_email' => 'admin-email',
    ];

    public function __construct(
        private readonly InstallPaths $paths,
        private readonly BootConfig $config,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'An ini file of settings: site_title, url, database, sqlite_path, mysql_name, mysql_user, mysql_pass, mysql_host, upload_path, admin_name, admin_handle, admin_email')
            ->addOption('site-title', null, InputOption::VALUE_REQUIRED, 'The site\'s name')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The site\'s address, for example https://example.com/')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'sqlite or mysql (MySQL or MariaDB)')
            ->addOption('sqlite-path', null, InputOption::VALUE_REQUIRED, 'For SQLite: the database file, whose name must end in .sqlite (default data/idno.sqlite)')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'For MySQL: the database\'s name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'For MySQL: the user')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'For MySQL: the server, optionally with :port (default localhost)')
            ->addOption('db-password-file', null, InputOption::VALUE_REQUIRED, 'For MySQL: a file whose first line is the password')
            ->addOption('upload-path', null, InputOption::VALUE_REQUIRED, 'The folder uploaded files are kept in (default Uploads)')
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'The administrator\'s name')
            ->addOption('admin-handle', null, InputOption::VALUE_REQUIRED, 'The administrator\'s username')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'The administrator\'s email address')
            ->addOption('admin-password-file', null, InputOption::VALUE_REQUIRED, 'A file whose first line is the administrator\'s password')
            ->addOption('admin-password-stdin', null, InputOption::VALUE_NONE, 'Read the administrator\'s password from standard input')
            ->addOption('use-existing', null, InputOption::VALUE_NONE, 'Use the Idno site the database already holds (it is upgraded if it is from an older version; nothing in it is replaced)')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Do not create the administrator now (use idno:admin:create or idno:setup:link later)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($this->paths->existingConfigFiles() !== []) {
            $errors->writeln('Idno is already installed: a configuration file exists (' . $this->paths->existingConfigFiles()[0] . '). Nothing was changed.');

            return Command::FAILURE;
        }
        if (!$this->config->isDefault()) {
            $errors->writeln('Idno is already installed: its environment names a database (IDNO_DATABASE or IDNO_DATABASE_URL), so it needs no configuration file. Create the tables with php bin/console idno:upgrade and the administrator with php bin/console idno:admin:create. Nothing was changed.');

            return Command::FAILURE;
        }

        $settings = [];
        $manifest = $input->getOption('manifest');
        if (is_string($manifest)) {
            $read = is_file($manifest) ? @parse_ini_file($manifest) : false;
            if (!is_array($read)) {
                $errors->writeln('The manifest could not be read as an ini file.');

                return Command::FAILURE;
            }
            $settings = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $read);
        }
        foreach (self::SETTINGS as $key => $option) {
            if (is_string($input->getOption($option))) {
                $settings[$key] = $input->getOption($option);
            }
        }
        $helper = new QuestionHelper();
        $ask = static function (string $key, string $question, string $default = '', bool $hidden = false) use (&$settings, $input, $output, $helper): string {
            if (!isset($settings[$key]) && $input->isInteractive()) {
                $prompt = new Question($question . ($default === '' ? '' : ' [' . $default . ']') . ': ', $default);
                if ($hidden) {
                    $prompt->setHidden(true)->setHiddenFallback(false);
                }
                $settings[$key] = (string) $helper->ask($input, $output, $prompt);
            }

            return trim($settings[$key] ?? $default);
        };

        $output->writeln('Checking this server...');
        $checks = ServerChecks::run($this->paths, ['web' => false, 'writable' => static fn (string $path): bool => is_dir($path) && is_writable($path)]);
        foreach ($checks as $check) {
            $output->writeln('  ' . match ($check->state) {
                CheckResult::OK => 'OK   ',
                CheckResult::FAIL => 'FAIL ',
                default => 'NOTE ',
            } . $check->title);
            if ($check->state !== CheckResult::OK) {
                // The upload folder may be chosen below, and the command line can put an SQLite database anywhere.
                foreach (array_filter([$check->meaning, $check->fix, $check->command === '' ? '' : '    ' . $check->command]) as $line) {
                    $output->writeln('       ' . $line);
                }
            }
        }
        foreach ($checks as $check) {
            if ($check->state === CheckResult::FAIL && !in_array($check->id, ['uploads', 'data'], true)) {
                $errors->writeln('This server cannot run Idno yet: fix what is marked FAIL above and run this command again. Nothing was changed.');

                return Command::FAILURE;
            }
        }

        try {
            $title = $ask('site_title', 'The site\'s name');
            $address = $ask('url', 'The site\'s address (for example https://example.com/)');
            if ($address === '') {
                throw new ConfigurationException('The site\'s address is needed (--url=https://example.com/): background tasks and the links in the site\'s mail are built from it.');
            }
            try {
                $url = SiteAddress::fromUrl($address);
            } catch (\InvalidArgumentException $e) {
                throw new ConfigurationException($e->getMessage());
            }
            $type = DatabaseChoice::type($ask('database', 'The database: sqlite (a file on this server) or mysql (MySQL or MariaDB)', 'sqlite'));
            $choice = new DatabaseChoice($this->paths);
            if ($type === 'sqlite') {
                $databaseInput = ['path' => $ask('sqlite_path', 'The database file (its name must end in .sqlite)', $this->paths->sqliteFile())];
                $written = ['dbname' => $choice->sqlitePath($databaseInput['path'])];
            } else {
                $databaseInput = [
                    'name' => $ask('mysql_name', 'The database\'s name'),
                    'user' => $ask('mysql_user', 'The database user'),
                    'pass' => $this->databasePassword($input, $settings, $ask),
                    'host' => $ask('mysql_host', 'The database server', 'localhost'),
                ];
                $written = ['dbname' => $databaseInput['name'], 'dbuser' => $databaseInput['user'], 'dbpass' => $databaseInput['pass'], 'dbhost' => $databaseInput['host']];
            }
            $upload = $ask('upload_path', 'The folder for uploaded files', $this->paths->uploadDir());
            $real = realpath($upload);
            if ($real === false || !is_dir($real) || !is_writable($real)) {
                throw new ConfigurationException('The upload folder ' . $upload . ' does not exist or cannot be written to. Create it, and make it writable for the web server.');
            }
            $upload = rtrim($real, '/\\') . '/';

            $config = new ConfigFile($this->paths);
            $config->assertWritable($written + ['uploadpath' => $upload, 'url' => $url]);
            $params = $choice->params($type, $databaseInput);
            $finding = $choice->inspect($params);
            if ($finding->kind === DatabaseFinding::REFUSED) {
                throw new ConfigurationException($finding->message);
            }
            if ($finding->holdsASite() && !$input->getOption('use-existing')) {
                throw new ConfigurationException('That database already holds an Idno site' . ($finding->kind === DatabaseFinding::IDNO_ONE_X ? ' from Idno 1.x, which would be upgraded (its tables are renamed, so the old version of Idno could no longer use it)' : '') . '. To use it, run this command again with --use-existing; nothing in it is replaced. To start a new site, choose an empty database.');
            }
            if (!$config->writeOnce($config->build($params + ['uploadpath' => $upload, 'url' => $url]))) {
                throw new ConfigurationException('A configuration file appeared while this command ran. Nothing was changed.');
            }
        } catch (ConfigurationException $e) {
            $errors->writeln($e->getMessage());
            $errors->writeln('Idno was not installed.');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Wrote ' . $this->paths->configFile() . '.');
        if (!SiteAddress::isLocal((string) parse_url($url, PHP_URL_HOST)) && str_starts_with($url, 'http://')) {
            $output->writeln('Note: the address is http, not https. Passwords typed on the site can be read on the network; use this only on a private network.');
        }
        if ($finding->holdsASite()) {
            $output->writeln('Using the Idno site the database already holds: it is upgraded if it is from an older version. Nothing in it is replaced.');
        }

        $output->writeln('Setting up the database...');
        if ($this->console(['idno:upgrade'], null, $output) !== 0) {
            $errors->writeln('The configuration is written, but the database could not be set up. Fix what is reported above, then run: php bin/console idno:upgrade');

            return Command::FAILURE;
        }

        $status = Command::SUCCESS;
        if ($input->getOption('no-admin') || $finding->holdsASite()) {
            $output->writeln($finding->holdsASite()
                ? 'The site\'s own accounts sign in as before. If it has no administrator, run: php bin/console idno:admin:create'
            : 'No administrator was created. Run php bin/console idno:admin:create, or php bin/console idno:setup:link to do it in a browser.');
        } else {
            $status = $this->createAdministrator($input, $output, $errors, $title, $ask);
        }

        $output->writeln('');
        $output->writeln('The site\'s files are owned by the user who ran this command. The web server must be able to read ' . $this->paths->configFile() . ' and to write to ' . rtrim($upload, '/') . '.');
        if ($type === 'sqlite') {
            foreach (DatabaseChoice::sqliteOwnershipNotes($params['dbname']) as $line) {
                $output->writeln($line);
            }
        }
        $output->writeln('');
        $output->writeln($status === Command::SUCCESS ? 'Idno is installed. Your site: ' . $url : 'Idno is installed at ' . $url . ', without an administrator yet.');

        return $status;
    }

    /**
     * @param array<string, string>                              $settings
     * @param callable(string, string, string=, bool=): string $ask
     * @throws ConfigurationException
     */
    private function databasePassword(InputInterface $input, array $settings, callable $ask): string
    {
        $file = $input->getOption('db-password-file');
        if (is_string($file)) {
            $text = @file_get_contents($file);
            if ($text === false) {
                throw new ConfigurationException('The database password file could not be read.');
            }

            return rtrim(strtok($text, "\n") ?: '', "\r");
        }

        return $settings['mysql_pass'] ?? $ask('mysql_pass', 'The database password', '', true);
    }

    /**
     * @param callable(string, string, string=, bool=): string $ask
     */
    private function createAdministrator(InputInterface $input, OutputInterface $output, OutputInterface $errors, string $title, callable $ask): int
    {
        $handle = $ask('admin_handle', 'The administrator\'s username (letters, numbers and underscores)');
        $email = $ask('admin_email', 'The administrator\'s email address');
        $name = $ask('admin_name', 'The administrator\'s name (leave empty to use the username)');
        $file = $input->getOption('admin-password-file');
        $password = null;
        if (is_string($file)) {
            $text = @file_get_contents($file);
            $password = $text === false ? null : rtrim(strtok($text, "\n") ?: '', "\r");
        } elseif ($input->getOption('admin-password-stdin')) {
            $password = rtrim(strtok((string) stream_get_contents(STDIN), "\n") ?: '', "\r");
        } elseif ($input->isInteractive()) {
            $helper = new QuestionHelper();
            $first = (string) $helper->ask($input, $output, (new Question('The administrator\'s password (at least ' . AdministratorDetails::PASSWORD_LENGTH . ' characters): '))->setHidden(true)->setHiddenFallback(false));
            $again = (string) $helper->ask($input, $output, (new Question('The password again: '))->setHidden(true)->setHiddenFallback(false));
            $password = $first === $again ? $first : null;
            if ($password === null) {
                $errors->writeln('The two passwords are not the same.');
            }
        }
        if ($password === null || $handle === '' || $email === '') {
            $errors->writeln('The site is installed, but no administrator was created' . ($password === null ? ' (no password was given: use --admin-password-file or --admin-password-stdin)' : '') . '. Run: php bin/console idno:admin:create');

            return Command::FAILURE;
        }
        $arguments = ['idno:admin:create', '--no-interaction', '--password-stdin', '--handle=' . $handle, '--email=' . $email, '--name=' . $name, '--site-title=' . $title];
        if ($this->console($arguments, $password . "\n", $output) !== 0) {
            $errors->writeln('The site is installed, but the administrator was not created. Run: php bin/console idno:admin:create');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Runs a console command of the site in a process of its own (this one booted before the site
     * had a configuration), with this process's environment, and passes its output on.
     *
     * @param string[] $arguments
     */
    private function console(array $arguments, ?string $stdin, OutputInterface $output): int
    {
        $command = array_merge([PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), $this->projectDir . '/bin/console'], $arguments);
        // Standard error goes to a temporary file, not a pipe: two pipes read one after the other can
        // leave both processes waiting when the one read second fills up.
        $errorFile = tmpfile();
        if ($errorFile === false) {
            return 1;
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errorFile], $pipes, $this->projectDir);
        if (!is_resource($process)) {
            return 1;
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        rewind($errorFile);
        $err = (string) stream_get_contents($errorFile);
        fclose($errorFile);
        // What the command wrote on standard error is its log unless it failed.
        foreach (array_filter(explode("\n", $out . ($status === 0 ? '' : $err)), static fn (string $line): bool => trim($line) !== '') as $line) {
            $output->writeln('  ' . $line, OutputInterface::OUTPUT_RAW);
        }

        return $status;
    }
}
