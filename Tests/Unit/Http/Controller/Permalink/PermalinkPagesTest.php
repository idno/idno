<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Entities\User;
use Idno\Http\Controller\Permalink\PermalinkPages;
use Idno\Http\Controller\Permalink\Permalinks;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageNotModified;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The post permalink's one implementation (`PermalinkPages`), on a double of what it asks the site and a real renderer over a double of the drawing: the
 * order of its checks, what it draws and with which variables, how it answers a conditional request, what it does with a POST and a DELETE, and the
 * webmentions it takes.
 */
final class PermalinkPagesTest extends TestCase
{
    private PermalinksDouble $site;

    private PermalinkDrawerDouble $drawer;

    private PermalinkLogger $logger;

    private PermalinkPages $pages;

    protected function setUp(): void
    {
        $this->site = new PermalinksDouble();
        $this->drawer = new PermalinkDrawerDouble();
        $this->logger = new PermalinkLogger();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $this->pages = new PermalinkPages(new PageRenderer($this->drawer, $translation), $this->site, fn () => $this->logger);
    }

    private function context(string $key = 'a-note', string $method = 'GET', array $headers = []): PageContext
    {
        $request = Request::create('/2026/' . $key, $method, [], [], [], array_combine(array_map(static fn (string $name): string => 'HTTP_' . strtoupper(str_replace('-', '_', $name)), array_keys($headers)), $headers) ?: []);
        $context = new PageContext($request, new SitePage('Idno\Pages\Entity\View', shell: true, markdownPermalink: true));
        $context->setArguments($key === '' ? [] : [$key]);

        return $context;
    }

    private function entity(): Entity
    {
        return $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
    }

    public function testAMissingPostIsGone(): void
    {
        $response = $this->pages->view($this->context('no-such-post'));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['pages/410'], array_column($this->drawer->templates, 0));
        $this->assertSame(['selectMarkdownIfWanted', 'find:no-such-post'], $this->site->calls);
    }

    public function testAnAddressWithNoArgumentIsGoneAndAsksNothing(): void
    {
        $response = $this->pages->view($this->context(''));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['selectMarkdownIfWanted'], $this->site->calls);
    }

    public function testAnArgumentOfZeroIsNoAddressAsAnEmptyOneIsNot(): void
    {
        // the legacy page asked `empty()` of the argument, which '0' is
        $this->assertSame(410, $this->pages->view($this->context('0'))->getStatusCode());
        $this->assertSame(['selectMarkdownIfWanted'], $this->site->calls);
        $this->assertFalse($this->pages->receiveWebmention('idno_permalink', ['0'], new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/2026/0', [], [])));
        $this->assertNotContains('find:0', $this->site->calls);
    }

    public function testAPostTheViewerMayNotReadIsDenied(): void
    {
        $this->site->entity = $this->entity();
        $this->site->readable = false;

        $response = $this->pages->view($this->context());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['pages/403'], array_column($this->drawer->templates, 0));
        $this->assertNotContains('updated', $this->site->calls, 'nothing of the post is asked before the viewer may read it');
    }

    public function testAUserIsSentToItsProfile(): void
    {
        $this->site->entity = $this->entity();
        $this->site->user = true;
        $this->site->display = 'https://example.com/profile/ada';

        $response = $this->pages->view($this->context());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/profile/ada', $response->headers->get('Location'));
        $this->assertSame([], $this->drawer->pages);
    }

    public function testAnAddressOfAnotherLayoutIsSentPermanentlyToThePostsOwnAddress(): void
    {
        $this->site->entity = $this->entity();
        $this->site->display = 'https://example.com/2026/a-note';

        $response = $this->pages->otherLayout($this->context('a-note'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note', $response->headers->get('Location'), 'the target is built from the post, never from the request');
        $this->assertSame([], $this->drawer->pages);
    }

    public function testAnAddressOfAnotherLayoutOfAMissingPostAndOfOneTheViewerMayNotReadAreTheSameAnswer(): void
    {
        $missing = $this->pages->otherLayout($this->context('no-such-post'));
        $this->site->entity = $this->entity();
        $this->site->readable = false;
        $private = $this->pages->otherLayout($this->context('a-note'));

        $this->assertSame(410, $missing->getStatusCode());
        $this->assertSame(410, $private->getStatusCode(), 'nothing tells a post the viewer may not read from one that is not there');
        $this->assertNull($private->headers->get('Location'));
        $this->assertSame(['pages/410', 'pages/410'], array_column($this->drawer->templates, 0));
    }

    public function testAnAddressOfAnotherLayoutWithNoArgumentIsGoneAndAsksNothing(): void
    {
        $this->assertSame(410, $this->pages->otherLayout($this->context(''))->getStatusCode());
        $this->assertSame([], $this->site->calls);
    }

    public function testTheMarkdownTypeIsSelectedBeforeTheChecks(): void
    {
        $this->pages->view($this->context());

        $this->assertSame('selectMarkdownIfWanted', $this->site->calls[0]);
    }

    public function testThePageAsksForConditionalRequests(): void
    {
        $this->site->entity = $this->entity();
        $this->site->updated = 1_700_000_000;
        $copy = gmdate('D, d M Y H:i:s', 1_700_000_000 + 60) . ' GMT';

        $page = $this->context('a-note', 'GET', ['If-Modified-Since' => $copy]);
        try {
            $this->pages->view($page);
            $this->fail('a current copy stops the page');
        } catch (PageNotModified) {
            $this->assertSame([], $this->drawer->pages, 'nothing is drawn for a copy that is current');
        }

        // a copy before the change is not current; the page says when it last changed
        $old = $this->context('a-note', 'GET', ['If-Modified-Since' => gmdate('D, d M Y H:i:s', 1_700_000_000 - 60) . ' GMT']);
        $response = $this->pages->view($old);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([gmdate('D, d M Y H:i:s', 1_700_000_000) . ' GMT'], $old->headers()['Last-Modified']);
    }

    public function testAPostWithNoDateIsAlwaysSentAndSaysNothingOfWhenItChanged(): void
    {
        $this->site->entity = $this->entity();
        $this->site->updated = 0;
        $page = $this->context('a-note', 'GET', ['If-Modified-Since' => 'Sat, 01 Jan 2050 00:00:00 GMT']);

        $response = $this->pages->view($page);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('Last-Modified', $page->headers());
    }

    public function testAConditionalRequestIsAnsweredOnlyAfterTheViewerMayReadThePost(): void
    {
        $this->site->entity = $this->entity();
        $this->site->readable = false;
        $this->site->updated = 1_700_000_000;
        $page = $this->context('a-note', 'GET', ['If-Modified-Since' => 'Sat, 01 Jan 2050 00:00:00 GMT']);

        $this->assertSame(403, $this->pages->view($page)->getStatusCode());
    }

    public function testTheBodyAndShellVariables(): void
    {
        $this->site->entity = $this->entity();
        $this->site->title = 'A title';
        $this->site->description = 'A description';
        $owner = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->site->owner = $owner;
        $page = $this->context();

        $response = $this->pages->view($page);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['entity/wrapper', ['object' => $this->site->entity]]], $this->drawer->templates);
        $this->assertCount(1, $this->drawer->pages);
        [$vars, $status, $shell] = $this->drawer->pages[0];
        $this->assertSame(['title' => 'A title', 'body' => '<entity/wrapper>', 'description' => 'A description'], $vars);
        $this->assertSame([200, 'shell'], [$status, $shell]);
        $this->assertSame($owner, $page->owner());
        $this->assertTrue($page->isPermalink());
        $this->assertSame($this->site->entity, $page->entity());
    }

    public function testTheMarkdownFormIsDrawnOnlyAfterTheChecks(): void
    {
        $this->site->entity = $this->entity();
        $this->site->markdown = true;
        $this->site->readable = false;
        $this->assertSame(403, $this->pages->view($this->context())->getStatusCode());
        $this->assertNotContains('markdown', $this->site->calls, 'a post the viewer may not read is never drawn as Markdown');

        $this->site->readable = true;
        $this->site->calls = [];
        $this->drawer->pages = [];
        $response = $this->pages->view($this->context());

        $this->assertSame('# the post', $response->getContent());
        $this->assertSame([], $this->drawer->pages, 'the shell is not drawn around a Markdown document');
        $this->assertLessThan(array_search('markdown', $this->site->calls, true), array_search('wantsMarkdown', $this->site->calls, true));
    }

    public function testSaveAndDeleteForwardAsTheLegacyPageDid(): void
    {
        $this->site->entity = $this->entity();
        $this->site->display = 'https://example.com/2026/a-note';
        $this->site->referrer = 'https://example.com/where-from';

        // saved: sent to the post
        $this->site->saved = true;
        $this->assertSame('https://example.com/2026/a-note', $this->pages->save($this->context())->headers->get('Location'));
        // not saved: sent back to where the request came from
        $this->site->saved = false;
        $this->assertSame('https://example.com/where-from', $this->pages->save($this->context())->headers->get('Location'));
        // deleted: sent back to where the request came from
        $this->site->calls = [];
        $this->assertSame('https://example.com/where-from', $this->pages->delete($this->context())->headers->get('Location'));
        $this->assertContains('delete', $this->site->calls);
    }

    public function testNothingIsWrittenBeforeTheRequesterIsKnownToBeAllowed(): void
    {
        $this->site->entity = $this->entity();
        $this->site->display = 'https://example.com/2026/a-note';
        foreach (['save', 'delete'] as $action) {
            // a visitor is refused, and nothing but who they are is asked
            $this->site->loggedIn = false;
            $this->site->calls = [];
            $this->assertSame(403, $this->pages->$action($this->context())->getStatusCode(), $action . ' of a visitor');
            $this->assertSame(['isLoggedIn'], $this->site->calls, $action . ' of a visitor');

            // a member who may not edit it is refused
            $this->site->loggedIn = true;
            $this->site->editable = false;
            $this->site->calls = [];
            $this->assertSame(403, $this->pages->$action($this->context())->getStatusCode(), $action . ' of a member who may not edit');
            $this->assertNotContains('saveFromInput', $this->site->calls);
            $this->assertNotContains('delete', $this->site->calls);

            // a post the member may not read is answered as a missing one is: sent home, and nothing is done to it
            $this->site->editable = true;
            $this->site->readable = false;
            $this->site->calls = [];
            $response = $this->pages->$action($this->context());
            $this->assertSame(302, $response->getStatusCode(), $action . ' of a post the member may not read');
            $this->assertNotContains('saveFromInput', $this->site->calls);
            $this->assertNotContains('delete', $this->site->calls);
            $this->site->readable = true;
            $this->site->editable = true;
        }
        // a post that is not there is answered the same way
        $this->site->entity = null;
        $this->assertSame(302, $this->pages->save($this->context())->getStatusCode());
        $this->assertSame(302, $this->pages->delete($this->context())->getStatusCode());
    }

    public function testAnAccountIsDeletedOnlyByAnAdministrator(): void
    {
        $this->site->entity = $this->entity();
        $this->site->user = true;
        $this->site->editable = true;
        $this->site->administrator = false;

        $this->assertSame(403, $this->pages->delete($this->context())->getStatusCode());
        $this->assertNotContains('delete', $this->site->calls);

        $this->site->administrator = true;
        $this->pages->delete($this->context());
        $this->assertContains('delete', $this->site->calls);
    }

    public function testOnlyThePermalinkRouteReceives(): void
    {
        $this->assertTrue($this->pages->receivesFor('idno_permalink'));
        foreach (['idno_home', 'idno_content', 'idno_feed', 'idno_tag', ''] as $route) {
            $this->assertFalse($this->pages->receivesFor($route), $route);
            $this->assertFalse($this->pages->receiveWebmention($route, ['a-note'], new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/2026/a-note', [], [])), $route);
        }
        $this->assertSame([], $this->site->calls);
    }

    public function testAMentionIsAddedToThePost(): void
    {
        $this->site->entity = $this->entity();
        $this->site->mentionTaken = true;
        $mention = new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/2026/a-note', ['response' => 200], ['items' => []]);

        $this->assertTrue($this->pages->receiveWebmention('idno_permalink', ['a-note'], $mention));
        $this->assertSame([$mention], $this->site->mentions);

        $this->site->mentionTaken = false;
        $this->assertFalse($this->pages->receiveWebmention('idno_permalink', ['a-note'], $mention));
    }

    public function testAMentionFromThePostItselfIsAcceptedAndNotStored(): void
    {
        $this->site->entity = $this->entity();
        $this->site->objectUrl = 'https://example.com/2026/a-note';

        $fromItself = new ReceivedWebmention('https://example.com/2026/a-note', 'https://example.com/other', [], []);
        $this->assertTrue($this->pages->receiveWebmention('idno_permalink', ['a-note'], $fromItself));
        $sameSourceAndTarget = new ReceivedWebmention('https://elsewhere.example/a', 'https://elsewhere.example/a', [], []);
        $this->assertTrue($this->pages->receiveWebmention('idno_permalink', ['a-note'], $sameSourceAndTarget));

        $this->assertSame([], $this->site->mentions);
    }

    public function testAMentionToAPostThatIsNotThereIsLoggedAndNotTaken(): void
    {
        $mention = new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/2026/gone', [], []);

        $this->assertFalse($this->pages->receiveWebmention('idno_permalink', ["go\nne"], $mention));

        $this->assertSame([['error', 'No object was found with ID go ne.']], $this->logger->lines);
        $this->assertFalse($this->pages->receiveWebmention('idno_permalink', [], $mention));
    }
}

final class PermalinksDouble implements Permalinks
{
    /** @var list<string> */
    public array $calls = [];

    public ?Entity $entity = null;

    public bool $readable = true;

    public bool $editable = true;

    public bool $loggedIn = true;

    public bool $administrator = false;

    public bool $user = false;

    public bool $markdown = false;

    public bool $saved = true;

    public bool $mentionTaken = true;

    public int $updated = 1_700_000_000;

    public string $display = 'https://example.com/display';

    public string $title = 'Title';

    public string $description = 'Description';

    public string $objectUrl = 'https://example.com/object';

    public string $referrer = 'https://example.com/';

    public ?User $owner = null;

    /** @var list<ReceivedWebmention> */
    public array $mentions = [];

    public function find(string $idOrSlug): ?Entity
    {
        $this->calls[] = 'find:' . $idOrSlug;

        return $this->entity;
    }

    public function canRead(Entity $entity): bool
    {
        $this->calls[] = 'canRead';

        return $this->readable;
    }

    public function canEdit(Entity $entity): bool
    {
        $this->calls[] = 'canEdit';

        return $this->editable;
    }

    public function isLoggedIn(): bool
    {
        $this->calls[] = 'isLoggedIn';

        return $this->loggedIn;
    }

    public function isAdministrator(): bool
    {
        $this->calls[] = 'isAdministrator';

        return $this->administrator;
    }

    public function isUser(Entity $entity): bool
    {
        return $this->user;
    }

    public function displayUrl(Entity $entity): string
    {
        return $this->display;
    }

    public function owner(Entity $entity): ?User
    {
        return $this->owner;
    }

    public function title(Entity $entity): string
    {
        return $this->title;
    }

    public function description(Entity $entity): string
    {
        return $this->description;
    }

    public function updated(Entity $entity): int
    {
        $this->calls[] = 'updated';

        return $this->updated;
    }

    public function selectMarkdownIfWanted(PageContext $page): void
    {
        $this->calls[] = 'selectMarkdownIfWanted';
    }

    public function wantsMarkdown(PageContext $page): bool
    {
        $this->calls[] = 'wantsMarkdown';

        return $this->markdown;
    }

    public function markdown(PageContext $page, Entity $entity): Response
    {
        $this->calls[] = 'markdown';

        return new Response('# the post');
    }

    public function saveFromInput(Entity $entity): bool
    {
        $this->calls[] = 'saveFromInput';

        return $this->saved;
    }

    public function delete(Entity $entity): bool
    {
        $this->calls[] = 'delete';

        return true;
    }

    public function addWebmentions(Entity $entity, ReceivedWebmention $mention): bool
    {
        $this->mentions[] = $mention;

        return $this->mentionTaken;
    }

    public function objectUrl(Entity $entity): string
    {
        return $this->objectUrl;
    }

    public function referrer(PageContext $page): string
    {
        return $this->referrer;
    }
}

final class PermalinkDrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
    public array $pages = [];

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
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
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('page:' . ($vars['title'] ?? '') . ':' . ($vars['body'] ?? ''), [['Content-type', 'text/html']]);
    }
}

final class PermalinkLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
    }
}
