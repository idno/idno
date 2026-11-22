<?php

namespace Tests\Characterisation {

    /**
     * The locale of a request is its Accept-Language header, and the page draws the string from the plugin's
     * catalogue (IdnoPlugins/Event ships a fr_FR one). Nothing here needs the gettext extension, and the
     * header is never used as a path. Idno 2026 shows the name of each content type (`Event`, from that catalogue) in the
     * compose dialog of a signed-in member, so the pages are the member's.
     */
    class TranslationTest extends CharacterisationTestCase
    {
        private ?string $cookie = null;

        private function page(?string $acceptLanguage): \Tests\Characterisation\SiteResponse
        {
            $this->cookie ??= $this->logIn($this->user()); // a site with a user serves its pages instead of the registration redirect
            $headers = $acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage];

            return $this->site()->request('GET', 'content/all/', $headers + ['Cookie' => $this->cookie]);
        }

        /**
         * Logs the user in through the login form, with a new password set for this test (the test users' passwords are
         * random), and returns the session cookie (`name=value`).
         */
        private function logIn(\Idno\Entities\User $user): string
        {
            $password = bin2hex(random_bytes(12));
            $user->setPassword($password);
            $user->save(true);

            return (new \Tests\Acceptance\Browser($this->site()))->login($user->handle, $password);
        }

        public function testAFrenchRequestDrawsTheFrenchString(): void
        {
            $r = $this->page('fr-FR,fr;q=0.9,en;q=0.8');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('Évènement', $r->body);
        }

        public function testALanguageOnlyOrALowerCaseOrAnotherRegionFindsTheFrenchCatalogue(): void
        {
            foreach (['fr', 'fr-fr', 'FR-FR', 'fr-CA', 'fr-CA,fr;q=0.9'] as $header) {
                $r = $this->page($header);

                $this->assertSame(200, $r->status, $header);
                $this->assertStringContainsString('Évènement', $r->body, $header);
            }
        }

        public function testARequestWithoutTheHeaderDrawsTheEnglishOne(): void
        {
            $r = $this->page(null);

            $this->assertSame(200, $r->status);
            $this->assertStringNotContainsString('Évènement', $r->body);
            $this->assertStringContainsString('Event</div>', $r->body);
        }

        public function testALocaleThatIsNotOneIsTheDefault(): void
        {
            foreach (['../../etc', 'fr_FR/../../x', '*', str_repeat('fr-', 100)] as $header) {
                $r = $this->page($header);

                $this->assertSame(200, $r->status, $header);
                $this->assertStringNotContainsString('Évènement', $r->body, $header);
            }
        }
    }
}
