<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A post's address has one layout, and the administration page has no control for another. A form a browser loaded before the setting was removed still
 * posts the field `permalink_structure`: the page ignores it, changes nothing it does not read and causes no error, and the address of a post is
 * the one layout's whatever a stored value says.
 */
final class PermalinkStructureFormTest extends PrivateSiteTestCase
{
    public function testAStaleFormPostOfThePermalinkLayoutIsIgnored(): void
    {
        $cookie = $this->registerUser('ada');
        $note = $this->publishStatus($cookie, 'A note to be found at its address.');
        $this->assertMatchesRegularExpression('#^' . preg_quote($this->baseUrl(), '#') . '[0-9]{4}/[a-z0-9-]+$#', $note, 'the address is the one layout\'s');
        // a value a site that could choose a layout stored
        $this->assertTrue($this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->permalink_structure = \'/:year/:month/:slug\'; $config->save(); return true;'));

        $token = $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $token->status);
        $field = trim($token->body);
        $decoded = json_decode($field, true);
        if (is_array($decoded)) {
            $field = (string) ($decoded['token'] ?? reset($decoded));
        }
        $answer = $this->site()->request('POST', '/admin/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'admin/'], [
            '__bTk' => $field,
            'title' => 'A site retitled by a stale form',
            'url' => $this->baseUrl(),
            'permalink_structure' => '/:year/:month/:day/:slug',
        ]);

        $this->assertSame(302, $answer->status, 'the form is accepted as any other: ' . $answer->body);
        $stored = $this->runInSite('$config = \Idno\Core\Idno::site()->config(); return [$config->title, $config->permalink_structure ?? null];');
        $this->assertSame('A site retitled by a stale form', $stored[0], 'what the page reads is saved');
        $this->assertSame('/:year/:month/:slug', $stored[1], 'the posted layout is neither stored nor read: the stored value is as it was');
        $this->assertSame(200, $this->site()->request('GET', substr($note, strlen($this->baseUrl())))->status, 'the post is at the one layout\'s address');
        $this->assertSame(200, $this->site()->request('GET', 'admin/', ['Cookie' => $cookie])->status, 'the administration page is drawn');
        $this->assertStringNotContainsString('permalink_structure', $this->site()->request('GET', 'admin/', ['Cookie' => $cookie])->body, 'and offers no such control');
    }
}
