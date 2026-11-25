<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What LegacyPageDrawer leaves in a process that is not a web request (the CLI, as the queue worker is): the status it puts in place for
 * a draw is put back, also when the process had none, and no header list is taken (PHP keeps none there). A subprocess of a site of its own.
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
        $this->assertSame(200, $result['after'], 'not the 404 of the draw');
        $this->assertTrue($result['drawn']);
        $this->assertTrue($result['headers'], 'the type\'s headers are there whatever PHP lists');
    }
}
