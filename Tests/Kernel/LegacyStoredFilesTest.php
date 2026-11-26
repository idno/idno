<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * The file page's rules of who may have a stored file (`Idno\Legacy\LegacyStoredFiles`), on the files of a site of the test's own: a public file, a file
 * whose stored type holds a line break, a file with no type, files that carry the `restricted` mark and the earlier `site_export` mark, the archive the
 * settings record (in the other letter case), a file attached to each of a member's posts (public, members only, private, a draft, one that is gone), asked
 * for by a visitor, another member, the owner and an administrator.
 */
final class LegacyStoredFilesTest extends PrivateSiteTestCase
{
    private const CODE = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $site->config()->host = (string) parse_url($site->config()->getURL(), PHP_URL_HOST);
    $store = \Idno\Core\Idno::container()->get(\Idno\Storage\Files\FileStore::class);
    $make = static function (string $handle, bool $admin): \Idno\Entities\User {
        $user = new \Idno\Entities\User();
        $user->handle = $handle;
        $user->email = $handle . '@example.com';
        $user->setTitle(ucfirst($handle));
        $user->setPassword('correct-horse-battery-9');
        $user->setAdmin($admin);
        $user->save(true);

        return $user;
    };
    $ada = $make('ada', true);
    $bob = $make('bob', false);
    $carol = $make('carol', false);
    $post = static function (string $id, string $access, string $status) use ($bob): \Idno\Common\Entity {
        $entity = new \IdnoPlugins\Status\Status();
        $entity->_id = $id;
        $entity->body = 'A note ' . $id;
        $entity->setOwner($bob);
        $entity->setAccess($access);
        $entity->setPublishStatus($status);
        $entity->created = (int) strtotime('2026-03-14 12:00:00 UTC');
        $entity->save(true);

        return $entity;
    };
    $public = $post('00000000000040008000000000000041', 'PUBLIC', 'published');
    $members = $post('00000000000040008000000000000042', 'SITE', 'published');
    $private = $post('00000000000040008000000000000043', $bob->getUUID(), 'published');
    $draft = $post('00000000000040008000000000000044', 'PUBLIC', 'draft');
    $gone = $post('00000000000040008000000000000045', 'PUBLIC', 'published');
    $goneUuid = $gone->getUUID();
    $gone->delete();

    $bytes = 'ABCDEFGHIJ';
    $files = [
        'public' => ['00000000000040008000000000000f01', ['mime_type' => 'image/png']],
        'break' => ['00000000000040008000000000000f02', ['mime_type' => "image/png\r\nX-Evil: yes"]],
        'untyped' => ['00000000000040008000000000000f03', []],
        'restricted' => ['00000000000040008000000000000f04', ['mime_type' => 'application/zip', 'restricted' => 'admin']],
        'restricted-empty' => ['00000000000040008000000000000f05', ['mime_type' => 'application/zip', 'restricted' => '']],
        'site-export' => ['00000000000040008000000000000f06', ['mime_type' => 'application/zip', 'site_export' => true]],
        'recorded' => ['00000000000040008000000000000f07', ['mime_type' => 'application/zip']],
        'attached-public' => ['00000000000040008000000000000f08', ['mime_type' => 'image/png', 'attached_entity' => $public->getUUID()]],
        'attached-members' => ['00000000000040008000000000000f09', ['mime_type' => 'image/png', 'attached_entity' => $members->getUUID()]],
        'attached-private' => ['00000000000040008000000000000f0a', ['mime_type' => 'image/png', 'attached_entity' => $private->getUUID()]],
        'attached-draft' => ['00000000000040008000000000000f0b', ['mime_type' => 'image/png', 'attached_entity' => $draft->getUUID()]],
        'touched' => ['00000000000040008000000000000f0d', ['mime_type' => 'image/png']],
        'attached-gone' => ['00000000000040008000000000000f0c', ['mime_type' => 'image/png', 'attached_entity' => $goneUuid]],
    ];
    $ids = [];
    foreach ($files as $name => [$id, $metadata]) {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $store->storeAs($id, $stream, $metadata);
        $ids[$name] = $id;
    }
    touch(rtrim((string) $site->config()->uploadpath, '/') . '/' . ltrim($store->path($ids['touched']), '/'), 1700000000);
    $site->config()->export_file_id = strtoupper($ids['recorded']);
    $site->config()->save();
    // (saving the settings loads them again, and a script has no request to name the host)
    $site->config()->host = (string) parse_url($site->config()->getURL(), PHP_URL_HOST);

    $lookup = new \Idno\Legacy\LegacyStoredFiles();
    $describe = static function (string $id) use ($lookup): array {
        $found = $lookup->lookUp($id);
        $out = ['outcome' => $found->outcome];
        if ($found->file !== null) {
            $stream = $found->file->open();
            $out += [
                'size' => $found->file->size, 'type' => $found->file->mimeType, 'caching' => $found->file->caching->value,
                'changed' => $found->file->lastChange, 'bytes' => is_resource($stream) ? stream_get_contents($stream) : false
            ];
        }

        return $out;
    };
    $session = $site->session();
    $viewers = ['visitor' => null, 'member' => $carol, 'owner' => $bob, 'admin' => $ada];
    $out = ['ids' => $ids, 'now' => time()];
    foreach ($viewers as $viewer => $user) {
        $user === null ? $session->logUserOff() : $session->logUserOn($user);
        foreach ($ids as $name => $id) {
            $out[$viewer][$name] = $describe($id);
        }
    }
    $session->logUserOff();
    $out['unknown'] = $describe(str_repeat('0', 32));
    $out['empty'] = $describe('');
    $out['zero'] = $describe('0');
    $out['malformed'] = $describe('zzzz');
    $out['short'] = $describe('abc123');

    return $out;
    PHP;

    /** @var array<string, mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $answers = $this->runInSite(self::CODE);
        $this->assertIsArray($answers);
        $this->answers = $answers;
    }

    /** @return array<string, mixed> */
    private function answer(string $viewer, string $file): array
    {
        return $this->answers[$viewer][$file];
    }

    public function testAFileThatIsNoFileIsMissing(): void
    {
        foreach (['unknown', 'empty', 'zero', 'malformed', 'short'] as $name) {
            $this->assertSame(['outcome' => 'missing'], $this->answers[$name], $name);
        }
    }

    public function testAPublicFileIsServedToEveryoneWithItsBytesAndTheHeadersOfAPublicFile(): void
    {
        foreach (['visitor', 'member', 'owner', 'admin'] as $viewer) {
            $a = $this->answer($viewer, 'public');
            $this->assertSame('served', $a['outcome'], $viewer);
            $this->assertSame(10, $a['size']);
            $this->assertSame('image/png', $a['type']);
            $this->assertSame('public', $a['caching']);
            $this->assertSame('ABCDEFGHIJ', $a['bytes']);
        }
    }

    public function testAFileIsDatedByTheTimeItsBytesWereWritten(): void
    {
        $a = $this->answer('visitor', 'public');

        $this->assertEqualsWithDelta($this->answers['now'], $a['changed'], 30, 'written when the script made it');
        $this->assertSame(1_700_000_000, $this->answer('visitor', 'touched')['changed'], 'the time the file system says');
    }

    public function testTheStoredTypeLosesWhatFollowsALineBreakAndDefaultsToOctetStream(): void
    {
        $this->assertSame('image/png', $this->answer('visitor', 'break')['type']);
        $this->assertSame('application/octet-stream', $this->answer('visitor', 'untyped')['type']);
    }

    public function testAnArchiveIsForAdministratorsWhateverTheMarkHoldsOrHowTheSettingsSpellItsId(): void
    {
        foreach (['restricted', 'restricted-empty', 'site-export', 'recorded'] as $file) {
            foreach (['visitor', 'member', 'owner'] as $viewer) {
                $this->assertSame(['outcome' => 'administrators-only'], $this->answer($viewer, $file), $viewer . ' ' . $file);
            }
            $admin = $this->answer('admin', $file);
            $this->assertSame('served', $admin['outcome'], $file);
            $this->assertSame('administrators', $admin['caching'], $file);
            $this->assertSame('ABCDEFGHIJ', $admin['bytes']);
        }
    }

    public function testAFileAttachedToAPublicPostIsServedAsAPublicFile(): void
    {
        foreach (['visitor', 'member', 'owner', 'admin'] as $viewer) {
            $a = $this->answer($viewer, 'attached-public');
            $this->assertSame('served', $a['outcome'], $viewer);
            $this->assertSame('public', $a['caching'], $viewer);
        }
    }

    public function testAFileAttachedToAPostOnlyItsReadersMayReadIsServedToThemAsARestrictedFileAndIsMissingToTheRest(): void
    {
        $this->assertSame(['outcome' => 'missing'], $this->answer('visitor', 'attached-members'));
        $this->assertSame('restricted', $this->answer('member', 'attached-members')['caching']);

        foreach (['visitor', 'member'] as $viewer) {
            $this->assertSame(['outcome' => 'missing'], $this->answer($viewer, 'attached-private'), $viewer . ' private');
            $this->assertSame(['outcome' => 'missing'], $this->answer($viewer, 'attached-draft'), $viewer . ' draft');
        }
        foreach (['owner', 'admin'] as $viewer) {
            $this->assertSame('restricted', $this->answer($viewer, 'attached-private')['caching'], $viewer . ' private');
            $this->assertSame('restricted', $this->answer($viewer, 'attached-draft')['caching'], $viewer . ' draft');
        }
    }

    public function testAFileWhosePostIsGoneIsServedAsAPublicFile(): void
    {
        $this->assertSame('public', $this->answer('visitor', 'attached-gone')['caching']);
    }
}
