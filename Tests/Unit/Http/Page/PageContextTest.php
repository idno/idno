<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PageContextTest extends TestCase
{
    private function context(Request $request, ?SitePage $page = null): PageContext
    {
        return new PageContext($request, $page ?? new SitePage('Idno\Pages\Homepage'));
    }

    public function testPostWinsOverQuery(): void
    {
        $context = $this->context(Request::create('/x?a=query&b=query', 'POST', ['a' => 'post']));

        $this->assertSame('post', $context->input('a'));
        $this->assertSame('query', $context->input('b'));
    }

    public function testCookiesAreNeverInput(): void
    {
        $context = $this->context(Request::create('/x', 'GET', [], ['secret' => 'cookie']));

        $this->assertNull($context->input('secret'));
        $this->assertSame('d', $context->input('secret', 'd'));
    }

    public function testSetInputIsUsedWhenTheRequestHasNone(): void
    {
        $context = $this->context(Request::create('/x?a=query'));
        $context->setInput('a', 'set');
        $context->setInput('b', 'set');

        $this->assertSame('query', $context->input('a'));
        $this->assertSame('set', $context->input('b'));
    }

    public function testJsonFieldAndJsonBodyAreRead(): void
    {
        $field = $this->context(Request::create('/x', 'POST', ['json' => '{"a":"field","list":["x"]}']));
        $this->assertSame('field', $field->input('a'));
        $this->assertSame(['x'], $field->input('list'));

        $body = $this->context(Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":"body","b":2}'));
        $this->assertSame('body', $body->input('a'));
        $this->assertSame(2, $body->input('b'));

        $put = $this->context(Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":"put"}'));
        $this->assertSame('put', $put->input('a'));
    }

    public function testAGetBodyIsNotRead(): void
    {
        $context = $this->context(Request::create('/x', 'GET', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":"body"}'));

        $this->assertNull($context->input('a'));
    }

    public function testRequestValuesBeatJsonAndSetInputBeatsJson(): void
    {
        $context = $this->context(Request::create('/x', 'POST', ['a' => 'post', 'json' => '{"a":"json","b":"json","c":"json"}']));
        $context->setInput('b', 'set');

        $this->assertSame('post', $context->input('a'));
        $this->assertSame('set', $context->input('b'));
        $this->assertSame('json', $context->input('c'));
    }

    public function testDefaultWhenNothing(): void
    {
        $context = $this->context(Request::create('/x'));

        $this->assertNull($context->input('nope'));
        $this->assertSame('d', $context->input('nope', 'd'));
        $this->assertNull($context->input(''));
    }

    public function testXhrHeader(): void
    {
        $this->assertFalse($this->context(Request::create('/x'))->isXhr());
        $this->assertTrue($this->context(Request::create('/x', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']))->isXhr());
    }

    public function testPermalinkSetsTheEntity(): void
    {
        $context = $this->context(Request::create('/x'));
        $this->assertFalse($context->isPermalink());
        $this->assertNull($context->entity());

        $entity = $this->createStub(\Idno\Common\Entity::class);
        $context->setPermalink(true, $entity);
        $this->assertTrue($context->isPermalink());
        $this->assertSame($entity, $context->entity());

        $context->setPermalink(false);
        $this->assertFalse($context->isPermalink());
        $this->assertSame($entity, $context->entity());

        $context->setEntity(null);
        $this->assertNull($context->entity());
    }

    public function testOwnerAndAssetsAndStatus(): void
    {
        $context = $this->context(Request::create('/x'));
        $this->assertNull($context->owner());
        $this->assertSame(200, $context->status());
        $this->assertSame([], $context->assets('css'));

        $context->setAsset('a', '/a.css', 'css');
        $context->setAsset('b', '/b.css', 'css');
        $context->setStatus(404);

        $this->assertSame(['a' => '/a.css', 'b' => '/b.css'], $context->assets('css'));
        $this->assertSame([], $context->assets('javascript'));
        $this->assertSame(404, $context->status());
    }

    public function testHeadersAccumulateAndReplace(): void
    {
        $context = $this->context(Request::create('/x'));
        $context->addHeader('Link', '<a>');
        $context->addHeader('Link', '<b>', false);
        $context->addHeader('X-One', '1');
        $context->addHeader('x-one', '2');

        $this->assertSame(['Link' => ['<a>', '<b>'], 'X-One' => ['2']], $context->headers());

        $context->addHeader('Link', '<c>');
        $this->assertSame(['<c>'], $context->headers()['Link']);
    }

    public function testAHeaderFirstAddedWithoutReplacingDoesNotReplaceAnotherPartsHeader(): void
    {
        $context = $this->context(Request::create('/x'));
        $context->addHeader('Link', '<a>', false);
        $context->addHeader('Link', '<b>', false);
        $context->addHeader('X-One', '1');
        $context->addHeader('x-two', '2', false);
        $context->addHeader('X-Two', '3');

        $this->assertFalse($context->replacesHeader('link'));
        $this->assertTrue($context->replacesHeader('X-One'));
        $this->assertTrue($context->replacesHeader('x-two'), 'a replacing add starts the list again');
        $this->assertTrue($context->replacesHeader('X-Unknown'));
    }

    public function testDefaultHeadersAreKeptByName(): void
    {
        $context = $this->context(Request::create('/x'));
        $context->addDefaultHeader('Content-Type', 'application/json');
        $context->addDefaultHeader('Content-Type', 'text/plain');

        $this->assertSame(['Content-Type' => 'text/plain'], $context->defaultHeaders());
    }

    public function testSetCachingOwnsCaching(): void
    {
        $context = $this->context(Request::create('/x'));
        $this->assertFalse($context->ownsCaching());
        $this->assertSame([], $context->caching());

        $context->setCaching(['Cache-Control' => 'no-store']);
        $context->setCaching(['Pragma' => 'no-cache']);

        $this->assertTrue($context->ownsCaching());
        $this->assertSame(['Pragma' => 'no-cache'], $context->caching());
    }

    public function testArgumentsAreTheRouteParametersWithoutLeadAndSlash(): void
    {
        $request = Request::create('/x');
        $request->attributes->set('_route_params', ['_controller' => 'x', 'lead' => '/', 'id' => 'abc', 'slash' => '/', 'slug' => 'a-b', '_route' => 'r']);

        $this->assertSame(['abc', 'a-b'], $this->context($request)->arguments());
    }

    public function testNoRouteParametersMeansNoArguments(): void
    {
        $this->assertSame([], $this->context(Request::create('/x'))->arguments());
    }

    public function testSetArgumentsReplacesThem(): void
    {
        $request = Request::create('/x');
        $request->attributes->set('_route_params', ['id' => 'abc']);
        $context = $this->context($request);
        $context->setArguments(['one', 'two']);

        $this->assertSame(['one', 'two'], $context->arguments());
    }

    public function testOfThrowsWithoutAContext(): void
    {
        $request = Request::create('/x');
        $this->assertFalse(PageContext::has($request));
        $this->expectException(\LogicException::class);
        PageContext::of($request);
    }

    public function testAttachToMakesItFound(): void
    {
        $request = Request::create('/x');
        $context = $this->context($request);
        $context->attachTo($request);

        $this->assertTrue(PageContext::has($request));
        $this->assertSame($context, PageContext::of($request));
        $this->assertSame($request, $context->request());
        $this->assertSame('Idno\Pages\Homepage', $context->page()->name);
    }

    public function testRequestUriIsRaw(): void
    {
        $this->assertSame('/tag/a%2Bb', $this->context(Request::create('/tag/a%2Bb'))->requestUri());
        $this->assertSame('/search/?q=a+b&x=1', $this->context(Request::create('/search/?q=a+b&x=1'))->requestUri());
    }

    public function testRequestFieldAndDataFieldAreSeparate(): void
    {
        $context = $this->context(Request::create('/x?q=query', 'POST', ['p' => 'post', 'json' => '{"j":"json","q":"json"}']));
        $context->setInput('s', 'set');

        $this->assertSame('query', $context->requestField('q'));
        $this->assertSame('post', $context->requestField('p'));
        $this->assertNull($context->requestField('s'));
        $this->assertSame('set', $context->dataField('s'));
        $this->assertSame('json', $context->dataField('j'));
        $this->assertSame('json', $context->dataField('q'), 'the data is not the request\'s');
        $this->assertNull($context->dataField('nope'));
    }

    public function testAMethodOverrideDoesNotHideTheBodyOfARealPost(): void
    {
        $request = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET'], '{"a":"body"}');

        $this->assertSame('body', $this->context($request)->input('a'), 'the real method is what decides');
    }

    public function testMarkdownSuffixRedirectAndAfterResponseWork(): void
    {
        $context = $this->context(Request::create('/x'));
        $this->assertFalse($context->markdownSuffixRequested());
        $this->assertNull($context->redirect());
        $this->assertSame([], $context->afterResponseWork());

        $context->setMarkdownSuffix();
        $redirect = new \Idno\Http\Page\PageRedirect('/somewhere');
        $context->setRedirect($redirect);
        $work = static fn () => 1;
        $context->afterResponse($work);

        $this->assertTrue($context->markdownSuffixRequested());
        $this->assertSame($redirect, $context->redirect());
        $this->assertSame([$work], $context->afterResponseWork());
        $this->assertSame(302, $redirect->status);
        $this->assertNull($redirect->body);
    }
}
