<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Install\AdministratorDetails;
use Idno\Install\FirstAdministrator;
use Idno\Install\SetupToken;
use Idno\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Creates the site's first administrator from the command line: for a site installed with
 * `idno:install`, configured by hand or by the environment, or whose installer lost its browser.
 * It works only while the site has no administrator, and the password is never an argument: it is
 * typed at a prompt, or read from a file or from standard input.
 */
#[AsCommand('idno:admin:create', 'Creates the site\'s first administrator account (only while the site has none)')]
final class AdminCreateCommand extends Command
{
    /**
     * @param \Closure(): FirstAdministrator $administrator Fetched when the command runs: it needs the legacy application
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly KernelInterface $kernel,
        #[AutowireServiceClosure(FirstAdministrator::class)]
        private readonly \Closure $administrator,
        private readonly SetupToken $setup,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The administrator\'s name, as shown on the site')
            ->addOption('handle', null, InputOption::VALUE_REQUIRED, 'The username: letters, numbers and underscores')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The email address')
            ->addOption('site-title', null, InputOption::VALUE_REQUIRED, 'The site\'s name')
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'A file whose first line is the password')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from standard input (its first line)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (!$this->kernel instanceof Kernel || !$this->kernel->legacyBooted()) {
            $errors->writeln('The site\'s database is not set up yet: run php bin/console idno:upgrade first.');

            return Command::FAILURE;
        }
        $administrator = ($this->administrator)();
        if ($administrator->administratorExists()) {
            $errors->writeln('This site already has an administrator. An administrator can make another account an administrator on the site\'s Users page.');

            return Command::FAILURE;
        }

        $helper = new QuestionHelper();
        $ask = static function (string $option, string $question) use ($input, $output, $helper): string {
            $value = $input->getOption($option);
            if (is_string($value)) {
                return $value;
            }

            return $input->isInteractive() ? (string) $helper->ask($input, $output, new Question($question)) : '';
        };
        $handle = $ask('handle', 'Username (letters, numbers and underscores): ');
        $email = $ask('email', 'Email address: ');
        $name = $ask('name', 'Name shown on the site (leave empty to use the username): ');

        try {
            [$password, $again] = $this->password($input, $output, $helper);
        } catch (\RuntimeException $e) {
            $errors->writeln($e->getMessage());

            return Command::FAILURE;
        }

        $details = new AdministratorDetails($name, $handle, $email, $password, $again);
        $problems = $administrator->problems($details);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $errors->writeln($problem);
            }
            $errors->writeln('No account was created.');

            return Command::FAILURE;
        }
        $title = $input->getOption('site-title');
        try {
            $administrator->create($details, is_string($title) && trim($title) !== '' ? $title : $this->setup->title());
        } catch (\RuntimeException $e) {
            $errors->writeln($e->getMessage());

            return Command::FAILURE;
        }
        $this->setup->clear();
        $output->writeln('Created the administrator ' . strtolower($details->handle) . '. Sign in on the site with that username and the password.');

        return Command::SUCCESS;
    }

    /**
     * The password and its repetition: from the file or standard input (one line, given once), else
     * typed twice at a hidden prompt.
     *
     * @return array{string, string}
     * @throws \RuntimeException with what to say when there is no password to read
     */
    private function password(InputInterface $input, OutputInterface $output, QuestionHelper $helper): array
    {
        $file = $input->getOption('password-file');
        if (is_string($file)) {
            $text = @file_get_contents($file);
            if ($text === false) {
                throw new \RuntimeException('The password file could not be read.');
            }
            $password = self::firstLine($text);

            return [$password, $password];
        }
        if ($input->getOption('password-stdin')) {
            $password = self::firstLine((string) stream_get_contents(STDIN));

            return [$password, $password];
        }
        if (!$input->isInteractive()) {
            throw new \RuntimeException('Give the password with --password-file=<file> or --password-stdin: it is never taken as an argument.');
        }
        $first = (new Question('Password (at least ' . AdministratorDetails::PASSWORD_LENGTH . ' characters): '))->setHidden(true)->setHiddenFallback(false);
        $second = (new Question('Password again: '))->setHidden(true)->setHiddenFallback(false);

        return [(string) $helper->ask($input, $output, $first), (string) $helper->ask($input, $output, $second)];
    }

    private static function firstLine(string $text): string
    {
        return rtrim(strtok($text, "\n") ?: '', "\r");
    }
}
