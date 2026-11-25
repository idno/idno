<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * What ties the creation of the site's first administrator to the person who installed the site.
 * When the web installer writes the configuration it makes a random token, gives it to that browser
 * as a cookie and keeps its SHA-256, with the site's name, in the marker file. While the marker is
 * there the site serves only the create-your-account step, and only to a browser holding the token.
 * The marker is removed when the site has an administrator.
 */
final class SetupToken
{
    public const COOKIE = 'idno_setup';

    /** How long the cookie lasts: thirty days. */
    public const LIFETIME = 2592000;

    public function __construct(private readonly InstallPaths $paths)
    {
    }

    /**
     * Makes a new token (or takes the one given, 64 hex characters: the web installer gives the
     * random value its browser already holds) and writes the marker, replacing one that is there: the
     * earlier token stops working. Returns the token.
     *
     * @throws \RuntimeException when the marker cannot be written
     */
    public function issue(string $siteTitle = '', ?string $token = null): string
    {
        if ($token === null || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
        }
        $json = json_encode(['hash' => hash('sha256', $token), 'title' => $siteTitle], JSON_THROW_ON_ERROR);
        if (@file_put_contents($this->paths->marker(), $json, LOCK_EX) === false) {
            throw new \RuntimeException('The folder configuration cannot be written to.');
        }

        return $token;
    }

    public function pending(): bool
    {
        return is_file($this->paths->marker());
    }

    /**
     * Whether this is the token the marker was made for. Anything that is not 64 hex characters is not.
     */
    public function matches(mixed $token): bool
    {
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return false;
        }
        $hash = $this->read()['hash'] ?? null;

        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    /**
     * The site's name as the installer was given it, or ''.
     */
    public function title(): string
    {
        $title = $this->read()['title'] ?? '';

        return is_string($title) ? $title : '';
    }

    /**
     * Runs $work while no other request runs work of its own here (an exclusive lock on the marker
     * file), so that two submissions of the account form cannot both create an administrator. Returns
     * what $work returns, or null, without running it, when the marker is gone.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T|null
     */
    public function exclusively(\Closure $work): mixed
    {
        $handle = @fopen($this->paths->marker(), 'r');
        if ($handle === false) {
            return null;
        }
        try {
            flock($handle, LOCK_EX);
            clearstatcache(true, $this->paths->marker());

            return $this->pending() ? $work() : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function clear(): void
    {
        if (is_file($this->paths->marker())) {
            @unlink($this->paths->marker());
        }
    }

    /**
     * What a form of the browser holding this token carries as a hidden field, so that another site
     * cannot post the form for it.
     */
    public static function fieldFor(string $token): string
    {
        return hash('sha256', 'idno-setup-form:' . $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $text = @file_get_contents($this->paths->marker());
        $data = is_string($text) ? json_decode($text, true) : null;

        return is_array($data) ? $data : [];
    }
}
