<?php

namespace Tests\Core {

    /**
     * A post's address has one layout, `/YYYY/<slug>`: a `permalink_structure` stored by a site that could choose one is ignored, and an address in another
     * layout the site once offered is sent permanently to the post's own.
     */
    class PermalinkStructureTest extends \Tests\IdnoTestCase
    {

        private function createEntry()
        {
            $rnd = rand(0, 9999).'-'.time();
            $entity = new \IdnoPlugins\Text\Entry();
            $entity->setOwner($this->user());
            $entity->title = "The Title $rnd";
            $entity->body = 'Unlikely to be present elsewhere in the post template: hamstring baseball duckbill firecracker';
            $entity->save(true);
            return $entity;
        }

        /**
         * @return array{0: int, 1: string|null} the status and the Location of a GET that follows no redirect
         */
        private function answer(string $url): array
        {
            $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'method' => 'GET']]);
            file_get_contents($url, false, $context);
            $status = 0;
            $location = null;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                } elseif (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
            }

            return [$status, $location];
        }

        public function testAStoredPermalinkStructureIsIgnored()
        {
            $entity = $this->createEntry();
            try {
                $base = \Idno\Core\Idno::site()->config()->getDisplayUrl();
                $year = date('Y', $entity->created);
                $slug = $entity->getSlug();

                foreach ([null, '/:year/:month/:slug', '/:year/:month/:day/:slug', '/:slug'] as $stored) {
                    \Idno\Core\Idno::site()->config()->permalink_structure = $stored;
                    \Idno\Core\Idno::site()->config()->save();
                    $this->assertEquals('/[0-9]{4}/([^/.]+)', \Idno\Common\Entity::getPermalinkRoute());
                    $this->assertEquals('/:year/:slug', \Idno\Core\Idno::site()->config()->getPermalinkStructure());
                    $this->assertEquals("$base$year/$slug", $entity->getURL());
                    $contents = file_get_contents($entity->getURL());
                    $this->assertNotFalse(strpos($contents, 'hamstring baseball duckbill firecracker'), 'The specified string should have been found in the entity body. If this is failing, IDNO_DOMAIN may not be set.');
                }
            } finally {
                \Idno\Core\Idno::site()->config()->permalink_structure = null;
                \Idno\Core\Idno::site()->config()->save();
                $entity->delete();
            }
        }

        public function testTheOtherLayoutsASiteCouldChooseAreSentToThePostsAddress()
        {
            $entity = $this->createEntry();
            try {
                $base = \Idno\Core\Idno::site()->config()->getDisplayUrl();
                $slug = $entity->getSlug();
                $year = date('Y', $entity->created);
                $month = date('m', $entity->created);
                $day = date('d', $entity->created);

                foreach (["$base$year/$month/$slug", "$base$year/$month/$day/$slug"] as $old) {
                    [$status, $location] = $this->answer($old);
                    $this->assertSame(301, $status, $old);
                    $this->assertSame($entity->getURL(), $location, $old);
                }
                [$status] = $this->answer($entity->getURL());
                $this->assertSame(200, $status);
            } finally {
                $entity->delete();
            }
        }
    }

}
