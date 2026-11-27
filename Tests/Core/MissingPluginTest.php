<?php

namespace Tests\Core {

    use Idno\Core\Idno;

    /**
     * A site's settings may list a plugin as enabled that is not installed (its folder was
     * deleted, or a release stopped bundling it). Such a site loads without the plugin.
     *
     * The requests are made over HTTP, so this test needs the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class MissingPluginTest extends \Tests\BrowserTestCase
    {

        const PLUGIN = 'NotInstalledHere';

        private $savedPlugins = null;

        protected function setUp(): void
        {
            $this->savedPlugins = Idno::site()->config()->plugins;
            Idno::site()->config()->plugins = array_merge($this->savedPlugins, [self::PLUGIN]);
            Idno::site()->config()->save();
        }

        protected function tearDown(): void
        {
            Idno::site()->config()->plugins = $this->savedPlugins;
            Idno::site()->config()->save();
            parent::tearDown();
        }

        public function testSiteLoadsWithoutIt()
        {
            $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/IdnoPlugins/' . self::PLUGIN);
            $member = static::member('missingplugin');

            foreach (['/', '/content/all/', '/account/settings/'] as $path) {
                $page = $this->get($path, $member);
                $this->assertLessThan(400, $page['status'], "$path should load on a site whose settings name a plugin that is not installed.");
            }
            $this->assertLessThan(500, $this->get('/admin/plugins/', static::member('missingpluginadmin', true))['status'], 'The plugins page should load.');
        }
    }

}
