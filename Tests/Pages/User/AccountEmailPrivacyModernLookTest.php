<?php

namespace Tests\Pages\User {

    /**
     * The account email tests again in the modern look, which draws the profile and a post
     * with templates of its own.
     */
    class AccountEmailPrivacyModernLookTest extends AccountEmailPrivacyTest
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

        public function testTheProfileIsDrawnInTheModernLook()
        {
            $profile = $this->get('/profile/' . static::$author->getHandle());
            $this->assertSame(200, $profile['status']);
            $this->assertModernLook($profile['body'], 'The profile');
        }
    }

}
