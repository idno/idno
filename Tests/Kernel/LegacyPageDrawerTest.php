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
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Onboarding\Begin());
        $before = http_response_code();
        $drawn = (new \Idno\Legacy\LegacyPageDrawer())->drawPage(['title' => 'T', 'body' => 'B'], 404);

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
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Onboarding\Begin());
        echo 'output before the page';
        $drawn = (new \Idno\Legacy\LegacyPageDrawer())->drawPage(['title' => 'T', 'body' => 'B'], 404);

        return ['drawn' => $drawn->body !== ''];
        PHP);

        $this->assertTrue($result['drawn']);
    }
}
