<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Templating\TemplateTranslation;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webmention endpoint (`/webmention/`): its page, and the receiver other servers post to (W3C Webmention). The sender is anonymous, and the request
 * acts as nobody: nothing a mention does depends on who made the request.
 *
 * A mention is two addresses, `source` and `target`, in a form-encoded body. In order (a refusal is a 400 with a JSON body `{"error": ..., "error_text": ...}`
 * and nothing after it is done; the source is fetched only when every check before the fetch has passed):
 *
 * 1. both are text, present, different, of at most `MAX_ADDRESS` bytes and without white space or a control character, or `unknown_error`;
 * 2. the target, less its fragment, is a page of this site that takes mentions (`WebmentionTargets::resolve()`: on the site's own host, found as a GET of it
 *    is routed, with a service or a legacy page to take the mention), or `target_not_found`;
 * 3. a source of this site is not the target's own page, or `target_not_supported` ("a page can't webmention itself");
 * 4. the site may fetch the source (an http or https address that resolves to a public one), or `source_not_allowed`;
 * 5. the source is fetched through the client that refuses private networks on every hop, or `source_not_found`;
 * 6. the source holds the target (or answered 410: a source that has gone is how a mention is withdrawn), or `no_link_found`;
 * 7. the page takes the mention, or `source_not_supported`: a page a controller serves gives it to the service that receives its mentions (none, or one that
 *    takes no mention for the route, is `target_not_found`), a legacy page (a plugin's) receives it as it always did. 202 and no body when it does.
 */
final class WebmentionEndpoint
{
    /** The longest address a mention may name, in bytes. */
    public const MAX_ADDRESS = 2048;

    public function __construct(
        private readonly WebmentionTargets $targets,
        private readonly WebmentionSources $sources,
        #[AutowireLocator('idno.webmention_receiver')]
        private readonly ContainerInterface $receivers,
        private readonly PageRenderer $renderer,
        private readonly TemplateTranslation $translation,
    )
    {
    }

    /**
     * The endpoint's page: a line that says what it is.
     */
    public function page(PageContext $page): Response
    {
        return $this->renderer->page($page, [
            'title' => $this->translation->translate('Webmention endpoint'),
            'body' => $this->renderer->template('pages/webmention', []),
        ]);
    }

    /**
     * Receives a mention.
     */
    public function receive(PageContext $page): Response
    {
        parse_str(trim($page->request()->getContent()), $fields);
        $source = $fields['source'] ?? null;
        $target = $fields['target'] ?? null;
        if (!is_string($source) || !is_string($target) || $source === '' || $target === '' || $source === $target) {
            return $this->refusal('unknown_error', 'Not all the required webmention variables were set.');
        }
        if (strlen($source) > self::MAX_ADDRESS || strlen($target) > self::MAX_ADDRESS || preg_match('/[\x00-\x20\x7f]/', $source . $target) === 1) {
            return $this->refusal('unknown_error', 'The source and the target must each be one address of at most ' . self::MAX_ADDRESS . ' characters.');
        }
        $this->sources->log('debug', 'received webmention from ' . self::line($source) . ' to ' . self::line($target));

        // The fragment says where in the page, which no page of this site takes into account
        if (strpos($target, '#') !== false) {
            $target = explode('#', $target, 2)[0];
        }

        $resolved = $this->targets->resolve($target);
        if ($resolved === null) {
            $this->sources->log('error', 'Could not find handler for ' . self::line($target));

            return $this->refusal('target_not_found', 'The target page ' . $target . ' does not exist.');
        }
        // A page of the site that takes mentions, and a target that names something there (a visitor may see): a mention of an address that names nothing, or
        // something a visitor may not see, is refused as one answer, before the source is looked at (a private post, a draft, a deleted post and a missing one are the same)
        $receiver = $resolved->isLegacy() ? null : ($this->receivers->has($resolved->page) ? $this->receivers->get($resolved->page) : null);
        if (!$resolved->isLegacy() && (!$receiver instanceof ReceivesWebmentions || !$receiver->receivesFor($resolved->route) || !$receiver->accepts($resolved->route, $resolved->arguments))) {
            $this->sources->log('error', 'Could not find handler for ' . self::line($target));

            return $this->refusal('target_not_found', 'The target page ' . $target . ' does not exist.');
        }
        if ($this->mentionsItself($source, $target)) {
            return $this->refusal('target_not_supported', 'A page can\'t webmention itself.');
        }
        if (!$this->sources->isSafe($source)) {
            return $this->refusal('source_not_allowed', 'The source URL is not allowed.');
        }
        $this->sources->log('debug', 'webmention is ok with target page ' . self::line($resolved->page));

        $answer = $this->sources->fetch($source);
        if ($answer === false) {
            $this->sources->log('warning', 'No content from ' . self::line($source));

            return $this->refusal('source_not_found', 'The source content for ' . $source . ' could not be obtained.');
        }
        $content = (string) ($answer['content'] ?? '');
        if (substr_count($content, $target) === 0 && ($answer['response'] ?? null) != 410) {
            $this->sources->log('warning', 'No link from ' . self::line($source) . ' to ' . self::line($target));

            return $this->refusal('no_link_found', 'The source URI does not contain a link to the target URI.');
        }

        $mention = new ReceivedWebmention($source, $target, $answer, $this->sources->parse($content, $source));
        $taken = $receiver === null ? $resolved->receiveLegacy($mention) : $receiver->receiveWebmention($resolved->route, $resolved->arguments, $mention);

        return $taken ? new Response('', 202, ['X-Content-Type-Options' => 'nosniff']) : $this->refusal('source_not_supported', 'Could not interpret source as a comment.');
    }

    /**
     * Whether a source on this site is the page it mentions (the paths are the same, a trailing slash aside).
     */
    private function mentionsItself(string $source, string $target): bool
    {
        if (!$this->sources->isLocal($source)) {
            return false;
        }
        $sourcePath = parse_url($source, PHP_URL_PATH);
        $targetPath = parse_url($target, PHP_URL_PATH);

        return is_string($sourcePath) && $sourcePath !== '' && is_string($targetPath) && $targetPath !== '' && rtrim($sourcePath, '/') === rtrim($targetPath, '/');
    }

    private function refusal(string $error, string $text): Response
    {
        // (what the sender wrote is in the text: nothing of it is markup, in a browser that is made to show the answer)
        return new Response((string) json_encode(['error' => $error, 'error_text' => $text], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 400, ['Content-Type' => 'application/json', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * What a sender supplied, for a log line: no control characters, and no more than 200 bytes.
     */
    private static function line(string $text): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text), 0, 200);
    }
}
