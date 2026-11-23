<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Upgrade\PublicMessage;
use PHPUnit\Framework\TestCase;

/**
 * The body of /admin/legacytables (`admin/legacytables/body`) drawn by the site's template engine
 * with each state forced, for the branches the HTTP tests cannot reach on the rig: the 1.x
 * `entities` tables back beside the completed upgrade (the gate is closed then, so the page is
 * reached only in a process whose legacy application booted anyway), a deletable set and an upgrade
 * that is not complete.
 */
final class AdminLegacyTablesTemplateTest extends TestCase
{
    public function testTheRestoredStateShowsTheGatesMessageAndNoDeleteForm(): void
    {
        $html = $this->draw(['deletable' => false, 'restored' => true]);

        $this->assertStringContainsString('id="legacytables-restored"', $html);
        $this->assertStringContainsString('These tables cannot be deleted now.', $html);
        $this->assertStringContainsString(htmlspecialchars(PublicMessage::oneXBesideCompletedUpgrade()->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        $this->assertStringNotContainsString('name="confirm"', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('<code>legacy_&lt;b&gt;x&lt;/b&gt;</code>', $html, 'names escaped');
    }

    public function testADeletableSetHasTheDeleteForm(): void
    {
        $html = $this->draw(['deletable' => true, 'restored' => false]);

        $this->assertStringContainsString('name="confirm"', $html);
        $this->assertStringNotContainsString('id="legacytables-restored"', $html);
    }

    public function testAnUpgradeThatIsNotCompleteHasNeither(): void
    {
        $html = $this->draw(['deletable' => false, 'restored' => false]);

        $this->assertStringContainsString('is not complete, so these tables cannot be deleted yet', $html);
        $this->assertStringNotContainsString('name="confirm"', $html);
        $this->assertStringNotContainsString('id="legacytables-restored"', $html);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function draw(array $state): string
    {
        // A copy, so that these variables never reach another test's templates.
        $template = clone Idno::site()->template();

        // The page adds the text of the gate's message when the 1.x tables are back (a template does not call a class)
        if (!empty($state['restored'])) {
            $state += ['restored_message' => PublicMessage::oneXBesideCompletedUpgrade()->text];
        }

        return (string) $template->__($state + [
            'available' => true,
            'tables' => ['legacy_entities' => 3, 'legacy_<b>x</b>' => 1],
            'reappeared' => [],
            'rejects' => [],
            'reject_count' => 0,
            'offset' => 0,
            'page_size' => 100,
        ])->draw('admin/legacytables/body');
    }
}
