<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The delete of an annotation a site made itself while the post had another address (`AnnotationLayoutSiteCase`): the post's editor removes it by the key it is
 * stored under (the address of the delete link the post prints), by the address the post shows it at now, and at the delete address of its own id. Each test
 * starts a site of its own, because the requests change it.
 */
final class AnnotationOtherLayoutDeleteTest extends AnnotationLayoutSiteCase
{
    /** Whether the post's list names an annotation by what it holds. */
    private function lists(string $content): bool
    {
        return str_contains($this->site()->request('GET', $this->post() . '/annotations/', $this->asViewer('owner'))->body, $content);
    }

    private function delete(string $address, array $headers = []): SiteResponse
    {
        return $this->site()->request('POST', $address, $this->asViewer('owner', $headers), ['__bTk' => $this->tokenOf('owner')]);
    }

    public function testTheKeyTheDeleteLinkPostsFindsTheAnnotation(): void
    {
        $this->seedPermalinkContent();
        $this->seedAnnotationsInOtherLayouts();
        $this->assertTrue($this->lists('Carol commented while the post had a month in its address.'));
        $key = $this->baseUrl() . '2026/03/' . $this->slug() . '/annotations/' . md5('old-month');

        $answer = $this->delete($this->post() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($key));

        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->lists('Carol commented while the post had a month in its address.'), 'the annotation is gone');
        $this->assertTrue($this->lists('Dave commented since.'), 'and no other');
    }

    public function testTheAddressOfTheAnnotationsOwnIdFindsIt(): void
    {
        $this->seedPermalinkContent();
        $this->seedAnnotationsInOtherLayouts();
        $this->assertTrue($this->lists('Carol commented while the post had a month in its address.'));

        // no permalink: the annotation the address names, wherever the post stores it
        $answer = $this->delete($this->post() . '/annotations/' . md5('old-month') . '/delete');

        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->lists('Carol commented while the post had a month in its address.'), 'the annotation stored under the month layout is gone');
        $this->assertTrue($this->lists('Dave commented since.'));
    }

    public function testTheAddressThePostShowsTheAnnotationAtFindsIt(): void
    {
        $this->seedPermalinkContent();
        $this->seedAnnotationsInOtherLayouts();
        $shown = $this->baseUrl() . ltrim($this->post(), '/') . '/annotations/' . md5('old-month');

        $answer = $this->delete($this->post() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($shown));

        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->lists('Carol commented while the post had a month in its address.'), 'removed by the address it is shown at');
    }

    public function testAnotherMemberRemovesNothing(): void
    {
        $this->seedPermalinkContent();
        $this->seedAnnotationsInOtherLayouts();
        $key = $this->baseUrl() . '2026/03/' . $this->slug() . '/annotations/' . md5('old-month');
        $answer = $this->site()->request('POST', $this->post() . '/annotations/' . md5('old-month') . '/delete', $this->asViewer('member'), ['__bTk' => $this->tokenOf('member')]);

        $this->assertSame(302, $answer->status);
        $this->assertTrue($this->lists('Carol commented while the post had a month in its address.'), 'nothing is removed by someone who may not edit the post: ' . $key);
    }
}
