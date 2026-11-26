<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink does with a webmention, recorded from the legacy page, on a site of the test's own (a webmention writes an annotation).
 *
 * The endpoint (`/webmention/`) fetches the source with the client that refuses private networks, and the rig's own address is one, so no webmention
 * gets past the endpoint over HTTP: the endpoint's answer for each target is recorded over HTTP (`*-http`), and what the page does once the endpoint has
 * fetched a source that links to its target is recorded by handing the page the mention in the site, as the endpoint does: the permalink's page with the
 * last segment of the target as its argument (the router's choice), `setPermalink()`, `webmentionContent()` (`result`: what it returned, and `stored`: the annotations the post holds afterwards, with the time left out).
 * The answers are `Tests/Characterisation/fixtures/answers/permalink-webmentions/`.
 */
final class PermalinkWebmentionsTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'permalink-webmentions';

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /**
     * What the page makes of a webmention to a target path from a source: an annotation of the kind the source's markup says.
     *
     * @return array{result: bool, stored: mixed}
     */
    private function mention(string $targetPath, string $sourceKind, ?string $storedId): array
    {
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $target = $site->config()->getURL() . ltrim(%TARGET%, '/');
        $kind = %KIND%;
        $source = $kind === 'self' ? $target : 'https://remote.example/reply-to-the-post';
        $content = '<!DOCTYPE html><html><body class="h-entry"><a class="u-in-reply-to" href="' . $target . '">in reply to</a>'
            . '<span class="p-name e-content">A reply from another site.</span><a class="u-url" href="' . $source . '">permalink</a>'
            . '<a class="p-author h-card" href="https://remote.example/">Remote Author</a></body></html>';
        $response = ['response' => 200, 'content' => $content];
        $parsed = \Idno\Core\Webmention::parseContent($content, $source);
        $page = new \Idno\Pages\Entity\View();
        $page->arguments = [basename(rtrim(%TARGET%, '/'))];
        $page->setPermalink();
        $result = $page->webmentionContent($source, $target, $response, $parsed);
        $stored = null;
        if (%ID% !== null) {
            $record = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById('entities', %ID%);
            $stored = $record['annotations'] ?? null;
            if (is_array($stored)) {
                array_walk_recursive($stored, static function (&$value, $key) { if ($key === 'time') { $value = '{time}'; } });
            }
        }

        return ['result' => (bool) $result, 'stored' => $stored];
        PHP;
        $answer = $this->runInSite(str_replace(['%TARGET%', '%KIND%', '%ID%'], [$this->literal($targetPath), $this->literal($sourceKind), $storedId === null ? 'null' : $this->literal($storedId)], $code));
        $this->assertIsArray($answer);

        return $answer;
    }

    private function recordMention(string $case, array $answer): void
    {
        // an annotation's address ends in a random id
        $stored = (string) preg_replace('~/annotations/[0-9a-f]{32}~', '/annotations/{id}', (string) json_encode($answer['stored'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $body = 'result: ' . json_encode($answer['result']) . "\nstored: " . $stored . "\n";
        $this->assertRecordedAnswer(self::GROUP, $case, new SiteResponse($answer['result'] === true ? 202 : 400, ['Content-Type' => 'text/plain'], $body));
    }

    /** The scenario writes annotations, so a capture that is verified records each case on a site of its own: this is the first. */
    public function testWhatThePageDoesWithAWebmentionToEachKindOfPost(): void
    {
        $this->mentions();
    }

    /** The same scenario on another site (the second recording of each case in a verified capture). */
    public function testTheSameMentionsOnAnotherSiteAreAnsweredTheSame(): void
    {
        $this->mentions();
    }

    private function mentions(): void
    {
        $paths = $this->seedPermalinkContent();
        $ids = $paths['ids'];
        $this->recordMention('public-post', $this->mention($paths['bob-public'], 'reply', $ids['bob-public']));
        $this->recordMention('public-post-again', $this->mention($paths['bob-public'], 'reply', $ids['bob-public']));
        $this->recordMention('self-source', $this->mention($paths['bob-public'], 'self', $ids['bob-public']));
        $this->recordMention('private-post', $this->mention($paths['bob-owner'], 'reply', '00000000000040008000000000000043'));
        $this->recordMention('members-only-post', $this->mention($paths['bob-site'], 'reply', '00000000000040008000000000000042'));
        $this->recordMention('draft-post', $this->mention($paths['bob-draft'], 'reply', '00000000000040008000000000000044'));
        $this->recordMention('missing-post', $this->mention('2026/no-such-slug-here', 'reply', null));
        $this->recordMention('deleted-post', $this->mention($paths['deleted'], 'reply', null));
        $this->recordMention('view-address', $this->mention('view/' . $ids['view-note'], 'reply', $ids['view-note']));
    }

    /** What the endpoint answers to a mention of each kind of target, from a source it cannot fetch. */
    public function testWhatTheEndpointAnswersForEachKindOfTarget(): void
    {
        $paths = $this->seedPermalinkContent();
        $source = 'https://remote.invalid/reply';
        $cases = [
            'public-post-http' => $paths['bob-public'],
            'private-post-http' => $paths['bob-owner'],
            'draft-post-http' => $paths['bob-draft'],
            'missing-post-http' => '2026/no-such-slug-here',
            'deleted-post-http' => $paths['deleted'],
            'view-address-http' => 'view/' . $paths['ids']['view-note'],
            'markdown-address-http' => $paths['bob-public'] . '.md',
        ];
        foreach ($cases as $case => $target) {
            $this->record($case, 'POST', 'webmention/', [], 'visitor', ['source' => $source, 'target' => $this->baseUrl() . $target]);
        }
    }
}
