<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Discovery;

use Idno\Http\Controller\Discovery\MentionCandidates;
use Idno\Http\Controller\Discovery\SiteIcons;
use Idno\Http\Controller\Discovery\WebfingerDirectory;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Request;

/**
 * The doubles the discovery services' tests share: a drawer that records what is drawn, and a `PageRenderer` over it.
 */
final class DiscoveryDoubles
{
    public static function drawer(): RecordingDrawer
    {
        return new RecordingDrawer();
    }

    public static function renderer(PageDrawer $drawer): PageRenderer
    {
        return new PageRenderer($drawer, new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        });
    }

    public static function context(string $uri = '/', array $server = []): PageContext
    {
        return new PageContext(Request::create($uri, 'GET', [], [], [], $server), new SitePage('Fixture\Page'));
    }
}

final class RecordingDrawer implements PageDrawer
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

        return $name === 'txt/robots' ? "User-agent: *\nDisallow:\n" : '[' . $name . ']';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('<html>' . ($vars['body'] ?? '') . '</html>', [['Access-Control-Allow-Origin', '*']], null);
    }
}

final class FixedIcons implements SiteIcons
{
    public function __construct(private readonly string $display = 'https://example.com/')
    {
    }

    public function icons(): array
    {
        $defaults = [];
        foreach (['36', '48', '72', '96', '144', '192'] as $size) {
            $defaults['default_' . $size] = 'https://example.com/static/logo_' . $size . '.png';
        }

        return ['defaults' => $defaults];
    }

    public function title(): string
    {
        return 'Ada\'s "site"';
    }

    public function displayUrl(): string
    {
        return $this->display;
    }
}

final class FixedDirectory implements WebfingerDirectory
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, array{aliases: list<string>, links: array<mixed>}> $known by resource
     */
    public function __construct(private readonly array $known)
    {
    }

    public function find(string $resource): ?array
    {
        $this->asked[] = $resource;

        return $this->known[$resource] ?? null;
    }
}

final class FixedCandidates implements MentionCandidates
{
    /** @var list<string> */
    public array $asked = [];

    public function candidates(string $username): array
    {
        $this->asked[] = $username;

        return [['username' => 'ada', 'name' => 'Ada L', 'image' => 'https://example.com/a.png']];
    }
}
