<?php

declare(strict_types=1);

namespace Idno\Install;

use Idno\Exceptions\ConfigurationException;

/**
 * A MySQL or MariaDB database could not be connected to. The message is one of
 * DatabaseChoice::connectionProblem()'s sentences, which say why (the host did not answer, the
 * password was refused, there is no such database): right for the command line, whose user runs the
 * server; the web installer, which anyone may reach, shows one sentence for all of them instead.
 */
final class DatabaseConnectionFailed extends ConfigurationException
{
}
