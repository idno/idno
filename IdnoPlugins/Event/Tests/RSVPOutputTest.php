<?php

namespace IdnoPlugins\Event\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Event\RSVP;

    /**
     * The text of an RSVP, its answer and the address of the event are typed by a member
     * and shown to everybody, so they must be printed as text, or as cleaned HTML, and
     * the address must be an http or https one.
     */
    class RSVPOutputTest extends \Tests\IdnoTestCase
    {

        /// Entities to remove after each test
        private $toDelete = [];

        /// The template type and the page in use before the test started
        private $previousTemplateType;
        private $previousPage;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // The Event plugin need not be enabled for its templates to be drawn here;
            // Twenty26 only has templates of the "modern" type.
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Event');
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->previousPage = Idno::site()->currentPage();
            $this->swapUser($this->admin());
        }

        public function tearDown(): void
        {
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
        }

        public static function lookProvider(): array
        {
            return [
                'no theme' => ['default'],
                'Twenty26' => ['modern'],
            ];
        }

        private function draw(RSVP $rsvp, string $templateType): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType($templateType);
            $html = $t->__(['object' => $rsvp])->draw('entity/RSVP');
            unset($t->vars['object']);

            return $html;
        }

        /**
         * Fail if the markup has an element or an attribute that runs script, or an
         * address that is not an http or https one.
         */
        private function assertMarkupIsSafe(string $html): void
        {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
            libxml_clear_errors();

            foreach ($doc->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
                $this->assertNotContains(
                    $element->nodeName,
                    ['script', 'iframe', 'object', 'embed', 'svg', 'style', 'link', 'meta', 'base', 'form'],
                    'Unexpected element in: ' . $html
                );
                foreach ($element->attributes as $attribute) {
                    $name = strtolower($attribute->nodeName);
                    $this->assertStringStartsNotWith('on', $name, "Unexpected attribute \"$name\" in: " . $html);
                    $this->assertNotContains($name, ['srcdoc', 'formaction'], "Unexpected attribute \"$name\" in: " . $html);
                    if (in_array($name, ['href', 'src', 'action'])) {
                        $this->assertMatchesRegularExpression(
                            '~^($|#|/[^/]|(https?:)?//)~i',
                            trim($attribute->nodeValue),
                            "The address in $name is not an http or https one: " . $html
                        );
                    }
                }
            }
        }

        public static function hostileProvider(): array
        {
            $cases = [];
            $values = [
                'script in the text'   => ['yes', 'See you there <script>alert(1)</script>'],
                'handler in the text'  => ['yes', 'See you <img src="https://example.com/x.png" onerror="alert(1)"> there'],
                'link in the text'     => ['maybe', 'Details <a href="javascript:alert(1)">here</a>'],
                'quote in the answer'  => ['yes" onmouseover="alert(1)', 'See you there'],
                'tag in the answer'    => ['"><script>alert(1)</script>', 'See you there'],
            ];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($values as $name => $value) {
                    $cases["$lookName, $name"] = array_merge($look, $value);
                }
            }

            return $cases;
        }

        /**
         * An RSVP that is already stored with hostile values.
         *
         * @dataProvider hostileProvider
         */
        public function testStoredRsvpIsDrawnSafely($look, $answer, $body)
        {
            $rsvp = new RSVP();
            $rsvp->rsvp = $answer;
            $rsvp->body = $body;
            $rsvp->inreplyto = 'https://example.com/event';

            $html = $this->draw($rsvp, $look);

            $this->assertStringContainsString('<data class="p-rsvp"', $html, 'The RSVP should be drawn.');
            $this->assertMarkupIsSafe($html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onerror', $html);
        }

        /**
         * @dataProvider lookProvider
         */
        public function testOrdinaryRsvpIsDrawn($look)
        {
            $rsvp = new RSVP();
            $rsvp->rsvp = 'yes';
            $rsvp->body = 'Fish & chips after? See https://example.com/menu #dinner';
            $rsvp->inreplyto = 'https://example.com/event';

            $html = $this->draw($rsvp, $look);

            $this->assertMarkupIsSafe($html);
            $this->assertStringContainsString('<data class="p-rsvp" value="yes"><strong>Yes:</strong>', $html);
            $this->assertStringContainsString('Fish &amp; chips after?', $html);
            $this->assertStringContainsString('href="https://example.com/menu"', $html);
            $this->assertStringContainsString('#dinner</a>', $html);
        }

        public static function hostileDescriptionProvider(): array
        {
            return [
                'quote in the address'  => ['yes', 'Hello', 'https://example.com/" onmouseover="alert(1)'],
                'javascript address'    => ['yes', 'Hello', 'javascript:alert(1)'],
                'tag in the address'    => ['yes', 'Hello', '"><script>alert(1)</script>'],
                'quote in the answer'   => ['yes" onmouseover="alert(1)', 'Hello', 'https://example.com/event'],
                'script in the text'    => ['yes', 'Hello <script>alert(1)</script>', 'https://example.com/event'],
                'handler in the text'   => ['yes', 'Hello <img src="https://example.com/x.png" onerror="alert(1)">', 'https://example.com/event'],
            ];
        }

        /**
         * getDescription() is the HTML that feeds and other sites are given.
         *
         * @dataProvider hostileDescriptionProvider
         */
        public function testDescriptionIsSafeMarkup($answer, $body, $inreplyto)
        {
            $rsvp = new RSVP();
            $rsvp->rsvp = $answer;
            $rsvp->body = $body;
            $rsvp->inreplyto = $inreplyto;

            $html = $rsvp->getDescription();

            $this->assertStringContainsString('Hello', $html);
            $this->assertMarkupIsSafe($html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('javascript:', $html);
        }

        public function testOrdinaryDescription()
        {
            $rsvp = new RSVP();
            $rsvp->rsvp = 'maybe';
            $rsvp->body = 'I will <em>try</em>';
            $rsvp->inreplyto = 'https://example.com/event?a=1&b=2';

            $this->assertSame(
                '<data class="p-rsvp" value="maybe"><a href="https://example.com/event?a=1&amp;b=2" class="u-in-reply-to"></a>I will <em>try</em></data>',
                $rsvp->getDescription()
            );
        }

        public static function hostileEventAddressProvider(): array
        {
            return [
                'javascript' => ['javascript:alert(1)'],
                'quote'      => ['https://example.com/" onmouseover="alert(1)'],
                'tag'        => ['"><script>alert(1)</script>'],
            ];
        }

        private function saveFromInput(array $input): RSVP
        {
            $page = new \Idno\Pages\Homepage();
            foreach ($input + ['access' => 'SITE'] as $name => $value) {
                $page->setInput($name, $value);
            }
            Idno::site()->setCurrentPage($page);

            $rsvp = new RSVP();
            $this->assertTrue($rsvp->saveDataFromInput());
            $this->toDelete[] = $rsvp;
            Idno::site()->session()->getAndFlushMessages();

            return $rsvp;
        }

        /**
         * @dataProvider hostileEventAddressProvider
         */
        public function testEventAddressThatIsNotHttpIsNotStored($inreplyto)
        {
            $rsvp = $this->saveFromInput(['rsvp' => 'yes', 'body' => 'Hello', 'inreplyto' => $inreplyto]);

            $this->assertSame('', $rsvp->inreplyto);
        }

        public function testTextIsCleanedWhenSaved()
        {
            $rsvp = $this->saveFromInput([
                'rsvp'      => 'Yes',
                'body'      => 'Hello <em>all</em><script>alert(1)</script>',
                'inreplyto' => ' https://example.com/event ',
            ]);

            $this->assertSame('yes', $rsvp->rsvp);
            $this->assertSame('https://example.com/event', $rsvp->inreplyto);
            $this->assertStringContainsString('Hello <em>all</em>', $rsvp->body);
            $this->assertStringNotContainsString('script', $rsvp->body);
        }
    }

}
