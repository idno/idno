<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TemplateHelpers;

/**
 * A TemplateHelpers with no site behind it: each method answers something recognisable from its arguments and records the
 * call, so a test sees what the extension passed on. `parseUrls()` and the other text methods return their input unchanged in
 * a marker, to show that the extension (and not the double) escaped the text before.
 */
final class TemplateHelpersDouble implements TemplateHelpers
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    public array $settings = ['title' => 'My site', 'smtp_password' => 'hunter2', 'dbpass' => 'x', 'dbuser' => 'root'];

    public bool $xhr = false;

    private function note(string $method, mixed ...$args): void
    {
        $this->calls[] = [$method, $args];
    }

    public function siteUrl(string $path = ''): string
    {
        $this->note(__FUNCTION__, $path);

        return 'https://site.example/' . $path;
    }

    public function staticUrl(string $path = ''): string
    {
        $this->note(__FUNCTION__, $path);

        return 'https://static.example/' . $path;
    }

    public function siteTitle(): string
    {
        return 'A <b>site</b>';
    }

    public function siteIcons(): array
    {
        return ['defaults' => ['default_57' => 'a.png']];
    }

    public function setting(string $name): mixed
    {
        $this->note(__FUNCTION__, $name);

        return $this->settings[$name] ?? null;
    }

    public function canWrite(): bool
    {
        return true;
    }

    public function contentTypes(): array
    {
        return [(object) ['name' => 'note']];
    }

    public function contentTypeForPostType(string $type): ?object
    {
        return $type === 'note' ? (object) ['name' => 'note'] : null;
    }

    public function currentUser(): ?object
    {
        return (object) ['handle' => 'ben'];
    }

    public function isLoggedIn(): bool
    {
        return true;
    }

    public function isAdmin(): bool
    {
        return false;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $name === 'offset' ? '20' : $default;
    }

    public function pathMatches(string $path): bool
    {
        return $path === 'a/b';
    }

    public function isPermalink(): bool
    {
        return false;
    }

    public function isXhr(): bool
    {
        return $this->xhr;
    }

    public function pageAssets(string $type): array
    {
        return $type === 'css' ? ['a' => '/a.css'] : [];
    }

    public function pageOwner(): ?object
    {
        return null;
    }

    public function httpStatus(): int
    {
        return 200;
    }

    public function loginUrlFor(string $path): string
    {
        return 'https://site.example/session/login/?fwd=' . rawurlencode($path);
    }

    public function urlWithVar(string $name, string $value, string $url = ''): string
    {
        return ($url === '' ? 'https://site.example/here' : $url) . '?' . $name . '=' . $value;
    }

    public function urlWithoutVar(string $url, string $name): string
    {
        return $url . '#without-' . $name;
    }

    public function currentUrlWithoutVar(string $name): string
    {
        return 'https://site.example/here#without-' . $name;
    }

    public function bodyClasses(): string
    {
        return 'page-a "quoted" <i>';
    }

    public function formToken(string $action): string
    {
        $this->note(__FUNCTION__, $action);

        return '<input name="__bTk" value="token-for-' . htmlspecialchars($action) . '">';
    }

    public function actionLink(string $url, string $label, array $data = [], array $options = []): string
    {
        $this->note(__FUNCTION__, $url, $label, $data, $options);

        return '<a href="' . $url . '">' . $label . '</a>';
    }

    public function formData(string $html): string
    {
        $this->note(__FUNCTION__, $html);

        return $this->xhr ? $html : '';
    }

    public function shellVariables(array $vars): array
    {
        $this->note(__FUNCTION__, $vars);

        return $vars + ['lang' => 'en'];
    }

    public function pagination(int $count, ?int $perPage = null, array $vars = []): string
    {
        $this->note(__FUNCTION__, $count, $perPage, $vars);

        return '<nav>' . $count . '</nav>';
    }

    public function syndicationServices(string $contentType): array
    {
        return ['twitter'];
    }

    public function syndicationSelected(string $service, ?string $username = null): mixed
    {
        return $service . ':' . $username;
    }

    public function shareTypes(array $types): array
    {
        return $types + ['x' => 'X'];
    }

    public function expandUrls(?object $object, string $text): string
    {
        return $text . ' expanded';
    }

    public function localEntity(string $url): ?object
    {
        return null;
    }

    public function localFile(string $url): ?object
    {
        return null;
    }

    public function accessGroupsOf(string $ownerUuid): array
    {
        return [];
    }

    public function latestPostOf(object $user): ?object
    {
        return null;
    }

    public function sanitizeHtml(string $html): string
    {
        $this->note(__FUNCTION__, $html);

        return '[sanitized ' . $html . ']';
    }

    public function autop(string $html): string
    {
        $this->note(__FUNCTION__, $html);

        return '<p>' . $html . '</p>';
    }

    public function parseUrls(string $html): string
    {
        $this->note(__FUNCTION__, $html);

        return $html;
    }

    public function parseHashtags(string $html): string
    {
        $this->note(__FUNCTION__, $html);

        return $html;
    }

    public function parseUsers(string $html, string|array|null $inReplyTo = null): string
    {
        $this->note(__FUNCTION__, $html, $inReplyTo);

        return $html;
    }

    public function displayUrl(string $url): string
    {
        return '//' . preg_replace('~^https?://~', '', $url);
    }

    public function fixUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : 'http://' . $url;
    }

    public function proxiedImage(string $url, ?int $max = null, ?string $transform = null): string
    {
        $this->note(__FUNCTION__, $url, $max, $transform);

        return $url;
    }

    public function sampleText(string $text, int $words = 32): string
    {
        $this->note(__FUNCTION__, $text, $words);

        return implode(' ', array_slice(explode(' ', $text), 0, $words));
    }
}
