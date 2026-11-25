<?php

namespace Tests\Pages\Account {

    /**
     * The invitation tests again in the modern look, where the pages for a visitor (joining,
     * the private site's front door, the password reset) are templates of their own, drawn
     * without jQuery.
     */
    class InvitationFlowModernLookTest extends InvitationFlowTest
    {

        use \Tests\ModernLook;

        public static function setUpBeforeClass(): void
        {
            static::useModernLook();
            parent::setUpBeforeClass();
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::restoreLook();
        }

        public function testTheVisitorsPagesAreDrawnInTheModernLook()
        {
            $address = static::address('modern');
            $this->invite($address);
            $jar = $this->visitor();

            $pages = [
                'the front door'         => $this->visit($jar, 'GET', '/'),
                'the registration page'  => $this->visit($jar, 'GET', $this->linkFor($address)),
                'a refused invitation'   => $this->visit($jar, 'GET', '/account/register/?email=' . urlencode($address) . '&code=wrong'),
                'the password reset'     => $this->visit($jar, 'GET', '/account/password'),
                'the reset confirmation' => $this->visit($jar, 'GET', '/account/password/?sent=true'),
            ];
            foreach ($pages as $name => $page) {
                $this->assertStringContainsString('class="idno-gate', $page['body'], $name . ' is a centred panel');
                // ... and nothing of the account settings pages, which are for people who are signed in
                $this->assertModernLook($page['body'], $name, ['idno-account-', 'account/settings']);
            }
            $this->assertStringContainsString('is private', $pages['the front door']['body']);
            $this->assertStringContainsString('invitation', $pages['the front door']['body']);
        }
    }

}
