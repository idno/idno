<?php

namespace IdnoPlugins\Event\Tests {

    use IdnoPlugins\Event\Event;
    use IdnoPlugins\Event\RSVP;

    /**
     * Events and RSVPs in Twenty26.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Event';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Event', 'entity/Event/edit', 'entity/RSVP', 'entity/RSVP/edit'];
        }

        private function event(): Event
        {
            $event = new Event();
            $event->title = 'Open house';
            $event->summary = 'Meet the <editors>';
            $event->location = 'Room "204"';
            $event->starttime = '2026-12-03 17:00';
            $event->endtime = '2026-12-03 19:00';
            $event->timezone = 'America/New_York';
            $event->body = '<p>Pizza provided.</p>';

            return $event;
        }

        public function testTheEventKeepsItsMicroformatsInTheDetailList()
        {
            $html = $this->drawModern('entity/Event', ['object' => $this->keep($this->event())]);

            $this->assertIsTwenty26Markup($html, 'The event');
            $this->assertStringContainsString('<h2 class="idno-entry-title p-name">', $html);
            $this->assertStringContainsString('class="u-url"', $html);
            $this->assertMatchesRegularExpression('~<p class="idno-entry-summary p-summary">\s*<strong>Meet the </strong>~', $html);
            $this->assertStringContainsString('class="idno-details idno-event-details"', $html);
            $this->assertMatchesRegularExpression('~<time class="dt-start"\s+datetime="2026-12-03T17:00~', $html);
            $this->assertMatchesRegularExpression('~<time class="dt-end"\s+datetime="2026-12-03T19:00~', $html);
            $this->assertMatchesRegularExpression('~class="p-location">Room &quot;204&quot;</span>~', $html);
            $this->assertStringContainsString('class="idno-entry-body e-content"', $html);
            // One row each for the time, the time zone and the place, each with an icon
            $this->assertSame(3, substr_count($html, 'class="idno-details-row"'));
            $this->assertSame(3, substr_count($html, 'class="idno-details-icon"'));
        }

        public function testTheEventFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Event/edit', ['object' => new Event()]);

            $this->assertIsComposeForm($html, 'The event form');
            foreach (['title', 'summary', 'location', 'starttime', 'endtime', 'timezone'] as $field) {
                $this->assertMatchesRegularExpression('~name="' . $field . '"~', $html, "The form has no \"$field\" field.");
                $this->assertStringContainsString('<label class="idno-label" for="' . $field . '">', $html, "The \"$field\" field has no label.");
            }
            $this->assertStringContainsString('name="body"', $html);
            $this->assertStringContainsString('class="idno-editor-row"', $html, 'The start and the end sit side by side.');
            $this->assertStringContainsString('name="__bTa" value="/event/edit"', $html);
        }

        public function testTheRsvpFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/RSVP/edit', ['object' => new RSVP(), 'url' => 'https://example.com/event?a=1&b="2"']);

            $this->assertIsComposeForm($html, 'The RSVP form');
            $this->assertMatchesRegularExpression('~<input[^>]*name="inreplyto"[^>]*id="reply"~', $html);
            $this->assertStringContainsString('value="https://example.com/event?a=1&amp;b=&quot;2&quot;"', $html);
            $this->assertStringContainsString('<label class="idno-label" id="in-reply-to" for="reply">', $html);
            $this->assertMatchesRegularExpression('~<select[^>]*name="rsvp"[^>]*id="rsvp"~', $html);
            $this->assertStringContainsString('<label class="idno-label" for="rsvp">', $html);
            $this->assertMatchesRegularExpression('~<textarea name="body"[^>]*id="body"~', $html);
            $this->assertStringContainsString('<label class="idno-label" for="body">', $html);
            $this->assertStringContainsString('name="__bTa" value="/status/edit"', $html);
        }
    }

}
