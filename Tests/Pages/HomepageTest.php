<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;

    class HomepageTest extends \Tests\IdnoTestCase
    {

        function testHomepageLoads()
        {
            $this->admin(); // a site with no user answers its home page 503: make sure there is one

            // Get the rendered homepage
            $contents = file_get_contents(\Idno\Core\Idno::site()->config()->getDisplayURL());

            // Make sure it's not empty
            $this->assertNotEmpty($contents, 'The homepage load should not be empty. If this is failing, you may need to set IDNO_DOMAIN.');
        }

        function test404Page()
        {
            $result = Webservice::get(Idno::site()->config()->getURL() . 'this-resource-does-not-exist');
            $this->assertEquals(404, $result['response'], 'Loading a nonexistent resource should result in a 404 error.');
        }

        private function doWebmentionContent($source, $target)
        {
            $sourceContent = <<<EOD
<div class="h-entry">
  <a class="p-author h-card" href="http://foo.bar">Foo Bar</a>
  <span class="p-name e-content">test mention of $target</span>
</div>
EOD;
            $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
            $sourceResp = ['response' => 200, 'content' => $sourceContent];

            $homepage = new \Idno\Pages\Homepage();

            return $homepage->webmentionContent($source, $target, $sourceResp, $sourceMf2);
        }


        /**
         * Test that in single-user mode, mentions of the homepage
         * are handed off to the user profile page.
         */
        function testWebmentionContentSingleUser()
        {
            Idno::site()->config()->single_user = true;
            $this->admin(); // make sure there is an admin user
            $source = 'http://foo.bar/homepage-mention-single-user-'.md5(time() . rand(0, 9999));
            $target = Idno::site()->config()->getDisplayURL();
            $result = $this->doWebmentionContent($source, $target);
            $this->assertTrue($result !== false, 'Webmention to single-user homepage should be accepted.');
        }


        /**
         * Test that in single-user mode without a trailing slash
         * on the domain name
         */
        function testWebmentionContentSingleUserNoSlash()
        {
            Idno::site()->config()->single_user = true;
            $this->admin(); // make sure there is an admin user
            $source = 'http://foo.bar/homepage-mention-single-user-no-slash-'.md5(time() . rand(0, 9999));
            $target = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            $result = $this->doWebmentionContent($source, $target);
            $this->assertTrue($result !== false, 'Webmention to single-user homepage (no trailing slash) should be accepted.');
        }

        /**
         * Make sure that homepage mentions are ignored for multiuser sites
         */
        function testWebmentionContentMultiUser()
        {
            Idno::site()->config()->single_user = false;
            $source = 'http://foo.bar/homepage-mention-multi-user-'.md5(time() . rand(0, 9999));
            $target = Idno::site()->config()->getDisplayURL();
            $result = $this->doWebmentionContent($source, $target);
            $this->assertFalse($result);
        }

    }

}
