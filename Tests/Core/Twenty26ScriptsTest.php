<?php

namespace Tests\Core;

use Idno\Core\Idno;

/**
 * Twenty26 does not load jQuery, so it must not draw the default look's scripts that
 * need it: each one stopped with "$ is not defined" on every page that had it.
 */
class Twenty26ScriptsTest extends \Tests\IdnoTestCase
{

    private $previousTemplateType;
    private $previousUser;
    private $previousPage;
    private $toDelete = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Twenty26 only has templates of the "modern" type
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
    }

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->previousUser = $this->swapUser($this->admin());
        $this->previousPage = Idno::site()->currentPage();
        Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
        Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
        // Leave the session as it was found: later tests depend on who is logged in
        $this->swapUser($this->previousUser);
    }

    private function draw(string $template, array $vars, string $templateType): string
    {
        $t = Idno::site()->template();
        $t->setTemplateType($templateType);
        $html = (string) $t->__($vars)->draw($template);
        foreach (array_keys($vars) as $name) {
            unset($t->vars[$name]);
        }

        return $html;
    }

    private function post(): \IdnoPlugins\Status\Status
    {
        $status = new \IdnoPlugins\Status\Status();
        $status->body = 'A post to edit';
        $status->setAccess('PUBLIC');
        $status->setPublishStatus('draft');
        $this->assertNotEmpty($status->save(true));
        $this->toDelete[] = $status;

        return $status;
    }

    public function testTheEditFooterHasNoJQueryButKeepsTheDeleteControl()
    {
        $post = $this->post();
        $html = $this->draw('entity/edit/footer', ['object' => $post], 'modern');

        $this->assertStringNotContainsString('$(', $html, 'Twenty26 does not load jQuery.');
        $this->assertStringNotContainsString('submit-spinner', $html);
        $this->assertStringContainsString('idno-edit-delete', $html, 'The Delete control of an existing post is still drawn.');
        $this->assertStringContainsString($post->getDeleteURL(), $html);
    }

    public function testTheEditFooterOfANewPostIsEmpty()
    {
        $html = $this->draw('entity/edit/footer', ['object' => new \IdnoPlugins\Status\Status()], 'modern');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('idno-edit-delete', $html);
    }

    public function testTheDefaultEditFooterIsUnchanged()
    {
        $html = $this->draw('entity/edit/footer', ['object' => $this->post()], 'default');

        $this->assertStringContainsString("\$('div.page-body form').submit(", $html);
        $this->assertStringContainsString('submit-spinner', $html);
    }

    public function testFitVidsIsLoadedOnlyByTheDefaultLook()
    {
        $this->assertNotEmpty(Idno::site()->config()->assets['fitvids'], 'This test needs the fitvids asset to be on, as it is by default.');

        $this->assertStringNotContainsString('fitvids', $this->draw('shell/assets', [], 'modern'));
        $this->assertStringContainsString('jquery.fitvids.js', $this->draw('shell/assets', [], 'default'));
    }
}
