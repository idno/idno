<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Config\BootConfig;
use Idno\Install\FirstAdministrator;
use Idno\Install\SetupToken;
use Idno\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Prints a one-time link that opens the "Create your account" step of the installation in any
 * browser: for a site configured by hand, or when the browser that installed the site is not at
 * hand. It makes a new setup token (an earlier link, and the installer's cookie, stop working) and
 * works only while the site has no administrator.
 */
#[AsCommand('idno:setup:link', 'Prints a one-time link to create the site\'s first administrator in a browser (only while the site has none)')]
final class SetupLinkCommand extends Command
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
        private readonly BootConfig $config,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (!$this->kernel instanceof Kernel || !$this->kernel->legacyBooted()) {
            $errors->writeln('The site\'s database is not set up yet: run php bin/console idno:upgrade first.');

            return Command::FAILURE;
        }
        if (($this->administrator)()->administratorExists()) {
            $errors->writeln('This site already has an administrator: there is nothing to set up.');

            return Command::FAILURE;
        }
        try {
            $token = $this->setup->issue($this->setup->title());
        } catch (\RuntimeException) {
            $errors->writeln('The folder configuration cannot be written to, so no link can be made. Create the administrator here instead: php bin/console idno:admin:create');

            return Command::FAILURE;
        }
        $url = $this->config->get('url');
        if (is_string($url) && $url !== '') {
            $output->writeln('Open this link in your browser to create the administrator account:');
            $output->writeln('    ' . rtrim($url, '/') . '/_setup/' . $token);
        } else {
            $output->writeln('Open your site\'s address followed by this in your browser to create the administrator account:');
            $output->writeln('    /_setup/' . $token);
        }
        $output->writeln('The link works once: the browser that opens it can then create the account. Run this command again for another link. Until the account exists, visitors see a page saying the site is being set up.');
        $output->writeln('The web server must be able to read the file configuration/setup-pending this command wrote: if the link shows "being set up", run this command as the web server\'s user.');

        return Command::SUCCESS;
    }
}
