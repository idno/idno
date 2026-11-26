<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The site the tests of annotations made while a post had another address are made on: Bob's public note holds an annotation stored under the address of each
 * layout a site could choose (a comment in the month layout, a like in the day layout, a like of the month layout, a comment under the layout it has now), and
 * a note whose slug is two digits, dated in December, holds one of its own.
 */
abstract class AnnotationLayoutSiteCase extends PermalinkSiteCase
{
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException(static::class . ' records no answer.');
    }

    protected function seedSharedSite(): void
    {
        $this->seedPermalinkContent();
        $this->seedAnnotationsInOtherLayouts();
    }

    /** The ids of the annotations of the post, by name. */
    protected const IDS = ['month' => 'old-month', 'day' => 'old-day', 'month-like' => 'old-month-like', 'new' => 'new-layout'];

    /**
     * Gives Bob's public note an annotation stored under the address of each layout (a comment in the month layout, a like in the day layout, a like of the
     * month layout, a comment under the layout it has now), and the post the layouts are told apart on: a note whose slug is two digits.
     */
    protected function seedAnnotationsInOtherLayouts(): void
    {
        $paths = $this->seedPermalinkContent();
        $code = <<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $base = \Idno\Core\Idno::site()->config()->getURL();
        $path = %PATH%;
        $slug = basename($path);
        $record = $store->findById('entities', '00000000000040008000000000000041');
        $make = static fn (string $key, string $name, string $content, string $when): array => ['permalink' => $key, 'owner_name' => $name, 'owner_url' => 'https://example.org/' . strtolower($name), 'owner_image' => '', 'content' => $content, 'time' => (int) strtotime($when . ' UTC'), 'title' => ''];
        $month = $base . '2026/03/' . $slug . '/annotations/' . md5('old-month');
        $day = $base . '2026/03/14/' . $slug . '/annotations/' . md5('old-day');
        $monthLike = $base . '2026/03/' . $slug . '/annotations/' . md5('old-month-like');
        $new = $base . $path . '/annotations/' . md5('new-layout');
        $record['annotations'] = [
            'reply' => [$month => $make($month, 'Carol', 'Carol commented while the post had a month in its address.', '2026-03-15 10:00:00'), $new => $make($new, 'Dave', 'Dave commented since.', '2026-03-15 11:00:00')],
            'like' => [$day => $make($day, 'Erin', '', '2026-03-15 12:00:00'), $monthLike => $make($monthLike, 'Frank', '', '2026-03-15 13:00:00')],
        ];
        $store->save('entities', $record);

        // a note whose slug is two digits, in a month that has two digits, with an annotation of its own
        $bob = \Idno\Entities\User::getByHandle('bob');
        $twelve = new \IdnoPlugins\Status\Status();
        $twelve->_id = '00000000000040008000000000000071';
        $twelve->body = '12';
        $twelve->setOwner($bob);
        $twelve->setAccess('PUBLIC');
        $twelve->setPublishStatus('published');
        $twelve->created = (int) strtotime('2026-12-05 12:00:00 UTC');
        $twelve->setSlugResilient('12');
        $twelve->save(true);
        $record = $store->findById('entities', '00000000000040008000000000000071');
        $key = $twelve->getURL() . '/annotations/' . md5('on-twelve');
        $record['annotations'] = ['reply' => [$key => $make($key, 'Gina', 'Gina comments on the note called twelve.', '2026-12-06 10:00:00')]];
        $record['updated'] = (int) strtotime('2026-03-20 12:00:00 UTC');
        $store->save('entities', $record);

        return ['twelve' => substr($twelve->getURL(), strlen($base))];
        PHP;
        $result = $this->runInSite(str_replace('%PATH%', $this->literal($paths['bob-public']), $code));
        $this->assertIsArray($result);
        self::$twelve = (string) $result['twelve'];
    }

    protected static string $twelve = '';

    protected function post(): string
    {
        return '/' . $this->seedPermalinkContent()['bob-public'];
    }

    protected function slug(): string
    {
        return basename($this->post());
    }

}
