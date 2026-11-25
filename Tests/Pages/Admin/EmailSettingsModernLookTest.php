<?php

namespace Tests\Pages\Admin {

    /**
     * The email settings tests again in the modern look, which has a template of its own for
     * the page.
     */
    class EmailSettingsModernLookTest extends EmailSettingsTest
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

        public function testThePageIsDrawnInTheModernLook()
        {
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringContainsString('idno-admin-card', $page);
            foreach (['from_email', 'from_name', 'reply_to_email', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password'] as $field) {
                $this->assertMatchesRegularExpression('/<label[^>]*for="' . $field . '"/', $page, 'A label for ' . $field);
                $this->assertStringContainsString('id="help-' . $field . '"', $page, 'A line of help for ' . $field);
            }
            $this->assertModernLook($page, 'The email settings page', ['form-control']);
        }
    }

}
