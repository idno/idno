<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * Who WebFinger describes (`Idno\Legacy\LegacyWebfingerDirectory`) and who the mention search lists (`LegacyMentionCandidates`), on a site of the
 * test's own with one member: the links are the `webfinger` event's (the user's own listener and the ActivityPub plugin's), the aliases the profile
 * and the actor, and a resource that is not an `acct:` of this host's members is nobody.
 */
final class LegacyWebfingerDirectoryTest extends PrivateSiteTestCase
{
    public function testTheDirectoryAnswersForAMemberAndForNobodyElse(): void
    {
        $this->addUser('ada');
        $this->addUser('lovelace');
        $answers = $this->runInSite(
            '$directory = new \Idno\Legacy\LegacyWebfingerDirectory(); $host = \Idno\Core\Idno::site()->config()->host; $base = \Idno\Core\Idno::site()->config()->getDisplayURL();'
            . 'return ["host" => $host, "base" => $base, "found" => $directory->find("acct:ada@" . $host), "nohost" => $directory->find("acct:lovelace"), "withhost" => $directory->find("acct:lovelace@" . $host), "tooshort" => $directory->find("acct:ada"),'
            . ' "unknown" => $directory->find("acct:nobody@" . $host), "scheme" => $directory->find("mailto:ada@" . $host), "short" => $directory->find("acct:a"), "empty" => $directory->find("")];'
        );

        $base = $answers['base'];
        $this->assertSame([$base . 'profile/ada', $base . 'actor/ada'], $answers['found']['aliases']);
        $rels = array_column($answers['found']['links'], 'rel');
        $this->assertContains('self', $rels, 'the ActivityPub actor link');
        $this->assertContains('http://webfinger.net/rel/profile-page', $rels);
        $self = $answers['found']['links'][array_search('self', $rels, true)];
        $this->assertSame('application/activity+json', $self['type']);
        $this->assertSame($base . 'actor/ada', $self['href']);
        $this->assertSame($answers['withhost'], $answers['nohost'], 'the host is optional');
        // (the resource must be longer than eight characters, `acct:` and three: a three-letter handle with no host is refused, as it was)
        foreach (['tooshort', 'unknown', 'scheme', 'short', 'empty'] as $none) {
            $this->assertNull($answers[$none], $none);
        }
    }

    public function testTheMentionCandidatesAreHandleNameAndAvatarOnly(): void
    {
        $this->addUser('ada');
        $this->addUser('bob');

        $list = $this->runInSite('return (new \Idno\Legacy\LegacyMentionCandidates())->candidates("a");');

        $handles = array_column($list, 'username');
        sort($handles);
        $this->assertSame(['ada', 'bob'], $handles);
        foreach ($list as $candidate) {
            $this->assertSame(['username', 'name', 'image'], array_keys($candidate));
        }
    }
}
