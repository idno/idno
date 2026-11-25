<?php

declare(strict_types=1);

namespace Tests\Queue;

use Tests\Acceptance\Browser;

/**
 * Publishes, edits and deletes a post of each bundled content type through its own form over HTTP, as the
 * logged-in member whose cookie is given, with the text of the post carrying one link. For the tests of what a
 * save sends to the sites its text links to (ContentTypeWebmentionsQueuedTest, ContentTypeWebmentionsSynchronousTest).
 *
 * The class using it provides site() (PrivateSiteTestCase) and the assertions of PHPUnit.
 */
trait PublishesEachContentType
{
    /**
     * @return array<string, array{string}>
     */
    public static function contentTypes(): array
    {
        return array_combine(array_keys(self::specs()), array_map(static fn (string $type): array => [$type], array_keys(self::specs())));
    }

    /**
     * Where each type is composed, the entity class it makes, whether a save of it can be a draft, and whether
     * an edit of it sends mentions (a check-in sends only when it is new).
     *
     * @return array<string, array{page: string, class: class-string, draft: bool, editSends: bool, upload: ?array{field: string, name: string, type: string, bytes: string}, fields: \Closure(string): array<string, string>}>
     */
    private static function specs(): array
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        return [
            'status' => ['page' => 'status/edit', 'class' => \IdnoPlugins\Status\Status::class, 'draft' => true, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['body' => 'Reading ' . $link . ' today']],
            'reply' => ['page' => 'status/edit', 'class' => \IdnoPlugins\Status\Reply::class, 'draft' => true, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['body' => 'Replying about ' . $link, 'inreplyto' => $link]],
            'article' => ['page' => 'entry/edit', 'class' => \IdnoPlugins\Text\Entry::class, 'draft' => true, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['title' => 'An article', 'body' => 'Long form text that links to ' . $link]],
            'photo' => ['page' => 'photo/edit', 'class' => \IdnoPlugins\Photo\Photo::class, 'draft' => true, 'editSends' => true,
                'upload' => ['field' => 'photo', 'name' => 'pixel.png', 'type' => 'image/png', 'bytes' => $png],
                'fields' => static fn (string $link): array => ['title' => 'A photo', 'body' => 'Seen near ' . $link]],
            'media' => ['page' => 'media/edit', 'class' => \IdnoPlugins\Media\Media::class, 'draft' => false, 'editSends' => true,
                'upload' => ['field' => 'media', 'name' => 'clip.mp3', 'type' => 'audio/mpeg', 'bytes' => 'ID3' . str_repeat("\0", 64)],
                'fields' => static fn (string $link): array => ['title' => 'A clip', 'body' => 'Recorded at ' . $link]],
            'bookmark' => ['page' => 'like/edit', 'class' => \IdnoPlugins\Like\Like::class, 'draft' => true, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['body' => $link, 'title' => 'A bookmark', 'description' => 'Worth keeping']],
            'event' => ['page' => 'event/edit', 'class' => \IdnoPlugins\Event\Event::class, 'draft' => true, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['title' => 'An event', 'body' => 'Meet at ' . $link, 'starttime' => '2030-01-01T10:00', 'endtime' => '2030-01-01T11:00', 'timezone' => 'UTC']],
            'rsvp' => ['page' => 'rsvp/edit', 'class' => \IdnoPlugins\Event\RSVP::class, 'draft' => false, 'editSends' => true, 'upload' => null,
                'fields' => static fn (string $link): array => ['rsvp' => 'yes', 'body' => 'Coming, see ' . $link]],
            'checkin' => ['page' => 'checkin/edit', 'class' => \IdnoPlugins\Checkin\Checkin::class, 'draft' => true, 'editSends' => false, 'upload' => null,
                'fields' => static fn (string $link): array => ['lat' => '51.5', 'long' => '-0.1', 'placename' => 'The pier', 'body' => 'Here, near ' . $link]],
        ];
    }

    /**
     * Posts a new post of the type whose text links to $link; returns the response.
     *
     * @param array<string, string> $extra fields added to (or replacing) the form's
     */
    private function postNew(string $type, string $cookie, string $link, array $extra = []): \Tests\Characterisation\SiteResponse
    {
        $spec = self::specs()[$type];

        return $this->postForm($type, $spec['page'], $spec['page'] . '/', $cookie, $link, $extra);
    }

    /**
     * @param array<string, string> $extra
     */
    private function postEdit(string $type, string $cookie, string $id, string $link, array $extra = []): \Tests\Characterisation\SiteResponse
    {
        $spec = self::specs()[$type];

        return $this->postForm($type, $spec['page'] . '/' . $id, $spec['page'] . '/' . $id, $cookie, $link, $extra, true);
    }

    /**
     * @param array<string, string> $extra
     */
    private function postForm(string $type, string $page, string $action, string $cookie, string $link, array $extra, bool $edit = false): \Tests\Characterisation\SiteResponse
    {
        $spec = self::specs()[$type];
        $browser = new Browser($this->site());
        // The compose pages read `body` without a default, which the rig's pedantic_mode makes an exception.
        $getPage = $edit ? $page : $page . '?body=x';
        $fields = $spec['fields']($link) + ['access' => 'PUBLIC'];
        $fields = $extra + $fields;
        if ($spec['upload'] !== null && !$edit) {
            return $browser->upload($getPage, $action, $fields, $spec['upload'], $cookie);
        }
        if ($spec['upload'] !== null) {
            // The photo form reads its file field even when it is an edit (the rig makes a missing key an exception)
            $form = $browser->get($getPage, $cookie);
            $boundary = 'idno' . bin2hex(random_bytes(8));
            $body = '';
            foreach (Browser::token($form->body, '') + $fields as $name => $value) {
                $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
            }
            $file = $spec['upload'];
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n--$boundary--\r\n";

            return $this->site()->request('POST', $action, ['Cookie' => $cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
        }

        if ($edit) {
            // The edit form posts to the post's own address, which is not the address it is posted to here
            $form = $browser->get($getPage, $cookie);
            $this->assertSame(200, $form->status, 'the edit page draws');

            return $this->site()->request('POST', $action, ['Cookie' => $cookie], Browser::token($form->body, '') + $fields);
        }

        return $browser->submit($getPage, $action, $fields, $cookie);
    }

    /**
     * The id of the newest post of the type, whoever may read it.
     */
    private function newestIdOf(string $type): string
    {
        $id = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);'
            . ' $found = \Idno\Core\Idno::site()->db()->getObjects(' . $this->literal(self::specs()[$type]['class']) . ', [], [], 1);'
            . ' return $found ? $found[0]->getID() : null;'
        );
        $this->assertIsString($id, 'a ' . $type . ' was stored');

        return $id;
    }
}
