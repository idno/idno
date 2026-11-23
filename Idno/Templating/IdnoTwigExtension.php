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
 *    without a request, and never ask the request for its host (`current_url()` is the site's configured address plus the request's URI, as
 *    `Page::currentUrl()` builds it); `entity_value()`, `http_url` and `setting()`'s refusal of secrets are here because they need nothing of the site.
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
        $html = ['is_safe' => ['html']];

        return [
            new TwigFunction('idno_include', $this->includeTemplate(...), ['needs_context' => true, 'needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('slot', $this->slot(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('t_html', $this->translateHtml(...), $html),

            new TwigFunction('site_url', $this->siteUrl(...)),
            new TwigFunction('static_url', $this->staticUrl(...)),
            new TwigFunction('site_title', $this->siteTitle(...)),
            new TwigFunction('site_icons', $this->siteIcons(...)),
            new TwigFunction('setting', $this->setting(...)),
            new TwigFunction('can_write', $this->canWrite(...)),
            new TwigFunction('content_types', $this->contentTypes(...)),
            new TwigFunction('content_type_for_post_type', $this->contentTypeForPostType(...)),

            new TwigFunction('current_user', $this->currentUser(...)),
            new TwigFunction('is_logged_in', $this->isLoggedIn(...)),
            new TwigFunction('is_admin', $this->isAdmin(...)),

            new TwigFunction('current_url', $this->currentUrl(...)),
            new TwigFunction('current_url_parts', $this->currentUrlParts(...)),
            new TwigFunction('current_path', $this->currentPath(...)),
            new TwigFunction('referrer', $this->referrer(...)),
            new TwigFunction('input', $this->input(...)),
            new TwigFunction('path_matches', $this->pathMatches(...)),
            new TwigFunction('is_permalink', $this->isPermalink(...)),
            new TwigFunction('is_xhr', $this->isXhr(...)),
            new TwigFunction('is_secure', $this->isSecure(...)),
            new TwigFunction('page_icon', $this->pageIcon(...)),
            new TwigFunction('leaked_user', $this->leakedUser(...), ['needs_context' => true]),
            new TwigFunction('page_assets', $this->pageAssets(...)),
            new TwigFunction('page_owner', $this->pageOwner(...)),
            new TwigFunction('http_status', $this->httpStatus(...)),
            new TwigFunction('login_url_for', $this->loginUrlFor(...)),
            new TwigFunction('url_with_var', $this->urlWithVar(...)),
            new TwigFunction('url_without_var', $this->urlWithoutVar(...)),
            new TwigFunction('current_url_without_var', $this->currentUrlWithoutVar(...)),
            new TwigFunction('body_classes', $this->bodyClasses(...)),

            new TwigFunction('form_token', $this->formToken(...), $html),
            new TwigFunction('action_link', $this->actionLink(...), $html),
            new TwigFunction('form_data', $this->formData(...), $html),

            new TwigFunction('shell_variables', $this->shellVariables(...), ['needs_context' => true, 'needs_environment' => true]),
            new TwigFunction('pagination', $this->pagination(...), $html),
            new TwigFunction('syndication_services', $this->syndicationServices(...)),
            new TwigFunction('syndication_selected', $this->syndicationSelected(...)),
            new TwigFunction('share_types', $this->shareTypes(...)),
            new TwigFunction('expand_urls', $this->expandUrls(...)),

            new TwigFunction('local_entity', $this->localEntity(...)),
            new TwigFunction('local_file', $this->localFile(...)),
            new TwigFunction('access_groups_of', $this->accessGroupsOf(...)),
            new TwigFunction('latest_post_of', $this->latestPostOf(...)),

            new TwigFunction('entity_value', self::entityValue(...)),
            new TwigFunction('twitter_account_of', self::twitterAccountOf(...)),
            new TwigFunction('is_user', self::isUser(...)),
        ];
    }

    public function getFilters(): array
    {
        $text = ['is_safe' => ['html'], 'pre_escape' => 'html'];

        return [
            new TwigFilter('sanitize_html', $this->sanitizeHtml(...), ['is_safe' => ['html']]),
            new TwigFilter('autop', $this->autop(...), $text),
            new TwigFilter('parse_urls', $this->parseUrls(...), $text),
            new TwigFilter('parse_hashtags', $this->parseHashtags(...), $text),
            new TwigFilter('parse_users', $this->parseUsers(...), $text),
            new TwigFilter('http_url', self::httpUrl(...)),
            new TwigFilter('identity_url', self::identityUrl(...)),
            new TwigFilter('to_int', self::toInt(...)),
            new TwigFilter('display_url', $this->displayUrl(...)),
            new TwigFilter('fix_url', $this->fixUrl(...)),
            new TwigFilter('proxied_image', $this->proxiedImage(...)),
            new TwigFilter('sample_text', $this->sampleText(...)),
        ];
    }

    /*
     * The functions and filters below pass what a template asks for to the site's helpers. They are methods of this extension, and not
     * closures over the helpers, so that Twig compiles a call in a template to a call of the method (`$this->extensions[...]->siteUrl()`)
     * and does not look the function up in the environment at run time: that look-up makes Twig build every function, filter, test,
     * token parser and operator of every extension before the first one is called, in every request that draws a template.
     */

    public function siteUrl(string $path = ''): string
    {
        return $this->helpers->siteUrl($path);
    }

    public function staticUrl(string $path = ''): string
    {
        return $this->helpers->staticUrl($path);
    }

    public function siteTitle(): string
    {
        return $this->helpers->siteTitle();
    }

    /**
     * @return array<string, mixed>
     */
    public function siteIcons(): array
    {
        return $this->helpers->siteIcons();
    }

    public function canWrite(): bool
    {
        return $this->helpers->canWrite();
    }

    /**
     * @return list<object>
     */
    public function contentTypes(): array
    {
        return $this->helpers->contentTypes();
    }

    public function contentTypeForPostType(string $type): ?object
    {
        return $this->helpers->contentTypeForPostType($type);
    }

    public function currentUser(): ?object
    {
        return $this->helpers->currentUser();
    }

    public function isLoggedIn(): bool
    {
        return $this->helpers->isLoggedIn();
    }

    public function isAdmin(): bool
    {
        return $this->helpers->isAdmin();
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->helpers->input($name, $default);
    }

    public function pathMatches(string $path): bool
    {
        return $this->helpers->pathMatches($path);
    }

    public function isPermalink(): bool
    {
        return $this->helpers->isPermalink();
    }

    public function isXhr(): bool
    {
        return $this->helpers->isXhr();
    }

    public function isSecure(): bool
    {
        return $this->helpers->isSecure();
    }

    public function pageIcon(): string
    {
        return $this->helpers->pageIcon();
    }

    /**
     * The user that icon lookups left on the site's template object, when the template that asks is that object (see
     * `TemplateHelpers::siteTemplateUser()`).
     *
     * @param array<string, mixed> $context
     */
    public function leakedUser(array $context): ?object
    {
        $template = $context['_idno_context'] ?? null;

        return is_object($template) ? $this->helpers->siteTemplateUser($template) : null;
    }

    /**
     * @return array<string, string>
     */
    public function pageAssets(string $type): array
    {
        return $this->helpers->pageAssets($type);
    }

    public function pageOwner(): ?object
    {
        return $this->helpers->pageOwner();
    }

    public function httpStatus(): int
    {
        return $this->helpers->httpStatus();
    }

    public function loginUrlFor(string $path): string
    {
        return $this->helpers->loginUrlFor($path);
    }

    public function urlWithVar(string $name, string $value, string $url = ''): string
    {
        return $this->helpers->urlWithVar($name, $value, $url);
    }

    public function urlWithoutVar(string $url, string $name): string
    {
        return $this->helpers->urlWithoutVar($url, $name);
    }

    public function currentUrlWithoutVar(string $name): string
    {
        return $this->helpers->currentUrlWithoutVar($name);
    }

    public function bodyClasses(): string
    {
        return $this->helpers->bodyClasses();
    }

    public function formToken(string $action): Markup
    {
        return new Markup($this->helpers->formToken($action), 'UTF-8');
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function pagination(int $count, ?int $per_page = null, array $vars = []): Markup
    {
        return new Markup($this->helpers->pagination($count, $per_page, $vars), 'UTF-8');
    }

    /**
     * @return list<string>
     */
    public function syndicationServices(string $contentType): array
    {
        return $this->helpers->syndicationServices($contentType);
    }

    public function syndicationSelected(string $service, ?string $username = null): mixed
    {
        return $this->helpers->syndicationSelected($service, $username);
    }

    /**
     * @param array<string, string> $types
     * @return array<string, string>
     */
    public function shareTypes(array $types): array
    {
        return $this->helpers->shareTypes($types);
    }

    public function expandUrls(?object $object, string $text): string
    {
        return $this->helpers->expandUrls($object, $text);
    }

    public function localEntity(string $url): ?object
    {
        return $this->helpers->localEntity($url);
    }

    public function localFile(string $url): ?object
    {
        return $this->helpers->localFile($url);
    }

    /**
     * @return list<object>
     */
    public function accessGroupsOf(string $ownerUuid): array
    {
        return $this->helpers->accessGroupsOf($ownerUuid);
    }

    public function latestPostOf(object $user): ?object
    {
        return $this->helpers->latestPostOf($user);
    }

    public function sanitizeHtml(mixed $html): string
    {
        return $this->helpers->sanitizeHtml(self::text($html));
    }

    public function autop(mixed $html): string
    {
        return $this->helpers->autop(self::text($html));
    }

    public function parseUrls(mixed $html): string
    {
        return $this->helpers->parseUrls(self::text($html));
    }

    public function parseHashtags(mixed $html): string
    {
        return $this->helpers->parseHashtags(self::text($html));
    }

    /**
     * @param string|array<int|string, mixed>|null $in_reply_to
     */
    public function parseUsers(mixed $html, string|array|null $in_reply_to = null): string
    {
        return $this->helpers->parseUsers(self::text($html), $in_reply_to);
    }

    public function displayUrl(mixed $url): string
    {
        return $this->helpers->displayUrl(self::text($url));
    }

    public function fixUrl(mixed $url): string
    {
        return $this->helpers->fixUrl(self::text($url));
    }

    public function proxiedImage(mixed $url, ?int $max = null, ?string $transform = null): string
    {
        return $this->helpers->proxiedImage(self::text($url), $max, $transform);
    }

    public function sampleText(mixed $text, int $words = 32): string
    {
        return $this->helpers->sampleText(self::text($text), $words);
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
        // The context is the largest of the two (every variable of the page), so it is the globals that are looked at one by one
        static $bookkeeping = null;
        $bookkeeping ??= array_flip(self::BOOKKEEPING);
        $variables = array_diff_key($context, $bookkeeping);
        foreach ($environment->getGlobals() as $key => $value) {
            if (array_key_exists($key, $variables) && $variables[$key] === $value) {
                unset($variables[$key]);
            }
        }

        return $variables;
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

        return $request === null ? '' : $this->helpers->currentUrlFor(self::requestUri($request));
    }

    /**
     * The request's URI exactly as the server gave it (what the legacy code reads from `$_SERVER['REQUEST_URI']`), without
     * asking the request for its host, scheme or full URI: those validate the Host header, which a request that Symfony rejected
     * and the legacy router serves anyway has malformed, and they are the visitor's words, never the site's address.
     */
    private static function requestUri(\Symfony\Component\HttpFoundation\Request $request): string
    {
        return (string) $request->server->get('REQUEST_URI', '');
    }

    /**
     * The parts of the page's address (`parse_url()` of the site's configured address, with the request URI as `path`), empty without a request.
     *
     * @return array<string, mixed>
     */
    public function currentUrlParts(): array
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? [] : $this->helpers->currentUrlPartsFor(self::requestUri($request));
    }

    public function currentPath(): string
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? '' : (string) parse_url(self::requestUri($request), PHP_URL_PATH);
    }

    public function referrer(): string
    {
        $request = $this->requests->getCurrentRequest();

        return $request === null ? '' : (string) $request->headers->get('referer', '');
    }

    /**
     * A link that submits a signed POST form. The label is markup only when it is `Markup` (a capture, the output of a safe
     * function) and is escaped otherwise; the url must be relative to the site or http(s), else nothing is drawn (`forms/link` escapes the class, title
     * and confirmation text; the helper percent-encodes the url's characters that end an attribute).
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
        return $url === '' || \Idno\Core\UrlValidator::linkUrl($url) !== '';
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

    /**
     * Whether a value is a user (`$value instanceof \Idno\Entities\User`).
     */
    public static function isUser(mixed $value): bool
    {
        return $value instanceof \Idno\Entities\User;
    }

    /**
     * `(int) $value`, as PHP casts it (a non-numeric string is 0, `'12abc'` is 12).
     */
    public static function toInt(mixed $value): int
    {
        return is_scalar($value) || $value === null ? (int) $value : 0;
    }

    /**
     * The address a profile may name as one of its identities (`rel="me"`): an http(s) address as `httpUrl()` reads it, or a `mailto:`,
     * `sms:` or `tel:` address (any letter case, surrounding white space removed) that holds no white space or control character; an
     * empty string for anything else (`javascript:`, `data:`, `vbscript:`, a bare word). The result is printed escaped, like any value.
     */
    public static function identityUrl(mixed $url): string
    {
        if (!is_string($url) && !$url instanceof \Stringable) {
            return '';
        }
        $url = trim((string) $url);
        $http = \Idno\Core\UrlValidator::httpUrl($url);
        if ($http !== '') {
            return $http;
        }
        if (preg_match('/^(?:mailto|sms|tel):/i', $url) === 1 && preg_match('/[\s\x00-\x1f\x7f]/', $url) === 0) {
            return $url;
        }

        return '';
    }

    /**
     * The Twitter account a person's profile names, without its `@`, or an empty string: the first profile address that is a
     * handle (`@name`) or an address on twitter.com with a name in it.
     */
    public static function twitterAccountOf(mixed $owner): string
    {
        $profile = self::entityValue($owner, 'profile');
        $addresses = is_array($profile) ? ($profile['url'] ?? null) : null;
        if (!is_array($addresses)) {
            return '';
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || $address === '') {
                continue;
            }
            if ($address[0] === '@' && preg_match('/\@[a-z0-9_]+/i', $address) === 1) {
                return str_replace('@', '', $address);
            }
            if (str_replace('www.', '', (string) parse_url($address, PHP_URL_HOST)) === 'twitter.com'
                && preg_match('/https?:\/\/(www\.)?twitter\.com\/(#!\/)?@?([^\/]*)/', $address, $matches) === 1
                && !empty($matches[3])) {
                return str_replace('@', '', $matches[3]);
            }
        }

        return '';
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
