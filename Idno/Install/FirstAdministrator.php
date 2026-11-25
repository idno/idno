<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The site's first administrator: whether the site has one, and making it. The setup step of the web
 * installation and the console commands use it; the site's own code implements it.
 */
interface FirstAdministrator
{
    /**
     * Whether the site has an administrator. Once it has, nothing here creates another.
     */
    public function administratorExists(): bool;

    /**
     * What stops these details from becoming an account, by field (handle, email, password,
     * password2); empty when nothing does. It includes AdministratorDetails::problems().
     *
     * @return array<string, string>
     */
    public function problems(AdministratorDetails $details): array;

    /**
     * Creates the administrator, and gives the site its name (`$siteTitle`, or one made from the
     * administrator's name), its sender address and closed registration. Returns the account's uuid.
     *
     * @throws \RuntimeException when the site has an administrator already, the details have
     *                           problems() or the account cannot be saved
     */
    public function create(AdministratorDetails $details, string $siteTitle): string;

    /**
     * Signs the browser of the current request in as this account.
     */
    public function signIn(string $uuid): void;
}
