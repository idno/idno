<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Core\Idno;
use Idno\Portability\ArchiveExporter;

/**
 * The export page's two templates (the default one and the theme's): the warning names what the archive holds,
 * and without the PHP zip extension the create button is replaced by a notice.
 */
final class ExportPageTemplatesTest extends PortabilityTestCase
{
    private function draw(string $type, array $vars): string
    {
        Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Export()); // the admin menu asks which page is shown
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/admin/export/';
        try {
            $template = clone Idno::site()->template();
            $template->setTemplateType($type);

            return (string) $template->__($vars)->draw('admin/export');
        } finally {
            if ($uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $uri;
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function types(): array
    {
        return ['default' => ['default']];
    }

    /**
     * @dataProvider types
     */
    public function testTheWarningNamesThePasswordHashesAndThePrivateKeysThatSign(string $type): void
    {
        $this->logOn($this->admin());

        $raw = $this->draw($type, ['zip_available' => true, 'export_in_progress' => false]);
        $page = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString("password hash", $page, $type);
        $this->assertStringContainsString('private keys that sign each user\'s posts to the fediverse', $page, $type);
        $this->assertStringContainsString('can sign as any user', $page, $type);
        $this->assertStringContainsString('review config.json before you share the archive', $page, $type);
        $this->assertStringContainsString('Create archive', $raw, 'with zip, the button is there');
    }

    /**
     * @dataProvider types
     */
    public function testWithoutZipTheCreateButtonIsReplacedByANotice(string $type): void
    {
        $this->logOn($this->admin());

        $page = $this->draw($type, ['zip_available' => false, 'export_in_progress' => false]);

        $this->assertStringContainsString('needs the PHP zip extension', $page, $type);
        $this->assertStringNotContainsString('admin/export/generate', $page, $type);
        $this->assertStringNotContainsString('Create archive', $page, $type);
    }

    public function testTheExporterSaysWhetherZipIsThere(): void
    {
        $container = Idno::container();
        $make = static fn (?\Closure $zip): ArchiveExporter => new ArchiveExporter(
            $container->get(\Idno\Storage\EntityRepository::class),
            $container->get(\Idno\Storage\Files\FileStore::class),
            $container->get(\Idno\Storage\RecordStore::class),
            $container->get(\Idno\Storage\CollectionDump::class),
            new \Idno\Legacy\LegacySiteFacts(Idno::site()->config()),
            $container->get('idno.http_client'),
            static fn () => $container->get('idno.http_client.remote'),
            $container->get(\Idno\Config\BootConfig::class),
            Idno::site()->logging(),
            $zip
        );

        $this->assertFalse($make(static fn (): bool => false)->zipAvailable());
        $this->assertTrue($make(static fn (): bool => true)->zipAvailable());
        $this->assertSame(class_exists(\ZipArchive::class), $make(null)->zipAvailable());
    }
}
