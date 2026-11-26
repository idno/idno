<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The site the profile's recordings are made on: `PermalinkSiteCase`'s (Ada, Bob and Carol, and Bob's posts in each state a viewer's rights differ by) with
 * the accounts' `updated` fixed. A profile's `Last-Modified` is the later of the account's last change and its newest post's, and Bob's account was saved
 * (to store his API key) after the fixture's times were written, so it holds the time of the seeding.
 */
abstract class ProfileSiteCase extends PermalinkSiteCase
{
    /** @var array<string, array<string, mixed>> what the seeding returned, by class and site address */
    private static array $profiles = [];

    /**
     * Seeds the site (once, whether the class shares it or not) and returns what `seedPermalinkContent()` does.
     *
     * @return array<string, mixed>
     */
    protected function seedProfiles(): array
    {
        $key = static::class . '|' . $this->baseUrl();
        if (isset(self::$profiles[$key])) {
            return self::$profiles[$key];
        }
        $paths = $this->seedPermalinkContent();
        $fixed = $this->runInSite(<<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $at = (int) strtotime('2026-03-20 12:00:00 UTC');
        foreach (['ada', 'bob', 'carol'] as $handle) {
            $user = \Idno\Entities\User::getByHandle($handle);
            $record = $store->findById('entities', (string) $user->getID());
            $record['updated'] = $at;
            $store->save('entities', $record);
        }

        return true;
        PHP);
        $this->assertTrue($fixed);

        return self::$profiles[$key] = $paths;
    }

    /** The answer without its `Last-Modified`: the legacy router's lines for an XHR request hold the time of the request. */
    protected static function withoutLastModified(SiteResponse $r): SiteResponse
    {
        return new SiteResponse(
            $r->status,
            array_filter($r->headers, static fn ($name): bool => strcasecmp((string) $name, 'last-modified') !== 0, ARRAY_FILTER_USE_KEY),
            $r->body,
            array_values(array_filter($r->lines, static fn (string $line): bool => !str_starts_with(strtolower($line), 'last-modified:')))
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::$profiles) as $key) {
            if (str_starts_with($key, static::class . '|')) {
                unset(self::$profiles[$key]);
            }
        }
        parent::tearDownAfterClass();
    }
}
