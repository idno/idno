<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * A site set up before the jQuery plugins were removed still has `assets` = `{mediaelementplayer: true, fitvids: true}` in its stored
 * settings. Those names have no template any more: every page still draws, and no asset tag is written for them.
 */
final class StoredAssetsSettingTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    public function testAStoredAssetsSettingThatNamesDeletedAssetsDrawsNothingAndNoPageFails(): void
    {
        $this->seedSite();
        $stored = $this->runInSite(<<<'PHP'
        $config = \Idno\Core\Idno::site()->config();
        $config->assets = ['mediaelementplayer' => true, 'fitvids' => true];
        $config->save();

        return $config->assets;
        PHP);
        $this->assertSame(['mediaelementplayer' => true, 'fitvids' => true], $stored);

        foreach (['', 'content/all', 'session/login'] as $path) {
            $page = $this->site()->request('GET', $path);
            $this->assertSame(200, $page->status, $path . ': ' . substr($page->body, 0, 500));
            $this->assertStringContainsString('js/idno-2026.min.js', $page->body, $path . ' is the whole page');
            $this->assertDoesNotMatchRegularExpression('~mediaelement|fitvids|<!-- (Flexible media player|Video shim)~i', $page->body, $path);
            $this->assertStringNotContainsString('Fatal error', $page->body);
        }

        $this->assertStringNotContainsString('assets/', $this->serverLogContents(), 'no error about the missing templates');
    }
}
