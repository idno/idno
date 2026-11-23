<?php

declare(strict_types=1);

namespace Idno\Templating;

use Idno\Portability\SettingsRedactor;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What a Twig template can call to reach the rest of the template system and the site. Everything is a named function or
 * filter; there is no object for the site and no way to ask for a service or a class by name.
 *
 *  - `idno_include(name, vars)`: draws the template `name` as `$this->draw()` does in a `.tpl.php` file (the cascade, the
 *    type and then `default`, and what plugins and themes registered for the name), with the variables of the including
 *    template and the given ones over them; it can draw a `.tpl.php` file or another Twig template. Its output is
 *    HTML that was already drawn, so it is not escaped again. The included template draws with a COPY of the including
 *    template's variables: what an included template sets is not seen after it.
 *  - `slot(name)`: draws what plugins and themes registered for `name` with `extendTemplate()` and its relatives (the
 *    prepends, the extensions and the added content), and nothing else. Not escaped again, for the same reason.
 *  - `t(text, subs)`: the site's translation of `text`. Its result is an ordinary string: Twig escapes it as it does any other.
 *    `t_html(text, subs)`: the same for a string that holds markup (the translated text is trusted: it is Idno's own or a
 *    translator's); each string in `subs` is escaped, a `Twig\Markup` one (a `{% set %}` capture, or what a safe function returned) is kept; `|raw` does NOT make a string `Markup`.
 *  - The functions that need the site (the site's address and title, settings, the viewer, the page, forms, lookups) are
 *    `TemplateHelpers`; the request facts (`current_url()`, `current_path()`, `referrer()`) come from the RequestStack, empty
 *    without a request; `entity_value()`, `http_url` and `setting()`'s refusal of secrets are here because they need nothing of the site.
 *
 * The functions marked safe return `Twig\Markup`, so another function can tell their output from a plain string.
 *
 * What is marked safe for HTML is exactly: `idno_include`, `slot`, `t_html`, `form_token`, `action_link`, `form_data` and
 * `pagination` (functions); `sanitize_html`, `autop`, `parse_urls`, `parse_hashtags` and `parse_users` (filters). The last
 * four work on text and so ESCAPE their input first (`pre_escape`: raw text is escaped, sanitized HTML and `Markup` are
 * kept). `action_link`'s label and `form_data`'s argument are kept only when they are `Markup` (a capture, or the output of a safe
 * function) and escaped otherwise, so a plain string can never become markup by passing through them. `action_link` refuses an
 * address that is not relative to the site or http(s). Nothing else is safe: everything else is escaped by Twig as any string is.
 */
final class IdnoTwigExtension extends AbstractExtension
{
    /** What the engine and Twig itself put in a template's context: not variables of the template object. */
    public const BOOKKEEPING = ['_idno_type', '_idno_context', '_parent', '_seq', '_key', '_iterated', 'loop'];

    public function __construct(
        private readonly TemplateTranslation $translation,
        private readonly TemplateHelpers $helpers,
        private readonly RequestStack $requests,
    )
    {
    }

    public function getFunctions(): array
    {
        $h = $this->helpers;
        $html = ['is_safe' => ['html']];

        return [
            new TwigFunction('idno_include', $this->includeTemplate(...), ['needs_context' => true, 'needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('slot', $this->slot(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('t_html', $this->translateHtml(...), $html),

            new TwigFunction('site_url', $h->siteUrl(...)),
            new TwigFunction('static_url', $h->staticUrl(...)),
            new TwigFunction('site_title', $h->siteTitle(...)),
            new TwigFunction('site_icons', $h->siteIcons(...)),
            new TwigFunction('setting', $this->setting(...)),
            new TwigFunction('can_write', $h->canWrite(...)),
            new TwigFunction('content_types', $h->contentTypes(...)),
            new TwigFunction('content_type_for_post_type', $h->contentTypeForPostType(...)),

            new TwigFunction('current_user', $h->currentUser(...)),
            new TwigFunction('is_logged_in', $h->isLoggedIn(...)),
            new TwigFunction('is_admin', $h->isAdmin(...)),

            new TwigFunction('current_url', $this->currentUrl(...)),
            new TwigFunction('current_path', $this->currentPath(...)),
            new TwigFunction('referrer', $this->referrer(...)),
            new TwigFunction('input', $h->input(...)),
            new TwigFunction('path_matches', $h->pathMatches(...)),
            new TwigFunction('is_permalink', $h->isPermalink(...)),
            new TwigFunction('is_xhr', $h->isXhr(...)),
            new TwigFunction('page_assets', $h->pageAssets(...)),
            new TwigFunction('page_owner', $h->pageOwner(...)),
            new TwigFunction('http_status', $h->httpStatus(...)),
            new TwigFunction('login_url_for', $h->loginUrlFor(...)),
            new TwigFunction('url_with_var', $h->urlWithVar(...)),
            new TwigFunction('url_without_var', $h->urlWithoutVar(...)),
            new TwigFunction('current_url_without_var', $h->currentUrlWithoutVar(...)),
            new TwigFunction('body_classes', $h->bodyClasses(...)),

            new TwigFunction('form_token', fn (string $action): Markup => new Markup($h->formToken($action), 'UTF-8'), $html),
            new TwigFunction('action_link', $this->actionLink(...), $html),
            new TwigFunction('form_data', $this->formData(...), $html),

            new TwigFunction('shell_variables', $this->shellVariables(...), ['needs_context' => true, 'needs_environment' => true]),
            new TwigFunction('pagination', fn (int $count, ?int $per_page = null, array $vars = []): Markup => new Markup($h->pagination($count, $per_page, $vars), 'UTF-8'), $html),
            new TwigFunction('syndication_services', $h->syndicationServices(...)),
            new TwigFunction('syndication_selected', $h->syndicationSelected(...)),
            new TwigFunction('share_types', $h->shareTypes(...)),
            new TwigFunction('expand_urls', $h->expandUrls(...)),

            new TwigFunction('local_entity', $h->localEntity(...)),
            new TwigFunction('local_file', $h->localFile(...)),
            new TwigFunction('access_groups_of', $h->accessGroupsOf(...)),
            new TwigFunction('latest_post_of', $h->latestPostOf(...)),

            new TwigFunction('entity_value', self::entityValue(...)),
        ];
    }

    public function getFilters(): array
    {
        $text = ['is_safe' => ['html'], 'pre_escape' => 'html'];

        return [
            new TwigFilter('sanitize_html', fn (mixed $html): string => $this->helpers->sanitizeHtml(self::text($html)), ['is_safe' => ['html']]),
            new TwigFilter('autop', fn (mixed $html): string => $this->helpers->autop(self::text($html)), $text),
            new TwigFilter('parse_urls', fn (mixed $html): string => $this->helpers->parseUrls(self::text($html)), $text),
            new TwigFilter('parse_hashtags', fn (mixed $html): string => $this->helpers->parseHashtags(self::text($html)), $text),
            new TwigFilter('parse_users', fn (mixed $html, string|array|null $in_reply_to = null): string => $this->helpers->parseUsers(self::text($html), $in_reply_to), $text),
            new TwigFilter('http_url', self::httpUrl(...)),
            new TwigFilter('display_url', fn (mixed $url): string => $this->helpers->displayUrl(self::text($url))),
            new TwigFilter('fix_url', fn (mixed $url): string => $this->helpers->fixUrl(self::text($url))),
            new TwigFilter('proxied_image', fn (mixed $url, ?int $max = null, ?string $transform = null): string => $this->helpers->proxiedImage(self::text($url), $max, $transform)),
            new TwigFilter('sample_text', fn (mixed $text, int $words = 32): string => $this->helpers->sampleText(self::text($text), $words)),
        ];
    }

    /**
     * @param array<string, mixed> $context the template's variables, as Twig has them
     * @param array<string, mixed> $vars
     */
    public function includeTemplate(Environment $environment, array $context, string $name, array $vars = []): Markup
    {
        $template = self::templateContext($context);

        return new Markup((string) $template->withVars(array_merge(self::variablesOf($environment, $context), $vars))->draw($name), 'UTF-8');
    }

    /**
     * The variables of a template as its object has them: the context holds Twig's globals (`app`, ...) and its own bookkeeping
     * (`_parent`, `loop`, ...) and the engine's, and none of those is a variable of the template.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function variablesOf(Environment $environment, array $context): array
    {
        $globals = $environment->getGlobals();

        return array_filter(
            $context,
            static fn ($value, $key): bool => !in_array($key, self::BOOKKEEPING, true) && !(array_key_exists($key, $globals) && $globals[$key] === $value),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * What the page shell needs, computed from the variables of the template that asks.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function shellVariables(Environment $environment, array $context): array
    {
        return $this->helpers->shellVariables(self::variablesOf($environment, $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function slot(array $context, string $name): Markup
    {
        return new Markup((string) self::templateContext($context)->drawSlot($name), 'UTF-8');
    }

    /**
     * @param array<int, mixed> $subs
     */
    public function translate(string $text, array $subs = []): string
    {
        return $this->translation->translate($text, $subs);
    }

    /**
     * The translation of a string that holds markup: the text is trusted, so only what is put into it is escaped.
     *
     * @param array<int, mixed> $subs
     */
    public function translateHtml(string $text, array $subs = []): Markup
    {
        $escaped = array_map(static function (mixed $sub): mixed {
            if ($sub instanceof Markup) {
                return (string) $sub;
            }

            return is_string($sub) || $sub instanceof \Stringable ? htmlspecialchars((string) $sub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $sub;
        }, $subs);

        return new Markup($this->translation->translate($text, $escaped), 'UTF-8');
    }

    /**
     * A setting of the site, null for one that is a secret (by name or by what it holds: `SettingsRedactor`'s rule).
     */
    public function setting(string $name): mixed
    {
        // A name the rule would remove is not even asked for
        if (SettingsRedactor::redact([$name => 'x'], null) === []) {
            return null;
        }
        $value = $this->helpers->setting($name);

        return SettingsRedactor::redact([$name => $value], null)[$name] ?? null;
    }

    public function currentUrl(): string
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? '' : $request->getSchemeAndHttpHost() . $request->getRequestUri();
    }

    public function currentPath(): string
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? '' : (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
    }

    public function referrer(): string
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? '' : (string) $request->headers->get('referer', '');
    }

    /**
     * A link that submits a signed POST form. The label is markup only when it is `Markup` (a capture, the output of a safe
     * function) and is escaped otherwise; the url must be relative to the site or http(s), else nothing is drawn (the helper
     * escapes the class, title and confirmation text and the url's characters that end an attribute).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function actionLink(string $url, string|\Stringable $label, array $data = [], array $options = []): Markup
    {
        if (!self::isLinkableAddress($url)) {
            return new Markup('', 'UTF-8');
        }

        return new Markup($this->helpers->actionLink($url, self::markup($label), $data, $options), 'UTF-8');
    }

    /**
     * Content for the page shell's form area (or, in an XHR request, for the place it is called from): markup (a `Markup`);
     * any other string is escaped.
     */
    public function formData(string|\Stringable $html): Markup
    {
        return new Markup($this->helpers->formData(self::markup($html)), 'UTF-8');
    }

    /**
     * An address a link may point to: http(s) (or scheme-relative `//host`) as `UrlValidator::httpUrl()` reads it, or relative
     * to the site (a path, a query or a fragment: no scheme, no backslash, no white space or control character, not `//`).
     */
    private static function isLinkableAddress(string $url): bool
    {
        if (\Idno\Core\UrlValidator::httpUrl($url) !== '') {
            return true;
        }

        return preg_match('~^(?![a-zA-Z][a-zA-Z0-9+.\-]*:)(?!//)[^"<>\\\\\x00-\x20\x7f]*$~D', $url) === 1;
    }

    /**
     * The address, or '' for any other scheme than http and https (and an address with a character a URL cannot hold).
     */
    public static function httpUrl(mixed $url): string
    {
        return \Idno\Core\UrlValidator::httpUrl($url instanceof \Stringable ? (string) $url : $url);
    }

    /**
     * An attribute of an entity as it is stored, as the `.tpl.php` templates read it (`$object->name`). An array is read by key, a method is never called.
     */
    public static function entityValue(mixed $object, string $name): mixed
    {
        if (is_array($object)) {
            return $object[$name] ?? null;
        }
        if (!is_object($object)) {
            return null;
        }
        if (method_exists($object, '__get') || (property_exists($object, $name) && (new \ReflectionProperty($object, $name))->isPublic())) {
            return $object->$name;
        }

        return null;
    }

    private static function markup(string|\Stringable $value): string
    {
        return $value instanceof Markup ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function text(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function templateContext(array $context): PhpTemplateContext
    {
        $template = $context['_idno_context'] ?? null;
        if (!$template instanceof PhpTemplateContext) {
            throw new RuntimeError('idno_include() and slot() can only be used in a template drawn through the template system');
        }

        return $template;
    }
}
