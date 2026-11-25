<?php

declare(strict_types=1);

namespace Tests\Characterisation\fixtures;

use Tests\Characterisation\HttpSiteClient;
use Tests\Support\PrivateSiteTestCase;

/**
 * A class that shares its site and whose tests each change it in another way (a setting, an uploaded file, a write made through an
 * extra server, a record of the reader and the site collections): only SharedSiteTest runs it, in a process of its own, and expects
 * every test to fail with the guard's message.
 */
final class SharedSiteEveryChangeFixture extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
    }

    public function testASettingIsSaved(): void
    {
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->shared_probe = "changed"; $c->save(); return 1;');
        $this->assertTrue(true);
    }

    public function testAFileIsUploaded(): void
    {
        file_put_contents($this->uploadDirectory() . '/probe.file', 'bytes');
        $this->assertTrue(true);
    }

    public function testAPostIsWrittenThroughAnExtraServer(): void
    {
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');
        $url = $this->startExtraServer(['IDNO_TEST_SERVER_LABEL' => 'writer']);
        $client = new HttpSiteClient($url);
        $form = $client->request('GET', 'status/edit?body=x', ['Cookie' => $cookie]);
        $response = $client->request('POST', 'status/edit/', ['Cookie' => $cookie], $this->formToken($form->body, 'status/edit/') + ['body' => 'written elsewhere', 'access' => 'PUBLIC']);
        $this->assertSame(302, $response->status);
    }

    public function testARecordOfTheReaderAndSiteCollectionsIsWritten(): void
    {
        $this->runInSite('$store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class); $store->save("reader", ["_id" => "probe-reader-record", "uuid" => "https://example.test/reader/probe", "entity_subtype" => "Idno\\Entities\\Reader\\Feed"]); return 1;');
        $this->assertTrue(true);
    }

    public function testNothingIsChanged(): void
    {
        $this->assertSame(200, $this->site()->request('GET', '_test/server')->status);
    }
}
