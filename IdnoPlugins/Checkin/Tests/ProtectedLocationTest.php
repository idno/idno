<?php

declare(strict_types=1);

namespace IdnoPlugins\Checkin\Tests;

use Tests\Acceptance\Browser;
use Tests\Characterisation\SiteResponse;
use Tests\Support\PrivateSiteTestCase;

/**
 * A member can protect the location of a check-in for 24 hours: until then visitors who are not signed in are told "somewhere near..." and shown
 * a position that is off by up to about a kilometre. The place must then reach them by no other way either: not in the post's address, not in any
 * form of the page (HTML with its head, RSS, JSON Feed, JSON, JSONP, KML, Markdown, ActivityPub), not in a stream or a profile, not in search.
 * Members who are signed in, and the owner, are shown the place. The site is shared by the tests (they only read it): the members post the
 * check-ins over HTTP in seedSharedSite(), as the form does.
 */
final class ProtectedLocationTest extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    private const LATITUDE = '51.752345';

    private const LONGITUDE = '-1.257712';

    private const OPEN_LATITUDE = '48.858371';

    private const OPEN_LONGITUDE = '2.294482';

    /** @var array<string, string> the cookies of the people who read: owner, other (a member) and admin */
    private static array $cookies = [];

    /** @var array<string, string> the check-ins: protected, second, open, older (path of the permalink) */
    private static array $paths = [];

    private static string $place = '';

    private static string $street = '';

    private static string $openPlace = '';

    /** @var array<string, string> what each check-in's author wrote, by path */
    private static array $bodies = [];

    private static string $ownerHandle = 'bobby';

    protected function seedSharedSite(): void
    {
        self::$place = 'Quagga' . substr(md5((string) mt_rand()), 0, 10);
        self::$street = 'Okapirow' . substr(md5((string) mt_rand()), 0, 10);
        self::$openPlace = 'Zebra' . substr(md5((string) mt_rand()), 0, 10);

        self::$cookies['admin'] = $this->registerUser('alice');
        $this->addUser(self::$ownerHandle);
        $this->addUser('carol');
        self::$cookies['owner'] = $this->loginCookie(self::$ownerHandle, 'correct-horse-battery-9');
        self::$cookies['other'] = $this->loginCookie('carol', 'correct-horse-battery-9');

        self::$paths['protected'] = $this->checkIn([]);
        self::$paths['second'] = $this->checkIn(['placename' => 'Another ' . self::$place]);
        self::$paths['open'] = $this->checkIn(['placename' => self::$openPlace, 'anonymity' => ''] + $this->openPlace());
        self::$paths['older'] = $this->checkIn(['placename' => 'Older ' . self::$openPlace, 'created' => date('c', time() - 86400 - 600)] + $this->openPlace());
    }

    /**
     * @return array<string, string>
     */
    private function openPlace(): array
    {
        return ['user_address' => '9 Open Street', 'lat' => self::OPEN_LATITUDE, 'long' => self::OPEN_LONGITUDE];
    }

    /** Checks in as the owner with the form, over HTTP; returns the permalink's path. */
    private function checkIn(array $fields): string
    {
        $fields += [
            'body' => 'What happened there ' . substr(md5((string) mt_rand()), 0, 8),
            'placename' => self::$place,
            'user_address' => '17 ' . self::$street,
            'lat' => self::LATITUDE,
            'long' => self::LONGITUDE,
            'anonymity' => 'Yes',
            'access' => 'PUBLIC',
        ];
        $answer = (new Browser($this->site()))->submit('checkin/edit?body=x', 'checkin/edit/', $fields, self::$cookies['owner']);
        $this->assertSame(302, $answer->status, substr($answer->body, 0, 600) . $this->serverLog());
        $location = (string) $answer->header('Location');
        $this->assertNotSame('', $location);
        $path = '/' . ltrim(substr($location, strlen($this->baseUrl())), '/');
        self::$bodies[$path] = $fields['body'];

        return $path;
    }

    private function get(string $path, ?string $cookie = null, array $headers = []): SiteResponse
    {
        return $this->site()->request('GET', ltrim($path, '/'), ($cookie !== null ? ['Cookie' => $cookie] : []) + $headers);
    }

    private function assertHidesThePlace(string $text, string $what): void
    {
        $this->assertStringNotContainsStringIgnoringCase(self::$place, $text, $what . ' names the place');
        $this->assertStringNotContainsStringIgnoringCase(self::$street, $text, $what . ' gives the address');
        $this->assertStringNotContainsString(self::LATITUDE, $text, $what . ' gives the exact latitude');
        $this->assertStringNotContainsString(ltrim(self::LONGITUDE, '-'), $text, $what . ' gives the exact longitude');
    }

    /** @return array<string, array{0: string}> */
    public static function forms(): array
    {
        return [
            'the page' => [''],
            'the page as RSS' => ['?_t=rss'],
            'the page as JSON Feed' => ['?_t=jsonfeed'],
            'the page as JSON' => ['?_t=json'],
            'the page as JSONP' => ['?_t=jsonp'],
            'the page as KML' => ['?_t=kml'],
            'the page as Markdown' => ['?_t=markdown'],
            'the page as Markdown by Accept' => ['@Accept: text/markdown'],
            'the page as Markdown by its suffix' => ['.md'],
            'the page as ActivityPub' => ['@Accept: application/activity+json'],
        ];
    }

    /** @return list<array{0: string, 1: array<string, string>}> */
    private function request(string $permalink, string $form): array
    {
        if (str_starts_with($form, '@')) {
            [$name, $value] = explode(': ', substr($form, 1), 2);

            return [$permalink, [$name => $value]];
        }

        return [$permalink . $form, []];
    }

    /**
     * @dataProvider forms
     */
    public function testAVisitorIsNotToldThePlaceInAnyForm(string $form): void
    {
        [$path, $headers] = $this->request(self::$paths['protected'], $form);
        $answer = $this->get($path, null, $headers);
        $this->assertLessThan(500, $answer->status, $path);
        $this->assertSame(200, $answer->status, $path);
        $this->assertHidesThePlace($answer->body . implode("\n", $answer->lines), $path . ' as a visitor');
        if (!str_starts_with($form, '@Accept: application/activity')) {
            $this->assertStringContainsString('somewhere near', $answer->body, $path);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function streams(): array
    {
        $out = [];
        foreach (['/', '/profile/bobby', '/profile/bobby/locations', '/profile/bobby/all', '/content/checkin/'] as $stream) {
            foreach (['', '_t=rss', '_t=jsonfeed', '_t=json', '_t=kml', '_t=markdown'] as $type) {
                $out[$stream . ' ' . $type] = [$stream . ($type === '' ? '' : (str_contains($stream, '?') ? '&' : '?') . $type)];
            }
        }

        return $out;
    }

    /**
     * @dataProvider streams
     */
    public function testAVisitorIsNotToldThePlaceInAStream(string $path): void
    {
        $answer = $this->get($path);
        $this->assertLessThan(500, $answer->status, $path);
        $this->assertHidesThePlace($answer->body . implode("\n", $answer->lines), $path . ' as a visitor');
    }

    public function testTheStreamStillListsTheCheckin(): void
    {
        $answer = $this->get('/profile/' . self::$ownerHandle . '/locations?_t=jsonfeed');
        $this->assertSame(200, $answer->status);
        $this->assertStringContainsString(self::$paths['protected'], str_replace('\\/', '/', $answer->body));
    }

    public function testTheAddressOfAProtectedCheckinDoesNotNameThePlace(): void
    {
        foreach (['protected', 'second'] as $name) {
            $this->assertStringNotContainsStringIgnoringCase(self::$place, self::$paths[$name], $name);
            $this->assertStringNotContainsStringIgnoringCase('another', self::$paths[$name], $name);
        }
        $this->assertNotSame(self::$paths['protected'], self::$paths['second'], 'each has an address of its own');
        $this->assertSame(200, $this->get(self::$paths['second'])->status);
    }

    public function testTheAddressOfACheckinThatIsNotProtectedStillNamesThePlace(): void
    {
        $this->assertStringContainsStringIgnoringCase(self::$openPlace, self::$paths['open']);
        $this->assertStringContainsStringIgnoringCase('Older-' . self::$openPlace, self::$paths['older']);
    }

    public function testAVisitorIsShownAPositionNearbyThatIsTheSameEveryTime(): void
    {
        $positions = [];
        for ($i = 0; $i < 3; $i++) {
            $object = $this->get(self::$paths['protected'] . '?_t=json')->json()['object'];
            $this->assertArrayNotHasKey('placeName', $object);
            $this->assertArrayNotHasKey('address', $object);
            $this->assertEqualsWithDelta((float) self::LATITUDE, (float) $object['latitude'], 0.011);
            $this->assertEqualsWithDelta((float) self::LONGITUDE, (float) $object['longitude'], 0.011);
            $this->assertNotSame(self::LATITUDE, $object['latitude']);
            $positions[$object['latitude'] . ',' . $object['longitude']] = true;
        }
        $this->assertCount(1, $positions, 'asking again gives the same position: ' . implode(' ', array_keys($positions)));
    }

    public function testTheMapAndTheMicroformatsOfAVisitorHoldNumbersAndNoPlace(): void
    {
        $page = $this->get(self::$paths['protected'])->body;
        $this->assertSame(1, preg_match('/setView\(\[(-?[0-9.]+), (-?[0-9.]+)\], 16\)/', $page, $m), 'the map is set to two numbers');
        $this->assertEqualsWithDelta((float) self::LATITUDE, (float) $m[1], 0.011);
        $this->assertEqualsWithDelta((float) self::LONGITUDE, (float) $m[2], 0.011);
        $this->assertStringContainsString('class="p-latitude" value="' . $m[1] . '"', $page);
        $this->assertStringNotContainsString('class="p-name" value=', $page, 'the place has no p-name');
        $this->assertStringNotContainsString('"address"', $page, 'the structured data holds no address');
    }

    /**
     * Search looks in the stored title of a post, which named the place.
     */
    public function testAVisitorDoesNotFindTheCheckinByItsPlace(): void
    {
        $text = trim(strip_tags(self::$bodies[self::$paths['protected']] ?? ''));
        $this->assertNotSame('', $text);
        // The search finds it by what its author wrote about it
        $this->assertStringContainsString($text, $this->get('/?q=' . urlencode($text))->body);

        // By the place alone: a full-text search for "Checked into <place>" finds every check-in by the words they all have
        foreach ([self::$place, self::$street] as $query) {
            foreach (['', '_t=json&', '_t=rss&', '_t=markdown&'] as $type) {
                $answer = $this->get('/?' . $type . 'q=' . urlencode($query));
                $this->assertLessThan(500, $answer->status);
                $this->assertStringNotContainsString($text, $answer->body, 'A search for "' . $query . '" should not find the check-in');
                $this->assertStringNotContainsString(self::$paths['protected'], str_replace('\\/', '/', $answer->body));
            }
        }
    }

    public function testAMemberWhoIsSignedInIsShownThePlaceInEveryForm(): void
    {
        foreach (['owner', 'other', 'admin'] as $who) {
            $cookie = self::$cookies[$who];
            $path = self::$paths['protected'];

            $page = $this->get($path, $cookie)->body;
            $this->assertStringContainsString('Checked into ' . self::$place, $page, $who);
            $this->assertStringContainsString('value="' . self::$place . '"', $page, $who);
            $this->assertStringContainsString(self::LATITUDE, $page, $who);
            $this->assertStringContainsString(self::LONGITUDE, $page, $who);
            $this->assertStringContainsString('setView([' . self::LATITUDE . ', ' . self::LONGITUDE . '], 16)', $page, $who);

            $object = $this->get($path . '?_t=json', $cookie)->json()['object'];
            $this->assertSame(self::$place, $object['placeName'], $who);
            $this->assertSame('17 ' . self::$street, $object['address'], $who);
            $this->assertSame(self::LATITUDE, $object['latitude'], $who);
            $this->assertSame(self::LONGITUDE, $object['longitude'], $who);

            $kml = $this->get($path . '?_t=kml', $cookie)->body;
            $this->assertStringContainsString(self::LONGITUDE . ',' . self::LATITUDE, $kml, $who);
            $this->assertStringContainsString(self::$place, $kml, $who);

            $feed = json_decode($this->get($path . '?_t=jsonfeed', $cookie)->body, true);
            $this->assertSame(self::$place, $feed['items'][0]['_meta']['placename'], $who);
            $this->assertSame(self::LATITUDE, (string) $feed['items'][0]['_meta']['latitude'], $who);

            $rss = $this->get($path . '?_t=rss', $cookie)->body;
            $this->assertStringContainsString('<geo:lat>' . self::LATITUDE . '</geo:lat>', $rss, $who);

            $markdown = $this->get($path . '?_t=markdown', $cookie)->body;
            $this->assertStringContainsString(self::$place, $markdown, $who);
            $this->assertStringContainsString(self::LATITUDE, $markdown, $who);
        }
    }

    public function testAVisitorIsToldNothingOfThePlaceInTheMarkdownFrontMatter(): void
    {
        $markdown = $this->get(self::$paths['protected'] . '?_t=markdown')->body;
        $this->assertStringStartsWith('---', $markdown);
        $this->assertStringContainsString('somewhere near', $markdown);
        $this->assertStringContainsString('Location: ', $markdown);
        $this->assertHidesThePlace($markdown, 'the Markdown form');
    }

    /**
     * The protection is for 24 hours.
     */
    public function testAfterADayAVisitorIsShownThePlace(): void
    {
        $path = self::$paths['older'];
        $page = $this->get($path)->body;
        $this->assertStringContainsString('Checked into Older ' . self::$openPlace, $page);
        $this->assertStringContainsString(self::OPEN_LATITUDE, $page);

        $object = $this->get($path . '?_t=json')->json()['object'];
        $this->assertSame('Older ' . self::$openPlace, $object['placeName']);
        $this->assertSame('9 Open Street', $object['address']);
        $this->assertSame(self::OPEN_LATITUDE, $object['latitude']);
        $this->assertStringContainsString(self::OPEN_LONGITUDE . ',' . self::OPEN_LATITUDE, $this->get($path . '?_t=kml')->body);
    }

    public function testACheckinThatIsNotProtectedIsShownToAVisitor(): void
    {
        $path = self::$paths['open'];
        $this->assertStringContainsString('Checked into ' . self::$openPlace, $this->get($path)->body);
        $object = $this->get($path . '?_t=json')->json()['object'];
        $this->assertSame(self::$openPlace, $object['placeName']);
        $this->assertSame('9 Open Street', $object['address']);
        $this->assertSame(self::OPEN_LATITUDE, $object['latitude']);
        $activity = $this->get($path, null, ['Accept' => 'application/activity+json'])->json();
        $this->assertSame(self::$openPlace, $activity['location']['name']);
    }

    public function testTheRuleOfWhatAViewerMayBeTold(): void
    {
        $answer = $this->runInSite(<<<'PHP'
            $found = \Idno\Core\Idno::site()->db()->getObjects(\IdnoPlugins\Checkin\Checkin::class, [], [], 50);
            $owner = \Idno\Entities\User::getByHandle('bobby');
            $other = \Idno\Entities\User::getByHandle('carol');
            $out = [];
            foreach ($found as $checkin) {
                if (strpos((string) $checkin->placename, 'Quagga') !== 0) {
                    continue;
                }
                $out = [
                    'protected' => $checkin->isLocationProtected(),
                    'public' => $checkin->locationFor(null),
                    'publicLocation' => $checkin->publicLocation(),
                    'owner' => $checkin->locationFor($owner),
                    'other' => $checkin->locationFor($other),
                    'stored' => $checkin->title,
                    'slug' => $checkin->getSlug(),
                    'publicTitle' => $checkin->getPublicTitle(),
                ];
                break;
            }
            return $out;
        PHP);
        $this->assertTrue($answer['protected']);
        $this->assertFalse($answer['public']['precise']);
        $this->assertSame('', $answer['public']['placename']);
        $this->assertSame('', $answer['public']['address']);
        $this->assertSame($answer['public'], $answer['publicLocation']);
        $this->assertEqualsWithDelta((float) self::LATITUDE, $answer['public']['latitude'], 0.011);
        $this->assertNotEquals((float) self::LATITUDE, $answer['public']['latitude']);
        foreach (['owner', 'other'] as $viewer) {
            $this->assertTrue($answer[$viewer]['precise']);
            $this->assertSame(self::$place, $answer[$viewer]['placename']);
            $this->assertSame('17 ' . self::$street, $answer[$viewer]['address']);
            $this->assertSame((float) self::LATITUDE, $answer[$viewer]['latitude']);
        }
        $this->assertStringNotContainsStringIgnoringCase(self::$place, $answer['stored'], 'the stored title is what search looks in');
        $this->assertStringNotContainsStringIgnoringCase(self::$place, $answer['slug']);
        $this->assertSame($answer['stored'], $answer['publicTitle']);
    }

    public function testEveryEntityHasAPublicTitleAndAWholeLocation(): void
    {
        $answer = $this->runInSite(<<<'PHP'
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'A status update';
            $status->lat = '1.5';
            $status->placename = 'Here';
            return [$status->getTitle(), $status->getPublicTitle(), $status->publicLocation()];
        PHP);
        $this->assertSame($answer[0], $answer[1]);
        $this->assertTrue($answer[2]['precise']);
        $this->assertSame('Here', $answer[2]['placename']);
    }
}
