<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\IdnoTwigExtension;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

final class IdnoTwigExtensionTest extends TestCase
{
    private function extension(?TemplateHelpersDouble $helpers = null, ?RequestStack $requests = null, bool $brackets = true): IdnoTwigExtension
    {
        return new IdnoTwigExtension(new class ($brackets) implements TemplateTranslation {
            public function __construct(private readonly bool $brackets)
            {
            }

            public function translate(string $text, array $subs = []): string
            {
                return $this->brackets ? '[' . vsprintf($text, $subs) . ']' : vsprintf($text, $subs);
            }
        }, $helpers ?? new TemplateHelpersDouble(), $requests ?? new RequestStack());
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars = [], ?TemplateHelpersDouble $helpers = null, ?RequestStack $requests = null, bool $brackets = false): string
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => $template]), ['cache' => false, 'autoescape' => 'name']);
        $twig->addExtension($this->extension($helpers, $requests, $brackets));

        return $twig->render('a.html.twig', $vars);
    }

    /** What the extension marks as safe for HTML: functions, and filters */
    private const SAFE_FUNCTIONS = ['idno_include', 'slot', 't_html', 'form_token', 'action_link', 'form_data', 'pagination'];

    private const SAFE_FILTERS = ['sanitize_html', 'autop', 'parse_urls', 'parse_hashtags', 'parse_users'];

    public function testOnlyTheListedFunctionsAreSafe(): void
    {
        $extension = $this->extension();
        $functions = [];
        foreach ($extension->getFunctions() as $function) {
            $functions[$function->getName()] = $function->getSafe(new \Twig\Node\Nodes([]));
        }
        $filters = [];
        foreach ($extension->getFilters() as $filter) {
            $filters[$filter->getName()] = $filter->getSafe(new \Twig\Node\Nodes([]));
        }

        $this->assertSame(self::SAFE_FUNCTIONS, array_keys(array_filter($functions, static fn ($safe): bool => $safe === ['html'])), 'exactly these functions print markup');
        $this->assertSame([], array_filter($functions, static fn ($safe): bool => $safe !== ['html'] && $safe !== [] && $safe !== null), 'and none is safe for anything else');
        $this->assertSame(self::SAFE_FILTERS, array_keys(array_filter($filters, static fn ($safe): bool => $safe === ['html'])), 'exactly these filters print markup');
        $this->assertSame([], array_filter($filters, static fn ($safe): bool => $safe !== ['html'] && $safe !== [] && $safe !== null));
        foreach (['autop', 'parse_urls', 'parse_hashtags', 'parse_users'] as $name) {
            $filter = null;
            foreach ($extension->getFilters() as $candidate) {
                $filter = $candidate->getName() === $name ? $candidate : $filter;
            }
            $this->assertSame('html', $filter->getPreEscape(), $name . ' escapes raw text before it works on it');
        }
        foreach ($extension->getFilters() as $filter) {
            if ($filter->getName() === 'sanitize_html') {
                $this->assertNull($filter->getPreEscape(), 'sanitize_html purifies its input, so it takes it as it is');
            }
        }
        $this->assertSame([], $extension->getTests());
    }

    public function testEveryNamedFunctionAndFilterExists(): void
    {
        $extension = $this->extension();
        $functions = array_map(static fn ($f): string => $f->getName(), $extension->getFunctions());
        $filters = array_map(static fn ($f): string => $f->getName(), $extension->getFilters());
        foreach (['idno_include', 'slot', 't', 't_html', 'site_url', 'static_url', 'site_title', 'site_icons', 'setting', 'can_write', 'content_types', 'content_type_for_post_type', 'current_user', 'is_logged_in', 'is_admin', 'current_url', 'current_path', 'referrer', 'input', 'path_matches', 'is_permalink', 'is_xhr', 'page_assets', 'page_owner', 'http_status', 'login_url_for', 'url_with_var', 'url_without_var', 'current_url_without_var', 'form_token', 'action_link', 'form_data', 'shell_variables', 'pagination', 'syndication_services', 'syndication_selected', 'share_types', 'expand_urls', 'local_entity', 'local_file', 'access_groups_of', 'latest_post_of', 'entity_value', 'body_classes'] as $name) {
            $this->assertContains($name, $functions);
        }
        foreach (['sanitize_html', 'autop', 'parse_urls', 'parse_hashtags', 'parse_users', 'http_url', 'display_url', 'fix_url', 'proxied_image', 'sample_text'] as $name) {
            $this->assertContains($name, $filters);
        }
    }

    public function testNoFunctionReachesAServiceByName(): void
    {
        $extension = $this->extension();
        foreach ([...$extension->getFunctions(), ...$extension->getFilters()] as $callable) {
            $reflection = new \ReflectionFunction($callable->getCallable());
            foreach ($reflection->getParameters() as $parameter) {
                $this->assertDoesNotMatchRegularExpression('/^(class|class_?name|fqcn|service_?name|service_?id|container|id)$/i', $parameter->getName(), $callable->getName() . ' takes ' . $parameter->getName());
            }
        }
        $helpers = new TemplateHelpersDouble();
        $out = $this->render("{{ entity_value('Idno\\Core\\Idno', 'x') is null ? 'none' : 'some' }}{{ entity_value({x: 1}, 'x') }}", [], $helpers);
        $this->assertSame('none1', $out, 'a class name is only a string');
        $this->assertSame([], $helpers->calls, 'and reaches nothing');
    }

    public function testTHtmlEscapesStringSubsAndKeepsMarkupSubs(): void
    {
        $this->assertSame('Hi <strong>&lt;x&gt;</strong>', $this->render("{{ t_html('Hi <strong>%s</strong>', ['<x>']) }}"));
        $this->assertSame('Hi <strong>&lt;&quot;&#039;&amp;</strong>', $this->render("{{ t_html('Hi <strong>%s</strong>', ['<\"\'&']) }}"));
        $this->assertSame('See <a href="/x">it</a> now', $this->render("{% set link %}<a href=\"/x\">it</a>{% endset %}{{ t_html('See %s now', [link]) }}"), 'a capture is markup');
        $this->assertSame('3 items', $this->render("{{ t_html('%d items', [3]) }}"));
    }

    public function testTHtmlEscapesWhatGoesIntoNumberedAndTypedPlaceholders(): void
    {
        $this->assertSame('<b>&lt;i&gt;</b> and &quot;x&quot; 5 %', $this->render("{{ t_html('<b>%1\$s</b> and %2\$s %3\$d %%', ['<i>', '\"x\"', 5]) }}"));
        $this->assertSame('&lt;a&gt; 7 &lt;a&gt;', $this->render("{{ t_html('%1\$s %2\$d %1\$s', ['<a>', 7]) }}"));
        $this->assertSame('Total: 12', $this->render("{{ t_html('Total: %d', [12]) }}"));
        $this->assertSame('&lt;/p&gt;&lt;script&gt; items', $this->render("{{ t_html('%s items', ['</p><script>']) }}"));
    }

    public function testTIsStillEscapedWhereTHtmlIsNot(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $this->render("{{ t('<b>hi</b>') }}"));
        $this->assertSame('<b>hi</b>', $this->render("{{ t_html('<b>hi</b>') }}"));
    }

    public function testParseFiltersEscapeRawTextFirst(): void
    {
        $helpers = new TemplateHelpersDouble();
        $this->assertSame('&lt;script&gt;', $this->render("{{ raw|parse_urls }}", ['raw' => '<script>'], $helpers));
        $this->assertSame('&lt;script&gt;', $this->render("{{ raw|parse_hashtags }}", ['raw' => '<script>'], $helpers));
        $this->assertSame('&lt;script&gt;', $this->render("{{ raw|parse_users }}", ['raw' => '<script>'], $helpers));
        $this->assertSame('<p>&lt;script&gt;</p>', $this->render("{{ raw|autop }}", ['raw' => '<script>'], $helpers));
        $this->assertSame('[sanitized <b>x</b>]', $this->render("{{ raw|sanitize_html|parse_urls }}", ['raw' => '<b>x</b>'], $helpers), 'sanitized HTML is kept');
        $this->assertSame('<p><b>x</b></p>', $this->render("{{ markup|autop }}", ['markup' => new Markup('<b>x</b>', 'UTF-8')], $helpers), 'markup is kept');
        $helpers->calls = [];
        $this->render("{{ 'x'|parse_users('https://twitter.com/a') }}", [], $helpers);
        $this->assertSame([['parseUsers', ['x', 'https://twitter.com/a']]], $helpers->calls, 'the reply target is passed on');
    }

    public function testHttpUrlRefusesOtherSchemes(): void
    {
        $this->assertSame('', $this->render("{{ 'javascript:alert(1)'|http_url }}"));
        $this->assertSame('', $this->render("{{ 'data:text/html,x'|http_url }}"));
        $this->assertSame('', $this->render("{{ 'https://a.example/\"x'|http_url }}"));
        $this->assertSame('', $this->render("{{ null|http_url }}"));
        $this->assertSame('https://a.example/x?y=1&amp;z=2', $this->render("{{ 'https://a.example/x?y=1&z=2'|http_url }}"), 'the result is still escaped by Twig');
        $this->assertSame('//a.example/x', $this->render("{{ '//a.example/x'|http_url }}"));
    }

    public function testSettingRefusesSecrets(): void
    {
        $helpers = new TemplateHelpersDouble();
        $this->assertSame('', $this->render("{{ setting('smtp_password') }}", [], $helpers));
        $this->assertSame('', $this->render("{{ setting('dbpass') }}", [], $helpers));
        $this->assertSame('My site', $this->render("{{ setting('title') }}", [], $helpers));
        $this->assertSame('none', $this->render("{{ setting('smtp_password') is null ? 'none' : 'some' }}", [], $helpers));
        $this->assertSame([['setting', ['title']]], array_values(array_filter($helpers->calls, static fn (array $call): bool => $call[0] === 'setting' && $call[1] === ['title'])), 'a permitted name is passed on');
        $helpers->settings['api_key'] = 'k';
        $helpers->settings['home'] = 'sk_live_abc';
        $this->assertSame('none', $this->render("{{ setting('api_key') is null and setting('home') is null ? 'none' : 'some' }}", [], $helpers), 'by name, and by a value that is a key');
        $helpers->settings['home'] = 'mysql://user:pw@host/db';
        $this->assertSame('mysql://host/db', $this->render("{{ setting('home') }}", [], $helpers), 'an address loses its user name and password');
    }

    public function testRequestFactsComeFromTheRequestStack(): void
    {
        $this->assertSame('||', $this->render("{{ current_url() }}|{{ current_path() }}|{{ referrer() }}"), 'no request');
        $stack = new RequestStack();
        $stack->push(Request::create('https://site.example/a/b?x=1&y=2', 'GET', [], [], [], ['HTTP_REFERER' => 'https://other.example/p']));

        $this->assertSame('https://site.example/a/b?x=1&amp;y=2|/a/b|https://other.example/p', $this->render("{{ current_url() }}|{{ current_path() }}|{{ referrer() }}", [], null, $stack));
    }

    /**
     * The address of the page is the site's configured address plus the request's URI, as `Page::currentUrl()` builds it: never the
     * Host header or the transport scheme (behind a TLS-terminating proxy Symfony says http; a request may name any host).
     */
    public function testCurrentUrlIsTheConfiguredAddressPlusTheRequestUriWhateverTheHostHeaderAndSchemeSay(): void
    {
        foreach ([[], ['HTTP_X_FORWARDED_PROTO' => 'https'], ['HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PORT' => '8080']] as $server) {
            $stack = new RequestStack();
            $stack->push(Request::create('http://evil.example:81/sub/a%20b/?x=1&y[]=2#frag', 'GET', [], [], [], $server + ['HTTP_REFERER' => 'https://other.example/p']));

            $out = $this->render("{{ current_url()|raw }}|{{ current_path() }}|{{ referrer() }}", [], null, $stack);

            $this->assertSame('https://site.example/sub/a%20b/?x=1&y[]=2|/sub/a%20b/|https://other.example/p', $out, json_encode($server));
            $this->assertStringNotContainsString('evil.example', $out);
        }
    }

    public function testNoRequestHelperTouchesTheHostSoAMalformedHostIsNotAnError(): void
    {
        $request = Request::create('/sub/p?x=1', 'GET', [], [], [], ['HTTP_REFERER' => 'https://other.example/p']);
        $request->headers->set('Host', 'a b');
        $request->server->set('HTTP_HOST', 'a b');
        try {
            $request->getHost();
            $this->fail('the request is one Symfony rejects');
        } catch (\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException) {
        }
        $stack = new RequestStack();
        $stack->push($request);

        $out = $this->render("{{ current_url()|raw }}|{{ current_path() }}|{{ referrer() }}|{{ url_with_var('a', 1) }}|{{ current_url_without_var('a') }}|{{ login_url_for('/x') }}", [], null, $stack);

        $this->assertStringStartsWith('https://site.example/sub/p?x=1|/sub/p|https://other.example/p|', $out);
    }

    public function testSiteAndViewerFunctionsAnswerWhatTheHelpersDo(): void
    {
        $helpers = new TemplateHelpersDouble();
        $out = $this->render("{{ site_url('x') }} {{ site_url() }} {{ static_url('s.css') }} {{ site_title() }} {{ site_icons().defaults.default_57 }} {{ can_write() ? 'w' : '-' }} {{ content_types()|length }} {{ content_type_for_post_type('note').name }} {{ content_type_for_post_type('x') is null ? 'null' : 'some' }} {{ current_user().handle }} {{ is_logged_in() ? 'in' : 'out' }} {{ is_admin() ? 'admin' : 'member' }}", [], $helpers);

        $this->assertSame('https://site.example/x https://site.example/ https://static.example/s.css A &lt;b&gt;site&lt;/b&gt; a.png w 1 note null ben in member', $out);
    }

    public function testPageFunctionsAnswerWhatTheHelpersDo(): void
    {
        $out = $this->render("{{ input('offset') }} {{ input('nope', 'dflt') }} {{ path_matches('a/b') ? 'y' : 'n' }} {{ is_permalink() ? 'y' : 'n' }} {{ is_xhr() ? 'y' : 'n' }} {{ page_assets('css').a }} {{ page_assets('js')|length }} {{ page_owner() is null ? 'none' : 'owner' }} {{ http_status() }} {{ login_url_for('/a b') }} {{ url_with_var('offset', 20) }} {{ url_with_var('offset', 20, 'https://x.example/p') }} {{ url_without_var('https://x.example/p?a=1', 'a') }} {{ current_url_without_var('a') }} {{ body_classes() }}");

        $this->assertSame('20 dflt y n n /a.css 0 none 200 https://site.example/session/login/?fwd=%2Fa%20b https://site.example/here?offset=20 https://x.example/p?offset=20 https://x.example/p?a=1#without-a https://site.example/here#without-a page-a &quot;quoted&quot; &lt;i&gt;', $out, 'body_classes() is escaped');
    }

    public function testFormFunctionsKeepMarkupAndEscapePlainStrings(): void
    {
        $helpers = new TemplateHelpersDouble();
        $this->assertSame('<input name="__bTk" value="token-for-/a?b=1&amp;c=2">', $this->render("{{ form_token('/a?b=1&c=2') }}", [], $helpers));

        $this->assertSame('<a href="/x"><b>Go</b></a>', $this->render("{% set label %}<b>Go</b>{% endset %}{{ action_link('/x', label) }}", [], $helpers), 'a capture is markup');
        $this->assertSame('<a href="/x"><b>Go</b> x</a>', $this->render("{{ action_link('/x', t_html('<b>Go</b> %s', ['x'])) }}", [], $helpers), 'so is what a safe function returned');
        $this->assertSame('<a href="/x">&lt;b&gt;Go&lt;/b&gt;</a>', $this->render("{{ action_link('/x', label) }}", ['label' => '<b>Go</b>'], $helpers), 'a plain string is escaped');
        $this->assertSame('<a href="/x">&lt;b&gt;Go&lt;/b&gt;</a>', $this->render("{{ action_link('/x', label|raw) }}", ['label' => '<b>Go</b>'], $helpers), '|raw does not make a string markup');
        $helpers->calls = [];
        $this->render("{{ action_link('/x', 'Go', {a: 1}, {class: 'c'}) }}", [], $helpers);
        $this->assertSame([['actionLink', ['/x', 'Go', ['a' => 1], ['class' => 'c']]]], $helpers->calls);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function actionLinkAddresses(): array
    {
        return [
            'a path' => ['/x/y?a=1#f', true],
            'a relative path' => ['x/y', true],
            'a query' => ['?offset=20', true],
            'a path with a colon after the first slash' => ['/a:b', true],
            'https' => ['https://site.example/x', true],
            'http' => ['http://site.example/x', true],
            'scheme-relative' => ['//site.example/x', true],
            'javascript' => ['javascript:alert(1)', false],
            'javascript in capitals' => ['JavaScript:alert(1)', false],
            'javascript after a space' => [' javascript:alert(1)', false],
            'javascript after a tab' => ["\tjavascript:alert(1)", false],
            'data' => ['data:text/html,x', false],
            'vbscript' => ['vbscript:x', false],
            'mailto' => ['mailto:a@b.example', false],
            'a backslash host' => ['/\\evil.example', false],
            'a backslash' => ['\\\\evil.example', false],
            'a quote' => ['/x"onmouseover="y', false],
            'a tag' => ['/x<script>', false],
            'an empty address' => ['', true],
            'a scheme-relative address with a quote' => ['//a.example/"x', false],
        ];
    }

    /**
     * @dataProvider actionLinkAddresses
     */
    public function testActionLinkRefusesAnAddressThatIsNotRelativeOrHttp(string $url, bool $drawn): void
    {
        $helpers = new TemplateHelpersDouble();

        $out = $this->render('{{ action_link(url, "Go") }}', ['url' => $url], $helpers);

        $this->assertSame($drawn ? 1 : 0, count($helpers->calls), $url);
        if (!$drawn) {
            $this->assertSame('', $out);
        }
    }

    public function testFormDataIsMarkupBackOnlyInAnXhrRequest(): void
    {
        $helpers = new TemplateHelpersDouble();
        $this->assertSame('', $this->render("{% set html %}<form></form>{% endset %}{{ form_data(html) }}", [], $helpers));
        $helpers->xhr = true;
        $this->assertSame('<form></form>', $this->render("{% set html %}<form></form>{% endset %}{{ form_data(html) }}", [], $helpers));
        $this->assertSame('&lt;form&gt;&lt;/form&gt;', $this->render("{{ form_data(html) }}", ['html' => '<form></form>'], $helpers), 'a plain string is escaped');
        $this->assertSame('&lt;form&gt;&lt;/form&gt;', $this->render("{{ form_data(html|raw) }}", ['html' => '<form></form>'], $helpers), '|raw does not make a string markup');
    }

    public function testDrawingFunctionsPassTheirArgumentsOn(): void
    {
        $helpers = new TemplateHelpersDouble();
        $out = $this->render("{{ pagination(40) }} {{ pagination(40, 10, {'control-id': 'c'}) }} {{ syndication_services('note')|join(',') }} {{ syndication_selected('twitter', 'me') }} {{ share_types({a: 'A'})|length }} {{ expand_urls(null, 'text') }}", [], $helpers);

        $this->assertSame('<nav>40</nav> <nav>40</nav> twitter twitter:me 2 text expanded', $out);
        $this->assertSame([[40, null, []], [40, 10, ['control-id' => 'c']]], array_map(static fn (array $call): array => $call[1], array_values(array_filter($helpers->calls, static fn (array $call): bool => $call[0] === 'pagination'))));
    }

    public function testLookupFunctionsAnswerNullWhenThereIsNothing(): void
    {
        $this->assertSame('null null 0 null', $this->render("{{ local_entity('https://x.example/p') is null ? 'null' : 'some' }} {{ local_file('https://x.example/f') is null ? 'null' : 'some' }} {{ access_groups_of('u')|length }} {{ latest_post_of(user) is null ? 'null' : 'some' }}", ['user' => new \stdClass()]));
    }

    public function testShellVariablesAreComputedFromTheContextWithoutBookkeeping(): void
    {
        $helpers = new TemplateHelpersDouble();
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{% set sv = shell_variables() %}{{ sv.title }}|{{ sv.lang }}|{{ sv._idno_type is defined ? 'leak' : 'clean' }}"]), ['cache' => false, 'autoescape' => 'name']);
        $twig->addExtension($this->extension($helpers));

        $this->assertSame('T|en|clean', $twig->render('a.html.twig', ['title' => 'T', '_idno_type' => 'default', '_idno_context' => new \stdClass()]));
        $this->assertSame(['title' => 'T'], $helpers->calls[0][1][0]);
    }

    public function testEntityValueReadsAStoredAttributeAndNeverCallsAMethod(): void
    {
        $entity = new class () {
            private array $attributes = ['title' => 'Stored', 'zero' => 0];

            public function &__get(string $name): mixed
            {
                $this->attributes[$name] ??= null;

                return $this->attributes[$name];
            }

            public function __isset(string $name): bool
            {
                return !empty($this->attributes[$name]);
            }

            public function getTitle(): string
            {
                return 'METHOD';
            }
        };
        $this->assertSame('Stored|0||A|', $this->render("{{ entity_value(o, 'title') }}|{{ entity_value(o, 'zero') }}|{{ entity_value(o, 'missing') }}|{{ entity_value(a, 'k') }}|{{ entity_value(n, 'k') }}", ['o' => $entity, 'a' => ['k' => 'A'], 'n' => null]));
    }

    public function testTheTextFiltersThatArePlain(): void
    {
        $helpers = new TemplateHelpersDouble();
        $out = $this->render("{{ 'https://a.example/p'|display_url }} {{ 'a.example'|fix_url }} {{ 'https://i.example/a.jpg'|proxied_image(100, 'square') }} {{ 'a b c d'|sample_text(2) }} {{ '<b>x</b>'|sample_text }}", [], $helpers);

        $this->assertSame('//a.example/p http://a.example https://i.example/a.jpg a b &lt;b&gt;x&lt;/b&gt;', $out, 'none of them is markup');
    }

    public function testTranslationIsEscapedByTwig(): void
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ t('<b>%s</b>', ['<i>']) }}", 'a.txt.twig' => "{{ t('<b>%s</b>', ['<i>']) }}"]), ['cache' => false, 'autoescape' => 'name']);
        $twig->addExtension($this->extension());

        $this->assertSame('[&lt;b&gt;&lt;i&gt;&lt;/b&gt;]', $twig->render('a.html.twig'));
        $this->assertSame('[<b><i></b>]', $twig->render('a.txt.twig'));
    }

    public function testIncludeAndSlotNeedTheTemplateObject(): void
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ idno_include('x') }}", 'b.html.twig' => "{{ slot('x') }}"]), ['cache' => false]);
        $twig->addExtension($this->extension());

        foreach (['a.html.twig', 'b.html.twig'] as $name) {
            try {
                $twig->render($name, ['_idno_context' => 'not a template object']);
                $this->fail('refused');
            } catch (RuntimeError $e) {
                $this->assertStringContainsString('only be used in a template drawn through the template system', $e->getRawMessage());
            }
        }
    }

    public function testIncludeDrawsThroughACopyOfTheTemplateObjectWithTheVariablesMerged(): void
    {
        $drawn = [];
        $context = new class ($drawn) implements PhpTemplateContext {
            public array $vars = [];

            /** @param array<int, array{string, array<string, mixed>}> $drawn */
            public function __construct(private array &$drawn)
            {
            }

            public function withVars(array $vars): static
            {
                $copy = clone $this;
                $copy->vars = $vars;

                return $copy;
            }

            public function phpTemplateScope(): string
            {
                return self::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                $this->drawn[] = [$templateName, $this->vars];

                return '<i>drawn</i>';
            }

            public function drawSlot($name)
            {
                return '<i>slot ' . $name . '</i>';
            }
        };
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{% set local = 'L' %}{% for i in [1] %}{{ idno_include('inc/x', {b: 'B', a: 'over'}) }}{% endfor %}{{ slot('s') }}"]), ['cache' => false]);
        $twig->addExtension($this->extension());

        $out = $twig->render('a.html.twig', ['a' => 'A', 'c' => 'C', '_idno_context' => $context, '_idno_type' => 'default']);

        $this->assertSame('<i>drawn</i><i>slot s</i>', $out, 'neither is escaped again');
        $this->assertSame([['inc/x', ['a' => 'over', 'c' => 'C', 'local' => 'L', 'i' => 1, 'b' => 'B']]], $drawn, 'the including template\'s variables and its own, the given ones over them, and none of Twig\'s bookkeeping (_parent, loop) or the engine\'s');
    }

    public function testGlobalsAreNotPassedOnButUnderscoreVariablesAre(): void
    {
        $seen = [];
        $context = new class ($seen) implements PhpTemplateContext {
            public array $vars = [];

            /** @param array<int, array<string, mixed>> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function withVars(array $vars): static
            {
                $copy = clone $this;
                $copy->vars = $vars;

                return $copy;
            }

            public function phpTemplateScope(): string
            {
                return self::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                $this->seen[] = $this->vars;

                return '';
            }

            public function drawSlot($name)
            {
                return '';
            }
        };
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ idno_include('x') }}{% for i in [1] %}{{ idno_include('x') }}{% endfor %}"]), ['cache' => false]);
        $twig->addExtension($this->extension());
        $twig->addGlobal('app', ['debug' => true]);
        $twig->addGlobal('other', 'G');

        $twig->render('a.html.twig', ['_foo' => 'kept', 'a' => 1, 'other' => 'mine', '_idno_context' => $context, '_idno_type' => 'default']);

        $this->assertSame([
            ['_foo' => 'kept', 'a' => 1, 'other' => 'mine'],
            ['_foo' => 'kept', 'a' => 1, 'other' => 'mine', 'i' => 1],
        ], $seen, 'no global (a variable that only has a global\'s name and another value is kept), no bookkeeping, a variable starting with an underscore stays');
    }
}
