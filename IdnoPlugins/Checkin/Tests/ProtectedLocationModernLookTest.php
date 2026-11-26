<?php

namespace IdnoPlugins\Checkin\Tests {

    /**
     * The protected location tests again in the modern look, whose template for a check-in
     * also prints the address and names the place in the label of the map.
     */
    class ProtectedLocationModernLookTest extends ProtectedLocationTest
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

        public function testTheCheckinIsDrawnInTheModernLook()
        {
            $checkin = $this->protectedCheckin();
            $path = parse_url($checkin->getURL(), PHP_URL_PATH);

            $visitor = $this->get($path)['body'];
            $this->assertModernLook($visitor, 'The check-in for a visitor');
            $this->assertStringContainsString('class="idno-map"', $visitor);
            $this->assertStringNotContainsString('idno-entry-kicker-place', $visitor, 'A visitor is not given the address');

            $member = $this->get($path, static::$other)['body'];
            $this->assertStringContainsString('idno-entry-kicker-place', $member);
            $this->assertStringContainsString('17 ' . static::$street, $member);
        }
    }

}
