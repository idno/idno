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

        /** @var array<int,array{0:string,1:string}> [user UUID, token] pairs issued by issueMicropubToken() */
        private array $tokens = [];

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
            $first = null;
            try {
                foreach ($this->tokens as [$userUuid, $token]) {
                    try {
                        $user = $this->freshUser($userUuid);
                        $held = $user->indieauth_tokens;
                        if (is_array($held) && isset($held[$token])) {
                            unset($held[$token]);
                            $user->indieauth_tokens = $held;
                            $user->save(true);
                            $this->refreshSharedUser($user);
                        }
                    } catch (\Throwable $e) {
                        $first ??= $e;
                    }
                }
                foreach ($this->fixtures as $entity) {
                    try {
                        // Reload: the web server may have changed the stored entity.
                        $uuid = $entity->getUUID();
                        \Idno\Common\Entity::invalidateCache($uuid);
                        $fresh = \Idno\Common\Entity::getByUUID($uuid);
                        if ($fresh instanceof \Idno\Common\Entity) {
                            $fresh->delete();
                        }
                        \Idno\Common\Entity::invalidateCache($uuid);
                    } catch (\Throwable $e) {
                        $first ??= $e;
                    }
                }
            } finally {
                $this->tokens   = [];
                $this->fixtures = [];
                parent::tearDown();
            }
            // A leftover fixture is worse than a red test.
            if ($first !== null) {
                throw $first;
            }
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
         * Whether a status with exactly this body is stored. Lets a test prove a
         * request did not create anything.
         */
        protected function statusExists(string $body): bool
        {
            return \IdnoPlugins\Status\Status::getOne(['body' => $body]) !== false;
        }

        /**
         * Whether an ActivityPub follower record exists for this remote actor
         * (any local user). Lets a test prove a request did not create one.
         */
        protected function followerExists(string $actorUri): bool
        {
            return \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower::getOne(['actor_uri' => $actorUri]) !== false;
        }

        /**
         * Store an IndieAuth access token on the user, exactly as the token
         * endpoint does, and return it for use as a Bearer token. Removed again
         * in tearDown().
         */
        protected function issueMicropubToken(\Idno\Entities\User $user, string $scope = 'create'): string
        {
            $token = md5(uniqid('token', true));
            // Register first so a failed save is still cleaned up.
            $this->tokens[] = [$user->getUUID(), $token];

            $fresh = $this->freshUser($user->getUUID());
            $held  = $fresh->indieauth_tokens;
            if (!is_array($held)) {
                $held = [];
            }
            $held[$token] = [
                'me'           => $this->site()->baseUrl(),
                'redirect_uri' => 'https://client.invalid/cb',
                'scope'        => $scope,
                'client_id'    => 'https://client.invalid/',
                'issued_at'    => time(),
                'nonce'        => mt_rand(1000000, pow(2, 30)),
            ];
            $fresh->indieauth_tokens = $held;
            $fresh->save(true);
            $this->refreshSharedUser($fresh);

            return $token;
        }

        /**
         * Find the entity stored at a URL (for example a Location header from a
         * Micropub create) and register it for deletion in tearDown(). Fails the
         * test if there is no such entity.
         */
        protected function registerEntityAtUrl(string $absoluteUrl): \Idno\Common\Entity
        {
            $slug   = basename((string)parse_url($absoluteUrl, PHP_URL_PATH));
            $entity = \Idno\Common\Entity::getBySlug($slug);
            $this->assertInstanceOf(\Idno\Common\Entity::class, $entity, "No entity stored at $absoluteUrl");
            $this->registerFixture($entity);
            $this->assertSame($absoluteUrl, $entity->getURL());

            return $entity;
        }

        private function freshUser(string $uuid): \Idno\Entities\User
        {
            \Idno\Common\Entity::invalidateCache($uuid);
            $user = \Idno\Common\Entity::getByUUID($uuid);
            $this->assertInstanceOf(\Idno\Entities\User::class, $user);

            return $user;
        }

        /**
         * Keep the shared test user object in step with what is stored, so a
         * later save of it does not resurrect a removed token.
         */
        private function refreshSharedUser(\Idno\Entities\User $fresh): void
        {
            if (static::$testUser && static::$testUser->getUUID() === $fresh->getUUID()) {
                static::$testUser = $fresh;
            }
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

        /**
         * Absolute short URL of an entity. The short code is generated lazily and
         * its internal save() is refused without a session, so persist it here
         * for the web server to see.
         */
        protected function shortUrl(\Idno\Common\Entity $entity): string
        {
            $short = $entity->getShortURL();
            $entity->save(true);

            return $short;
        }

        /**
         * Track an entity for deletion in tearDown(). Called before saving so a
         * partially failed save is still cleaned up.
         */
        protected function registerFixture(\Idno\Common\Entity $entity): void
        {
            $this->fixtures[] = $entity;
        }

        private function saveFixture(\Idno\Common\Entity $entity, \Idno\Entities\User $owner, string $access, string $publishStatus): \Idno\Common\Entity
        {
            $this->registerFixture($entity);
            $entity->setOwner($owner);
            // 'PUBLIC' for public; otherwise the access group id, e.g. the owner's UUID for private.
            $entity->setAccess($access);
            $entity->setPublishStatus($publishStatus);
            $entity->save(true);

            return $entity;
        }
    }

}
