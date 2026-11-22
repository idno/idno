<?php

namespace IdnoPlugins\Event\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Event\RSVP;

    /**
     * An RSVP holds the address of its event as a string. Asking an entity for the
     * addresses it replies to must not change what is stored, and an RSVP that was saved
     * with a list of addresses (which that used to cause) must still be shown with its
     * link to the event.
     */
    class RSVPEventAddressTest extends \Tests\IdnoTestCase
    {

        private $toDelete = [];

        private $previousTemplateType;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // The Event plugin need not be enabled for its templates to be drawn here
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Event');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->swapUser($this->admin());
        }

        public function tearDown(): void
        {
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
        }

        private function savedRsvp($inreplyto): RSVP
        {
            $rsvp = new RSVP();
            $rsvp->rsvp = 'yes';
            $rsvp->body = 'See you there';
            $rsvp->inreplyto = $inreplyto;
            $rsvp->setAccess('PUBLIC');
            $this->assertNotEmpty($rsvp->save(true), 'The RSVP should have been saved.');
            $this->toDelete[] = $rsvp;

            return $rsvp;
        }

        private function reload(RSVP $rsvp): RSVP
        {
            $loaded = RSVP::getByID($rsvp->getID());
            $this->assertInstanceOf(RSVP::class, $loaded, 'The RSVP should be found again.');

            return $loaded;
        }

        public function testAskingForTheReplyAddressesDoesNotChangeWhatIsStored()
        {
            $rsvp = $this->reload($this->savedRsvp('https://example.com/event'));

            $this->assertTrue($rsvp->isReply());
            $this->assertSame(['https://example.com/event'], $rsvp->getReplyToURLs());
            $this->assertSame('https://example.com/event', $rsvp->inreplyto, 'The address should still be a string after it was asked for.');

            $this->assertNotEmpty($rsvp->save(true));
            $rsvp = $this->reload($rsvp);

            $this->assertSame('https://example.com/event', $rsvp->inreplyto, 'The address should be stored as a string.');
            $this->assertSame(
                '<data class="p-rsvp" value="yes"><a href="https://example.com/event" class="u-in-reply-to"></a>See you there</data>',
                $rsvp->getDescription()
            );
        }

        public function testReplyAddressesOfAPostWithSeveral()
        {
            $status = new \IdnoPlugins\Status\Reply();
            $status->inreplyto = ['https://example.com/one', 'https://example.com/two'];

            $this->assertSame(['https://example.com/one', 'https://example.com/two'], $status->getReplyToURLs());

            $status->inreplyto = '';
            $this->assertFalse($status->getReplyToURLs());
        }

        /**
         * Lists of addresses an RSVP may already be stored with, and the links expected.
         */
        public static function storedLists(): array
        {
            return [
                'one address'    => [['https://example.com/event'], ['https://example.com/event']],
                'two addresses'  => [['https://example.com/event', 'https://example.org/copy?a=1&b=2'], ['https://example.com/event', 'https://example.org/copy?a=1&b=2']],
                'a hostile one'  => [['javascript:alert(1)', 'https://example.com/event'], ['https://example.com/event']],
                'nothing usable' => [['javascript:alert(1)', ['nested']], []],
            ];
        }

        /**
         * @dataProvider storedLists
         */
        public function testRsvpStoredWithAListIsDescribedWithItsLinks($stored, $expected)
        {
            $rsvp = $this->reload($this->savedRsvp($stored));
            $this->assertSame($stored, $rsvp->inreplyto, 'The test needs an RSVP that is stored with a list.');

            $links = '';
            foreach ($expected as $url) {
                $links = '<a href="' . htmlspecialchars($url) . '" class="u-in-reply-to"></a>' . $links;
            }

            $this->assertSame('<data class="p-rsvp" value="yes">' . $links . 'See you there</data>', $rsvp->getDescription());
        }

        /**
         * @dataProvider storedLists
         */
        public function testRsvpStoredWithAListCanBeEdited($stored, $expected)
        {
            $rsvp = $this->reload($this->savedRsvp($stored));

            $t = Idno::site()->template();
            $t->setTemplateType('default');
            $html = $t->__(['object' => $rsvp])->draw('entity/RSVP/edit');
            unset($t->vars['object']);

            $this->assertSame(1, preg_match('~<input[^>]*name="inreplyto"[^>]*>~', $html, $match), 'The form should have a field for the address of the event.');
            $this->assertSame(1, preg_match('~value="([^"]*)"~', $match[0], $value), 'The field should have a value.');
            $this->assertSame($expected ? $expected[0] : '', html_entity_decode($value[1]), 'The field should hold the address of the event.');
        }

    }

}
