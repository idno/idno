<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * What the site sends, unprompted, when something happens: each case points the feature at a /_test/
 * route of the site itself (a page that advertises /_test/sink/<name> as its webmention endpoint, or
 * the sink itself) and reads what the sink recorded. The requests are made by the web server's
 * worker, from inside the request that publishes, to the same server: the rig runs four workers
 * for that. The rig's queue is synchronous, so the sends have happened when the publish returns.
 */
final class OutboundFlowsTest extends CharacterisationTestCase
{
    use \Tests\Support\LogsIn;

    /** @var list<string> */
    private array $sinks = [];

    private string $previousErrorLog = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Deleting a fixture sends webmentions from this process too, and the client logs each request
        // at debug level: keep that out of the test output.
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-flows-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        try {
            // First delete the fixtures (which sends webmentions for a deleted post, to the sink), then
            // empty what the sinks hold, so that nothing is left behind.
            parent::tearDown();
        } finally {
            foreach ($this->sinks as $name) {
                $this->site()->request('GET', '_test/sink/' . $name . '/log');
            }
            $this->sinks = [];
            ini_set('error_log', $this->previousErrorLog);
            @unlink($this->errorLog);
        }
    }

    private function sink(string $prefix): string
    {
        $name = $prefix . '-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        return $name;
    }

    /**
     * What a sink recorded, in order; the file is emptied.
     *
     * @return list<array<string, mixed>>
     */
    private function recorded(string $name): array
    {
        $response = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $this->assertSame(200, $response->status);

        return $response->json();
    }

    /**
     * Runs $work with settings of the site changed in its stored configuration (what the admin pages
     * save: the web server loads them on each request), and puts the stored configuration back as it
     * was, also when $work fails. Nothing in a configuration file or the environment changes.
     *
     * @param array<string, mixed> $settings
     */
    private function withStoredSettings(array $settings, callable $work): void
    {
        $db = \Idno\Core\Idno::site()->db();
        $original = $db->getAnyRecord('config');
        $this->assertIsArray($original, 'the site has stored settings');
        try {
            $this->assertNotFalse($db->saveRecord('config', array_replace($original, $settings)));
            $work();
        } finally {
            $db->saveRecord('config', $original);
            $this->assertSame($original, $db->getAnyRecord('config'), 'the stored settings are as they were');
        }
    }

    private function createdByMicropub(array $form): string
    {
        $token = $this->issueMicropubToken($this->user());
        $response = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer ' . $token], $form);
        $location = $response->header('Location');
        $this->assertNotNull($location, 'No Location header: ' . $response->status . ' ' . $response->body);
        $this->registerEntityAtUrl($location);
        $this->assertSame(201, $response->status);

        return $location;
    }

    public function testPublishingAPostThatLinksToAPageSendsItAWebmention(): void
    {
        $name = $this->sink('wm');
        $target = $this->site()->baseUrl() . '_test/page/' . $name;

        $post = $this->createdByMicropub(['h' => 'entry', 'content' => 'Reading ' . $target . ' today']);

        $requests = $this->recorded($name);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(['source' => $post, 'target' => $target], $requests[0]['fields']);
        $this->assertSame('application/x-www-form-urlencoded', $requests[0]['content_type']);
        $this->assertSame('Idno https://idno.co', $requests[0]['headers']['user-agent']);
    }

    /**
     * The hub is a stored setting (the admin's "WebSub hub"; the rig's configuration file does not pin
     * it): published content pings it with one form-encoded POST naming the site's feeds. The ping
     * passes its Content-Type as an associative header; that header used to be dropped and the body
     * went out with curl's default for a POST of a string. It is now sent, and is the same value.
     */
    public function testPublishingPingsTheConfiguredHub(): void
    {
        $name = $this->sink('hub');
        $base = $this->site()->baseUrl();

        $this->withStoredSettings(['hub' => $base . '_test/sink/' . $name], function () use ($name, $base): void {
            $this->createdByMicropub(['h' => 'entry', 'content' => 'A note for the hub ' . $name]);

            $requests = $this->recorded($name);
            $this->assertCount(1, $requests);
            $this->assertSame('POST', $requests[0]['method']);
            $this->assertSame('application/x-www-form-urlencoded', $requests[0]['content_type']);
            $this->assertSame('Idno https://idno.co', $requests[0]['headers']['user-agent']);
            $body = (string) base64_decode($requests[0]['body'], true);
            $this->assertStringStartsWith('hub.mode=publish&hub.url=' . urlencode($base) . ',', $body);
            $this->assertStringContainsString(urlencode($base . 'content/all/'), $body);
            $this->assertSame('publish', $requests[0]['fields']['hub_mode']);
        });
    }

    /**
     * The Webhooks plugin posts a JSON description of the post to a hook the post is syndicated to.
     * The hook is a site-wide one (the admin's `webhook_syndication`, a stored setting), selected on
     * the status form with the user logged in over HTTP. A user's own hooks (`webhook_syndication`
     * on the user) cannot be driven: the plugin registers them as syndication accounts while it
     * loads, only when the session is logged in then; the status form did not offer the hook of a
     * user whose `webhook_syndication` was set, and the site authenticates the session after the
     * plugins have loaded (Idno::__construct()), so a post cannot be syndicated to one. And Micropub's `mp-syndicate-to` cannot be driven on the rig
     * at all: IdnoPlugins/IndiePub/Pages/MicroPub/Endpoint.php interpolates an array into a log
     * message (line 395), which pedantic_mode makes an exception (answered as a 500).
     */
    public function testAWebhookIsCalledWhenAPostIsPublished(): void
    {
        $name = $this->sink('hook');
        $hook = $this->site()->baseUrl() . '_test/sink/' . $name;

        $this->withStoredSettings(['webhook_syndication' => [['url' => $hook, 'title' => 'Test sink']]], function () use ($name, $hook): void {
            $cookie = $this->logIn($this->user());
            // The form's page reads $body[0] of its `body` input without a default, which pedantic_mode makes
            // an exception when there is none (Status::factory(), line 23): give it one.
            $form = $this->site()->request('GET', 'status/edit?body=x', ['Cookie' => $cookie]);
            $this->assertSame(200, $form->status);
            $this->assertStringContainsString('webhooks::' . $hook, html_entity_decode($form->body), 'the form offers the hook');

            $response = $this->site()->request('POST', 'status/edit/', ['Cookie' => $cookie], $this->formToken($form->body, 'status/edit/') + [
                'body' => 'A note for a webhook ' . $name,
                'access' => 'PUBLIC',
                'syndication' => ['webhooks::' . $hook],
            ]);
            $post = (string) $response->header('Location');
            $this->assertNotSame('', $post, 'the status was published: ' . $response->status . ' ' . substr($response->body, 0, 500));
            $this->registerEntityAtUrl($post);
            $this->assertSame(302, $response->status);

            $requests = $this->recorded($name);
            $this->assertCount(1, $requests);
            $this->assertSame('POST', $requests[0]['method']);
            $this->assertSame('application/json', $requests[0]['content_type']);
            $this->assertSame('Idno https://idno.co', $requests[0]['headers']['user-agent']);
            $payload = json_decode((string) base64_decode($requests[0]['body'], true), true);
            $this->assertIsArray($payload);
            $this->assertSame('testuser', $payload['username']);
            $this->assertSame('note', $payload['content_type']);
            $this->assertSame($payload['title'] . ' <' . $post . '>', $payload['text']);
            $this->assertArrayHasKey('icon_url', $payload);
        });
    }

}
