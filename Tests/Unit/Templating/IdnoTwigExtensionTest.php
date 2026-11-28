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
    private const SAFE_FUNCTIONS = ['idno_include', 'slot', 't_html', 'form_token', 'action_link', 'form_data', 'js_string', 'pagination', 'mail_dark_css', 'mail_emphasise'];

    private const SAFE_FILTERS = ['sanitize_html', 'autop', 'parse_urls', 'parse_hashtags', 'parse_users'];

    public function testTheSystemFunctionsAnswerWhatTheAdminDependenciesPageChecks(): void
    {
        $this->assertSame('1', $this->render("{{ version_compare('1.0', '0.9') }}"));
        $this->assertSame('-1', $this->render("{{ version_compare('7.4', '8.0') }}"));
        $this->assertSame('0', $this->render("{{ version_compare('2.0', '2.0') }}"));
        $this->assertSame('1', $this->render("{{ extension_loaded('json') ? 1 : 0 }}"));
        $this->assertSame('0', $this->render("{{ extension_loaded('no_such_extension') ? 1 : 0 }}"));
        $this->assertSame(phpversion(), $this->render('{{ system_info().php }}'));
        $this->assertSame(\Idno\Core\Version::version(), $this->render('{{ system_info().idno }}'));
        $this->assertSame((string) \Idno\Core\Version::build(), $this->render('{{ system_info().build }}'));
        $this->assertSame(implode(',', \Idno\Install\ServerChecks::EXTENSIONS), $this->render("{{ system_info().extensions|join(',') }}"));
        $this->assertSame('1|0', $this->render("{{ can_make_site_private() ? 1 : 0 }}|{{ legacy_tables_in_menu() ? 1 : 0 }}"));
    }

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
        foreach (['idno_include', 'slot', 't', 't_html', 'site_url', 'static_url', 'site_title', 'site_icons', 'setting', 'can_write', 'can_add_users', 'can_make_site_private', 'legacy_tables_in_menu', 'system_info', 'extension_loaded', 'version_compare', 'content_types', 'content_type_for_post_type', 'current_user', 'is_logged_in', 'is_admin', 'current_url', 'current_path', 'referrer', 'input', 'path_matches', 'is_permalink', 'is_stream_page', 'is_xhr', 'page_assets', 'page_owner', 'page_entity', 'http_status', 'login_url_for', 'url_with_var', 'url_without_var', 'current_url_without_var', 'form_token', 'action_link', 'form_data', 'shell_variables', 'pagination', 'syndication_services', 'syndication_selected', 'share_types', 'expand_urls', 'local_entity', 'local_file', 'access_groups_of', 'latest_post_of', 'entity_value', 'body_classes', 'current_url_parts', 'is_secure', 'page_icon', 'leaked_user', 'twitter_account_of', 'is_user', 'is_entity', 'random_id', 'default_avatar', 'visitor_message', 'profile_link', 'embeds', 'syndication_has', 'syndication_accounts', 'js_string', 'next_input_id', 'security_token', 'timezone_options', 'timezone_info', 'email_is_blocked'] as $name) {
            $this->assertContains($name, $functions);
        }
        foreach (['sanitize_html', 'autop', 'parse_urls', 'parse_hashtags', 'parse_users', 'http_url', 'display_url', 'fix_url', 'proxied_image', 'sample_text', 'identity_url', 'to_int', 'to_timestamp', 'ucfirst', 'decode_entities', 'link_url', 'email_address', 'form_urlencode', 'tag_texts'] as $name) {
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

    public function testThePartsOfTheAddressAreTheSitesWithTheRequestsUri(): void
    {
        $this->assertSame('[]', $this->render("{{ current_url_parts()|json_encode }}"), 'no request');
        $stack = new RequestStack();
        $stack->push(new Request([], [], [], [], [], ['REQUEST_URI' => '/a/b?x=1&y=2', 'HTTP_HOST' => 'elsewhere.example']));

        $this->assertSame('{&quot;scheme&quot;:&quot;https&quot;,&quot;host&quot;:&quot;site.example&quot;,&quot;path&quot;:&quot;\/a\/b?x=1&amp;y=2&quot;}', $this->render("{{ current_url_parts()|json_encode }}", [], null, $stack), 'the request\'s URI, and not its host');
    }

    public function testSecurePageAndIconAnswerFromTheSite(): void
    {
        $helpers = new TemplateHelpersDouble();
        $icon = new \stdClass();
        $this->assertSame('no|https://static.example/page.png|none', $this->render("{{ is_secure() ? 'yes' : 'no' }}|{{ page_icon() }}|{{ leaked_user() is null ? 'none' : 'user' }}", ['_idno_context' => new \stdClass()], $helpers));
        $helpers->secure = true;
        $helpers->iconUser = $icon;
        $this->assertSame('yes|https://static.example/page.png|user', $this->render("{{ is_secure() ? 'yes' : 'no' }}|{{ page_icon() }}|{{ leaked_user() is null ? 'none' : 'user' }}", ['_idno_context' => new \stdClass()], $helpers));
    }

    public function testTheIdentityUrlKeepsHttpMailSmsAndTelAddressesOnly(): void
    {
        $identity = static fn (mixed $v): string => IdnoTwigExtension::identityUrl($v);

        $this->assertSame('http://a.example/x', $identity('http://a.example/x'));
        $this->assertSame('https://a.example/x', $identity('https://a.example/x'));
        $this->assertSame('mailto:a@b.example', $identity('mailto:a@b.example'));
        $this->assertSame('sms:+15555550100', $identity('sms:+15555550100'));
        $this->assertSame('tel:+15555550100', $identity('tel:+15555550100'));
        $this->assertSame('MAILTO:A@B.EXAMPLE', $identity('MAILTO:A@B.EXAMPLE'), 'any letter case');
        $this->assertSame('mailto:a@b', $identity(' mailto:a@b'), 'surrounding white space is removed');
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', 'ftp://a.example/', 'example.com', 'ada', '', 'mailto:a b@c', "mailto:a@b\nBcc: x@y", "sms:1\t2", "tel:\x001"] as $bad) {
            $this->assertSame('', $identity($bad), json_encode($bad));
        }
        $this->assertSame('//host/x/', $identity('//host/x/'), 'a scheme-relative address is an http(s) address as httpUrl() reads it (the page\'s own scheme applies)');
        $this->assertSame('', $identity(null));
        $this->assertSame('', $identity(['mailto:a@b']));
        $this->assertSame('mailto:a@b&quot;onx=', $this->render("{{ v|identity_url }}", ['v' => 'mailto:a@b"onx=']), 'a quote is kept and escaped when printed');
    }

    public function testToIntCastsAsPhpDoes(): void
    {
        $this->assertSame('0|12|7|0|0|1', $this->render("{{ 'abc'|to_int }}|{{ '12abc'|to_int }}|{{ 7.9|to_int }}|{{ null|to_int }}|{{ []|to_int }}|{{ true|to_int }}"));
    }

    public function testTheTimeAndTextFiltersAnswerAsPhpDoes(): void
    {
        $this->assertSame(strtotime('2026-04-10 18:00') . '|0|0|0|' . strtotime('2026-04-10 18:00 UTC'), $this->render("{{ '2026-04-10 18:00'|to_timestamp }}|{{ 'not a date'|to_timestamp }}|{{ null|to_timestamp }}|{{ []|to_timestamp }}|{{ '2026-04-10 18:00 UTC'|to_timestamp }}"));
        $this->assertSame('Yes|YES|éa||7', $this->render("{{ 'yes'|ucfirst }}|{{ 'yES'|ucfirst|upper }}|{{ 'éa'|ucfirst }}|{{ null|ucfirst }}|{{ 7|ucfirst }}"));
        $this->assertSame('Tom &amp; Jerry &lt;b&gt; &#039;quoted&#039; &quot;x&quot;|', $this->render("{{ v|decode_entities }}|{{ []|decode_entities }}", ['v' => 'Tom &amp; Jerry &lt;b&gt; &#039;quoted&#039; &quot;x&quot;']));
        $this->assertSame('x &amp;amp; y', $this->render("{{ v|decode_entities }}", ['v' => 'x &amp;amp; y']), 'one level is decoded: what is left is text, and is escaped when printed');
    }

    public function testIsUserIsAnInstanceCheck(): void
    {
        $this->assertTrue(IdnoTwigExtension::isUser((new \ReflectionClass(\Idno\Entities\User::class))->newInstanceWithoutConstructor()));
        $this->assertFalse(IdnoTwigExtension::isUser('user'));
        $this->assertFalse(IdnoTwigExtension::isUser(new \stdClass()));
        $this->assertFalse(IdnoTwigExtension::isUser(null));
    }

    public function testTheTwitterAccountOfAProfile(): void
    {
        $person = static fn (array $urls): object => new class ($urls) {
            public function __construct(private readonly array $urls)
            {
            }

            public function __get(string $name): mixed
            {
                return $name === 'profile' ? ['url' => $this->urls] : null;
            }
        };

        $this->assertSame('ada_l', IdnoTwigExtension::twitterAccountOf($person(['https://example.org/', '@ada_l'])), 'a handle');
        $this->assertSame('ada_l', IdnoTwigExtension::twitterAccountOf($person(['https://twitter.com/ada_l'])), 'an address on twitter.com');
        $this->assertSame('ada_l', IdnoTwigExtension::twitterAccountOf($person(['https://www.twitter.com/#!/@ada_l/status'])), 'with www, the old hash-bang and an at sign');
        $this->assertSame('', IdnoTwigExtension::twitterAccountOf($person(['https://twitter.com/', 'https://example.org/twitter.com/x', 'https://twitter.com.example.org/x', '@', 'ada', ''])), 'nothing that names an account');
        $this->assertSame('first', IdnoTwigExtension::twitterAccountOf($person(['@first', 'https://twitter.com/second'])), 'the first');
        $this->assertSame('', IdnoTwigExtension::twitterAccountOf($person([])), 'no addresses');
        $this->assertSame('', IdnoTwigExtension::twitterAccountOf(null), 'no person');
        $this->assertSame('', IdnoTwigExtension::twitterAccountOf(new \stdClass()), 'no profile');
        $this->assertSame('ada_l', $this->render("{{ twitter_account_of(p) }}", ['p' => ['profile' => ['url' => ['@ada_l']]]]), 'from the template, a person as an array');
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

    public function testIsStreamPageAnswersWhatTheHelpersSay(): void
    {
        $helpers = new TemplateHelpersDouble();
        $this->assertSame('n', $this->render("{{ is_stream_page() ? 'y' : 'n' }}", [], $helpers));
        $helpers->streamPage = true;
        $this->assertSame('y', $this->render("{{ is_stream_page() ? 'y' : 'n' }}", [], $helpers));
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

            public function forgetVariables(array $names): void
            {
                foreach ($names as $name) {
                    unset($this->vars[$name]);
                }
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

            public function forgetVariables(array $names): void
            {
                foreach ($names as $name) {
                    unset($this->vars[$name]);
                }
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

    public function testTheFormHelpers(): void
    {
        $helpers = new TemplateHelpersDouble();

        // A value as one single-quoted JavaScript string in a double-quoted attribute
        $this->assertSame("'It\\'s a \\\\ &quot;test&quot;\\n\\u2028 &lt;b&gt; &amp;'", $this->render("{{ js_string(v) }}", ['v' => "It's a \\ \"test\"\n\u{2028} <b> &"]), 'escaped for the string, then for the attribute');
        $this->assertSame("''", $this->render("{{ js_string(null) }}"), 'nothing is an empty string');
        $this->assertSame("'1'", $this->render("{{ js_string(1) }}"));
        // The address of a link
        $this->assertSame('/a?b=1|https://x.example/y||', $this->render("{{ '/a?b=1'|link_url }}|{{ 'https://x.example/y'|link_url }}|{{ 'javascript:alert(1)'|link_url }}|{{ null|link_url }}"));
        $this->assertSame('ada@example.com|abc@b.c', $this->render("{{ 'ada@example.com'|email_address }}|{{ 'a b\"<c>@b.c'|email_address }}"), 'what an address cannot hold is removed');
        $this->assertSame('a+b%2Fc%7E', $this->render("{{ 'a b/c~'|form_urlencode }}"), 'urlencode(), a space is a plus');
        // The counter of ids, the token, the zones and the blocked addresses are the site's: the extension passes them on
        $this->assertSame('1 2', $this->render('{{ next_input_id() }} {{ next_input_id() }}', [], $helpers));
        $this->assertSame('token-/a?b=1-1773000000|token--', $this->render("{{ security_token('/a?b=1', 1773000000) }}|{{ security_token(null, null) }}", [], $helpers));
        $this->assertSame('UTC=(GMT) UTC,Europe/London=(GMT) Europe, London,', $this->render("{% for zone, label in timezone_options() %}{{ zone }}={{ label }},{% endfor %}", [], $helpers));
        $this->assertSame('3600 +01:00 null', $this->render("{% set info = timezone_info('Europe/London') %}{{ info.offset }} {{ info.gmt }} {{ info.diff is null ? 'null' : info.diff }}", [], $helpers));
        $this->assertSame('1|0|0', $this->render("{{ email_is_blocked('blocked@example.com') ? 1 : 0 }}|{{ email_is_blocked('x@example.com') ? 1 : 0 }}|{{ email_is_blocked(null) ? 1 : 0 }}", [], $helpers));
    }

    public function testForgetVariablesTakesTheNamedVariablesOffTheObjectTheFileWasDrawnOnAndNothingElse(): void
    {
        $context = new class implements PhpTemplateContext {
            public array $vars = ['class' => 'x', 'name' => 'n', 'keep' => 'k'];

            public function forgetVariables(array $names): void
            {
                foreach ($names as $name) {
                    unset($this->vars[$name]);
                }
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
                return '';
            }

            public function drawSlot($name)
            {
                return '';
            }
        };
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{% do forget_variables(['class', 'name', 'absent']) %}{{ class }}{{ name }}{{ keep }}"]), ['cache' => false]);
        $twig->addExtension($this->extension());

        $this->assertSame('xnk', $twig->render('a.html.twig', ['class' => 'x', 'name' => 'n', 'keep' => 'k', '_idno_context' => $context]), 'the file goes on with the variables it was given');
        $this->assertSame(['keep' => 'k'], $context->vars, 'the object lost the named ones');
        $this->assertSame('', $twig->render('a.html.twig', ['_idno_context' => 'not a template object']), 'a context that is not a template object is left alone');
    }

    public function testThePureHelpersOfTheEntityTemplates(): void
    {
        $this->assertSame('ex.org|https|ex.org|', $this->render("{{ 'https://ex.org/a'|url_host }}|{{ 'https://ex.org/a'|url_scheme }}|{{ 'http://x@ex.org:81'|url_host }}|{{ 'no host'|url_host }}"), 'the host and scheme of an address, empty when it has none');
        $this->assertSame('c||b|x', $this->render("{{ 'https://ex.org/a/b/c'|url_basename }}|{{ 'https://ex.org/'|url_basename }}|{{ 'https://ex.org/a/b/'|url_basename }}|{{ x|url_basename }}", ['x' => '/p/x']), 'the last segment of the path, a trailing slash ignored');
        $this->assertSame('abb 1', $this->render("{{ 'a<b>&b \"1\"'|alnum_only }}"), 'ASCII letters, digits and spaces');
        $this->assertSame('YS1iYz8-', $this->render("{{ 'a-bc?>'|base64_url }}"), 'URL-safe base64 as Webservice makes it');
        $this->assertSame('1|0', $this->render("{{ is_entity(e) ? 1 : 0 }}|{{ is_entity(n) ? 1 : 0 }}", ['e' => $this->createMock(\Idno\Common\Entity::class), 'n' => 'not']));
    }

    public function testVisitorMessageAsksTheHelpersAboutAnExceptionAndNothingElse(): void
    {
        $helpers = new TemplateHelpersDouble();

        $this->assertSame('Shown|Something went wrong.|', $this->render("{{ visitor_message(a) }}|{{ visitor_message(b) }}|{{ visitor_message('a string') }}", ['a' => new \RuntimeException('Shown'), 'b' => new \TypeError('Hidden')], $helpers));
        $this->assertSame(['visitorMessage', 'visitorMessage'], array_map(static fn (array $call): string => $call[0], $helpers->calls), 'a string is not asked about');
    }

    public function testARandomIdIs32HexDigitsAndDiffers(): void
    {
        $id = $this->render('{{ random_id() }}');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertNotSame($id, $this->render('{{ random_id() }}'));
    }

    public function testTheDefaultAvatarIsOneOfFiveByUuid(): void
    {
        $paths = [];
        foreach (range(1, 40) as $n) {
            $paths[$this->render("{{ default_avatar('user-" . $n . "') }}")] = true;
        }

        $this->assertCount(5, $paths);
        foreach (array_keys($paths) as $path) {
            $this->assertMatchesRegularExpression('#^gfx/users/default-0[1-5]\.png$#', $path);
        }
        $this->assertSame($this->render("{{ default_avatar('same') }}"), $this->render("{{ default_avatar('same') }}"));
    }

    public function testProfileLinkDescribesAnAddressThroughTheSitesAddressFixer(): void
    {
        $out = $this->render("{% set l = profile_link('@ada_l') %}{{ l.url }}|{{ l.icon }}|{{ l.class }}|{% set m = profile_link('javascript:alert(1)') %}{{ m.url }}|{{ m.display }}");

        $this->assertSame('https://twitter.com/ada_l|fa fa-twitter|u-url|http://javascript:alert(1)|javascript:alert(1)', $out, 'the double has no fixer of its own but the one that adds http://');
    }

    public function testEmbedsListsWhatATextLinksTo(): void
    {
        $this->assertSame('vimeo:1,instagram:Q', $this->render("{% for e in embeds(t) %}{{ loop.first ? '' : ',' }}{{ e.kind }}:{{ e.id }}{% endfor %}", ['t' => 'https://vimeo.com/1 https://www.instagram.com/p/Q/']));
        $this->assertSame('none', $this->render("{{ embeds(null)|length ? 'some' : 'none' }}"));
    }

    public function testTheSyndicationLookupsAreTheHelpersAndAttachmentUrlIsAFilter(): void
    {
        $helpers = new TemplateHelpersDouble();

        $this->assertSame('1|0|x', $this->render("{{ syndication_has('s') ? 1 : 0 }}|{{ syndication_accounts('s')|length }}|{{ 'x'|attachment_url }}", [], $helpers));
        $this->assertSame(['syndicationHas', 'syndicationAccounts', 'attachmentUrl'], array_map(static fn (array $call): string => $call[0], $helpers->calls));
    }

    public function testParseUrlsPassesTheAttributesOfTheLinkOnToTheHelper(): void
    {
        $helpers = new TemplateHelpersDouble();

        $this->render("{{ 'see https://x.example/'|parse_urls('rel=\"nofollow\"') }}", [], $helpers);

        $this->assertSame('parseUrls', $helpers->calls[0][0]);
        $this->assertSame(['see https://x.example/', 'rel="nofollow"'], $helpers->calls[0][1]);
    }
}
