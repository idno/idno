<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Discovery;

use Idno\Http\Controller\Discovery\MentionSearch;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiscoveryDoubles.php';

final class MentionSearchTest extends TestCase
{
    public function testMentionsAreJson(): void
    {
        $candidates = new FixedCandidates();

        $response = (new MentionSearch($candidates))->answer(DiscoveryDoubles::context('/search/mentions.json?username=ad'));

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('[{"username":"ada","name":"Ada L","image":"https:\/\/example.com\/a.png"}]', $response->getContent());
        $this->assertSame(['ad'], $candidates->asked);
    }

    public function testANameThatIsNotTextIsAnEmptyOne(): void
    {
        $candidates = new FixedCandidates();

        (new MentionSearch($candidates))->answer(DiscoveryDoubles::context('/search/mentions.json?username[]=x'));
        (new MentionSearch($candidates))->answer(DiscoveryDoubles::context('/search/mentions.json'));

        $this->assertSame(['', ''], $candidates->asked);
    }
}
