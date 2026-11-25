<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $values = $vars['values'] ?? [];
    $errors = $vars['errors'] ?? [];
    $url = $config->getDisplayURL();

    // One text field: label, input, the error if there is one, a line of help
    $field = function ($name, $label, $help, array $attributes = []) use ($values, $errors) {
        $describedby = 'help-' . $name . (isset($errors[$name]) ? ' error-' . $name : '');
        $attributes += ['type' => 'text'];
        ?>
        <div class="idno-form-group">
            <label class="idno-label" for="<?= $name ?>"><?= $label ?></label>
            <input id="<?= $name ?>" name="<?= $name ?>" class="idno-input"
                   value="<?= htmlspecialchars((string) ($values[$name] ?? '')) ?>"
                   aria-describedby="<?= $describedby ?>"
                   <?php if (isset($errors[$name])) { ?>aria-invalid="true"<?php } ?>
                   <?php foreach ($attributes as $attribute => $value) { ?><?= $attribute ?>="<?= htmlspecialchars((string) $value) ?>" <?php } ?>>
            <?php if (isset($errors[$name])) { ?>
                <p class="idno-form-error" id="error-<?= $name ?>"><?= htmlspecialchars($errors[$name]) ?></p>
            <?php } ?>
            <p class="idno-form-help" id="help-<?= $name ?>"><?= $help ?></p>
        </div>
        <?php
    };
    ?>
<h1 class="idno-admin-page-title"><?= $lang->_('Email') ?></h1>
<p class="idno-admin-description">
    <?= $lang->_('Your site sends email to invite people, to reset passwords and to notify members. It sends it through a mail server: usually a mail service you have an account with.') ?>
</p>

<?php if (!empty($vars['configured'])) { ?>
    <div class="idno-notice idno-notice-ok" role="status">
        <?= $lang->esc_('Mail is sent through %s, from %s.', [$config->smtp_host, \Idno\Core\Email::fromName() . ' <' . $config->from_email . '>']) ?>
    </div>
<?php } else { ?>
    <div class="idno-notice idno-notice-warning" role="status">
        <strong><?= $lang->_('No mail service is set up yet.') ?></strong>
        <?= $lang->_('Until you enter a mail server and a From address below, invitations and password reset emails will probably not arrive.') ?>
    </div>
<?php } ?>

<?php if (!empty($vars['fixed'])) { ?>
    <div class="idno-notice" role="note">
        <?= $lang->esc_("These settings are set in the site's configuration file (config.ini) or environment, which wins over what is saved here: %s. Change them there.", [implode(', ', $vars['fixed'])]) ?>
    </div>
<?php } ?>

<form action="<?= $url ?>admin/email" method="post" id="mail-settings-form" novalidate>
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= $lang->_('Who the mail is from') ?></h2>

        <?php
            $field('from_email', $lang->_('From address'), $lang->_('Every email is sent from this address. It must be one your mail service lets you send from.'), ['type' => 'email', 'placeholder' => 'hello@example.com', 'autocomplete' => 'off', 'inputmode' => 'email']);
            $field('from_name', $lang->_('From name'), $lang->_("The name people see beside the address. Leave it empty to use the site's name."), ['placeholder' => (string) $config->title, 'maxlength' => 100, 'autocomplete' => 'off']);
            $field('reply_to_email', $lang->_('Reply-To address (optional)'), $lang->_('Where replies go, if not to the From address: for example your own inbox.'), ['type' => 'email', 'placeholder' => 'you@example.com', 'autocomplete' => 'off', 'inputmode' => 'email']);
        ?>
    </div>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= $lang->_('Mail server') ?></h2>

        <?php
            $field('smtp_host', $lang->_('Server (SMTP host)'), $lang->_('The name of the mail server, as your mail service gives it: for example smtp.sendgrid.net.'), ['placeholder' => 'smtp.example.com', 'autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false', 'inputmode' => 'url']);
        ?>

        <div class="idno-field-row">
            <div class="idno-form-group">
                <label class="idno-label" for="smtp_secure"><?= $lang->_('Security') ?></label>
                <select name="smtp_secure" id="smtp_secure" class="idno-select" aria-describedby="help-smtp_secure">
                    <?php foreach ([
                        'tls' => $lang->_('STARTTLS (usual, port 587)'),
                        'ssl' => $lang->_('TLS (port 465)'),
                        ''    => $lang->_('None (port 25)'),
                    ] as $value => $label) { ?>
                        <option value="<?= $value ?>" <?php if (($values['smtp_secure'] ?? '') === $value) echo 'selected'; ?>><?= $label ?></option>
                    <?php } ?>
                </select>
                <p class="idno-form-help" id="help-smtp_secure"><?= $lang->_('How the connection is encrypted. Mail services expect STARTTLS on port 587.') ?></p>
            </div>
            <?php
                $field('smtp_port', $lang->_('Port'), $lang->_('Usually 587. Leave it empty to use the usual port for the security you chose.'), ['placeholder' => '587', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'maxlength' => 5]);
            ?>
        </div>

        <?php
            $field('smtp_username', $lang->_('User name'), $lang->_('The user name your mail service gives you for SMTP. For some services it is a fixed word such as "apikey".'), ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false']);
        ?>

        <div class="idno-form-group">
            <label class="idno-label" for="smtp_password"><?= $lang->_('Password') ?></label>
            <input type="password" id="smtp_password" name="smtp_password" class="idno-input" value=""
                   autocomplete="new-password" aria-describedby="help-smtp_password"
                   placeholder="<?= !empty($vars['has_password']) ? $lang->_('Saved. Type a new one to replace it') : '' ?>">
            <p class="idno-form-help" id="help-smtp_password">
                <?php if (!empty($vars['has_password'])) { ?>
                    <span class="idno-badge idno-badge-ok"><?= $lang->_('Saved') ?></span>
                    <?= $lang->_('A password is saved and is not shown here. Leave this empty to keep it, or type a new one to replace it.') ?>
                <?php } else { ?>
                    <?= $lang->_('The password or API key for SMTP. It is stored on your site and never shown again.') ?>
                <?php } ?>
            </p>
            <?php if (!empty($vars['has_password'])) { ?>
                <div class="idno-checkbox-group">
                    <input type="checkbox" id="smtp_password_remove" name="smtp_password_remove" value="1">
                    <label for="smtp_password_remove"><?= $lang->_('Remove the saved password') ?></label>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="idno-form-actions">
        <button type="submit" class="idno-btn idno-btn-primary"><?= $lang->_('Save settings') ?></button>
    </div>

    <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/email') ?>
</form>

<?= $this->draw('admin/email/services') ?>

<div class="idno-admin-card" id="test">
    <form action="<?= $url ?>admin/emailtest" method="post">
        <h2 class="idno-admin-card-title"><?= $lang->_('Send a test email') ?></h2>
        <p class="idno-form-help"><?= $lang->_('This sends one email with the settings as they are saved, and tells you what the mail server answered. Save your changes first.') ?></p>

        <div class="idno-form-group">
            <label class="idno-label" for="to_email"><?= $lang->_('Send it to') ?></label>
            <input type="email" id="to_email" name="to_email" class="idno-input" required
                   placeholder="you@example.com" inputmode="email"
                   value="<?= htmlspecialchars((string) ($vars['test_to'] ?? '')) ?>">
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn"><?= $lang->_('Send test email') ?></button>
        </div>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/emailtest') ?>
    </form>
</div>

<?= $this->draw('admin/email/script') ?>
