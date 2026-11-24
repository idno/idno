<?php

namespace IdnoPlugins\Status\Tests {

    use IdnoPlugins\Status\Reply;
    use IdnoPlugins\Status\Status;

    /**
     * The title of a status is a string, also when its body says nothing in words (only tags, or nothing): the Open Graph
     * template strips tags from it, and a title that was an object made every page of such a post a server error.
     */
    class StatusTitleTest extends \Tests\IdnoTestCase
    {
        public function testAStatusWithABodyOfOnlyTagsHasTheDefaultTitleAsAString(): void
        {
            foreach ([new Status(), new Reply()] as $status) {
                $status->body = '<x-canary a="1"></x-canary>';

                $this->assertIsString($status->getTitle(), get_class($status));
                $this->assertSame('Status update', $status->getTitle());
            }
        }

        public function testAStatusWithWordsIsTitledByThem(): void
        {
            $status = new Status();
            $status->body = 'Hello world';

            $this->assertSame('Hello world', $status->getTitle());
        }
    }
}
