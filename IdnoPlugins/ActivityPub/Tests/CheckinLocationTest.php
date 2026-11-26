<?php

declare(strict_types=1);

namespace IdnoPlugins\ActivityPub\Tests;

use Tests\Queue\AsyncSiteTestCase;

/**
 * A check-in whose place is to be kept vague is drawn to a visitor who is not signed in with an inexact position and no place name. Other servers are never
 * signed in, so what the site tells them about such a check-in (the post fetched at its address and its `/view/<id>` address, the outbox, and the `Create`
 * delivered to a follower) holds no position and no place name. A check-in that is not anonymous still carries its place.
 */
final class CheckinLocationTest extends AsyncSiteTestCase
{
    private const ACCEPT = 'application/activity+json, application/ld+json';

    private const PLACE = 'The Hidden Hollow';

    private const LAT = '51.7520123';

    private const LONG = '-1.2577456';

    /** @return array{uuid: string, url: string} */
    private function checkin(string $anonymity, string $placename): array
    {
        $code = <<<'PHP'
        $owner = \Idno\Entities\User::getByHandle('alice');
        $entity = new \IdnoPlugins\Checkin\Checkin();
        $entity->placename = %PLACE%;
        $entity->lat = (float) %LAT%;
        $entity->long = (float) %LONG%;
        $entity->address = '1 Secret Lane';
        $entity->anonymity = %ANON%;
        $entity->body = 'Somewhere nice';
        $entity->title = 'Checked into ' . %PLACE%;
        $entity->setOwner($owner);
        $entity->setAccess('PUBLIC');
        $entity->setPublishStatus('published');
        $entity->created = time();
        $entity->setSlugResilient('somewhere nice ' . %PLACE%);
        if (!$entity->save(true)) {
            throw new \RuntimeException('not saved');
        }

        return ['uuid' => $entity->getUUID(), 'url' => $entity->getURL()];
        PHP;
        $answer = $this->runInSite(str_replace(['%PLACE%', '%LAT%', '%LONG%', '%ANON%'], [$this->literal($placename), $this->literal(self::LAT), $this->literal(self::LONG), $this->literal($anonymity)], $code));
        $this->assertIsArray($answer);

        return $answer;
    }

    private function assertNothingOfThePlace(string $body, string $what): void
    {
        $this->assertStringNotContainsString(self::PLACE, $body, $what . ': the place name');
        $this->assertStringNotContainsString('1 Secret Lane', $body, $what . ': the address');
        $this->assertStringNotContainsString('51.752', $body, $what . ': the latitude');
        $this->assertStringNotContainsString('1.2577', $body, $what . ': the longitude');
        $this->assertStringNotContainsString('"location"', $body, $what);
    }

    public function testWhatOtherServersAreToldOfAnAnonymousCheckinHoldsNoPlace(): void
    {
        $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);
        $post = $this->checkin('Yes', self::PLACE);
        $path = '/' . ltrim(substr($post['url'], strlen($this->baseUrl())), '/');
        $id = basename($post['uuid']);

        foreach ([$path, '/view/' . $id] as $address) {
            $answer = $this->site()->request('GET', $address, ['Accept' => self::ACCEPT]);
            $this->assertSame(200, $answer->status, $address);
            $this->assertSame('Note', $answer->json()['type'], $address);
            $this->assertNothingOfThePlace($answer->body, 'the post at ' . $address);
        }

        $outbox = $this->site()->request('GET', '/actor/alice/outbox?page=1', ['Accept' => self::ACCEPT]);
        $this->assertSame(200, $outbox->status);
        $this->assertStringContainsString($post['uuid'], str_replace('\\/', '/', $outbox->body), 'the check-in is in the outbox');
        $this->assertNothingOfThePlace($outbox->body, 'the outbox');

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);
        $requests = $this->sink($sink);
        $this->assertCount(1, $requests, 'one delivery' . $this->serverLog());
        $delivered = (string) base64_decode($requests[0]['body'], true);
        $this->assertSame('Create', json_decode($delivered, true)['type']);
        $this->assertNothingOfThePlace($delivered, 'the delivered Create');
    }

    public function testACheckinThatIsNotAnonymousCarriesItsPlace(): void
    {
        $this->registerUser('alice');
        $post = $this->checkin('', self::PLACE);

        $answer = $this->site()->request('GET', '/view/' . basename($post['uuid']), ['Accept' => self::ACCEPT]);
        $this->assertSame(200, $answer->status);
        $location = $answer->json()['location'];
        $this->assertSame('Place', $location['type']);
        $this->assertSame(self::PLACE, $location['name']);
        $this->assertEqualsWithDelta((float) self::LAT, $location['latitude'], 0.0001);
    }
}
