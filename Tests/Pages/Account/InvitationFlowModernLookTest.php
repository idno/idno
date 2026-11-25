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
            // The navigation offers a visitor the sign-in page, not a feed and a search they cannot open
            foreach ($pages as $name => $page) {
                $this->assertSame(1, preg_match('#<nav class="idno-nav-sidebar".*?</nav>#s', $page['body'], $nav), $name . ' has the side navigation');
                $this->assertStringContainsString('session/login', $nav[0], $name);
                $this->assertStringNotContainsString('open-search', $nav[0], $name . ': no search for a visitor of a private site');
                $this->assertStringNotContainsString('idno-nav-label">Home<', $nav[0], $name . ': no home feed for a visitor of a private site');
            }
            // A member has both
            $home = $this->get('/', static::$member);
            $this->assertSame(1, preg_match('#<nav class="idno-nav-sidebar".*?</nav>#s', $home['body'], $nav));
            $this->assertStringContainsString('open-search', $nav[0]);
            $this->assertStringContainsString('idno-nav-label">Home<', $nav[0]);

            $this->assertStringContainsString('is private', $pages['the front door']['body']);
            $this->assertStringContainsString('invitation', $pages['the front door']['body']);
        }
    }

}
