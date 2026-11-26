<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Entities\User;
use Idno\Http\Controller\Permalink\AnnotationPages;
use Idno\Http\Controller\Permalink\Annotations;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The annotation pages' one implementation (`AnnotationPages`), on a double of what it asks the site and a real renderer over a double of the drawing: the
 * order of its checks, what it draws and with which variables, which annotation an address names, who is answered what, and what a delete does.
 */
final class AnnotationPagesTest extends TestCase
{
    private AnnotationsDouble $site;

    private AnnotationDrawerDouble $drawer;

    private AnnotationPages $pages;

    protected function setUp(): void
    {
        $this->site = new AnnotationsDouble();
        $this->drawer = new AnnotationDrawerDouble();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $this->pages = new AnnotationPages(new PageRenderer($this->drawer, $translation), $this->site);
        $this->site->entity = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|array<int, string>> $fields
     */
    private function context(array $arguments, string $method = 'GET', array $fields = []): PageContext
    {
        $context = new PageContext(Request::create('/2026/a-note/annotations/', $method, $fields), new SitePage('Idno\Pages\Annotation\View'));
        $context->setArguments($arguments);

        return $context;
    }

    // ------------------------------------------------------------------ show: what is not there, and what may not be read

    public function testAPostThatIsNotThereIsGone(): void
    {
        $this->site->entity = null;

        $response = $this->pages->show($this->context(['no-such-post']));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['pages/410'], array_column($this->drawer->templates, 0));
        $this->assertSame(['find:no-such-post'], $this->site->calls);
    }

    public function testAnAddressWithNoPostOrAPostOfZeroIsGoneAndAsksNothing(): void
    {
        foreach ([[], [''], ['0']] as $arguments) {
            $this->assertSame(410, $this->pages->show($this->context($arguments))->getStatusCode(), json_encode($arguments));
            $this->assertSame(410, $this->pages->delete($this->context($arguments, 'POST'))->getStatusCode(), json_encode($arguments));
            $this->assertSame(410, $this->pages->otherLayout($this->context($arguments))->getStatusCode(), json_encode($arguments));
        }
        $this->assertSame([], $this->site->calls);
    }

    public function testAPostTheFinderReturnsButTheViewerMayNotReadIsGoneNotDenied(): void
    {
        $this->site->readable = false;

        $response = $this->pages->show($this->context(['a-note', 'abc']));

        $this->assertSame(410, $response->getStatusCode(), 'the same answer as a post that is not there, never a refusal that says the post exists');
        $this->assertSame(['pages/410'], array_column($this->drawer->templates, 0));
        $this->assertSame(['find:a-note', 'canRead'], $this->site->calls, 'nothing of the post is asked before the viewer may read it');
        $this->assertSame([], array_filter($this->drawer->templates, static fn (array $t): bool => $t[0] === 'entity/annotations/shell'), 'no annotation is drawn');
    }

    // ------------------------------------------------------------------ show: one annotation

    public function testOneAnnotationIsDrawnInItsShellWithTheVariablesTheLegacyPagePassed(): void
    {
        $this->site->annotations = ['reply' => ['https://example.com/2026/a-note/annotations/abc' => ['content' => 'Hello']]];
        $this->site->owner = $this->getMockBuilder(User::class)->disableOriginalConstructor()->getMock();

        $response = $this->pages->show($context = $this->context(['a-note', 'abc']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($context->isPermalink(), 'the page is a permalink');
        $this->assertSame($this->site->owner, $context->owner());
        $this->assertSame([['entity/annotations/shell', [
            'annotation' => ['content' => 'Hello'],
            'subtype' => 'reply',
            'permalink' => 'https://example.com/2026/a-note/annotations/abc',
            'object' => $this->site->entity,
        ]]], $this->drawer->templates);
        // the shell's variables are the template's, and the page is the post's (the title and the description are the post's)
        $this->assertSame(['title' => 'Title', 'body' => '<entity/annotations/shell>', 'description' => 'Description'], $this->drawer->pages[0][0]);
    }

    public function testTheAnnotationIsFoundByItsOwnIdWhateverAddressItWasStoredUnder(): void
    {
        $old = 'https://example.com/2026/03/a-note/annotations/abc';
        $this->site->keys = ['abc' => $old];
        $this->site->annotations = ['like' => [$old => ['content' => '']]];

        $this->pages->show($this->context(['a-note', 'abc']));

        $this->assertSame($old, $this->drawer->templates[0][1]['permalink']);
        $this->assertSame('like', $this->drawer->templates[0][1]['subtype']);
        $this->assertSame(['content' => ''], $this->drawer->templates[0][1]['annotation']);
    }

    public function testAnAnnotationThePostDoesNotHoldIsAnEmptyShellAtTheAddressItWouldHaveNow(): void
    {
        $this->pages->show($this->context(['a-note', 'nothing']));

        $this->assertSame([
            'annotation' => false,
            'subtype' => false,
            'permalink' => 'https://example.com/2026/a-note/annotations/nothing',
            'object' => $this->site->entity,
        ], $this->drawer->templates[0][1], 'false for both, as the legacy page passed them (a JSON page prints them)');
    }

    public function testAnAnnotationIdOfZeroIsTheListAsAnEmptyOneIs(): void
    {
        foreach (['0', ''] as $id) {
            $this->drawer->templates = [];
            $this->pages->show($this->context(['a-note', $id]));
            $this->assertSame([], $this->drawer->templates, 'an empty list draws no annotation');
            $this->assertSame('[t]Annotations on: Title', $this->drawer->pages[array_key_last($this->drawer->pages)][0]['title']);
        }
    }

    // ------------------------------------------------------------------ show: the list

    public function testTheListDrawsEachAnnotationAndLeavesOnlyThePostOnThePage(): void
    {
        $this->site->annotations = [
            'reply' => ['k1' => ['content' => 'one'], 'k2' => ['content' => 'two']],
            'like' => ['k3' => ['content' => 'three']],
        ];

        $response = $this->pages->show($this->context(['a-note']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['k1', 'k2', 'k3'], array_column(array_column($this->drawer->templates, 1), 'permalink'));
        $this->assertSame(['reply', 'reply', 'like'], array_column(array_column($this->drawer->templates, 1), 'subtype'));
        $this->assertSame(
            [['annotation', 'annotation_permalink', 'annotations', 'action', 'subtype', 'permalink']],
            array_values(array_unique($this->site->forgotten, SORT_REGULAR)),
            'what the shell drew is taken off the page after each (the post stays: an HTML or JSON page names it)'
        );
        $this->assertCount(3, $this->site->forgotten);
        $this->assertSame('[t]Annotations on: Title', $this->drawer->pages[0][0]['title']);
        $this->assertSame('<entity/annotations/shell><entity/annotations/shell><entity/annotations/shell>', $this->drawer->pages[0][0]['body']);
        $this->assertSame('Description', $this->drawer->pages[0][0]['description']);
        $this->assertArrayNotHasKey('annotations', $this->drawer->pages[0][0]);
    }

    public function testAnRssListNamesItsAnnotationsAndNotThePost(): void
    {
        $this->site->type = 'rss';
        $this->site->annotations = ['reply' => ['k1' => ['content' => 'one']], 'like' => ['k2' => ['content' => 'two']]];

        $this->pages->show($this->context(['a-note']));

        $this->assertSame([['annotation', 'annotation_permalink', 'annotations', 'action', 'subtype', 'permalink', 'object']], array_values(array_unique($this->site->forgotten, SORT_REGULAR)));
        $this->assertSame(['annotations', 'title', 'body', 'description'], array_keys($this->drawer->pages[0][0]), 'the annotations come first, as the legacy page set them');
        $this->assertSame([['content' => 'one'], ['content' => 'two']], $this->drawer->pages[0][0]['annotations']);
    }

    public function testTheListsTitleIsTheSitesOwnForTheListOfThePost(): void
    {
        $this->site->title = '100% sure';

        $this->pages->show($this->context(['a-note']));

        $this->assertSame('[t]Annotations on: 100% sure', $this->drawer->pages[0][0]['title']);
    }

    // ------------------------------------------------------------------ delete

    public function testDeleteOfAPostThatIsNotThereOrNotReadableIsGone(): void
    {
        $this->site->entity = null;
        $this->assertSame(410, $this->pages->delete($this->context(['x'], 'POST'))->getStatusCode());

        $this->site->entity = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
        $this->site->readable = false;
        $this->site->editable = true;
        $this->assertSame(410, $this->pages->delete($this->context(['a-note'], 'POST', ['permalink' => $this->encoded('k1')]))->getStatusCode());
        $this->assertSame([], $this->site->removed, 'nothing is removed before the viewer may read the post');
        $this->assertNotContains('canEditAnnotation:k1', $this->site->calls);
    }

    public function testTheEditorOfThePostRemovesTheAnnotationTheRequestNamesAndIsSentToTheComments(): void
    {
        $response = $this->pages->delete($this->context(['a-note'], 'POST', ['permalink' => $this->encoded('https://example.com/2026/a-note/annotations/abc')]));

        $this->assertSame(['https://example.com/2026/a-note/annotations/abc'], $this->site->removed);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note#comments', $response->headers->get('Location'));
    }

    public function testARequesterWhoMayNotEditTheAnnotationRemovesNothingAndIsSentToTheComments(): void
    {
        $this->site->editable = false;

        $response = $this->pages->delete($this->context(['a-note'], 'POST', ['permalink' => $this->encoded('k1')]));

        $this->assertSame([], $this->site->removed);
        $this->assertSame(['find:a-note', 'canRead', 'canEditAnnotation:k1', 'url'], $this->site->calls);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note#comments', $response->headers->get('Location'));
    }

    public function testWithNoPermalinkTheAnnotationTheAddressNamesIsRemovedWhereverItIsStored(): void
    {
        $this->site->keys = ['abc' => 'https://example.com/2026/03/a-note/annotations/abc'];

        $this->pages->delete($this->context(['a-note', 'abc'], 'POST'));
        $this->assertSame(['https://example.com/2026/03/a-note/annotations/abc'], $this->site->removed);

        $this->site->removed = [];
        $this->pages->delete($this->context(['a-note', 'other'], 'POST'));
        $this->assertSame(['https://example.com/2026/a-note/annotations/other'], $this->site->removed, 'an annotation the post does not hold: the address it would have now, which names nothing');
    }

    public function testAPermalinkOfZeroOrOneThatIsNotTextIsNoPermalink(): void
    {
        foreach ([['permalink' => 'MA'], ['permalink' => ['a', 'b']], ['permalink' => '']] as $fields) {
            $this->site->removed = [];
            $this->pages->delete($this->context(['a-note', 'abc'], 'POST', $fields));
            $this->assertSame(['https://example.com/2026/a-note/annotations/abc'], $this->site->removed, json_encode($fields));
        }
    }

    public function testThePermalinkIsReadAsTheTemplatesWriteIt(): void
    {
        // base64 with `-`, `_` and `,` for `+`, `/` and `=`
        $address = 'https://example.com/2026/a-note/annotations/ab?x=>>>???';
        $encoded = strtr(base64_encode($address), '+/=', '-_,');
        $this->assertNotSame(base64_encode($address), $encoded);

        $this->pages->delete($this->context(['a-note'], 'POST', ['permalink' => $encoded]));

        $this->assertSame([$address], $this->site->removed);
    }

    // ------------------------------------------------------------------ the other layouts

    public function testAnAnnotationAddressOfAnotherLayoutIsSentPermanentlyToTheAddressTheAnnotationHasNow(): void
    {
        $response = $this->pages->otherLayout($this->context(['a-note', 'abc']));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note/annotations/abc', $response->headers->get('Location'));
        $this->assertSame([], $this->drawer->templates);
    }

    public function testTheListOfAnotherLayoutIsSentToTheList(): void
    {
        $response = $this->pages->otherLayout($this->context(['a-note']));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.com/2026/a-note/annotations/', $response->headers->get('Location'));
    }

    public function testAnAddressOfAnotherLayoutForAPostThatIsMissingOrNotReadableIsTheSameGone(): void
    {
        $this->site->entity = null;
        $missing = $this->pages->otherLayout($this->context(['a-note', 'abc']));
        $this->site->entity = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
        $this->site->readable = false;
        $hidden = $this->pages->otherLayout($this->context(['a-note', 'abc']));

        $this->assertSame(410, $missing->getStatusCode());
        $this->assertSame(410, $hidden->getStatusCode());
        $this->assertNull($hidden->headers->get('Location'));
    }

    private function encoded(string $address): string
    {
        return strtr(base64_encode($address), '+/=', '-_,');
    }
}

final class AnnotationsDouble implements Annotations
{
    /** @var list<string> */
    public array $calls = [];

    public ?Entity $entity = null;

    public bool $readable = true;

    public bool $editable = true;

    public ?User $owner = null;

    public string $title = 'Title';

    public string $type = 'default';

    /** @var array<string, array<string, mixed>> */
    public array $annotations = [];

    /** @var array<string, string> id => the key the post stores it under */
    public array $keys = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var list<list<string>> */
    public array $forgotten = [];

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

    public function owner(Entity $entity): ?User
    {
        return $this->owner;
    }

    public function url(Entity $entity): string
    {
        $this->calls[] = 'url';

        return 'https://example.com/2026/a-note';
    }

    public function title(Entity $entity): string
    {
        return $this->title;
    }

    public function listTitle(Entity $entity): string
    {
        return '[t]Annotations on: ' . $this->title;
    }

    public function shortDescription(Entity $entity): string
    {
        return 'Description';
    }

    public function keyOf(Entity $entity, string $id): ?string
    {
        return $this->keys[$id] ?? null;
    }

    public function annotation(Entity $entity, string $permalink): mixed
    {
        foreach ($this->annotations as $list) {
            if (isset($list[$permalink])) {
                return $list[$permalink];
            }
        }

        return false;
    }

    public function subtype(Entity $entity, string $permalink): ?string
    {
        foreach ($this->annotations as $subtype => $list) {
            if (isset($list[$permalink])) {
                return $subtype;
            }
        }

        return null;
    }

    public function all(Entity $entity): array
    {
        return $this->annotations;
    }

    public function canEditAnnotation(Entity $entity, string $permalink): bool
    {
        $this->calls[] = 'canEditAnnotation:' . $permalink;

        return $this->editable;
    }

    public function remove(Entity $entity, string $permalink): bool
    {
        $this->removed[] = $permalink;

        return true;
    }

    public function templateType(): string
    {
        return $this->type;
    }

    public function forgetTemplateVariables(array $names): void
    {
        $this->forgotten[] = $names;
    }
}

final class AnnotationDrawerDouble implements PageDrawer
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

        return new DrawnPage('page', [['Content-type', 'text/html']]);
    }
}
