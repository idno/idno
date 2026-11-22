<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\File;
use Idno\Entities\User;
use Tests\IdnoTestCase;

/**
 * What the import and export tests share: the administrator logged on for the length of a test,
 * entities and stored files removed afterwards, directories removed afterwards, and the subprocess
 * (probe.php) that runs what cannot run inside PHPUnit.
 */
abstract class PortabilityTestCase extends IdnoTestCase
{
    /** @var list<Entity> */
    private array $entities = [];

    /** @var list<string> */
    private array $fileIds = [];

    /** @var list<string> */
    private array $paths = [];

    private ?User $loggedOn = null;

    protected function tearDown(): void
    {
        // Entities and files are removed with the administrator logged on, as the pages would.
        $this->logOn($this->admin());
        $first = null;
        foreach ($this->entities as $entity) {
            try {
                Entity::invalidateCache($entity->getUUID());
                $fresh = Entity::getByUUID($entity->getUUID());
                if ($fresh instanceof Entity) {
                    $fresh->delete();
                }
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        foreach ($this->fileIds as $id) {
            try {
                $file = File::getByID($id);
                if ($file instanceof Entity || is_object($file)) {
                    $file->remove();
                }
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        foreach ($this->paths as $path) {
            $this->removePath($path);
        }
        $this->entities = [];
        $this->fileIds = [];
        $this->paths = [];
        Idno::site()->session()->logUserOff();
        $this->loggedOn = null;
        parent::tearDown();
        if ($first !== null) {
            throw $first;
        }
    }

    protected function logOn(User $user): void
    {
        Idno::site()->session()->logUserOff();
        Idno::site()->session()->logUserOn($user);
        $this->loggedOn = $user;
    }

    /**
     * Saves an entity as $owner with the access and publish status, and removes it again in tearDown().
     *
     * @template T of Entity
     * @param T $entity
     * @return T
     */
    protected function store(Entity $entity, User $owner, string $access = 'PUBLIC', string $publishStatus = 'published'): Entity
    {
        $this->entities[] = $entity;
        $entity->setOwner($owner);
        $entity->setAccess($access);
        $entity->setPublishStatus($publishStatus);
        $entity->save(true);

        return $entity;
    }

    protected function track(Entity $entity): void
    {
        $this->entities[] = $entity;
    }

    /**
     * Stops tearDown() removing an entity: for one a test class keeps for its whole run.
     */
    protected function forget(Entity $entity): void
    {
        $this->entities = array_values(array_filter($this->entities, static fn (Entity $e): bool => $e !== $entity));
    }

    /**
     * Stores a file in the site's file store, as an upload would (and removes it in tearDown()), and
     * returns its id.
     */
    protected function storeFile(string $bytes, string $name, string $mime): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        $id = File::createFromFile($path, $name, $mime);
        $this->assertNotFalse($id, 'the file is stored');
        $this->fileIds[] = (string) $id;

        return (string) $id;
    }

    /**
     * A file or directory to remove again in tearDown().
     */
    protected function removeAfterwards(string $path): void
    {
        $this->paths[] = $path;
    }

    protected function removeFileAfterwards(string $id): void
    {
        $this->fileIds[] = $id;
    }

    /**
     * Stops tearDown() removing a stored file: for one a test class keeps for its whole run, and removes itself.
     */
    protected function keepFile(string $id): void
    {
        $this->fileIds = array_values(array_filter($this->fileIds, static fn (string $kept): bool => $kept !== $id));
    }

    private function removePath(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->removePath($path . DIRECTORY_SEPARATOR . $name);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * Runs probe.php in its own PHP process and returns its exit code and its lines of output.
     *
     * @param array<string, mixed>       $arguments
     * @param array<string, string|null> $environment variables to add to the process's own, null to remove one
     *
     * @return array{exit: int, output: string, result: ?array<string, mixed>}
     */
    protected function probe(string $operation, array $arguments = [], array $environment = []): array
    {
        $command = [
            PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            __DIR__ . '/probe.php', $operation, json_encode($arguments, JSON_THROW_ON_ERROR),
        ];
        $env = $environment + getenv();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), array_filter($env, static fn ($v): bool => $v !== null));
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $result = null;
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['stage'])) {
                $result = $decoded;
                break;
            }
        }

        return ['exit' => $exit, 'output' => $stdout . $stderr, 'result' => $result];
    }

    protected function assertWellFormedXml(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, 'well-formed XML: ' . implode('; ', $errors));

        return $document;
    }
}
