<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * For a test of a private site that asserts "nothing was mailed": the site only mails an alert for a failure when it has an address to
 * send it to (`oops_notify`) and a sender (`from_email`), and without them the assertion cannot fail. A using class is a
 * PrivateSiteTestCase.
 */
trait MailsAlerts
{
    /**
     * Gives the site an address for its alerts and a sender, as stored settings.
     */
    protected function mailAlertsToAnAdministrator(): void
    {
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->oops_notify = \'ops@example.net\'; $c->from_email = \'site@example.net\'; $c->save(); return true;');
    }
}
