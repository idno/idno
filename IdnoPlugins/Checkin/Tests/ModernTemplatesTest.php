<?php

namespace IdnoPlugins\Checkin\Tests {

    use IdnoPlugins\Checkin\Checkin;

    /**
     * Check-ins in Twenty26: the map is drawn without the Stamen tile servers (which no
     * longer exist) and the form works without jQuery (which Twenty26 does not load).
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Checkin';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Checkin', 'entity/Checkin/edit'];
        }

        private function checkin(): Checkin
        {
            $checkin = new Checkin();
            $checkin->title = 'Checked into City Hall';
            $checkin->placename = 'City "Hall"';
            $checkin->lat = 40.7128;
            $checkin->long = -74.006;
            $checkin->address = 'City Hall Park, <New York>';
            $checkin->body = '<p>Here for the hearing.</p>';

            return $checkin;
        }

        public function testThePostHasItsMapAndMicroformats()
        {
            $object = $this->keep($this->checkin());
            $html = $this->drawModern('entity/Checkin', ['object' => $object]);

            $this->assertStringContainsString('<h2 class="idno-entry-title p-name">', $html);
            $this->assertStringContainsString('class="p-location u-checkin h-card"', $html);
            $this->assertStringContainsString('<data class="p-name" value="City &quot;Hall&quot;"></data>', $html);
            $this->assertStringContainsString('class="p-latitude"', $html);
            $this->assertStringContainsString('class="p-longitude"', $html);
            $this->assertMatchesRegularExpression('~<div id="map_' . preg_quote((string) $object->_id, '~') . '" class="idno-map" role="img" aria-label="[^"]*City &quot;Hall&quot;"~', $html);
            $this->assertMatchesRegularExpression('~<p class="idno-entry-kicker idno-entry-kicker-place">\s*<span>City Hall Park, </span>~', $html, 'The address is printed as text.');
            $this->assertStringNotContainsString('<New York>', $html);
            $this->assertStringContainsString('tile.openstreetmap.org', $html);
            $this->assertStringContainsString('OpenStreetMap</a> contributors', $html, 'The map names the source of its tiles.');
            $this->assertStringNotContainsString('StamenTileLayer', $html);
            $this->assertStringContainsString('class="idno-entry-body e-content entry-content"', $html);
        }

        public function testAFeedGetsNoMapAndNoEmptyHeading()
        {
            $html = $this->drawModern('entity/Checkin', ['object' => $this->keep($this->checkin()), 'feed_view' => true]);

            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<h2', $html);
            $this->assertStringContainsString('p-location', $html);
        }

        public function testTheFormIsAComposeFormThatNeedsNoJQuery()
        {
            $html = $this->drawModern('entity/Checkin/edit', ['object' => new Checkin()]);

            $this->assertIsComposeForm($html, 'The check-in form');
            foreach (['placename', 'lat', 'long', 'user_address', 'address'] as $field) {
                $this->assertMatchesRegularExpression('~name="' . $field . '"[^>]*id="' . $field . '"|id="' . $field . '"[^>]*name="' . $field . '"~', $html, "The form has no \"$field\" field.");
            }
            $this->assertStringContainsString('<label class="idno-checkbox-label" for="anonymity">', $html);
            $this->assertMatchesRegularExpression('~<input name="anonymity" id="anonymity" type="checkbox"~', $html);
            $this->assertStringContainsString('id="checkinMap" class="idno-map"', $html);
            $this->assertStringContainsString('name="__bTa" value="/checkin/edit"', $html);

            // The script after the form
            $this->assertStringNotContainsString('checkin.min.js"', $html, 'checkin.min.js needs jQuery.');
            $this->assertStringContainsString('geocoder', $html);
            $this->assertStringContainsString('navigator.geolocation', $html);
        }
    }

}
