<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Http\Controller\Permalink\Permalinks;
use Idno\Http\Controller\Permalink\ShortlinkPages;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The shortlink's one implementation (`ShortlinkPages`), on a double of what it asks the site and a real renderer over a double of the drawing: a code that names
 * a post is moved permanently to the post's address, one that names none is gone, and a webmention to a shortlink is taken as one to the post.
 */
final class ShortlinkPagesTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    private ?Entity $entity = null;

    private bool $taken = true;

    private bool $readable = true;

    /** @var list<ReceivedWebmention> */
    private array $mentions = [];

    private ShortlinkPages $pages;

    private ShortlinkDrawerDouble $drawer;

    protected function setUp(): void
    {
        $this->drawer = new ShortlinkDrawerDouble();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $permalinks = $this->createMock(Permalinks::class);
        $permalinks->method('byShortUrl')->willReturnCallback(function (string $code): ?Entity {
            $this->asked[] = $code;

            return $this->entity;
        });
        $permalinks->method('canRead')->willReturnCallback(fn (): bool => $this->readable);
        $permalinks->method('displayUrl')->willReturn('https://example.com/2026/a-note');
        $permalinks->method('addWebmentions')->willReturnCallback(function (Entity $entity, ReceivedWebmention $mention): bool {
            $this->mentions[] = $mention;

            return $this->taken;
        });
        $this->pages = new ShortlinkPages(new PageRenderer($this->drawer, $translation), $permalinks);
    }

    private function context(string $code): PageContext
    {
        $context = new PageContext(Request::create('/s/' . $code), new SitePage('Idno\Pages\Entity\Shortlink'));
        $context->setArguments([$code]);

        return $context;
    }

    private function entity(): Entity
    {
        return $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
    }

    public function testACodeThatNamesAPostIsMovedPermanentlyToThePostsAddress(): void
    {
        $this->entity = $this->entity();

        $response = $this->pages->go($this->context('abc123'), 'abc123');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note', $response->headers->get('Location'));
        $this->assertSame(['abc123'], $this->asked);
        $this->assertSame([], $this->drawer->templates);
    }

    public function testASignedRequestIsToldWhereToGoWithTheRedirectsStatus(): void
    {
        $this->entity = $this->entity();
        $this->drawer->api = true;

        $response = $this->pages->go($this->context('abc123'), 'abc123');

        $this->assertSame(301, $response->getStatusCode(), 'the legacy page set the status before it forwarded');
        $this->assertSame('{"location":"https:\/\/example.com\/2026\/a-note"}', $response->getContent());
    }

    public function testAPostTheFinderReturnsButTheViewerMayNotReadIsGoneAsAMissingPostIs(): void
    {
        $this->entity = $this->entity();
        $this->readable = false;

        $response = $this->pages->go($this->context('abc123'), 'abc123');

        $this->assertSame(410, $response->getStatusCode());
        $this->assertNull($response->headers->get('Location'), 'the post\'s address is not revealed');
    }

    public function testACodeThatNamesNoPostIsGone(): void
    {
        $response = $this->pages->go($this->context('nosuch'), 'nosuch');

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['pages/410'], array_column($this->drawer->templates, 0));
        $this->assertNull($response->headers->get('Location'));
    }

    public function testACodeOfZeroOrNothingIsNoCodeAndAsksNothing(): void
    {
        $this->entity = $this->entity();
        foreach (['0', ''] as $code) {
            $this->assertSame(410, $this->pages->go($this->context($code), $code)->getStatusCode(), 'code ' . $code);
        }
        $this->assertSame([], $this->asked);
        $this->assertFalse($this->pages->receiveWebmention('idno_shortlink', ['0'], new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/s/0', [], [])));
        $this->assertSame([], $this->asked);
    }

    public function testAShortlinkTakesWebmentionsOnItsOwnRouteAlone(): void
    {
        $this->assertTrue($this->pages->receivesFor('idno_shortlink'));
        foreach (['idno_permalink', 'idno_home', 'idno_annotations', ''] as $route) {
            $this->assertFalse($this->pages->receivesFor($route), $route);
            $this->assertFalse($this->pages->receiveWebmention($route, ['abc123'], new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/s/abc123', [], [])), $route);
        }
        $this->assertSame([], $this->asked);
    }

    public function testAWebmentionToAShortlinkIsAddedToThePost(): void
    {
        $this->entity = $this->entity();
        $mention = new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/s/abc123', ['x'], ['y']);

        $this->assertTrue($this->pages->receiveWebmention('idno_shortlink', ['abc123'], $mention));
        $this->assertSame([$mention], $this->mentions);
        $this->assertSame(['abc123'], $this->asked);

        $this->taken = false;
        $this->assertFalse($this->pages->receiveWebmention('idno_shortlink', ['abc123'], $mention), 'what the post answers is what is answered');
    }

    public function testAWebmentionToACodeThatNamesNoPostIsNotTaken(): void
    {
        $mention = new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/s/nosuch', [], []);

        $this->assertFalse($this->pages->receiveWebmention('idno_shortlink', ['nosuch'], $mention));
        $this->assertFalse($this->pages->receiveWebmention('idno_shortlink', [], $mention));
        $this->assertSame([], $this->mentions);
    }
}

final class ShortlinkDrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    public function templateType(): string
    {
        return 'default';
    }

    public bool $api = false;

    public function isApiRequest(): bool
    {
        return $this->api;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->templates[] = [$name, $vars];

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        return new DrawnPage('page', [['Content-type', 'text/html']]);
    }
}
