<?php

namespace IdnoPlugins\Atmosphere\Tests {

    use Idno\Core\Idno;

    /**
     * Whose AT Protocol identity /.well-known/site.standard.publication names as the owner of
     * the site's publication.
     *
     * The page is asked for over HTTP, so this test needs the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class PublicationOwnerTest extends \Tests\BrowserTestCase
    {

        public function setUp(): void
        {
            if (!Idno::site()->plugins()->get('Atmosphere')) {
                $this->markTestSkipped('The Atmosphere plugin is not enabled.');
            }
        }

        private $enabled = null;

        public function tearDown(): void
        {
            if ($this->enabled !== null) {
                Idno::site()->config()->atmosphere_enabled = $this->enabled[0];
            }
            if ($this->enabled !== null || !Idno::site()->config()->isPublicSite()) {
                $this->setPrivate(false);
            }
        }

        /**
         * Make the site private or public, for this process and for the web server
         */
        private function setPrivate($private)
        {
            Idno::site()->config()->walled_garden = (bool) $private;
            Idno::site()->config()->save();
        }

        private static function connected($prefix)
        {
            $member = static::member($prefix, true);
            $member->standardsitesync_session = ['access_token' => 'not-a-real-token', 'did' => 'did:plc:' . substr(md5($member->handle), 0, 24)];
            $member->save(true);

            return $member;
        }

        /**
         * While an administrator's account was being deleted, the site could go on naming
         * that account's identity as the owner of its publication.
         */
        public function testTheSitesPublicationIsNotThatOfAnAccountThatIsBeingDeleted()
        {
            $staying = static::connected('pubstaying');
            $leaving = static::connected('publeaving');
            $leaving->created = time() + 86400; // (the newest administrator is asked first)
            $leaving->save(true);
            $this->swapUser(null);
            $this->assertStringContainsString($leaving->standardsitesync_session['did'], $this->get('/.well-known/site.standard.publication')['body'], 'Control: the newest connected administrator is named.');

            $this->assertTrue(static::fresh($leaving)->close());

            $this->assertStringContainsString($staying->standardsitesync_session['did'], $this->get('/.well-known/site.standard.publication')['body']);
        }

        /**
         * A private site answered any visitor who asked for this address with the AT Protocol
         * identity of one of its members.
         */
        public function testAPrivateSiteNamesNoMembersIdentity()
        {
            $member = static::connected('pubprivate');
            $member->created = time() + 172800; // (the newest administrator is asked first)
            $member->save(true);
            $did = $member->standardsitesync_session['did'];
            $this->swapUser(null);
            $this->assertStringContainsString($did, $this->get('/.well-known/site.standard.publication')['body'], 'Control: a public site names the identity.');

            $this->setPrivate(true);
            $answer = $this->get('/.well-known/site.standard.publication');

            $this->assertSame(404, $answer['status']);
            $this->assertStringNotContainsString($did, $answer['body']);
        }

        /**
         * A member of a private site who pressed "Backfill" had a publication record, with the
         * site's name, address and description, written to their public AT Protocol repository.
         */
        public function testAPrivateSiteWritesNoPublicationRecordToAMembersPds()
        {
            $pds = \Tests\LocalHttpServer::start();
            try {
                $member = static::member('pubbackfill');
                $member->standardsitesync_session = [
                    'did'          => 'did:plc:' . substr(md5($member->handle), 0, 24),
                    'pds'          => rtrim($pds->url('/'), '/'),
                    'access_token' => 'not-a-real-token',
                    'expires_at'   => time() + 3600,
                    'dpop_key'     => \IdnoPlugins\Atmosphere\ATProtoClient::generateDPoPKeyPair(),
                ];
                $member->save(true);
                $this->swapUser(null);
                // Members' settings pages offer the backfill only while syncing is turned on
                $this->enabled = [Idno::site()->config()->atmosphere_enabled];
                Idno::site()->config()->atmosphere_enabled = true;
                $this->setPrivate(false);

                $this->submit('/account/settings/atmosphere/backfill/', [], $member, '/account/settings/atmosphere/');
                $this->assertNotEmpty($pds->requests(), 'Control: on a public site the PDS is asked to store the publication.');

                $pds->reset();
                $this->setPrivate(true);
                $this->submit('/account/settings/atmosphere/backfill/', [], $member, '/account/settings/atmosphere/');

                $this->assertSame([], $pds->requests());
            } finally {
                $pds->stop();
            }
        }

    }

}
