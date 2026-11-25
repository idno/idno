<?php

declare(strict_types=1);

namespace Idno\Console;

use Doctrine\DBAL\Exception\ConnectionException;
use Idno\Config\BootConfig;
use Idno\Install\DatabaseChoice;
use Idno\Storage\ConnectionFactory;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Files\FilesystemFactory;
use Idno\Storage\Files\FileStore;
use Idno\Upgrade\Mongo\ExtMongoSource;
use Idno\Upgrade\Mongo\MongoImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * `idno:import:mongo`: moves a MongoDB site's data into a new, empty SQL database (MongoImporter) and
 * its GridFS files into the upload directory, reading MongoDB with the mongodb extension alone.
 *
 *   php bin/console idno:import:mongo --target=<database URL> [--upload-path=<dir>] [--file-host=<name>]
 *                                     [--source-uri=<uri>] [--source-db=<name>]
 *
 *  - The target: `mysql://user:password@host[:port]/name` (MySQL or MariaDB) or
 *    `sqlite:///relative/path` / `sqlite:////absolute/path`, from --target or the environment variable
 *    IMPORT_TARGET_URL (no IDNO_ prefix: IDNO_ variables are copied into the site's configuration).
 *    The user and password are percent-decoded. A URL with a user and no password asks for it, hidden,
 *    when the terminal is interactive. Any other URL is refused before anything connects.
 *  - The source: --source-uri and --source-db, by default the site's own settings as
 *    Idno\Data\Mongo::init() read them (`dbstring`, `dbname`, `dbuser`, `dbpass`, `dbauthsrc`) when
 *    the site's database is MongoDB.
 *  - The upload directory: --upload-path, by default the site's `uploadpath`. The directory under it
 *    (the site's file directory, Config::getFileBaseDirName()): --file-host; else the boot
 *    configuration's `file_path_host`; else the imported configuration's `file_path_host`; else the
 *    host of the site's `url` (the boot configuration's, else the imported configuration's), less
 *    `www.` as Config::pathHost() removes it. With none, the import stops before the first file.
 *
 * Nothing is done (exit 1) without the mongodb extension, a target, a source or an upload path, before
 * anything connects. When a run stops after it has written to the target, it says on stderr, after the
 * error, that the target is incomplete and must not be used, and to drop it and start again on a new,
 * empty database (INCOMPLETE). The command never cleans up a failed run: the target is dropped by hand. It exits 0 only when every document was imported or is a reject and every file
 * was copied (or was there) and verified; then it prints the counts, the collections not imported and
 * the lines to put in config.ini, and says to run idno:upgrade. The target's password is never
 * printed, and is replaced in anything logged: a failure to connect is a fixed line and the
 * exception's class, its detail logged on the `upgrade` channel with the password replaced.
 *
 * It runs whether or not the legacy application booted, so it takes nothing from it: its logger is the
 * Monolog channel `upgrade` (config/services.php), and it builds its target, stores and file store itself.
 */
#[AsCommand('idno:import:mongo', 'Imports a MongoDB site into a new, empty MySQL, MariaDB or SQLite database and its GridFS files into the upload directory')]
final class ImportMongoCommand extends Command
{
    /** Said on stderr when a run that wrote to the target stops. */
    public const INCOMPLETE = 'The new database is incomplete and must not be used: never point a site at it (its upgrade page would finish the schema and open the site on partial data). Drop it, make a new, empty database, and run the import again.';

    /** The target URL's environment variable. */
    public const TARGET_VARIABLE = 'IMPORT_TARGET_URL';

    /** What the MongoDB backend's configuration defaulted to (Idno\Core\Config). */
    private const DEFAULT_DBSTRING = 'mongodb://localhost:27017';

    private const DEFAULT_DBNAME = 'idno';

    /** @var list<string> the secrets that must never be printed or logged */
    private array $secrets = [];

    public function __construct(
        private readonly BootConfig $bootConfig,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('target', null, InputOption::VALUE_REQUIRED, 'The new, empty database: mysql://user:password@host[:port]/name, sqlite:///relative/path or sqlite:////absolute/path (or the environment variable ' . self::TARGET_VARIABLE . ')');
        $this->addOption('upload-path', null, InputOption::VALUE_REQUIRED, 'Where to put the GridFS files (default: the site\'s uploadpath)');
        $this->addOption('file-host', null, InputOption::VALUE_REQUIRED, 'The name of the files\' directory under the upload path (default: the site\'s, from file_path_host or its URL)');
        $this->addOption('source-uri', null, InputOption::VALUE_REQUIRED, 'The MongoDB server\'s URI (default: the site\'s dbstring)');
        $this->addOption('source-db', null, InputOption::VALUE_REQUIRED, 'The MongoDB database (default: the site\'s dbname)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->secrets = [];
        $config = $this->bootConfig->all();

        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\BSON\Document::class)) {
            $errors->writeln('The import reads MongoDB with the PHP extension mongodb (1.16 or later), which this PHP does not have: install it (pecl install mongodb) and run the import again. Nothing was done.');

            return Command::FAILURE;
        }

        // The target.
        $url = $input->getOption('target');
        if (!is_string($url) || $url === '') {
            $url = getenv(self::TARGET_VARIABLE);
        }
        if (!is_string($url) || trim($url) === '') {
            $errors->writeln('Give the new, empty database with --target=<database URL> (or the environment variable ' . self::TARGET_VARIABLE . '). Nothing was done.');

            return Command::FAILURE;
        }
        $target = self::targetSettings(trim($url));
        if (is_string($target)) {
            $errors->writeln($target . ' Nothing was done.');

            return Command::FAILURE;
        }
        if ($target['dbuser'] !== null && $target['dbpass'] === null && $input->isInteractive()) {
            $question = (new Question('The password of ' . $target['dbuser'] . ' on the new database: '))->setHidden(true)->setHiddenFallback(false);
            $answer = $this->getHelper('question')->ask($input, $output, $question);
            $target['dbpass'] = is_string($answer) ? $answer : '';
        }
        $this->keepSecret($target['dbpass']);

        // The source.
        $siteIsMongo = ConnectionParameters::driverFamily($config) === 'mongo';
        $uri = $input->getOption('source-uri');
        $database = $input->getOption('source-db');
        $credentials = [null, null, null];
        if (!is_string($uri) || $uri === '') {
            if (!$siteIsMongo) {
                $errors->writeln('The site is not configured for MongoDB: give the MongoDB database with --source-uri and --source-db. Nothing was done.');

                return Command::FAILURE;
            }
            $uri = self::text($config['dbstring'] ?? null) ?? self::DEFAULT_DBSTRING;
            $credentials = [self::text($config['dbuser'] ?? null), self::text($config['dbpass'] ?? null), self::text($config['dbauthsrc'] ?? null)];
        }
        if (!is_string($database) || $database === '') {
            $database = $siteIsMongo ? (self::text($config['dbname'] ?? null) ?? self::DEFAULT_DBNAME) : null;
        }
        if ($database === null) {
            $errors->writeln('Give the MongoDB database with --source-db. Nothing was done.');

            return Command::FAILURE;
        }
        $this->keepSecret($credentials[1]);
        foreach (self::uriPasswords($uri) as $password) {
            $this->keepSecret($password);
        }

        // The upload directory and the files' directory under it.
        $uploadPath = $input->getOption('upload-path');
        if (!is_string($uploadPath) || trim($uploadPath) === '') {
            $uploadPath = self::text($config['uploadpath'] ?? null);
        }
        if ($uploadPath === null || trim($uploadPath, ' /') === '') {
            $errors->writeln('Give the upload directory with --upload-path (the site has no uploadpath). Nothing was done.');

            return Command::FAILURE;
        }
        $uploadPath = rtrim(trim($uploadPath), '/') . '/';
        $fileHost = $input->getOption('file-host');
        $fileHost = is_string($fileHost) && $fileHost !== '' ? $fileHost : null;
        if ($fileHost !== null && !self::isDirectoryName($fileHost)) {
            $errors->writeln('--file-host must be a directory name (letters, digits, dots, hyphens, underscores). Nothing was done.');

            return Command::FAILURE;
        }

        $connection = null;
        $importer = null;
        try {
            $connection = ConnectionFactory::forConfig($target, [], $this->projectDir);
            $directory = null;
            $files = new FileStore(
                FilesystemFactory::fromConfig(['uploadpath' => $uploadPath] + $config),
                function () use (&$directory, &$importer, $fileHost, $config): string {
                    return $directory ??= $this->fileDirectory($fileHost, $config, $importer?->settings());
                },
            );
            try {
                $source = ExtMongoSource::connect($uri, $database, ...$credentials);
            } catch (\Throwable $e) {
                return $this->sourceFailed($e, $importer, $errors);
            }
            $importer = new MongoImporter($source, $connection, $files, $this->logger);
            $result = $importer->run(static function (string $line) use ($output): void {
                $output->writeln($line, OutputInterface::OUTPUT_PLAIN);
            });
        } catch (ConnectionException $e) {
            $this->logger->error('The MongoDB import could not connect to the target database: ' . $this->redact(get_class($e) . ': ' . $e->getMessage()));
            $errors->writeln('The import could not connect to the new database (' . get_class($e) . '). Check the target URL; the detail is in the error log.');
            $this->incomplete($importer, $errors);

            return Command::FAILURE;
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            return $this->sourceFailed($e, $importer, $errors);
        } catch (\Throwable $e) {
            $message = $this->redact($e->getMessage());
            $this->logger->error('The MongoDB import stopped: ' . get_class($e) . ': ' . $message);
            $errors->writeln('The import stopped: ' . $message, OutputInterface::OUTPUT_PLAIN);
            if (!$e instanceof \RuntimeException) {
                $errors->writeln('(' . get_class($e) . ')', OutputInterface::OUTPUT_PLAIN);
            }
            $this->incomplete($importer, $errors);

            return Command::FAILURE;
        } finally {
            $connection?->close();
        }

        $this->printSummary($result, $target, $uploadPath, $fileHost, $output);

        return Command::SUCCESS;
    }

    /**
     * A failure to parse the source's URI, to connect to MongoDB or to read from it: a fixed line and
     * the exception's class. The exception's message is not printed: the driver quotes the whole URI
     * in some of them, password included, so the detail goes to the log only, with every form of the
     * passwords replaced.
     */
    private function sourceFailed(\Throwable $e, ?MongoImporter $importer, OutputInterface $errors): int
    {
        $this->logger->error('The MongoDB import could not use the source: ' . $this->redact(get_class($e) . ': ' . $e->getMessage()));
        $errors->writeln('The import could not read the MongoDB source (' . get_class($e) . '). Check the source URI, the user and password and the database; the detail is in the error log.');
        $this->incomplete($importer, $errors);

        return Command::FAILURE;
    }

    /**
     * After a failure: what to do with the target. Once the run has written to it, it is incomplete,
     * and a site pointed at it would finish its schema from the upgrade page and open on partial data.
     */
    private function incomplete(?MongoImporter $importer, OutputInterface $errors): void
    {
        if ($importer?->touchedTarget()) {
            $errors->writeln(self::INCOMPLETE);
        } else {
            $errors->writeln('Nothing was written to the new database. Run the import again once the problem is solved.');
        }
    }

    /**
     * @param array{collections: array<string, array{source: int, imported: int, rejected: int}>, files: array{source: int, copied: int, skipped: int, bytes: int}, notImported: list<string>} $result
     * @param array{database: string, dbname: string, dbhost: ?string, dbport: ?int, dbuser: ?string, dbpass: ?string} $target
     */
    private function printSummary(array $result, array $target, string $uploadPath, ?string $fileHost, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('The import is complete.');
        $rejected = 0;
        foreach ($result['collections'] as $collection => $counts) {
            $output->writeln(sprintf('  %s: %d of %d imported, %d rejected', $collection, $counts['imported'], $counts['source'], $counts['rejected']), OutputInterface::OUTPUT_PLAIN);
            $rejected += $counts['rejected'];
        }
        $files = $result['files'];
        $output->writeln(sprintf('  files: %d of %d copied (%d bytes), %d already there', $files['copied'], $files['source'], $files['bytes'], $files['skipped']), OutputInterface::OUTPUT_PLAIN);
        if ($rejected > 0) {
            $output->writeln(sprintf('%d documents were not imported: each is in the table idno_upgrade_rejects of the new database, with its contents and why.', $rejected));
        }
        if ($result['notImported'] !== []) {
            $output->writeln('Not imported (no Idno 2 site reads them): ' . implode(', ', $result['notImported']), OutputInterface::OUTPUT_PLAIN);
        }

        $output->writeln('');
        $output->writeln('Put these lines in configuration/config.ini, in place of the MongoDB settings:');
        $lines = ['database = "' . $target['database'] . '"', 'dbname = "' . $target['dbname'] . '"'];
        if ($target['database'] === 'mysql') {
            $lines[] = 'dbhost = "' . $target['dbhost'] . '"';
            if ($target['dbport'] !== null) {
                $lines[] = 'dbport = "' . $target['dbport'] . '"';
            }
            $lines[] = 'dbuser = "' . ($target['dbuser'] ?? '') . '"';
            $lines[] = 'dbpass = "<the password you gave>"';
        }
        $lines[] = 'filesystem = "local"';
        $lines[] = 'uploadpath = "' . $uploadPath . '"';
        if ($fileHost !== null) {
            $lines[] = 'file_path_host = "' . $fileHost . '"';
        }
        foreach ($lines as $line) {
            // Raw: the placeholder <the password you gave> is not a formatting tag, and plain output would strip it.
            $output->writeln('  ' . $line, OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('');
        $output->writeln('Now run php bin/console idno:upgrade');
        if ($target['database'] === 'sqlite') {
            $path = str_starts_with($target['dbname'], '/') ? $target['dbname'] : $this->projectDir . '/' . $target['dbname'];
            $output->writeln('');
            foreach (DatabaseChoice::sqliteOwnershipNotes($path) as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_PLAIN);
            }
        }
    }

    /**
     * The target URL as legacy database settings, or why it cannot be a target.
     *
     * @return array{database: string, dbname: string, dbhost: ?string, dbport: ?int, dbuser: ?string, dbpass: ?string}|string
     */
    private static function targetSettings(string $url): array|string
    {
        $scheme = strtolower((string) strstr($url, ':', true));
        if ($scheme === 'sqlite') {
            if (!str_starts_with(strtolower($url), 'sqlite:///')) {
                return 'An SQLite target is sqlite:///relative/path (under the project directory) or sqlite:////absolute/path.';
            }
            $settings = ConnectionParameters::fromUrl($url);
            if ($settings['dbname'] === '' || $settings['dbname'] === ':memory:') {
                return 'An SQLite target names its database file: sqlite:///relative/path or sqlite:////absolute/path.';
            }
            if (!ConnectionParameters::isSqliteFileName($settings['dbname'])) {
                return 'The SQLite database file\'s name must end in lower case .sqlite exactly (not .SQLite or .Sqlite; letters, digits, dots, underscores and dashes before it), for example sqlite:///data/idno.sqlite: web servers are set up never to serve or run a file with that name.';
            }

            return ['database' => 'sqlite', 'dbname' => $settings['dbname'], 'dbhost' => null, 'dbport' => null, 'dbuser' => null, 'dbpass' => null];
        }
        if ($scheme !== 'mysql' && $scheme !== 'mariadb') {
            return 'The target must be a MySQL or MariaDB database (mysql://user:password@host/name) or an SQLite file (sqlite:///path).';
        }
        $settings = ConnectionParameters::fromUrl($url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (($settings['dbhost'] ?? '') === '' || $settings['dbname'] === '' || trim($path, '/') !== $settings['dbname']) {
            return 'A MySQL or MariaDB target is mysql://user:password@host[:port]/name.';
        }

        return [
            'database' => 'mysql',
            'dbname' => $settings['dbname'],
            'dbhost' => (string) $settings['dbhost'],
            'dbport' => $settings['dbport'] === null ? null : (int) $settings['dbport'],
            'dbuser' => $settings['dbuser'] === null ? null : rawurldecode($settings['dbuser']),
            'dbpass' => $settings['dbpass'] === null ? null : rawurldecode($settings['dbpass']),
        ];
    }

    /**
     * The files' directory under the upload path (see the class comment).
     *
     * @param array<string, mixed>         $config   the boot configuration
     * @param array<array-key, mixed>|null $imported the imported configuration
     */
    private function fileDirectory(?string $fileHost, array $config, ?array $imported): string
    {
        $candidates = [
            $fileHost,
            self::text($config['file_path_host'] ?? null),
            self::text($imported['file_path_host'] ?? null),
            self::urlHost($config['url'] ?? null),
            self::urlHost($imported['url'] ?? null),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                if (!self::isDirectoryName($candidate)) {
                    throw new \RuntimeException(sprintf('The site\'s file directory "%s" is not a plain directory name: give one with --file-host.', $candidate));
                }

                return $candidate;
            }
        }

        throw new \RuntimeException('Neither the site\'s configuration nor the imported one says which directory its files are in (file_path_host, or the host of url): give it with --file-host. No file was written.');
    }

    private static function urlHost(mixed $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        // As Idno\Core\Config::init() and pathHost() make it.
        return is_string($host) && $host !== '' ? str_replace('www.', '', $host) : null;
    }

    private static function isDirectoryName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,255}\z/', $name) === 1 && $name !== '.' && $name !== '..';
    }

    /**
     * The passwords in the user-info part of a MongoDB URI, as they are written and percent-decoded.
     * It does not use parse_url(), which fails on a URI with several hosts
     * (`mongodb://user:password@h1:27017,h2:27017/db`) and on a malformed one, exactly the URIs whose
     * password the driver may then quote in its error message. The user-info ends at the first `@`
     * before a `/`; a second reading, to the last `@`, covers a password that holds a raw `@`. A
     * password is what follows the first `:` of the user-info.
     *
     * @return list<string>
     */
    public static function uriPasswords(string $uri): array
    {
        $passwords = [];
        foreach (['#^mongodb(?:\+srv)?://([^@/]*)@#i', '#^mongodb(?:\+srv)?://(.*)@#is'] as $pattern) {
            if (preg_match($pattern, $uri, $match) !== 1 || !str_contains($match[1], ':')) {
                continue;
            }
            $password = substr($match[1], (int) strpos($match[1], ':') + 1);
            foreach ([$password, rawurldecode($password)] as $form) {
                if ($form !== '' && !in_array($form, $passwords, true)) {
                    $passwords[] = $form;
                }
            }
        }

        return $passwords;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function keepSecret(?string $secret): void
    {
        if ($secret === null || $secret === '') {
            return;
        }
        foreach ([$secret, rawurlencode($secret), urlencode($secret)] as $form) {
            $this->secrets[] = $form;
        }
    }

    /**
     * Text with every form of the passwords replaced.
     */
    private function redact(string $text): string
    {
        $secrets = array_unique($this->secrets);
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets === [] ? $text : str_replace($secrets, '********', $text);
    }
}
