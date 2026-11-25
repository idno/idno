<?php

    /*
     * "Using a mail service": the values to enter for the common hosted services, for both
     * looks. Checked against each service's own documentation in November 2026. The buttons
     * fill in the form above (admin/email/script); without JavaScript the values are there
     * to copy.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $starttls = $lang->_('587 with STARTTLS');
    $either = $lang->_('587 with STARTTLS, or 465 with TLS');

    $services = [
        [
            'name' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => $either,
            'user' => $lang->_('The word %s, exactly as written', ['<code>apikey</code>']), 'fill_user' => 'apikey',
            'pass' => $lang->_('An API key that has the "Mail Send" permission'),
            'note' => $lang->_('The From address must be a verified sender, or on a domain you have authenticated with SendGrid.'),
        ],
        [
            'name' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'port' => $starttls,
            'user' => $lang->_('Your Server API token'), 'fill_user' => '',
            'pass' => $lang->_('The same Server API token'),
            'note' => $lang->_('The From address needs a confirmed sender signature or a verified domain. Until Postmark approves a new account it sends only to addresses on your own domain.'),
        ],
        [
            'name' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => $either,
            'user' => $lang->_("The SMTP login from the domain's SMTP credentials in Mailgun"), 'fill_user' => '',
            'pass' => $lang->_('The password of that SMTP login'),
            'note' => $lang->_("Domains in Mailgun's EU region use the EU host shown with the credentials. A sandbox domain sends only to recipients you have authorised."),
        ],
        [
            'name' => 'Amazon SES', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => $either,
            'user' => $lang->_('The SMTP user name made under "SMTP settings" in the SES console'), 'fill_user' => '',
            'pass' => $lang->_('The SMTP password made with it (not your AWS access keys)'),
            'note' => $lang->_("Put your own region in the host name. A new SES account is in a sandbox and sends only to addresses you have verified, until Amazon grants production access."),
        ],
        [
            'name' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'port' => $either,
            'user' => $lang->_('The SMTP login shown with your SMTP keys in Brevo'), 'fill_user' => '',
            'pass' => $lang->_('An SMTP key (not an API key)'),
            'note' => $lang->_('The From address must be a verified sender on a domain you have authenticated with Brevo.'),
        ],
        [
            'name' => 'Resend', 'host' => 'smtp.resend.com', 'port' => $either,
            'user' => $lang->_('The word %s, exactly as written', ['<code>resend</code>']), 'fill_user' => 'resend',
            'pass' => $lang->_('An API key'),
            'note' => $lang->_('The From address must be on a domain you have verified with Resend.'),
        ],
    ];
    ?>
<details class="mail-help">
    <summary><?php echo $lang->_('Using a mail service') ?></summary>
    <div class="mail-help-body">
        <p>
            <?php echo $lang->_("A mail service delivers your site's email so that it reaches inboxes. Create an account with one, then enter its values here. These are the values for the common ones:") ?>
        </p>
        <?php foreach ($services as $service) { ?>
            <section class="mail-help-service">
                <h3><?php echo htmlspecialchars($service['name']) ?></h3>
                <dl>
                    <dt><?php echo $lang->_('Server') ?></dt>
                    <dd><code><?php echo htmlspecialchars($service['host']) ?></code></dd>
                    <dt><?php echo $lang->_('Port and security') ?></dt>
                    <dd><?php echo htmlspecialchars($service['port']) ?></dd>
                    <dt><?php echo $lang->_('User name') ?></dt>
                    <dd><?php echo $service['user'] ?></dd>
                    <dt><?php echo $lang->_('Password') ?></dt>
                    <dd><?php echo htmlspecialchars($service['pass']) ?></dd>
                </dl>
                <p class="mail-help-note"><?php echo htmlspecialchars($service['note']) ?></p>
                <p>
                    <button type="button" class="mail-help-fill"
                            data-mail-host="<?php echo htmlspecialchars($service['host']) ?>"
                            data-mail-user="<?php echo htmlspecialchars($service['fill_user']) ?>"
                            hidden><?php echo $lang->esc_('Fill in the values for %s', [$service['name']]) ?></button>
                </p>
            </section>
        <?php } ?>
        <h3><?php echo $lang->_('So that your mail is not treated as spam') ?></h3>
        <p>
            <?php echo $lang->_('The From address must be on a domain the service is allowed to send for. Each service asks you to prove the domain is yours by adding a few DNS records where your domain is managed: usually DKIM and SPF records that the service gives you. Add a DMARC record as well (a TXT record named %s; %s is enough to start). Mail from an address on a domain you have not set up this way, or from a free address such as Gmail, is likely to be refused or to go to spam.', ['<code>_dmarc</code>', '<code>v=DMARC1; p=none</code>']) ?>
        </p>
    </div>
</details>
