<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Profile;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Idno\Templating\TemplateTranslation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Response;

/**
 * A member's profile page: the account's published posts in the shell, at `/profile/<handle>` (and `/actor/<handle>` where ActivityPub does not take that
 * address), for the friendly content paths after the handle too. The one implementation: `ProfileController` answers the GET, HEAD and OPTIONS the router
 * hands it, and the deprecated `Idno\Pages\User\View` (and so a plugin's page that extends it, or a route a plugin registered for it) serves the edit
 * form's POST, webmentions and any GET of a subclass through the same methods. The home page of a single-user site is its owner's profile, and hands its
 * webmentions to `receiveWebmention()`.
 */
#[AsTaggedItem(index: 'Idno\Pages\User\View')]
final class ProfilePages implements ReceivesWebmentions
{
    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application
     */
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly Profiles $profiles,
        private readonly TemplateTranslation $translation,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
    )
    {
    }

    /**
     * The profile of the account the address names, in its shell: the account owns the page, which is a permalink; the stream is the account's
     * published posts of the types the friendly path names (the notes and the articles when it names none), `items_per_page` of them from the request's
     * `offset`. The page is last modified when the account or its newest post was. A handle nobody has is the site's missing page (404). The profile never
     * answers a conditional request: it sends the page.
     */
    public function view(PageContext $page): Response
    {
        $user = $this->userOf($page);
        if ($user === null) {
            return $this->renderer->notFound($page);
        }

        // Users own their own profiles
        $page->setOwner($user);
        $page->setPermalink(true, $user);

        $friendly = $page->arguments()[1] ?? '';
        $types = $this->profiles->typesFor(in_array($friendly, ['', '0'], true) ? null : $friendly);
        $offset = (int) $page->input('offset');
        $stream = $this->profiles->stream($user, $types, $offset);
        $items = $stream['items'];

        $lastModified = $this->profiles->updated($user);
        if (!empty($items) && is_array($items)) {
            $first = reset($items);
            if ($first !== false && (int) $first->updated > $lastModified) {
                $lastModified = (int) $first->updated;
            }
        }
        $page->setLastModified($lastModified);

        return $this->renderer->page($page, [
            'title' => $this->profiles->title($user),
            'body' => $this->renderer->template('entity/User/profile', ['user' => $user, 'items' => $items, 'count' => $stream['count'], 'offset' => $offset]),
            'description' => $this->translation->translate('The %s profile for %s', [$this->profiles->siteTitle(), $this->profiles->title($user)]),
        ]);
    }

    /**
     * What a POST to the profile's address does: the edit form. The account's own member saves what the request holds into the account and is sent to its
     * profile; any other requester (a visitor, another member, an administrator) is sent back to where the request came from, and nothing is stored: the
     * permission is the account's own `canEdit()`, asked by the one call that stores, before anything is. A handle nobody has is the site's missing page
     * (404), for a script's request too.
     */
    public function save(PageContext $page): Response
    {
        $user = $this->userOf($page);
        if ($user === null) {
            return $this->renderer->notFound($page);
        }
        if ($this->profiles->saveFromInput($user)) {
            return $this->renderer->redirect($page, $this->profiles->url($user));
        }

        return $this->renderer->redirect($page, $this->profiles->referrer($page));
    }

    /**
     * A profile takes a webmention on its own route (`idno_profile`).
     */
    public function receivesFor(string $route): bool
    {
        return $route === 'idno_profile';
    }

    public function accepts(string $route, array $arguments): bool
    {
        $handle = (string) ($arguments[0] ?? '');
        if (!$this->receivesFor($route) || in_array($handle, ['', '0'], true)) {
            return false;
        }

        return $this->profiles->byHandle($handle) !== null;
    }

    /**
     * A webmention to a profile means someone mentioned the account. The mention is accepted and NOT stored (a profile has nowhere to keep one): the
     * answer is true for an account that exists, and a mention whose source did not answer 200 (a deleted page, say) is accepted with nothing done. False
     * for an address that names no account. (What the legacy page worked out of the source, a title and the mention's content and author, was never used
     * and is not worked out.)
     */
    public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool
    {
        if (!$this->receivesFor($route)) {
            return false;
        }
        ($this->logger)()->info('received user mention from ' . self::line($mention->source) . ' to ' . self::line($mention->target));
        if ($arguments === []) {
            ($this->logger)()->debug('could not process user mention, no pagehandler arguments');

            return false;
        }

        $handle = (string) $arguments[0];
        $user = $this->profiles->byHandle($handle);
        if ($user === null) {
            ($this->logger)()->debug('could not process user mention, no user for handle ' . self::line($handle));

            return false;
        }
        ($this->logger)()->debug('found target user ' . $this->profiles->handle($user));

        // if this is anything other than a normal mention (e.g. a delete), accept the wm, but do nothing
        return true;
    }

    private function userOf(PageContext $page): ?\Idno\Entities\User
    {
        $handle = $page->arguments()[0] ?? '';

        // (the legacy page asked `empty()` of the argument: '0' is no handle)
        return !in_array($handle, ['', '0'], true) ? $this->profiles->byHandle($handle) : null;
    }

    /**
     * What a sender supplied, for a log line: no control characters, and no more than 200 bytes.
     */
    private static function line(string $text): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text), 0, 200);
    }
}
