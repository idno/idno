<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * What a Twig template can ask of the running site, one method for each named function and filter of IdnoTwigExtension
 * that needs the site (Idno\Legacy\LegacyTemplateHelpers is over the legacy application). A method takes the plain values
 * the template gave it and answers a plain value: there is no way to reach a service or a class by name through it.
 *
 * Methods that answer markup (`formToken`, `actionLink`, `pagination`, the four text filters and `sanitizeHtml`) answer HTML
 * the extension marks as safe, so each must escape or purify everything in it that a person or another site supplied.
 */
interface TemplateHelpers
{
    // site

    public function siteUrl(string $path = ''): string;

    /**
     * The address of a page as `Page::currentUrl()` builds it: the scheme, user, host and port of the site's configured address
     * and then the request URI (path and query, as the server gave it). Never the request's own Host header or scheme.
     */
    public function currentUrlFor(string $requestUri): string;

    /**
     * The parts of the address of a page, as `Page::currentUrl(true)` gives them: what `parse_url()` reads from the site's
     * configured address, with the request URI (path and query, as the server gave it) as `path`. Never the request's own Host header.
     *
     * @return array<string, mixed>
     */
    public function currentUrlPartsFor(string $requestUri): array;

    public function staticUrl(string $path = ''): string;

    public function siteTitle(): string;

    /**
     * @return array<string, mixed>
     */
    public function siteIcons(): array;

    /**
     * The value of a setting, whatever it is: the extension refuses the settings that are secrets before it asks.
     */
    public function setting(string $name): mixed;

    public function canWrite(): bool;

    /**
     * @return list<object>
     */
    public function contentTypes(): array;

    public function contentTypeForPostType(string $type): ?object;

    // viewer

    public function currentUser(): ?object;

    public function isLoggedIn(): bool;

    public function isAdmin(): bool;

    // page

    public function input(string $name, mixed $default = null): mixed;

    public function pathMatches(string $path): bool;

    public function isPermalink(): bool;

    public function isXhr(): bool;

    /**
     * Whether the request was made over HTTPS: the answer of `Page::isSSL()` itself (the server's own word, the port, a proxy's
     * `X-Forwarded-Proto`; a console process has none, and is secure when the site's configured address is https), which the adapter
     * calls and does not copy.
     */
    public function isSecure(): bool;

    /**
     * The icon of the current page (`Page::getIcon()`: the owner's picture or the site's, as plugins may change it); empty
     * without a page.
     */
    public function pageIcon(): string;

    /**
     * The `user` variable the site's own template object holds, when `$template` IS that object, else null. Code that looks up an icon
     * (`Page::getIcon()`, `Entity::getIcon()`) draws a default avatar on the site's template object, which leaves the avatar's user there as
     * its `user` variable, and the page shell has always drawn what follows its icons (the identity links, the ActivityPub link, the structured
     * data) with whatever user the LAST such lookup left: the shell passes it on, because a Twig template sees no variable that another
     * template set on the object. A shell drawn on a copy of the site's template (not the object icon lookups write to) saw none of it.
     */
    public function siteTemplateUser(object $template): ?object;

    /**
     * @return array<string, string>
     */
    public function pageAssets(string $type): array;

    public function pageOwner(): ?object;

    public function httpStatus(): int;

    public function loginUrlFor(string $path): string;

    public function urlWithVar(string $name, string $value, string $url = ''): string;

    public function urlWithoutVar(string $url, string $name): string;

    public function currentUrlWithoutVar(string $name): string;

    public function bodyClasses(): string;

    // forms

    public function formToken(string $action): string;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options class, confirm, confirm-text, title, method
     */
    public function actionLink(string $url, string $label, array $data = [], array $options = []): string;

    public function formData(string $html): string;

    // drawing

    /**
     * What the page shell needs, computed from the given variables (the template object's own are untouched).
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function shellVariables(array $vars): array;

    /**
     * @param array<string, mixed> $vars
     */
    public function pagination(int $count, ?int $perPage = null, array $vars = []): string;

    /**
     * @return list<string>
     */
    public function syndicationServices(string $contentType): array;

    public function syndicationSelected(string $service, ?string $username = null): mixed;

    /**
     * @param array<string, string> $types
     * @return array<string, string>
     */
    public function shareTypes(array $types): array;

    public function expandUrls(?object $object, string $text): string;

    // lookups

    public function localEntity(string $url): ?object;

    public function localFile(string $url): ?object;

    /**
     * @return list<object>
     */
    public function accessGroupsOf(string $ownerUuid): array;

    public function latestPostOf(object $user): ?object;

    // text and addresses

    public function sanitizeHtml(string $html): string;

    public function autop(string $html): string;

    public function parseUrls(string $html): string;

    public function parseHashtags(string $html): string;

    /**
     * @param string|list<string>|null $inReplyTo
     */
    public function parseUsers(string $html, string|array|null $inReplyTo = null): string;

    public function displayUrl(string $url): string;

    public function fixUrl(string $url): string;

    public function proxiedImage(string $url, ?int $max = null, ?string $transform = null): string;

    public function sampleText(string $text, int $words = 32): string;
}
