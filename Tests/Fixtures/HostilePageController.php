<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): a page of another site that says hostile things, for a test of what Idno prints
 * of what it fetched. `GET /_test/hostile/hcard` is a page whose h-card has the name `</h1><script>x()</script>`, a `javascript:` photo and a `javascript:` address.
 */
final class HostilePageController
{
    public const NAME = '</h1><script>x()</script>';

    #[Route('/_test/hostile/hcard', name: 'idno_test_hostile_hcard', methods: ['GET'])]
    public function hcard(): Response
    {
        $name = htmlspecialchars(self::NAME, ENT_QUOTES);

        return $this->page('<!doctype html><html><head><title>' . $name . '</title></head>'
            . '<body><div class="h-card"><a class="p-name u-url" href="javascript:alert(1)">' . $name . '</a>'
        . '<img class="u-photo" src="javascript:alert(2)" alt=""></div></body></html>');
    }

    private function page(string $html): Response
    {
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
