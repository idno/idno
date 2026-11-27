<?php

namespace Tests\Core {

    class DummyPage extends \Idno\Common\Page
    {
    }

    class InputTest extends \Tests\IdnoTestCase
    {

        public function testInputDefaults()
        {

            $this->assertNull(\Idno\Core\Input::getInput('nulltest', null), 'getInput should return null when this is specified as the default value and no input with the specified name is found.');
            $this->assertTrue(false === \Idno\Core\Input::getInput('falsetest', false), 'getInput should return false when this is specified as the default value and no input with the specified name is found.');
            $this->assertTrue(true === \Idno\Core\Input::getInput('truetest', true), 'getInput should return true when this is specified as the default value and no input with the specified name is found.');

            $page = new DummyPage();

            $this->assertNull($page->getInput('nulltest', null), 'getInput should return null when this is specified as the default value and no input with the specified name is found.');
            $this->assertTrue(false === $page->getInput('falsetest', false), 'getInput should return false when this is specified as the default value and no input with the specified name is found.');
            $this->assertTrue(true === $page->getInput('truetest', true), 'getInput should return true when this is specified as the default value and no input with the specified name is found.');
        }

        /**
         * A field read as one text is the text that was sent, and the default for a field that
         * was sent as an array (name[]=x, name[$gt]=x, an object in a JSON body) or not at all
         */
        public function testTextInput()
        {
            $this->assertSame('words', \Idno\Core\Input::text('words'));
            $this->assertSame('', \Idno\Core\Input::text(''));
            $this->assertSame('7', \Idno\Core\Input::text(7));
            $this->assertSame('1.5', \Idno\Core\Input::text(1.5));
            foreach ([['a'], ['$gt' => ''], [], null, true, false, new \stdClass()] as $value) {
                $this->assertSame('', \Idno\Core\Input::text($value));
                $this->assertSame('fallback', \Idno\Core\Input::text($value, 'fallback'));
            }

            $page = new DummyPage();
            $page->setInput('texttest', 'words');
            $page->setInput('listtest', ['words']);
            $page->setInput('maptest', ['$gt' => '']);
            $this->assertSame('words', $page->getTextInput('texttest'));
            $this->assertSame('', $page->getTextInput('listtest'));
            $this->assertSame('', $page->getTextInput('maptest'));
            $this->assertSame('fallback', $page->getTextInput('maptest', 'fallback'));
            $this->assertSame('', $page->getTextInput('missingtest'));
            $this->assertSame('fallback', $page->getTextInput('missingtest', 'fallback'));

            $_REQUEST['requesttest'] = ['$gt' => ''];
            try {
                $this->assertSame('', $page->getTextInput('requesttest'));
                $_REQUEST['requesttest'] = 'typed';
                $this->assertSame('typed', $page->getTextInput('requesttest'));
            } finally {
                unset($_REQUEST['requesttest']);
            }
        }

    }

}
