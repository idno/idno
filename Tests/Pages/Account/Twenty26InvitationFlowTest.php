<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;

    /**
     * The invitation tests again with the Twenty26 theme, whose pages for a visitor (joining,
     * the private site's front door, the password reset) are templates of its own, and which
     * loads no jQuery.
     */
    class Twenty26InvitationFlowTest extends InvitationFlowTest
    {

        private static $savedTheme;

        public static function setUpBeforeClass(): void
        {
            static::$savedTheme = Idno::site()->config()->theme;
            Idno::site()->config()->theme = 'Twenty26';
            Idno::site()->config()->save();
            parent::setUpBeforeClass();
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            Idno::site()->config()->theme = static::$savedTheme;
            Idno::site()->config()->save();
        }

        public function testTheVisitorsPagesAreTwenty26Markup()
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
                $this->assertStringContainsString('Themes/Twenty26/dist/modern.min.css', $page['body'], $name);
                $this->assertStringContainsString('class="idno-gate', $page['body'], $name . ' is a centred panel');
                // Nothing of the default look: its grid, its form classes, its jQuery ...
                // ... and nothing of the account settings pages, which are for people who are signed in
                foreach (['col-md-', 'form-horizontal', 'control-group', 'jumbotron', '$(', 'jQuery', 'idno-account-', 'account/settings'] as $mark) {
                    $this->assertStringNotContainsString($mark, $page['body'], $name . ' should not hold "' . $mark . '"');
                }
            }
            $this->assertStringContainsString('is private', $pages['the front door']['body']);
            $this->assertStringContainsString('invitation', $pages['the front door']['body']);
        }
    }

}
