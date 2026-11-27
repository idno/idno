<?php

namespace Tests\Common;

use Idno\Entities\GenericDataItem;
use Idno\Core\Idno;
use Idno\Core\Webservice;

class EntityTest extends \Tests\IdnoTestCase
{

    public function setUp(): void
    {
        $this->user()->notifications['email'] = 'none';
        $this->user()->save();
    }

    function tearDown(): void
    {
        if (isset($this->toDelete)) {
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
        }
    }

    public function slugProvider()
    {

        return [
            'simple title' => ['Test a Simple Title', 'test-a-simple-title', 10, 255],
            'long title' => [
                "True and I don't really like how there are never leftovers. But the recipes have been really creative, definitely different stuff (and more complicated) than I would think to cook on my own.",
                'true-and-i-dont-really-like-how-there-are-never', 10, 255],
            'weird title' => ['Aus BASE wird O2 - Dann versuchen wir mal, den Kunden über den Tisch zu ziehen', 'aus-base-wird-o2---dann-versuchen-wir-mal-den', 10, 255],
            'like umlat' => ['liked a post by Ben Werdmüller', 'liked-a-post-by-ben-werdm%C3%BCller', 10, 255],
            'percentage' => ['The Top 1% of the Top 1%', 'the-top-1%25-of-the-top-1%25', 10, 255],
            'non-english' => ['Voilà, this title has a % sign, до свидания', 'voil%C3%A0-this-title-has-a-%25-sign-%D0%B4%D0%BE-%D1%81%D0%B2%D0%B8%D0%B4%D0%B0%D0%BD%D0%B8%D1%8F', 10, 255],
            'few words' => ['ThisTitleIsReallyReallyLong WithVeryFewWords', 'thistitleisreallyreallylong-with', 10, 32],
            'russian' => ['Да, я ж давеча в Спортмастере был', '%D0%B4%D0%B0-%D1%8F-%D0%B6-%D0%B4%D0%B0%D0%B2%D0%B5%D1%87%D0%B0-%D0%B2-%D1%81%D0%BF%D0%BE%D1%80%D1%82%D0%BC%D0%B0%D1%81%D1%82%D0%B5%D1%80%D0%B5-%D0%B1%D1%8B%D0%BB', 10, 255],
            'russian few chars' => ['Да, я ж давеча в Спортмастере был', '%D0%B4%D0%B0-%D1%8F', 10, 24],
            'collapsed spaces' => ['Make Sure    <b>Spaces</b> are  Collapsed and Tags  Are  <i>Stripped</i>', 'make-sure-spaces-are-collapsed-and-tags-are-stripped', 10, 255],
        ];

    }

    public function webmentionEntityProvider()
    {
        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');
        $entity->setOwner($this->user());
        $entity->title = "This will be the target of our webmention";
        $entity->publish();
        return $entity;
    }

    // A slug is lower case with non-ASCII percent-encoded, and can be cut to a number of words and of bytes.
    public function testPrepareSlug()
    {
        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');

        $unique = md5(time() . rand(0, 9999));
        $title = "IndieWebCamp Nürnberg $unique is live!";
        $expected = "indiewebcamp-n%C3%BCrnberg-$unique-is-live";

        $this->assertEquals($expected, $entity->prepareSlug($title), 'Slug should have matched.');
        $this->assertEquals("indiewebcamp-n%C3%BCrnberg-$unique", $entity->prepareSlug($title, 3), "Slug should have matched indiewebcamp-n%C3%BCrnberg-$unique.");
        $this->assertEquals("indie", $entity->prepareSlug($title, 3, 5), 'Slug should have matched indie.');
        $this->assertEquals("indie-hello", $entity->prepareSlug($title, 3, 5, 'hello'), 'Slug should have matched indie-hello.');
    }

    // A second post with the same title gets an address of its own, not the address of the first.
    function testSetSlugResilient()
    {
        $unique = md5(time() . rand(0, 9999));
        $title = "IndieWebCamp Nürnberg $unique is live!";
        $slug = "indiewebcamp-n%C3%BCrnberg-$unique-is-live";

        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');
        $entity->setSlugResilient($title);
        $this->assertEquals($slug, $entity->getSlug(), 'Slug should have matched '. $slug . '.');
        $entity->save(true);
        $this->toDelete[] = $entity;

        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');
        $entity->setSlugResilient($title);
        $this->assertEquals($slug . '-1', $entity->getSlug(), 'Because there was a slug collision, slug should have matched ' . $slug . '-1.');
        $entity->save(true);
        $this->toDelete[] = $entity;
    }

    /**
     * A post whose body has a multi-byte character across the 255th byte (where the
     * MySQL back-end cuts the copy of each field that it indexes) is saved and can be
     * loaded again with its body unchanged.
     */
    function testSaveMultiByteBodyAcrossIndexLimit()
    {
        $body = str_repeat('é', 200) . ' ' . md5(time() . rand(0, 9999));

        $entity = new \IdnoPlugins\Status\Status();
        $entity->setOwner($this->user());
        $entity->setAccess('PUBLIC');
        $entity->body = $body;
        $id = $entity->save(true);
        $this->toDelete[] = $entity;

        $this->assertNotEmpty($id, 'save() should have returned the ID of the saved post.');

        $loaded = \IdnoPlugins\Status\Status::getByID($id);
        $this->assertInstanceOf('\IdnoPlugins\Status\Status', $loaded, 'The saved post should have been retrievable by ID.');
        $this->assertSame($body, $loaded->body, 'The body of the saved post should be unchanged.');
    }

    /**
     * Updating an existing post with such a body stores the new version.
     */
    function testUpdateWithMultiByteBodyAcrossIndexLimit()
    {
        $unique = md5(time() . rand(0, 9999));

        $entity = new \IdnoPlugins\Status\Status();
        $entity->setOwner($this->user());
        $entity->setAccess('PUBLIC');
        $entity->body = 'The first version ' . $unique;
        $id = $entity->save(true);
        $this->toDelete[] = $entity;

        $this->assertNotEmpty($id, 'save() should have returned the ID of the saved post.');

        $body = 'a' . str_repeat('日', 100) . ' ' . $unique;
        $entity->body = $body;
        $this->assertEquals($id, $entity->save(true), 'save() should have returned the ID of the updated post.');

        $loaded = \IdnoPlugins\Status\Status::getByID($id);
        $this->assertInstanceOf('\IdnoPlugins\Status\Status', $loaded, 'The updated post should have been retrievable by ID.');
        $this->assertSame($body, $loaded->body, 'The body of the updated post should be the new version.');
    }


    /**
     * Send a simple reply and make sure it gets collected.
     */
    function testAddWebmentions_SimpleReply()
    {
        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');
        $entity->setOwner($this->user());
        $entity->title = "This will be the target of our webmention";
        $entity->publish();
        $this->toDelete[] = $entity;

        $target = $entity->getURL();
        $source = 'http://example.com/2015/this-is-a-reply';
        $sourceContent = <<<EOD
<!DOCTYPE html>
<html>
<body class="h-entry">
  <a class="u-in-reply-to" href="$target">in reply to</a>
  <span class="p-name e-content">This is a reply</span>
  <a class="u-url" href="http://example.com/2015/this-is-a-reply">permalink</a>
  <a class="p-author h-card" href="https://example.com/">Jane Example</a>
</body>
</html>
EOD;
        $sourceResp = ['response' => 200, 'content' => $sourceContent];
        $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
        $entity->addWebmentions($source, $target, $sourceResp, $sourceMf2);

        $this->assertArrayHasKey('reply', $entity->getAllAnnotations(), 'Annotations should have included a reply affter a webmention.');
        $this->assertCount(1, $entity->getAllAnnotations()['reply'], 'There should have been exactly one reply.');

        $anno = array_values($entity->getAllAnnotations()['reply'])[0];

        $this->assertArrayHasKey('owner_name', $anno, 'The owner name should have been set.');
        $this->assertEquals('Jane Example', $anno['owner_name'], 'The owner name should have been set to Jane Example.');
        $this->assertArrayHasKey('permalink', $anno, 'The permalink should have been set.');
        $this->assertEquals($source, $anno['permalink'], 'The permalink should have been set to ' . $source . '.');
    }

    /**
     * Test webmention likes
     */
    function testAddWebmentionLikes()
    {
        $entity = $this->webmentionEntityProvider();
        $this->toDelete[] = $entity;
        $target = $entity->getURL();

        $sources = [
            'http://joe.example/this-is-a-like' => <<<EOD
<!DOCTYPE html>
<html>
<body>
<div class="h-entry">
  <p class="p-name">
    I liked this other post
    <a class="u-like-of" href="$target">on example.com</a>
  </p>
</div>
<a class="h-card" href="https://joe.example/">Joe Example</a>
</body>
</html>
EOD
            , 'http://chelsea.example/chelsea-liked-a-post' => <<<EOD
<!DOCTYPE html>
<html>
<body class="h-entry">
  <p class="p-name">
    <a class="p-author h-card" href="https://chelsea.example/">Chelsea Example</a> liked a post
    <a class="u-like-of" href="$target">on example.com</a>
  </p>
</body>
</html>
EOD
        ];

        foreach ($sources as $source => $sourceContent) {
            $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
            $entity->addWebmentions($source, $target, ['response' => 200, 'content' => $sourceContent], $sourceMf2);
        }

        $this->assertArrayHasKey('like', $entity->getAllAnnotations(), 'A like should have been set after a webmention.');
        $this->assertCount(2, $entity->getAllAnnotations()['like'], 'There should have been exactly two likes.');
        $anno = array_values($entity->getAllAnnotations()['like'])[0];
        $this->assertArrayHasKey('owner_name', $anno, 'The owner name should have been set.');
        $this->assertEquals('Joe Example', $anno['owner_name'], 'The owner name should have been set to Joe Example.');
        $this->assertArrayHasKey('permalink', $anno, 'The permalink should have been set.');
        $this->assertEquals('http://joe.example/this-is-a-like', $anno['permalink'], 'The permalink should have been set to http://joe.example/this-is-a-like.');

        $anno = array_values($entity->getAllAnnotations()['like'])[1];

        $this->assertArrayHasKey('owner_name', $anno, 'The owner name should have been set.');
        $this->assertEquals('Chelsea Example', $anno['owner_name'], 'The owner name should have been set to Chelsea Example.');
        $this->assertArrayHasKey('permalink', $anno, 'The permalink should have been set.');
        $this->assertEquals('http://chelsea.example/chelsea-liked-a-post', $anno['permalink'], 'The permalink should have been set to http://chelsea.example/chelsea-liked-a-post.');
    }

    // A page that links to a post arrives as a mention under the post, with its author and address.
    function testMentionWebmention()
    {
        $entity = $this->webmentionEntityProvider();
        $this->toDelete[] = $entity;
        $target = $entity->getURL();

        $webmention_source = 'http://gary.example/this-is-a-mention';
        $webmention_body = <<<EOD
<!DOCTYPE html>
<html>
<body>
<div class="h-entry">
  <p class="p-name">
    I just want to mention this post <a href="$target">on example.com</a>
  </p>
  <a class="p-author h-card" href="http://gary.example/">Gary Example</a>
  <a class="u-url" href="http://gary.example/this-is-a-mention"></a>
</div>
</body>
</html>
EOD;

        $sourceMf2 = (new \Mf2\Parser($webmention_body, $webmention_source))->parse();
        $entity->addWebmentions($webmention_source, $target, ['response' => 200, 'content' => $webmention_body], $sourceMf2);

        $anno = array_values($entity->getAllAnnotations()['mention'])[0];

        $this->assertArrayHasKey('mention', $entity->getAllAnnotations(), 'A mention should have been set after a webmention.');
        $this->assertArrayHasKey('owner_name', $anno, 'The owner name should have been set.');
        $this->assertEquals('Gary Example', $anno['owner_name'], 'The owner name should have been set to Gary Example.');
        $this->assertArrayHasKey('permalink', $anno, 'The permalink should have been set.');
        $this->assertEquals('http://gary.example/this-is-a-mention', $anno['permalink'], 'The permalink should have been set to http://gary.example/this-is-a-mention.');
    }


    /**
     * When we get a webmention where the source is a feed, make
     * sure we handle it gracefully.
     */
    function testAddWebmentionsToRemoteFeed()
    {
        $entity = new GenericDataItem();
        $entity->setDatatype('data-slug-test');
        $entity->setOwner($this->user());
        $entity->title = "This post will be the webmention target";
        $entity->publish();
        $this->toDelete[] = $entity;

        $target = $entity->getURL();
        $source = 'http://example.com/';
        $sourceContent = <<<EOD
<!DOCTYPE html>
<html>
<body>
  <div class="h-entry">
    <a class="p-author h-card" href="https://example.com/">Jane Example</a>
    <span class="p-name e-content">This is just nonsense</span>
    <a class="u-url" href="http://example.com/2015/this-is-just-nonsense">permalink</a>
  </div>
  <div class="h-entry">
    <a class="u-in-reply-to" href="$target">in reply to</a>
    <a class="p-author h-card" href="https://example.com/">Jane Example</a>
    <span class="p-name e-content">This is a reply</span>
    <a class="u-url" href="http://example.com/2015/this-is-a-reply">permalink</a>
  </div>
  <div class="h-entry">
    <a class="p-author h-card" href="https://example.com/">Jane Example</a>
    <span class="p-name e-content">This is probably really serious</span>
    <a class="u-url" href="http://example.com/2015/this-is-probably-really-serious">permalink</a>
  </div>
</body>
</html>
EOD;

        $sourceResp = ['response' => 200, 'content' => $sourceContent];
        $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
        $entity->addWebmentions($source, $target, $sourceResp, $sourceMf2);

        $this->assertEmpty($entity->getAllAnnotations(), 'Annotations should have been empty after sending a webmention to a feed.');
    }

    /**
     * Test that plain string content values (not {value, html} objects) are handled.
     * Regression test for https://github.com/idno/known/issues/3199
     */
    function testAddWebmentions_PlainStringContent()
    {
        $entity = $this->webmentionEntityProvider();
        $this->toDelete[] = $entity;
        $target = $entity->getURL();

        $source = 'http://example.com/2024/plain-content-reply';
        $sourceContent = <<<EOD
<!DOCTYPE html>
<html>
<body class="h-entry">
  <a class="u-in-reply-to" href="$target">in reply to</a>
  <span class="p-name">This is plain text content</span>
  <a class="u-url" href="$source">permalink</a>
  <a class="p-author h-card" href="https://example.com/">Plain Jane</a>
</body>
</html>
EOD;
        $sourceResp = ['response' => 200, 'content' => $sourceContent];
        $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
        $entity->addWebmentions($source, $target, $sourceResp, $sourceMf2);

        $this->assertArrayHasKey('reply', $entity->getAllAnnotations(), 'A reply annotation should have been created.');
        $anno = array_values($entity->getAllAnnotations()['reply'])[0];
        $this->assertIsString($anno['content'], 'Annotation content should be a string.');
        $this->assertIsString($anno['permalink'], 'Annotation permalink should be a string.');
        $this->assertIsString($anno['owner_name'], 'Annotation owner_name should be a string.');
    }

    /**
     * Test that mf2 photo values with alt text objects are handled.
     * Regression test for https://github.com/idno/known/issues/3199
     */
    function testAddWebmentions_PhotoWithAltText()
    {
        $entity = $this->webmentionEntityProvider();
        $this->toDelete[] = $entity;
        $target = $entity->getURL();

        // Construct mf2 data directly with a photo that has alt text (object form)
        $source = 'http://example.com/2024/photo-alt-mention';
        $sourceContent = <<<EOD
<!DOCTYPE html>
<html>
<body class="h-entry">
  <a class="u-in-reply-to" href="$target">in reply to</a>
  <span class="p-name e-content">A reply with author photo</span>
  <a class="u-url" href="$source">permalink</a>
  <div class="p-author h-card">
    <a class="p-name u-url" href="https://example.com/">Photo Jane</a>
    <img class="u-photo" src="https://example.com/photo.jpg" alt="Jane's photo" />
  </div>
</body>
</html>
EOD;
        $sourceResp = ['response' => 200, 'content' => $sourceContent];
        $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();

        // Simulate the case where the mf2 parser returns photo as an object with alt text
        // by modifying the parsed data to use the {value, alt} form
        foreach ($sourceMf2['items'] as &$item) {
            if (isset($item['type']) && in_array('h-entry', $item['type'])) {
                if (!empty($item['properties']['author'])) {
                    foreach ($item['properties']['author'] as &$author) {
                        if (is_array($author) && isset($author['properties']['photo'])) {
                            $author['properties']['photo'] = [
                                ['value' => 'https://example.com/photo.jpg', 'alt' => "Jane's photo"]
                            ];
                        }
                    }
                }
            }
        }

        $entity->addWebmentions($source, $target, $sourceResp, $sourceMf2);

        $this->assertArrayHasKey('reply', $entity->getAllAnnotations(), 'A reply annotation should have been created.');
        $anno = array_values($entity->getAllAnnotations()['reply'])[0];
        $this->assertEquals('Photo Jane', $anno['owner_name'], 'Owner name should be set.');
        $this->assertIsString($anno['permalink'], 'Permalink should be a string.');
    }

    /**
     * A particularly knotty case when we get a webmention from *our
     * own* feed. It looks valid because it includes a link, but it's
     * not really.
     */
    function testAddWebmentionsToLocalFeed()
    {
        for ($i = 0; $i < 5; $i++) {
            $entity = new GenericDataItem();
            $entity->setDatatype('data-slug-test');
            $entity->setOwner($this->user());
            $entity->title = "This post will be the webmention target";
            $entity->publish();
            $entities[] = $entity;
            $this->toDelete[] = $entity;
        }

        // take one from the middle
        $entity = $entities[2];

        $source = \Idno\Core\Idno::site()->config()->url;
        $target = $entity->getURL();

        // render the homepage feed
        $sourceResp = Webservice::get($source);
        $sourceContent = $sourceResp['content'];
        $sourceMf2 = (new \Mf2\Parser($sourceContent, $source))->parse();
        $entity->addWebmentions($source, $target, $sourceResp, $sourceMf2);

        $this->assertEmpty($entity->getAllAnnotations(), 'Annotations should have been empty after sending a webmention to a local feed.');
    }


}
