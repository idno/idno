<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\ContentType;
use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\AccessGroup;
use Idno\Entities\File;
use Idno\Portability\SettingsRedactor;
use Idno\Templating\TemplateHelpers;

/**
 * What Twig templates ask of the site, over the legacy application (`Idno::site()`). Nothing here needs the application until a
 * method is called, so the object can be built, and the Twig environment with it, where the legacy application is not booted
 * (`debug:twig`, with the schema gate closed).
 *
 * Where a legacy method this wraps would hand markup to a template that is marked safe, what in the markup came from a member
 * or a remote site is escaped or neutralised here (`actionLink()`, `pagination()`): the notes on those methods. (The text linkers
 * are safe in the legacy methods themselves, which the `.tpl.php` templates also call.)
 *
 * `setting()` is an ALLOW-LIST: a template reads only the settings in TEMPLATE_SETTINGS (every name core's and the bundled plugins'
 * Twig templates read, and a plugin adds the names of its own settings with allowSettings()). Any other name is null, whatever
 * the setting holds: the stored settings also hold facts about the server (the database, the upload directory, who is told of an
 * error) and secrets, which a template of a third-party plugin or theme must not be able to print.
 */
final class LegacyTemplateHelpers implements TemplateHelpers
{
    /**
     * The settings a template may read, in groups with the reason each group is there. A name the redactor calls a secret is never
     * listed (`LegacyTemplateHelpersTest` checks this and that every name the bundled templates read is here).
     */
    public const TEMPLATE_SETTINGS = [
        'the site\'s name, address and look, drawn in the page shell and the mails' => ['title', 'description', 'homepagetitle', 'url', 'items_per_page', 'user_avatar_favicons', 'assets'],
        'who may register, post and see the site' => ['open_registration', 'walled_garden', 'single_user', 'show_privacy', 'blocked_emails'],
        'what the site says to the IndieWeb and when it shares' => ['hub', 'indieweb_citation', 'indieweb_reference', 'share_backup_url'],
        'what the administration pages show and edit' => ['alwaysplugins', 'debug', 'from_email', 'smtp_host', 'smtp_port', 'smtp_secure'],
    ];

    /** @var array<string, true> the names a plugin allowed for its own settings, for this helper */
    private array $pluginSettings = [];

    public function allowSettings(string ...$names): void
    {
        foreach ($names as $name) {
            if ($name === '' || preg_match('/\A[A-Za-z0-9_.\-]{1,128}\z/', $name) !== 1) {
                throw new \InvalidArgumentException('A template setting name is 1 to 128 letters, digits, underscores, dots and hyphens');
            }
            if (SettingsRedactor::redact([$name => 'x'], null) === []) {
                throw new \InvalidArgumentException('The setting ' . $name . ' is a secret by its name: a template cannot read it');
            }
        }
        foreach ($names as $name) {
            $this->pluginSettings[$name] = true;
        }
    }

    private function settingAllowed(string $name): bool
    {
        if (isset($this->pluginSettings[$name])) {
            return true;
        }
        foreach (self::TEMPLATE_SETTINGS as $group) {
            if (in_array($name, $group, true)) {
                return true;
            }
        }

        return false;
    }

    public function siteUrl(string $path = ''): string
    {
        return Idno::site()->config()->getDisplayURL() . $path;
    }

    public function currentUrlFor(string $requestUri): string
    {
        $url = parse_url((string) Idno::site()->config()->url);
        $url = is_array($url) ? $url : [];
        $url['path'] = $requestUri;

        return \Idno\Common\Page::buildUrl($url);
    }

    public function currentUrlPartsFor(string $requestUri): array
    {
        $url = parse_url((string) Idno::site()->config()->url);
        $url = is_array($url) ? $url : [];
        $url['path'] = $requestUri;

        return $url;
    }

    public function staticUrl(string $path = ''): string
    {
        return Idno::site()->config()->getStaticURL() . $path;
    }

    public function siteTitle(): string
    {
        return (string) Idno::site()->config()->getTitle();
    }

    public function siteIcons(): array
    {
        return Idno::site()->getSiteIcons();
    }

    public function setting(string $name): mixed
    {
        if (!$this->settingAllowed($name)) {
            return null;
        }
        $settings = Idno::site()->config()->config;

        return is_array($settings) ? ($settings[$name] ?? null) : null;
    }

    public function canWrite(): bool
    {
        return (bool) Idno::site()->canWrite();
    }

    public function canAddUsers(): bool
    {
        return (bool) Idno::site()->config()->canAddUsers();
    }

    public function canMakeSitePrivate(): bool
    {
        return (bool) Idno::site()->config()->canMakeSitePrivate();
    }

    public function legacyTablesInMenu(): bool
    {
        return (bool) \Idno\Pages\Admin\LegacyTables::showInMenu();
    }

    public function contentTypes(): array
    {
        return array_values(ContentType::getRegistered() ?: []);
    }

    public function contentTypeForPostType(string $type): ?object
    {
        return ContentType::getRegisteredForIndieWebPostType($type) ?: null;
    }

    public function currentUser(): ?object
    {
        return Idno::site()->session()->currentUser() ?: null;
    }

    public function isLoggedIn(): bool
    {
        return (bool) Idno::site()->session()->isLoggedIn();
    }

    public function isAdmin(): bool
    {
        return (bool) Idno::site()->session()->isAdmin();
    }

    public function input(string $name, mixed $default = null): mixed
    {
        $page = Idno::site()->currentPage();

        return $page ? $page->getInput($name, $default) : $default;
    }

    public function pathMatches(string $path): bool
    {
        $page = Idno::site()->currentPage();

        return $page ? (bool) $page->doesPathMatch($path) : false;
    }

    public function isPermalink(): bool
    {
        $page = Idno::site()->currentPage();

        return $page ? (bool) $page->isPermalink() : false;
    }

    public function isStreamPage(): bool
    {
        $page = Idno::site()->currentPage();

        return $page ? !$page->isPermalink() : false;
    }

    public function isXhr(): bool
    {
        $page = Idno::site()->currentPage();

        return $page ? (bool) $page->xhr : false;
    }

    /**
     * Delegates to `Page::isSSL()` (the server's HTTPS variable, the port, `X-Forwarded-Proto`): the one rule the old templates asked,
     * not a second copy of it.
     */
    public function isSecure(): bool
    {
        return (bool) \Idno\Common\Page::isSSL();
    }

    public function pageIcon(): string
    {
        $page = Idno::site()->currentPage();

        return $page ? (string) $page->getIcon() : '';
    }

    public function siteTemplateUser(object $template): ?object
    {
        $site = Idno::site()->template();
        $user = $template === $site ? ($site->vars['user'] ?? null) : null;

        return is_object($user) ? $user : null;
    }

    public function pageAssets(string $type): array
    {
        $page = Idno::site()->currentPage();
        $assets = $page ? $page->getAssets($type) : false;

        return is_array($assets) ? $assets : [];
    }

    public function pageOwner(): ?object
    {
        $page = Idno::site()->currentPage();

        return $page ? ($page->getOwner() ?: null) : null;
    }

    public function pageEntity(): ?object
    {
        $page = Idno::site()->currentPage();

        return ($page && $page->isPermalink()) ? ($page->getEntity() ?: null) : null;
    }

    public function httpStatus(): int
    {
        $page = Idno::site()->currentPage();

        return $page ? $page->response() : 200;
    }

    public function loginUrlFor(string $path): string
    {
        $page = Idno::site()->currentPage();
        if ($page) {
            return (string) $page->forwardToLogin($path, true);
        }

        return Idno::site()->config()->getDisplayURL() . 'session/login/?fwd=' . Webservice::encodeValue($path);
    }

    public function urlWithVar(string $name, string $value, string $url = ''): string
    {
        return (string) Idno::site()->template()->getURLWithVar($name, $value, $url);
    }

    public function urlWithoutVar(string $url, string $name): string
    {
        return (string) Idno::site()->template()->getURLWithoutVar($url, $name);
    }

    public function currentUrlWithoutVar(string $name): string
    {
        return (string) Idno::site()->template()->getCurrentURLWithoutVar($name);
    }

    /**
     * Without a page (a console process) there are no classes to give.
     */
    public function bodyClasses(): string
    {
        return Idno::site()->currentPage() ? (string) Idno::site()->template()->getBodyClasses() : '';
    }

    /**
     * What `Actions::signForm()` draws: the token's hidden fields. The action is escaped by the token template.
     */
    public function formToken(string $action): string
    {
        return (string) Idno::site()->actions()->signForm($action);
    }

    /**
     * What `Actions::createLink()` draws. The `forms/link` template escapes the class, title and confirmation text itself (and
     * refuses an address that is neither http(s) nor relative to the site: `UrlValidator::linkUrl()`); this adds that the
     * confirmation text is one line, and that the address keeps what a URL holds and percent-encodes a character that could
     * end the attribute or start a tag (it is also what the token is signed for, so a page's own address, which has none of
     * them, is unchanged). Only the options the template reads are passed on (an option cannot replace the url or label). The
     * label is markup: the caller's.
     */
    public function actionLink(string $url, string $label, array $data = [], array $options = []): string
    {
        $options = array_intersect_key($options, array_flip(['class', 'confirm', 'confirm-text', 'title', 'method']));
        if (isset($options['confirm-text'])) {
            $options['confirm-text'] = str_replace(["\r", "\n"], ' ', (string) $options['confirm-text']);
        }
        $url = (string) preg_replace_callback('/["<>\x00-\x20\x7f]/', static fn (array $m): string => rawurlencode($m[0]), $url);

        return (string) Idno::site()->actions()->createLink($url, $label, $data, $options);
    }

    /**
     * In an XHR request the markup is returned to the template that is drawing the control (nothing draws the shell there);
     * otherwise it is registered with the shell's `shell/form-data` and nothing is returned. With no current page (a console process:
     * the queue worker's export draws entities with action links) nothing would ever draw or clear the registrations, so nothing is
     * registered and nothing is returned.
     */
    public function formData(string $html): string
    {
        $page = Idno::site()->currentPage();
        if (!$page) {
            return '';
        }
        if ($page->xhr) {
            return $html;
        }
        Idno::site()->template()->extendTemplateWithContent('shell/form-data', $html);

        return '';
    }

    public function nextInputId(): int
    {
        global $input_id;
        $input_id = (int) $input_id + 1;

        return $input_id;
    }

    public function securityToken(string $action, string $time): string
    {
        return (string) \Idno\Core\Bonita\Forms::token($action, $time);
    }

    public function timezoneOptions(): array
    {
        $options = [];
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        foreach (timezone_identifiers_list() as $timezone) {
            $now->setTimezone(new \DateTimeZone($timezone));
            $offset = $now->getOffset();
            $hours = intval($offset / 3600);
            $minutes = abs(intval($offset % 3600 / 60));
            $options[$timezone] = '(' . 'GMT' . ($offset ? sprintf('%+03d:%02d', $hours, $minutes) : '') . ') ' . str_replace('/', ', ', $timezone);
        }

        return $options;
    }

    public function timezoneInfo(string $zone): array
    {
        $offset = \Idno\Core\Time::timezoneToGMTOffset($zone);
        $diff = null;
        if (Idno::site()->session()->isLoggedIn()) {
            $diff = (string) \Idno\Core\Time::printTimezoneDiff(\Idno\Core\Time::timezoneDiff($zone, Idno::site()->session()->currentUser()->getTimezone()));
        }

        return ['offset' => (int) $offset, 'gmt' => \Idno\Core\Time::printTimezoneOffset($offset), 'diff' => $diff];
    }

    public function emailIsBlocked(string $email): bool
    {
        return (bool) Idno::site()->config()->emailIsBlocked($email);
    }

    public function shellVariables(array $vars): array
    {
        // A copy with no variables: the site's own template object is left as it was
        $copy = Idno::site()->template()->withVars([]);
        $copy->formatShellVariables($vars);

        return $copy->vars;
    }

    /**
     * Only `control-id` and `source-url` of `$vars` are used (the others are the pagination's own: a value there would replace the
     * count or the offset); the template escapes what it prints.
     */
    public function pagination(int $count, ?int $perPage = null, array $vars = []): string
    {
        $template = Idno::site()->template();
        $vars = array_intersect_key($vars, array_flip(['control-id', 'source-url']));

        return (string) $template->withVars($template->vars)->drawPagination($count, $perPage, $vars);
    }

    public function syndicationServices(string $contentType): array
    {
        return array_values(Idno::site()->syndication()->getServices($contentType));
    }

    public function syndicationSelected(string $service, ?string $username = null): mixed
    {
        $data = [
            'service' => $service,
            'reply-to' => $this->input('share_url'),
        ];
        if ($username !== null) {
            $data['username'] = $username;
            $data['syndicated-to'] = $this->input('syndicatedto');
        }

        return Idno::site()->events()->triggerEvent('syndication/selected/' . $service, $data, false);
    }

    public function visitorMessage(\Throwable $exception): string
    {
        return \Idno\Core\ExceptionMessage::forVisitor($exception);
    }

    public function syndicationHas(string $service): bool
    {
        return (bool) Idno::site()->syndication()->has($service);
    }

    public function syndicationAccounts(string $service): array
    {
        $accounts = Idno::site()->syndication()->getServiceAccounts($service);

        return is_array($accounts) ? array_values($accounts) : [];
    }

    public function attachmentUrl(string $url): string
    {
        return (string) Idno::site()->config()->sanitizeAttachmentURL($url);
    }

    public function shareTypes(array $types): array
    {
        $result = Idno::site()->events()->triggerEvent('share/types', ['types' => $types], $types);

        return is_array($result) ? $result : $types;
    }

    public function expandUrls(?object $object, string $text): string
    {
        return (string) Idno::site()->events()->triggerEvent('url/expandintext', ['object' => $object], $text);
    }

    public function localEntity(string $url): ?object
    {
        return Entity::getByURL($url) ?: null;
    }

    public function localFile(string $url): ?object
    {
        return File::getByURL($url) ?: null;
    }

    public function accessGroupsOf(string $ownerUuid): array
    {
        $groups = AccessGroup::get(['owner' => $ownerUuid]);

        return is_array($groups) ? array_values($groups) : [];
    }

    public function latestPostOf(object $user): ?object
    {
        $posts = Entity::getFromX(null, ['owner' => $user->getUUID()], [], 1, 0);

        return is_array($posts) && $posts !== [] ? reset($posts) : null;
    }

    /**
     * Purified by the `text/filter` event (HTMLPurifier): what a member typed becomes safe markup.
     */
    public function sanitizeHtml(string $html): string
    {
        return (string) Idno::site()->template()->sanitize_html($html);
    }

    public function autop(string $html): string
    {
        return (string) Idno::site()->template()->autop($html);
    }

    /**
     * The extension escapes raw text before this runs. Only text is linked: not the inside of a tag (an attribute of
     * sanitized HTML), a link, a script, a style or a textarea.
     */
    public function parseUrls(string $html, string $attributes = ''): string
    {
        return (string) Idno::site()->template()->parseURLs($html, $attributes);
    }

    /**
     * Links the hashtags of the text: not inside a tag or a link, and no entity of a markup character is decoded (the legacy
     * method does both, for every template that calls it).
     */
    public function parseHashtags(string $html): string
    {
        return (string) Idno::site()->template()->parseHashtags($html);
    }

    /**
     * The links to a handle are built from `[\w]` characters and `urlencode()`; a reply target is only compared with
     * (twitter.com, github.com) or handed to the `template/parseusers` event, whose listeners build the markup.
     */
    public function parseUsers(string $html, string|array|null $inReplyTo = null): string
    {
        return (string) Idno::site()->template()->parseUsers($html, $inReplyTo);
    }

    public function displayUrl(string $url): string
    {
        return (string) Idno::site()->template()->makeDisplayURL($url);
    }

    public function fixUrl(string $url): string
    {
        return (string) Idno::site()->template()->fixURL($url);
    }

    public function proxiedImage(string $url, ?int $max = null, ?string $transform = null): string
    {
        return (string) Idno::site()->template()->getProxiedImageUrl($url, $max, $transform);
    }

    public function sampleText(string $text, int $words = 32): string
    {
        return (string) Idno::site()->template()->sampleText($text, $words);
    }
}
