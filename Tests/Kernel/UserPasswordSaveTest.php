<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * A save of a user writes the password's hash and marker only when that object changed them: an object loaded before
 * another request changed the password must not write the old ones back.
 */
final class UserPasswordSaveTest extends PrivateSiteTestCase
{
    public function testAStaleObjectDoesNotRevertAPasswordChange(): void
    {
        $this->addUser('ada', 'the-old-passphrase-1');

        $result = $this->runInSite('
            $x = \Idno\Entities\User::getByHandle("ada");
            $y = \Idno\Entities\User::getByHandle("ada");
            $y->setPassword("the-new-passphrase-2");
            $y->save(true);
            $x->setTitle("Ada Lovelace");
            $x->save(true);
            $stored = \Idno\Core\Idno::site()->db()->getRecord($x->_id);
            $yStored = \Idno\Entities\User::getByHandle("ada");
            return [
                "hash_is_ys" => $stored["password"] === $y->password,
                "marker_is_ys" => $stored["password_generation"] === $y->password_generation,
                "new" => $yStored->checkPassword("the-new-passphrase-2"),
                "old" => $yStored->checkPassword("the-old-passphrase-1"),
                "title" => $yStored->getTitle(),
                "x_sees_it" => $x->password === $y->password,
            ];
        ');

        $this->assertSame(['hash_is_ys' => true, 'marker_is_ys' => true, 'new' => true, 'old' => false, 'title' => 'Ada Lovelace', 'x_sees_it' => true], $result);
    }

    public function testAnObjectThatChangedThePasswordWritesIt(): void
    {
        $this->addUser('ada', 'the-old-passphrase-1');

        $result = $this->runInSite('
            $x = \Idno\Entities\User::getByHandle("ada");
            $before = $x->password_generation;
            $x->setPassword("the-new-passphrase-2");
            $x->save(true);
            $fresh = \Idno\Entities\User::getByHandle("ada");
            // the flags are spent by the save: a second save does not write over what another request stores
            $z = \Idno\Entities\User::getByHandle("ada");
            $z->setPassword("a-third-passphrase-3");
            $z->save(true);
            $x->setTitle("Again");
            $x->save(true);
            $last = \Idno\Entities\User::getByHandle("ada");
            return ["changed" => $fresh->password_generation !== $before, "third_survives" => $last->checkPassword("a-third-passphrase-3")];
        ');

        $this->assertSame(['changed' => true, 'third_survives' => true], $result);
    }

    public function testTheHashUpgradeKeepsTheMarker(): void
    {
        $this->addUser('ada', 'the-old-passphrase-1');

        $result = $this->runInSite('
            $u = \Idno\Entities\User::getByHandle("ada");
            $marker = $u->password_generation;
            $u->setPasswordHash(password_hash("the-old-passphrase-1", PASSWORD_BCRYPT, ["cost" => 4]));
            $u->save(true);
            $u = \Idno\Entities\User::getByHandle("ada");
            $ok = $u->checkPassword("the-old-passphrase-1");
            $after = \Idno\Entities\User::getByHandle("ada");
            return ["ok" => $ok, "marker_kept" => $after->password_generation === $marker, "upgraded" => !(new \Idno\Security\Passwords())->needsRehash((string) $after->password)];
        ');

        $this->assertSame(['ok' => true, 'marker_kept' => true, 'upgraded' => true], $result);
    }

    public function testADirectWriteOfThePasswordPropertyIsNotStoredByASave(): void
    {
        $this->addUser('ada', 'the-old-passphrase-1');

        $result = $this->runInSite('
            $u = \Idno\Entities\User::getByHandle("ada");
            $u->password = "not-a-hash";
            $u->save(true);
            return \Idno\Entities\User::getByHandle("ada")->checkPassword("the-old-passphrase-1");
        ');

        $this->assertTrue($result);
    }

    public function testANewUserIsWrittenAsItIs(): void
    {
        $result = $this->runInSite('
            $u = new \Idno\Entities\User();
            $u->handle = "newbie"; $u->email = "newbie@example.com"; $u->setTitle("N");
            $u->setPassword("a-passphrase-12345");
            $u->save(true);
            $f = \Idno\Entities\User::getByHandle("newbie");
            return [$f->checkPassword("a-passphrase-12345"), !empty($f->password_generation)];
        ');

        $this->assertSame([true, true], $result);
    }
}
