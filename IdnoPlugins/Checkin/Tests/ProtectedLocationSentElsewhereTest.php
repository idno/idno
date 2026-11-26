<?php

declare(strict_types=1);

namespace IdnoPlugins\Checkin\Tests;

use Tests\Acceptance\Browser;
use Tests\Queue\AsyncSiteTestCase;

/**
 * What a check-in with a protected location sends away is put together while its owner is signed in, so it is made from what a visitor may be told:
 * the webmention text, the ActivityPub object and the title that is stored (which search looks in).
 */
final class ProtectedLocationSentElsewhereTest extends AsyncSiteTestCase
{
    private const PLACE = 'Quagga Hollow';

    /** @return array{cookie: string, path: string} */
    private function protectedCheckin(string $link): array
    {
        $cookie = $this->registerUser('alice');
        $answer = (new Browser($this->site()))->submit('checkin/edit?body=x', 'checkin/edit/', [
            'body' => 'Here, near ' . $link, 'placename' => self::PLACE, 'user_address' => '17 Okapirow', 'lat' => '51.752345', 'long' => '-1.257712',
            'anonymity' => 'Yes', 'access' => 'PUBLIC',
        ], $cookie);
        $this->assertSame(302, $answer->status, substr($answer->body, 0, 500) . $this->serverLog());

        return ['cookie' => $cookie, 'path' => (string) $answer->header('Location')];
    }

    public function testTheWebmentionTextDoesNotNameThePlace(): void
    {
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;
        $post = $this->protectedCheckin($target);

        $events = array_values(array_filter($this->queuedEvents(), static fn (array $event): bool => $event['event'] === 'webmention/sendall'));
        $this->assertCount(1, $events);
        $this->assertStringContainsString($target, $events[0]['data']['text']);
        $this->assertStringNotContainsStringIgnoringCase('Quagga', $events[0]['data']['text']);
        $this->assertStringContainsString('somewhere near', $events[0]['data']['text']);
        $this->assertStringNotContainsStringIgnoringCase('Quagga', $post['path']);
    }

    public function testWhatIsStoredAndSentWhileTheOwnerIsSignedInDoesNotNameThePlace(): void
    {
        $this->protectedCheckin($this->baseUrl() . 'nowhere');

        $answer = $this->runInSite(<<<'PHP'
            \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
            $checkin = \Idno\Core\Idno::site()->db()->getObjects(\IdnoPlugins\Checkin\Checkin::class, [], [], 1)[0];
            $owner = \Idno\Entities\User::getByHandle('alice');
            \Idno\Core\Idno::site()->session()->logUserOn($owner);
            $out = [
                'stored' => (string) $checkin->title,
                'title' => $checkin->getTitle(),
                'publicTitle' => $checkin->getPublicTitle(),
                'object' => \IdnoPlugins\ActivityPub\ActivityBuilder::buildObject($checkin),
                'uuid' => $checkin->getUUID(),
            ];
            \Idno\Core\Idno::site()->session()->logUserOff();
            return $out;
        PHP);
        $this->assertStringContainsString('Quagga', $answer['title'], 'the owner reads the place');
        $this->assertStringNotContainsStringIgnoringCase('Quagga', $answer['publicTitle']);
        $this->assertStringNotContainsStringIgnoringCase('Quagga', $answer['stored']);
        $this->assertStringNotContainsStringIgnoringCase('Quagga', $answer['uuid']);
        $this->assertStringNotContainsString('Quagga', (string) json_encode($answer['object']));
        $this->assertStringNotContainsString('Okapirow', (string) json_encode($answer['object']));
        $this->assertArrayNotHasKey('location', $answer['object']);
    }
}
