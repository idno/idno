<?php

declare(strict_types=1);

namespace IdnoPlugins\ActivityPub\Tests;

use Tests\Characterisation\PermalinkSiteCase;
use Tests\Characterisation\SiteResponse;

/**
 * A quote of a post of this site (FEP-044f), as the servers involved see it.
 *
 * The quoting server sends a QuoteRequest and gets an Accept whose "result" is the address of a QuoteAuthorization stamp; it puts that address in the quoting
 * post. Every other server that receives the quoting post fetches the quoted post and the stamp, and shows the quote only if they agree. These tests read the
 * documents as such a server does, with the checks of Mastodon's ActivityPub::VerifyQuoteService and JsonLdHelper#fetch_resource:
 *
 * - the stamp's address must be one that Mastodon's URL normalisation leaves alone, because it requests the normalised address and drops the answer unless
 *   that address, the address it was given and the document's id are the same string;
 * - the answer is 200 with an ActivityPub content type;
 * - the stamp is a QuoteAuthorization on the host of its attributedTo, which is the author of the quoted post; its interactingObject is the quoting post and
 *   its interactionTarget the id of the quoted post as fetched.
 *
 * The posts are the permalink recordings' site: Bob's public note, his draft and his post private to him, a post deleted, and a member.
 */
final class QuoteStampTest extends PermalinkSiteCase
{
    protected const SHARED_SITE = true;

    private const ACCEPT = 'application/activity+json, application/ld+json';

    private const QUOTING_ACTOR = 'https://mastodon.example/users/quoter';

    private const QUOTING_POST = 'https://mastodon.example/users/quoter/statuses/115000000000000001';

    protected function seedSharedSite(): void
    {
        $this->seedPermalinkContent();
    }

    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
    }

    /** The uuid of a seeded post by the name `seedPermalinkContent()` gives its path. */
    private function uuidOf(string $key): string
    {
        $code = <<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        $path = %PATH%;

        return \Idno\Common\Entity::getBySlug(rawurldecode(basename($path)))->getUUID();
        PHP;

        return (string) $this->runInSite(str_replace('%PATH%', $this->literal($this->target($key)), $code));
    }

    /**
     * What the handler answers to a request to quote `$quoted`, in the site: null, or the Accept as it would be sent (as JSON).
     *
     * @param string|array<string, mixed> $instrument
     * @return array<string, mixed>|null
     */
    private function accept(string $quoted, string|array $instrument = self::QUOTING_POST): ?array
    {
        $code = <<<'PHP'
        $request = [
            '@context' => ['https://www.w3.org/ns/activitystreams', ['QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest']],
            'id' => %POST% . '/quote', 'type' => 'QuoteRequest', 'actor' => %ACTOR%, 'object' => %QUOTED%, 'instrument' => json_decode(%INSTRUMENT%, true),
        ];
        $accepted = \IdnoPlugins\ActivityPub\ActivityHandler::acceptQuoteRequest($request);

        return $accepted === null ? null : json_decode(json_encode($accepted['accept']), true);
        PHP;
        $answer = $this->runInSite(str_replace(
            ['%POST%', '%ACTOR%', '%QUOTED%', '%INSTRUMENT%'],
            [$this->literal(self::QUOTING_POST), $this->literal(self::QUOTING_ACTOR), $this->literal($quoted), $this->literal(json_encode($instrument))],
            $code
        ));

        return $answer;
    }

    private function stampUrl(string $method, string $target, string $object): string
    {
        $code = '$s = \IdnoPlugins\ActivityPub\ActivityBuilder::' . $method . '(%T%, %O%); return $s;';

        return (string) $this->runInSite(str_replace(['%T%', '%O%'], [$this->literal($target), $this->literal($object)], $code));
    }

    private function fetch(string $url): SiteResponse
    {
        $path = (string) preg_replace('#^https?://[^/]+#', '', $url);

        return $this->site()->request('GET', $path, ['Accept' => self::ACCEPT]);
    }

    public function testStampAddressHasNothingAUrlLibraryWouldRewrite(): void
    {
        $url = $this->stampUrl('buildQuoteStampUrl', $this->uuidOf('bob-public'), self::QUOTING_POST . '?a=b&c=d e');
        $base = $this->baseUrl() . 'activitypub/quote-stamp/';

        $this->assertStringStartsWith($base, $url);
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[0-9a-f]{64}$#', substr($url, strlen($base)), 'no query string, no percent-encoding, no padding');
    }

    public function testStampAddressCannotBeMadeWithoutTheSiteSecret(): void
    {
        $url = $this->stampUrl('buildQuoteStampUrl', $this->uuidOf('bob-public'), self::QUOTING_POST);
        $this->assertSame(200, $this->fetch($url)->status);

        $parts = explode('/', $url);
        $parts[count($parts) - 2] = rtrim(strtr(base64_encode('https://mastodon.example/users/other/statuses/2'), '+/', '-_'), '=');
        $forged = $this->fetch(implode('/', $parts));
        $this->assertSame(404, $forged->status);
        $this->assertStringNotContainsString('QuoteAuthorization', $forged->body);

        $parts = explode('/', $url);
        $parts[count($parts) - 1] = str_repeat('0', 64);
        $this->assertSame(404, $this->fetch(implode('/', $parts))->status);
    }

    public function testQuoteRequestIsAcceptedWithAStampAThirdServerCanVerify(): void
    {
        $uuid = $this->uuidOf('bob-public');
        $accept = $this->accept($uuid);
        $this->assertIsArray($accept, 'a request to quote a public post is accepted');

        $actorId = $this->baseUrl() . 'actor/bob';
        $this->assertSame('Accept', $accept['type']);
        $this->assertSame($actorId, $accept['actor'], 'Mastodon looks the request up by the author of the quoted post');
        $this->assertSame(self::QUOTING_POST . '/quote', $accept['object']['id']);
        $this->assertSame('QuoteRequest', $accept['object']['type']);
        $this->assertSame(self::QUOTING_ACTOR, $accept['object']['actor']);
        $this->assertSame($uuid, $accept['object']['object']);
        $this->assertSame(self::QUOTING_POST, $accept['object']['instrument']);
        $this->assertSame(self::QUOTING_ACTOR, $accept['to']);
        $stampUrl = $accept['result'];
        $this->assertSame(parse_url($actorId, PHP_URL_HOST), parse_url($stampUrl, PHP_URL_HOST), 'Mastodon refuses a stamp on another host than the actor');

        $stamp = $this->fetch($stampUrl);
        $this->assertSame(200, $stamp->status);
        $this->assertSame('application/activity+json', trim(explode(';', (string) $stamp->header('Content-Type'))[0]));
        $document = $stamp->json();
        $this->assertSame($stampUrl, $document['id'], 'Mastodon drops a document whose id is not the address it asked for');
        $this->assertContains('https://www.w3.org/ns/activitystreams', $document['@context']);
        $this->assertSame('QuoteAuthorization', $document['type']);
        $this->assertSame(self::QUOTING_POST, $document['interactingObject']);
        $this->assertSame($uuid, $document['interactionTarget']);
        $this->assertSame($actorId, $document['attributedTo']);

        // the quoted post, fetched by the id it is known by, says the same
        $quoted = $this->fetch('/view/' . basename($uuid));
        $this->assertSame(200, $quoted->status);
        $this->assertSame($uuid, $quoted->json()['id']);
        $this->assertSame($quoted->json()['attributedTo'], $document['attributedTo']);
    }

    public function testQuotingPostSentWholeInTheRequestIsNotSentBack(): void
    {
        $note = ['id' => self::QUOTING_POST, 'type' => 'Note', 'attributedTo' => self::QUOTING_ACTOR, 'content' => '<p>The quoting post</p>', 'someVendorTerm' => ['x' => 'y']];
        $uuid = $this->uuidOf('bob-public');
        $accept = $this->accept($uuid, $note);

        $this->assertSame(self::QUOTING_POST, $accept['object']['instrument']);
        $this->assertStringNotContainsString('someVendorTerm', (string) json_encode($accept));
        $this->assertSame($this->stampUrl('buildQuoteStampUrl', $uuid, self::QUOTING_POST), $accept['result']);
    }

    public function testQuoteRequestForAPostThatIsNotFederatedIsNotAccepted(): void
    {
        $this->assertNull($this->accept($this->uuidOf('bob-draft')), 'a draft');
        $this->assertNull($this->accept($this->uuidOf('bob-owner')), 'a post private to its author');
        $this->assertNull($this->accept($this->uuidOf('bob-site')), 'a post for the members');
        $this->assertNull($this->accept($this->baseUrl() . 'view/nosuchpost'), 'a post that is not there');
        $this->assertNull($this->accept($this->uuidOf('bob-public'), 'javascript:alert(1)'), 'the quoting post must have an http address');
    }

    public function testNoStampIsServedForAPostThatIsNotFederated(): void
    {
        foreach (['bob-draft', 'bob-owner', 'bob-site'] as $key) {
            $uuid = $this->uuidOf($key);
            foreach (['buildQuoteStampUrl', 'buildLegacyQuoteStampUrl'] as $method) {
                $response = $this->fetch($this->stampUrl($method, $uuid, self::QUOTING_POST));
                $this->assertSame(404, $response->status, $key . ' ' . $method);
                $this->assertStringNotContainsString('QuoteAuthorization', $response->body);
                $this->assertStringNotContainsString('attributedTo', $response->body);
            }
        }
    }

    public function testNoStampIsServedWhenTheQuotedPostIsNamedByItsSlug(): void
    {
        $this->assertSame(404, $this->fetch($this->stampUrl('buildQuoteStampUrl', $this->baseUrl() . ltrim($this->target('bob-public'), '/'), self::QUOTING_POST))->status);
    }

    /** Stamps given out before the address changed are still asked for, answered with the id they were given out with. */
    public function testStampWithAnAddressOfTheOldFormIsStillServed(): void
    {
        $uuid = $this->uuidOf('bob-public');
        $url = $this->stampUrl('buildLegacyQuoteStampUrl', $uuid, self::QUOTING_POST);
        $this->assertStringContainsString('?target=http', $url);

        foreach ([$url, str_replace(['%3A', '%2F'], [':', '/'], $url)] as $address) {
            $stamp = $this->fetch($address);
            $this->assertSame(200, $stamp->status);
            $document = $stamp->json();
            $this->assertSame($url, $document['id']);
            $this->assertSame('QuoteAuthorization', $document['type']);
            $this->assertSame($uuid, $document['interactionTarget']);
            $this->assertSame(self::QUOTING_POST, $document['interactingObject']);
            $this->assertSame($this->baseUrl() . 'actor/bob', $document['attributedTo']);
        }
        $this->assertSame(404, $this->fetch($url . '0')->status, 'a changed signature is refused');
    }

    public function testStampTakesNoPost(): void
    {
        $url = $this->stampUrl('buildQuoteStampUrl', $this->uuidOf('bob-public'), self::QUOTING_POST);
        $answer = $this->site()->request('POST', (string) preg_replace('#^https?://[^/]+#', '', $url), [], 'x=y');
        $this->assertNotSame(200, $answer->status);
        $this->assertStringNotContainsString('QuoteAuthorization', $answer->body);
    }
}
