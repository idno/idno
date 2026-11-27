<?php

namespace Tests\Common;

use Idno\Entities\User;

/**
 * The member as structured data, which every page of the member and of their posts publishes: the member's name, their picture and address,
 * and never the account's email address.
 */
class UserJsonLdTest extends \Tests\IdnoTestCase
{
    public function testTheMemberIsAPersonWithANameAndNoEmailAddress(): void
    {
        $user = new User();
        $user->handle = 'jsonld' . md5((string) rand());
        $user->email = 'private@example.com';
        $user->setTitle('Ada Lovelace');

        $json = $user->jsonLDSerialise();

        $this->assertSame('Person', $json['@type']);
        $this->assertSame('Ada Lovelace', $json['name']);
        $this->assertSame(['@context', '@type', 'name', 'image', 'url'], array_keys($json), 'in the order dev gives them');
        $this->assertStringNotContainsString('private@example.com', json_encode($json));
    }
}
