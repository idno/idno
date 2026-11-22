<?php

namespace Tests\Core {

    use Idno\Core\ExceptionMessage;

    /**
     * When an exception is thrown while a page is being served, the visitor is shown its
     * message, unless the message is a technical one. \Idno\Core\ExceptionMessage holds the
     * rule: a message is written for the visitor unless the exception's type says otherwise.
     */
    class ExceptionMessageTest extends \Tests\IdnoTestCase
    {

        /// The message of the exceptions made by the tests
        const MESSAGE = 'SQLSTATE[HY000] [1045] Access denied for user \'dbuser\'@\'dbhost\'';

        /// The site's debug setting, to restore after each test
        private $debug;

        protected function setUp(): void
        {
            $this->debug = \Idno\Core\Idno::site()->config()->debug;
            \Idno\Core\Idno::site()->config()->debug = false;
        }

        protected function tearDown(): void
        {
            \Idno\Core\Idno::site()->config()->debug = $this->debug;
        }

        /**
         * What a visitor is shown in place of a technical message.
         */
        private function fixedText()
        {
            return \Idno\Core\Idno::site()->language()->_('Oh no! Something went wrong.');
        }

        /**
         * An exception that a library throws from its own code, with a type that says nothing.
         */
        private static function plainExceptionFromLibrary()
        {
            try {
                (new \Symfony\Component\EventDispatcher\GenericEvent())->getArgument('missing');
            } catch (\InvalidArgumentException $e) {
                return $e;
            }

            throw new \LogicException('The library call was expected to throw.');
        }

        /**
         * Exceptions whose message is written for the visitor.
         */
        public static function friendlyProvider()
        {
            return [
                'plain exception' => [new \Exception(self::MESSAGE)],
                'runtime exception' => [new \RuntimeException(self::MESSAGE)],
                'invalid argument exception' => [new \InvalidArgumentException(self::MESSAGE)],
                'logic exception' => [new \LogicException(self::MESSAGE)],
                'security exception' => [new \Idno\Exceptions\SecurityException(self::MESSAGE)],
                'configuration exception' => [new \Idno\Exceptions\ConfigurationException(self::MESSAGE)],
                'a plugin\'s own exception class' => [
                    new class(self::MESSAGE) extends \RuntimeException {
                    }
                ],
                'friendly exception' => [new \Idno\Exceptions\FriendlyException(self::MESSAGE)],
                'marked friendly, though its parent is marked technical' => [
                    new class(self::MESSAGE) extends \Idno\Exceptions\TechnicalException implements \Idno\Exceptions\Friendly {
                    }
                ],
                'marked friendly, though its parent belongs to an extension' => [
                    new class(self::MESSAGE) extends \PDOException implements \Idno\Exceptions\Friendly {
                    }
                ],
            ];
        }

        /**
         * Exceptions whose message is a technical one.
         */
        public static function technicalProvider()
        {
            return [
                'technical exception' => [new \Idno\Exceptions\TechnicalException(self::MESSAGE)],
                'a plugin\'s own exception class, marked technical' => [
                    new class(self::MESSAGE) extends \RuntimeException implements \Idno\Exceptions\Technical {
                    }
                ],
                'PHP error' => [new \Error(self::MESSAGE)],
                'PHP type error' => [new \TypeError(self::MESSAGE)],
                'PHP warning turned into an exception' => [new \ErrorException(self::MESSAGE)],
                'an extension\'s exception class' => [new \PDOException(self::MESSAGE)],
                'another extension\'s exception class' => [new \JsonException(self::MESSAGE)],
                'a class that extends an extension\'s' => [
                    new class(self::MESSAGE) extends \PDOException {
                    }
                ],
                'a library\'s exception class' => [new \Twig\Error\RuntimeError(self::MESSAGE)],
                'a class that extends a library\'s' => [
                    new class(self::MESSAGE) extends \Twig\Error\RuntimeError {
                    }
                ],
                'plain exception thrown by a library' => [self::plainExceptionFromLibrary()],
            ];
        }

        /**
         * @dataProvider friendlyProvider
         */
        public function testMessageIsFriendlyUnlessTheTypeSaysOtherwise($exception)
        {
            $this->assertFalse(ExceptionMessage::isTechnical($exception));
        }

        /**
         * @dataProvider technicalProvider
         */
        public function testMessageIsTechnicalWhenTheTypeSaysSo($exception)
        {
            $this->assertTrue(ExceptionMessage::isTechnical($exception));
        }

        /**
         * @dataProvider friendlyProvider
         */
        public function testVisitorIsShownAFriendlyMessage($exception)
        {
            $this->assertSame(self::MESSAGE, ExceptionMessage::forVisitor($exception));
        }

        /**
         * @dataProvider technicalProvider
         */
        public function testVisitorIsNotShownATechnicalMessage($exception)
        {
            $this->assertSame($this->fixedText(), ExceptionMessage::forVisitor($exception));
        }

        /**
         * @dataProvider technicalProvider
         */
        public function testTechnicalMessageIsShownInDebugMode($exception)
        {
            \Idno\Core\Idno::site()->config()->debug = true;

            $this->assertSame($exception->getMessage(), ExceptionMessage::forVisitor($exception));
        }

        public function testVisitorIsShownTheFixedTextWhenThereIsNoMessage()
        {
            $this->assertSame($this->fixedText(), ExceptionMessage::forVisitor(new \RuntimeException('')));
        }

        public function testTechnicalExceptionIsARuntimeException()
        {
            // Code that already catches \RuntimeException or \Exception must keep catching it
            $this->assertInstanceOf(\RuntimeException::class, new \Idno\Exceptions\TechnicalException(self::MESSAGE));
            $this->assertInstanceOf(\RuntimeException::class, new \Idno\Exceptions\FriendlyException(self::MESSAGE));
        }

    }

}
