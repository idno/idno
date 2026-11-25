<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * What the person installing a site types to create its first administrator, and the rules those
 * values must meet before anything is looked up or saved.
 */
final class AdministratorDetails
{
    public const PASSWORD_LENGTH = 10;
    public const HANDLE_LENGTH = 32;

    public readonly string $name;
    public readonly string $handle;
    public readonly string $email;

    public function __construct(string $name, string $handle, string $email, #[\SensitiveParameter] public readonly string $password, #[\SensitiveParameter] public readonly string $password2)
    {
        $this->name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '');
        $this->handle = trim($handle);
        $this->email = trim($email);
    }

    /**
     * The name shown for the account: the name typed, or the username.
     */
    public function displayName(): string
    {
        return $this->name === '' ? strtolower($this->handle) : $this->name;
    }

    /**
     * What is wrong with these values, by field (handle, email, password, password2); empty when
     * nothing is. The password is compared as typed, less the spaces around it (as the site's own
     * password code trims it).
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->handle === '') {
            $problems['handle'] = 'Choose a username.';
        } elseif (strlen($this->handle) > self::HANDLE_LENGTH) {
            $problems['handle'] = 'A username can be at most ' . self::HANDLE_LENGTH . ' characters long.';
        } elseif (preg_match('/\A[a-zA-Z0-9_]+\z/', $this->handle) !== 1) {
            $problems['handle'] = 'A username can only have letters, numbers and underscores.';
        }
        if ($this->email === '' || filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $problems['email'] = 'That does not look like an email address.';
        }
        $password = trim($this->password);
        if (strlen($password) < self::PASSWORD_LENGTH) {
            $problems['password'] = 'The password must be at least ' . self::PASSWORD_LENGTH . ' characters long.';
        } elseif (strlen($password) > 4096) {
            $problems['password'] = 'That password is too long.';
        } elseif ($password !== trim($this->password2)) {
            $problems['password2'] = 'The two passwords are not the same. Type the password again.';
        }

        return $problems;
    }
}
