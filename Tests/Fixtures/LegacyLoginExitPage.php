<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Entities\User;

/**
 * Test-environment only (`/_test/login-exit`, registered by TestEventHooks only when IDNO_TEST_LOGIN_EXIT=1, so no other test site has
 * a page that logs anyone in): a legacy page that logs on the member whose handle `?user=` names and then ENDS THE REQUEST with `exit`,
 * as a legacy page does, so that what the session keeps is what PHP writes when the process ends.
 */
class LegacyLoginExitPage extends Page
{
    public function getContent()
    {
        $user = User::getByHandle((string) $this->getInput('user', ''));
        if ($user instanceof User) {
            Idno::site()->session()->logUserOn($user);
        }
        header('Content-Type: text/plain');
        echo $user instanceof User ? "logged on\n" : "nobody\n";
        exit;
    }
}
