<?php

namespace Tests\Characterisation {

    /**
     * Base class for black-box characterisation tests. Tests talk to the site
     * only through site(); fixture helpers are the only place that touch Idno
     * internals.
     */
    abstract class CharacterisationTestCase extends \Tests\IdnoTestCase
    {
        private ?SiteClient $site = null;

        /** @var \Idno\Common\Entity[] Entities created by fixture helpers */
        private array $fixtures = [];

        protected function setUp(): void
        {
            parent::setUp();
            // An empty site redirects to onboarding; make it look installed.
            $this->admin();
            $this->user();
        }

        protected function tearDown(): void
        {
            // Idempotent: may be called explicitly by a test and again by PHPUnit.
            foreach ($this->fixtures as $entity) {
                // Reload: the web server may have changed the stored entity.
                $uuid = $entity->getUUID();
                \Idno\Common\Entity::invalidateCache($uuid);
                $fresh = \Idno\Common\Entity::getByUUID($uuid);
                if ($fresh instanceof \Idno\Common\Entity) {
                    $fresh->delete();
                }
                \Idno\Common\Entity::invalidateCache($uuid);
            }
            $this->fixtures = [];
            parent::tearDown();
        }

        protected function site(): SiteClient
        {
            if ($this->site === null) {
                $this->site = new HttpSiteClient();
            }

            return $this->site;
        }

        protected function createStatus(\Idno\Entities\User $owner, string $body, string $access = 'PUBLIC', string $publishStatus = 'published'): \IdnoPlugins\Status\Status
        {
            $status = new \IdnoPlugins\Status\Status();
            $status->body = $body;

            return $this->saveFixture($status, $owner, $access, $publishStatus);
        }

        protected function createArticle(\Idno\Entities\User $owner, string $title, string $body, string $access = 'PUBLIC'): \IdnoPlugins\Text\Entry
        {
            $entry = new \IdnoPlugins\Text\Entry();
            $entry->title = $title;
            $entry->body  = $body;

            return $this->saveFixture($entry, $owner, $access, 'published');
        }

        /**
         * Strip the site's base URL from an absolute URL.
         */
        protected function path(string $absoluteUrl): string
        {
            $base = $this->site()->baseUrl();
            if (str_starts_with($absoluteUrl, $base)) {
                return substr($absoluteUrl, strlen($base));
            }

            return $absoluteUrl;
        }

        private function saveFixture(\Idno\Common\Entity $entity, \Idno\Entities\User $owner, string $access, string $publishStatus): \Idno\Common\Entity
        {
            $entity->setOwner($owner);
            // 'PUBLIC' for public; otherwise the access group id, e.g. the owner's UUID for private.
            $entity->setAccess($access);
            $entity->setPublishStatus($publishStatus);
            $entity->save(true);
            $this->fixtures[] = $entity;

            return $entity;
        }
    }

}
