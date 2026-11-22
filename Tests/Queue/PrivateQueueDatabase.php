<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Connection;
use Tests\Storage\PrivateDatabase;

/**
 * A private database made by the schema migrations only, for a test case that runs on a SQLite file and
 * on a private database of the rig's MySQL or MariaDB server (skipped on an SQLite rig). The test case
 * calls releaseDatabase() in tearDown().
 */
trait PrivateQueueDatabase
{
    private ?Connection $connection = null;

    private ?string $directory = null;

    /**
     * @return array<string, array{string}>
     */
    public static function engines(): array
    {
        return ['SQLite' => ['sqlite'], 'MySQL or MariaDB' => ['mysql']];
    }

    private function database(string $engine, string $purpose = 'queue'): Connection
    {
        if ($engine === 'sqlite') {
            $this->directory = sys_get_temp_dir() . '/idno-' . $purpose . '-' . bin2hex(random_bytes(6));
            mkdir($this->directory, 0700);

            return $this->connection = PrivateDatabase::sqliteFile($this->directory . '/queue.sqlite');
        }

        return $this->connection = PrivateDatabase::mysql($purpose);
    }

    private function releaseDatabase(): void
    {
        if ($this->connection !== null) {
            if ($this->directory !== null) {
                $this->connection->close();
            } else {
                PrivateDatabase::drop($this->connection);
            }
            $this->connection = null;
        }
        if ($this->directory !== null) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
            $this->directory = null;
        }
    }
}
