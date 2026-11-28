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

    }

}
