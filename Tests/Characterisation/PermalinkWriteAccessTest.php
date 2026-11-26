<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * Who may write to a post through its permalink, and what happens before that is known: a visitor with the token of their own session and a member
 * who does not own the post change nothing, store no file and make the site fetch no address; the owner's request still works. Each test starts a
 * site of its own, because the requests change it.
 */
final class PermalinkWriteAccessTest extends PermalinkSiteCase
{
    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('PermalinkWriteAccessTest records no answer.');
    }

    private function filesStored(): int
    {
        $count = 0;
        $directory = $this->uploadDirectory();
        if (!is_dir($directory)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess' && $file->getFilename() !== 'readme.txt') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A multipart body.
     *
     * @param array<string, string>                                 $fields
     * @param array<string, array{string, string, string}>          $files  field => [file name, type, bytes]
     *
     * @return array{string, string} the content type header and the body
     */
    private static function multipart(array $fields, array $files = []): array
    {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $name => [$file, $type, $bytes]) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"$file\"\r\nContent-Type: $type\r\n\r\n$bytes\r\n";
        }

        return ['multipart/form-data; boundary=' . $boundary, $body . "--$boundary--\r\n"];
    }

    /**
     * The ways a requester who does not own the post can write to it: a visitor with the token of the session they were given, and another member.
     *
     * @return array<string, array<string, string>> kind => headers, with the token's value under `token`
     */
    private function nonOwners(): array
    {
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));

        return [
            'visitor' => ['Cookie' => $cookie, 'token' => $this->tokenWith(['Cookie' => $cookie])],
            'member' => $this->asViewer('member') + ['token' => $this->tokenOf('member')],
        ];
    }

    /** @param array<string, string> $who @return array{array<string, string>, string} */
    private function asWriter(array $who, array $fields, array $files = []): array
    {
        $token = $who['token'];
        unset($who['token']);
        [$type, $body] = self::multipart($fields + ['__bTk' => $token], $files);

        return [$who + ['Content-Type' => $type], $body];
    }

    private function sinkRequests(string $name): array
    {
        return $this->site()->request('GET', '_test/sink/' . $name . '/log')->json();
    }

    private function bodyOf(string $id): string
    {
        return (string) $this->runInSite('return (string) (\Idno\Common\Entity::getByID(' . $this->literal($id) . ')->body ?? \'\');');
    }

    public function testAVisitorAndAnotherMemberStoreNoFileThroughAPhotoOrMediaPermalink(): void
    {
        $paths = $this->seedPermalinkContent();
        $before = $this->filesStored();
        $targets = [
            ['/' . $paths['photo'], ['photo' => ['x.png', 'image/png', base64_decode(self::PNG)]]],
            ['/' . $paths['media'], ['media' => ['x.mp3', 'audio/mpeg', 'not really audio bytes']]],
        ];
        foreach ($targets as [$path, $files]) {
            foreach ($this->nonOwners() as $kind => $who) {
                [$headers, $body] = $this->asWriter($who, ['title' => 'Changed', 'body' => 'Changed by ' . $kind], $files);
                $answer = $this->site()->request('POST', $path, $headers, $body);
                $this->assertGreaterThanOrEqual(300, $answer->status, "$kind $path");
                $this->assertSame($before, $this->filesStored(), "$kind $path: no file was stored");
            }
        }
        $this->assertStringNotContainsString('Changed', $this->bodyOf((string) $paths['ids']['photo']));
    }

    public function testAVisitorAndAnotherMemberMakeTheSiteFetchNothingThroughAPermalink(): void
    {
        $paths = $this->seedPermalinkContent();
        $sink = 'permalink-write-' . bin2hex(random_bytes(4));
        $address = $this->baseUrl() . '_test/sink/' . $sink;
        $before = [];
        $targets = [
            'note' => ['/' . $paths['status'], ['body' => 'Changed', 'inreplyto' => $address]],
            'article' => ['/' . $paths['article'], ['body' => '<p>Changed</p>', 'title' => 'Changed', 'inreplyto' => $address]],
            'bookmark' => ['/' . $paths['bookmark'], ['bookmark-of' => $address, 'description' => 'Changed']],
        ];
        foreach ($targets as $what => [$path, $fields]) {
            foreach ($this->nonOwners() as $kind => $who) {
                [$headers, $body] = $this->asWriter($who, $fields);
                $this->site()->request('POST', $path, $headers, $body);
                $this->assertStringNotContainsString('Changed', $this->bodyOf((string) $paths['ids'][$what === 'note' ? 'status' : $what]), "$kind $what is unchanged");
            }
        }
        $this->assertSame([], $this->sinkRequests($sink), 'the site fetched nothing for a requester who may not edit');
    }

    public function testTheOwnerStillEditsAPostAndStoresAFileThroughItsPermalink(): void
    {
        $paths = $this->seedPermalinkContent();
        $before = $this->filesStored();
        $token = $this->tokenOf('admin');
        [$type, $body] = self::multipart(['__bTk' => $token, 'title' => 'A fixture photo', 'body' => 'Edited by the owner'], ['photo' => ['x.png', 'image/png', base64_decode(self::PNG)]]);
        $answer = $this->site()->request('POST', '/' . $paths['photo'], $this->asViewer('admin', ['Content-Type' => $type]), $body);
        $this->assertLessThan(400, $answer->status);
        $this->assertGreaterThan($before, $this->filesStored(), 'the owner stored the file');
        $this->assertStringContainsString('Edited by the owner', $this->bodyOf((string) $paths['ids']['photo']));
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('admin'), 'body' => 'A note edited by the owner']);
        $this->site()->request('POST', '/' . $paths['status'], $this->asViewer('admin', ['Content-Type' => $type]), $body);
        $this->assertSame('A note edited by the owner', $this->bodyOf((string) $paths['ids']['status']));
    }

    public function testAnotherMembersRequestToAnEditAddressStoresAndFetchesNothing(): void
    {
        $paths = $this->seedPermalinkContent();
        $ids = $paths['ids'];
        $sink = 'edit-write-' . bin2hex(random_bytes(4));
        $address = $this->baseUrl() . '_test/sink/' . $sink;
        $before = $this->filesStored();
        $member = $this->asViewer('member') + ['token' => $this->tokenOf('member')];
        $requests = [
            ['/photo/edit/' . $ids['photo'], ['title' => 'Changed', 'body' => 'Changed'], ['photo' => ['x.png', 'image/png', base64_decode(self::PNG)]]],
            ['/media/edit/' . $ids['media'], ['title' => 'Changed', 'body' => 'Changed'], ['media' => ['x.mp3', 'audio/mpeg', 'not really audio bytes']]],
            ['/status/edit/' . $ids['status'], ['body' => 'Changed', 'inreplyto' => $address], []],
            ['/entry/edit/' . $ids['article'], ['body' => '<p>Changed</p>', 'title' => 'Changed', 'inreplyto' => $address], []],
            ['/like/edit/' . $ids['bookmark'], ['bookmark-of' => $address, 'description' => 'Changed'], []],
        ];
        foreach ($requests as [$path, $fields, $files]) {
            [$headers, $body] = $this->asWriter($member, $fields, $files);
            $answer = $this->site()->request('POST', $path, $headers, $body);
            $this->assertSame(403, $answer->status, $path);
        }
        $this->assertSame($before, $this->filesStored(), 'no file was stored');
        $this->assertSame([], $this->sinkRequests($sink), 'nothing was fetched');
    }

    public function testARedirectAfterAWriteOrADeleteStaysOnTheSite(): void
    {
        $paths = $this->seedPermalinkContent();
        $ids = $paths['ids'];
        $evil = 'https://evil.example/landing';
        $same = fn (SiteResponse $answer): bool => str_starts_with($answer->lines('location')[0] ?? '', $this->baseUrl());
        // the edit form's `forward-to` field
        $answer = $this->site()->request('POST', '/status/edit/' . $ids['status'], $this->asViewer('admin'), ['__bTk' => $this->tokenOf('admin'), 'body' => 'Edited', 'forward-to' => $evil]);
        $this->assertSame(302, $answer->status);
        $this->assertTrue($same($answer), 'forward-to: ' . ($answer->lines('location')[0] ?? ''));
        // the `Referer` of a delete
        foreach (['/delete/' . $ids['status'], '/photo/delete/' . $ids['photo']] as $path) {
            $answer = $this->site()->request('POST', $path, $this->asViewer('admin', ['Referer' => $evil]), ['__bTk' => $this->tokenOf('admin')]);
            $this->assertSame(302, $answer->status, $path);
            $this->assertTrue($same($answer), $path . ': ' . ($answer->lines('location')[0] ?? ''));
        }
    }

    public function testTheStaticPagesCategoryPagesAreForAnAdministrator(): void
    {
        $this->seedPermalinkContent();
        foreach (['add' => ['category' => 'Mine'], 'edit' => ['category' => 'Mine', 'new_category' => 'Yours'], 'delete' => ['category' => 'Mine'], 'categories' => ['categories' => "A\nB"]] as $page => $fields) {
            foreach ($this->nonOwners() as $kind => $who) {
                $token = $who['token'];
                unset($who['token']);
                $answer = $this->site()->request('POST', '/admin/staticpages/' . $page . '/', $who, $fields + ['__bTk' => $token]);
                $this->assertSame(403, $answer->status, "$kind $page");
            }
            $answer = $this->site()->request('POST', '/admin/staticpages/' . $page . '/', $this->asViewer('admin'), $fields + ['__bTk' => $this->tokenOf('admin')]);
            $this->assertSame(302, $answer->status, "an administrator $page");
        }
    }
}
