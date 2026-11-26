<?php

namespace Tests\Core {

    use Idno\Core\Idno;

    /**
     * A bundled plugin that has been removed (Bridgy) may still be listed as enabled in the
     * settings of a site that had it on. Such a site must load as before, without the plugin
     * and without its pages.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class RemovedPluginTest extends \Tests\BrowserTestCase
    {

        private $savedPlugins = null;

        protected function setUp(): void
        {
            $this->savedPlugins = Idno::site()->config()->plugins;
            Idno::site()->config()->plugins = array_merge($this->savedPlugins, ['Bridgy']);
            Idno::site()->config()->save();
        }

        protected function tearDown(): void
        {
            Idno::site()->config()->plugins = $this->savedPlugins;
            Idno::site()->config()->save();
            parent::tearDown();
        }

        public function testTheBridgyPluginIsGone()
        {
            $this->assertFalse(class_exists('IdnoPlugins\\Bridgy\\Main'), 'The Bridgy plugin should no longer be part of Idno.');
        }

        public function testASiteThatStillListsItLoads()
        {
            $member = static::member('nobridgy');

            foreach (['/', '/content/all/', '/account/settings/'] as $path) {
                $page = $this->get($path, $member);
                $this->assertLessThan(400, $page['status'], "$path should load on a site whose settings still name the removed plugin.");
            }
            $this->assertLessThan(500, $this->get('/admin/plugins/', static::member('nobridgyadmin', true))['status'], 'The plugins page should load.');
        }

        public function testItsPagesAreGone()
        {
            $member = static::member('nobridgypages');

            foreach (['/account/bridgy/', '/account/bridgy/enabled/?service=twitter&result=success&user=https%3A%2F%2Fbrid.gy%2Fx&key=k', '/account/bridgy/disabled/?service=twitter&result=success', '/account/bridgy/check/'] as $path) {
                $answer = $this->get($path, $member);
                $this->assertSame(404, $answer['status'], "$path should no longer exist.");
            }
            $this->assertEmpty(static::fresh($member)->bridgy ?? null, 'Nothing is stored for the member any more.');
        }
    }

}
