<?php

namespace Tests\Characterisation {

    /**
     * The locale of a request is its Accept-Language header, and the page draws the string from the plugin's
     * catalogue (IdnoPlugins/Event ships a fr_FR one). Nothing here needs the gettext extension, and the
     * header is never used as a path.
     */
    class TranslationTest extends CharacterisationTestCase
    {
        private function page(?string $acceptLanguage): \Tests\Characterisation\SiteResponse
        {
            $this->user(); // a site with a user serves its pages instead of the registration redirect
            $headers = $acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage];

            return $this->site()->request('GET', 'content/all/', $headers);
        }

        public function testAFrenchRequestDrawsTheFrenchString(): void
        {
            $r = $this->page('fr-FR,fr;q=0.9,en;q=0.8');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('Evenements', $r->body);
        }

        public function testALanguageOnlyOrALowerCaseOrAnotherRegionFindsTheFrenchCatalogue(): void
        {
            foreach (['fr', 'fr-fr', 'FR-FR', 'fr-CA', 'fr-CA,fr;q=0.9'] as $header) {
                $r = $this->page($header);

                $this->assertSame(200, $r->status, $header);
                $this->assertStringContainsString('Evenements', $r->body, $header);
            }
        }

        public function testARequestWithoutTheHeaderDrawsTheEnglishOne(): void
        {
            $r = $this->page(null);

            $this->assertSame(200, $r->status);
            $this->assertStringNotContainsString('Evenements', $r->body);
            $this->assertStringContainsString('Events', $r->body);
        }

        public function testALocaleThatIsNotOneIsTheDefault(): void
        {
            foreach (['../../etc', 'fr_FR/../../x', '*', str_repeat('fr-', 100)] as $header) {
                $r = $this->page($header);

                $this->assertSame(200, $r->status, $header);
                $this->assertStringNotContainsString('Evenements', $r->body, $header);
            }
        }
    }
}
