<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What LegacyPageDrawer leaves in a process that is not a web request (the CLI, as the queue worker is): the status it puts in place for
 * a draw is put back, and a process that had none has none again, and no header list is taken (PHP keeps none there). A subprocess of a site of its own.
 */
final class LegacyPageDrawerTest extends PrivateSiteTestCase
{
    public function testTheProcessStatusIsPutBackAfterADraw(): void
    {
        $result = $this->runInSite(<<<'PHP'
        $uri = '/status/x';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Following\Add());
        $before = http_response_code();
        $drawn = (new \Idno\Legacy\LegacyPageDrawer(new \Idno\Legacy\PhpHeaders()))->drawPage(['title' => 'T', 'body' => 'B'], 404);

        return ['before' => $before, 'after' => http_response_code(), 'drawn' => $drawn->body !== '', 'headers' => $drawn->headers !== []];
        PHP);

        $this->assertFalse($result['before'], 'the CLI has no status until one is set');
        $this->assertFalse($result['after'], 'still none: not the 404 of the draw, and not a 200');
        $this->assertTrue($result['drawn']);
        $this->assertTrue($result['headers'], 'the type\'s headers are there whatever PHP lists');
    }

    public function testNoStatusIsSetOnceTheOutputHasBegun(): void
    {
        // PHP warns when a status is set after the headers were sent (`pedantic_mode` makes the warning an exception): a draw then leaves it alone
        $result = $this->runInSite(<<<'PHP'
        $uri = '/status/x';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Following\Add());
        echo 'output before the page';
        $drawn = (new \Idno\Legacy\LegacyPageDrawer(new \Idno\Legacy\PhpHeaders()))->drawPage(['title' => 'T', 'body' => 'B'], 404);

        return ['drawn' => $drawn->body !== ''];
        PHP);

        $this->assertTrue($result['drawn']);
    }

    public function testEveryCallGoesThroughTheHeaderQueueAndTheCleanupAsksAgain(): void
    {
        // A double for PHP's list: it is there when the draw begins and gone when it ends (the draw flushed output)
        $result = $this->runInSite(<<<'PHP'
        $uri = '/status/x';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Following\Add());
        $queue = new class implements \Idno\Legacy\HeaderQueue {
            public array $calls = [];
            private int $asked = 0;
            public function listed(): bool { return ++$this->asked === 1; }
            public function queued(): array { $this->calls[] = 'queued'; return ['Expires: x']; }
            public function remove(string $name): void { $this->calls[] = 'remove ' . $name; }
            public function send(string $line, bool $replace): void { $this->calls[] = 'send ' . $line; }
            public function status(): int|false { $this->calls[] = 'status'; return false; }
            public function setStatus(int $code): void { $this->calls[] = 'setStatus ' . $code; }
            public function unsetStatus(): void { $this->calls[] = 'unsetStatus'; }
            public function defaultContentType(): ?string { return null; }
            public function defaultMimetype(): string { return 'text/html'; }
            public function setDefaultMimetype(string $mimetype): void { $this->calls[] = 'mimetype ' . $mimetype; }
        };
        (new \Idno\Legacy\LegacyPageDrawer($queue))->drawPage(['title' => 'T', 'body' => 'B'], 404);

        return $queue->calls;
        PHP);

        $this->assertContains('setStatus 404', $result, 'the status goes through it');
        $this->assertContains('unsetStatus', $result, 'and is put back through it');
        $this->assertNotEmpty(array_filter($result, static fn (string $call): bool => str_starts_with($call, 'send ')), 'the type\'s headers are sent through it');
        $this->assertSame([], array_filter($result, static fn (string $call): bool => str_starts_with($call, 'remove ')), 'once the list is gone nothing is read back or removed');
    }
}
