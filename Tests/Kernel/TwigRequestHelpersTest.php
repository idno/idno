<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the Twig request helpers answer for a REAL legacy page request (the page runs after the kernel returned, with its request
 * on Symfony's request stack): `current_url()` is what `Page::currentUrl()` answers, byte for byte, whatever Host header, scheme
 * header or query string the request has. The private site has a probe plugin (made here, removed in tearDown()) whose page
 * prints the legacy value and the Twig one.
 */
final class TwigRequestHelpersTest extends PrivateSiteTestCase
{
    private string $plugin = '';

    protected function tearDown(): void
    {
        if ($this->plugin !== '') {
            exec('rm -rf ' . escapeshellarg($this->plugin));
        }
        parent::tearDown();
    }

    private function makeProbe(): void
    {
        $this->plugin = dirname(__DIR__, 2) . '/IdnoPlugins/ZzRequestProbe';
        // A killed run leaves the directory behind, and mkdir() would then fail
        exec('rm -rf ' . escapeshellarg($this->plugin));
        mkdir($this->plugin . '/Pages', 0777, true);
        mkdir($this->plugin . '/templates/default/zzprobe', 0777, true);
        file_put_contents($this->plugin . '/plugin.ini', "[Plugin description]\nname = 'Zz request probe'\nversion = '1.0'\n");
        file_put_contents($this->plugin . '/Main.php', "<?php\nnamespace IdnoPlugins\\ZzRequestProbe {\n    class Main extends \\Idno\\Common\\Plugin\n    {\n        function registerPages()\n        {\n            \\Idno\\Core\\Idno::site()->routes()->addRoute('zzprobe/?', 'IdnoPlugins\\ZzRequestProbe\\Pages\\Probe', true);\n        }\n    }\n}\n");
        file_put_contents($this->plugin . '/Pages/Probe.php', "<?php\nnamespace IdnoPlugins\\ZzRequestProbe\\Pages {\n    class Probe extends \\Idno\\Common\\Page\n    {\n        function getContent()\n        {\n            header('Content-type: text/plain');\n            echo \$this->currentUrl() . \"\\n\" . \\Idno\\Core\\Idno::site()->template()->draw('zzprobe/probe');\n        }\n    }\n}\n");
        file_put_contents($this->plugin . '/templates/default/zzprobe/probe.html.twig', "{{ current_url()|raw }}\n{{ current_path()|raw }}\n{{ referrer()|raw }}");
        $this->registerUser('writer');
        $this->assertTrue($this->runInSite('return \\Idno\\Core\\Idno::site()->plugins()->enable("ZzRequestProbe");'));
    }

    public function testTheTwigHelperEqualsPageCurrentUrlForARealPageRequest(): void
    {
        $this->makeProbe();

        foreach ([
            ['zzprobe/', []],
            ['zzprobe/?a=1&b=%20x&c[]=2', []],
            ['zzprobe?q=a%2Fb&r=%E2%82%AC', ['Host' => 'evil.example']],
            ['zzprobe/?x=1', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example', 'Referer' => 'https://other.example/p?z=1']],
        ] as [$path, $headers]) {
            $response = $this->site()->request('GET', $path, $headers);

            $this->assertSame(200, $response->status, $path . ' ' . $this->serverLog());
            $lines = explode("\n", $response->body);
            $this->assertSame($lines[0], $lines[1], $path . ': the Twig helper is Page::currentUrl()');
            $this->assertStringStartsWith($this->baseUrl(), $lines[1]);
            $this->assertStringNotContainsString('evil.example', $response->body);
            $this->assertSame(parse_url('/' . $path, PHP_URL_PATH), $lines[2], $path . ': current_path()');
        }
    }
}
