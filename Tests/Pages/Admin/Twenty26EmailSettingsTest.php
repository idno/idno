<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Idno;

    /**
     * The email settings tests again with the Twenty26 theme, which has a template of its own
     * for the page.
     */
    class Twenty26EmailSettingsTest extends EmailSettingsTest
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

        public function testThePageIsTwenty26Markup()
        {
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringContainsString('Themes/Twenty26/dist/modern.min.css', $page);
            $this->assertStringContainsString('idno-admin-card', $page);
            foreach (['from_email', 'from_name', 'reply_to_email', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password'] as $field) {
                $this->assertMatchesRegularExpression('/<label[^>]*for="' . $field . '"/', $page, 'A label for ' . $field);
                $this->assertStringContainsString('id="help-' . $field . '"', $page, 'A line of help for ' . $field);
            }
            foreach (['col-md-', 'form-horizontal', 'form-control', '$(', 'jQuery'] as $mark) {
                $this->assertStringNotContainsString($mark, $page, 'The page should not hold "' . $mark . '"');
            }
        }
    }

}
