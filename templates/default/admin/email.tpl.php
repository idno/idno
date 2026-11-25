<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $values = $vars['values'] ?? \Idno\Pages\Admin\Email::storedValues();
    $errors = $vars['errors'] ?? [];
    $has_password = $vars['has_password'] ?? ((string) $config->smtp_password !== '');
    $url = $config->getDisplayURL();

    // One row: label, input, the error if there is one, a line of help
    $field = function ($name, $label, $help, array $attributes = []) use ($values, $errors) {
        $attributes += ['type' => 'text'];
        ?>
        <div class="row<?php if (isset($errors[$name])) echo ' has-error'; ?>">
            <div class="col-md-2">
                <p class="control-label"><label for="<?php echo $name ?>"><strong><?php echo $label ?></strong></label></p>
            </div>
            <div class="col-md-4">
                <input id="<?php echo $name ?>" name="<?php echo $name ?>" class="form-control"
                       value="<?php echo htmlspecialchars((string) ($values[$name] ?? '')) ?>"
                       <?php if (isset($errors[$name])) { ?>aria-invalid="true"<?php } ?>
                       <?php foreach ($attributes as $attribute => $value) { ?><?php echo $attribute ?>="<?php echo htmlspecialchars((string) $value) ?>" <?php } ?>>
                <?php if (isset($errors[$name])) { ?>
                    <p class="help-block"><strong><?php echo htmlspecialchars($errors[$name]) ?></strong></p>
                <?php } ?>
            </div>
            <div class="col-md-6">
                <p class="config-desc"><?php echo $help ?></p>
            </div>
        </div>
        <?php
    };
    ?>
<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1><?php echo $lang->_('Email settings'); ?></h1>

        <div class="explanation">
            <p>
                <?php echo $lang->_('Your site sends email to invite people, to reset passwords and to notify members. It sends it through a mail server: usually a mail service you have an account with.'); ?>
            </p>
        </div>

        <?php if (!empty($vars['configured']) || \Idno\Core\Email::isConfigured()) { ?>
            <p class="alert alert-success" role="status">
                <?php echo $lang->esc_('Mail is sent through %s, from %s.', [$config->smtp_host, \Idno\Core\Email::fromName() . ' <' . $config->from_email . '>']) ?>
            </p>
        <?php } else { ?>
            <p class="alert alert-warning" role="status">
                <strong><?php echo $lang->_('No mail service is set up yet.') ?></strong>
                <?php echo $lang->_('Until you enter a mail server and a From address below, invitations and password reset emails will probably not arrive.') ?>
            </p>
        <?php } ?>
        <?php if (!empty($vars['fixed'])) { ?>
            <p class="alert alert-info" role="note">
                <?php echo $lang->esc_("These settings are set in the site's configuration file (config.ini) or environment, which wins over what is saved here: %s. Change them there.", [implode(', ', $vars['fixed'])]) ?>
            </p>
        <?php } ?>
    </div>
    <div class="col-md-10 col-md-offset-1">
        <form action="<?php echo $url ?>admin/email" class="form-horizontal" method="post" id="mail-settings-form" novalidate>

            <h3><?php echo $lang->_('Who the mail is from') ?></h3>
            <?php
                $field('from_email', $lang->_('From address'), $lang->_('Every email is sent from this address. It must be one your mail service lets you send from.'), ['type' => 'email', 'placeholder' => 'hello@example.com', 'autocomplete' => 'off']);
                $field('from_name', $lang->_('From name'), $lang->_("The name people see beside the address. Leave it empty to use the site's name."), ['placeholder' => (string) $config->title, 'maxlength' => 100, 'autocomplete' => 'off']);
                $field('reply_to_email', $lang->_('Reply-To address (optional)'), $lang->_('Where replies go, if not to the From address: for example your own inbox.'), ['type' => 'email', 'placeholder' => 'you@example.com', 'autocomplete' => 'off']);
            ?>

            <h3><?php echo $lang->_('Mail server') ?></h3>
            <?php
                $field('smtp_host', $lang->_('Server (SMTP host)'), $lang->_('The name of the mail server, as your mail service gives it: for example smtp.sendgrid.net.'), ['placeholder' => 'smtp.example.com', 'autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false']);
            ?>
            <div class="row">
                <div class="col-md-2">
                    <p class="control-label"><label for="smtp_secure"><strong><?php echo $lang->_('Security') ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <select name="smtp_secure" id="smtp_secure" class="form-control">
                        <?php foreach ([
                            'tls' => $lang->_('STARTTLS (usual, port 587)'),
                            'ssl' => $lang->_('TLS (port 465)'),
                            ''    => $lang->_('None (port 25)'),
                        ] as $value => $label) { ?>
                            <option value="<?php echo $value ?>" <?php if (($values['smtp_secure'] ?? '') === $value) echo 'selected'; ?>><?php echo $label ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo $lang->_('How the connection is encrypted. Mail services expect STARTTLS on port 587.') ?></p>
                </div>
            </div>
            <?php
                $field('smtp_port', $lang->_('Port'), $lang->_('Usually 587. Leave it empty to use the usual port for the security you chose.'), ['placeholder' => '587', 'autocomplete' => 'off', 'maxlength' => 5]);
                $field('smtp_username', $lang->_('User name'), $lang->_('The user name your mail service gives you for SMTP. For some services it is a fixed word such as "apikey".'), ['autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false']);
            ?>
            <div class="row">
                <div class="col-md-2">
                    <p class="control-label"><label for="smtp_password"><strong><?php echo $lang->_('Password') ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="password" id="smtp_password" name="smtp_password" class="form-control" value=""
                           autocomplete="new-password"
                           placeholder="<?php echo $has_password ? $lang->_('Saved. Type a new one to replace it') : '' ?>">
                    <?php if ($has_password) { ?>
                        <label><input type="checkbox" name="smtp_password_remove" value="1"> <?php echo $lang->_('Remove the saved password') ?></label>
                    <?php } ?>
                </div>
                <div class="col-md-6">
                    <p class="config-desc">
                        <?php if ($has_password) { ?>
                            <?php echo $lang->_('A password is saved and is not shown here. Leave this empty to keep it, or type a new one to replace it.') ?>
                        <?php } else { ?>
                            <?php echo $lang->_('The password or API key for SMTP. It is stored on your site and never shown again.') ?>
                        <?php } ?>
                    </p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-10">
                    <p>
                        <button type="submit" class="btn btn-primary"><?php echo $lang->_('Save settings'); ?></button>
                    </p>
                </div>
            </div>
            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/email') ?>
        </form>

        <?php echo $this->draw('admin/email/services') ?>

        <form action="<?php echo $url ?>admin/emailtest" class="form-horizontal" method="post" id="test">
            <h3><?php echo $lang->_('Send a test email') ?></h3>
            <p><?php echo $lang->_('This sends one email with the settings as they are saved, and tells you what the mail server answered. Save your changes first.') ?></p>
            <div class="row">
                <div class="col-md-2">
                    <p class="control-label"><label for="to_email"><strong><?php echo $lang->_('Send it to') ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="email" id="to_email" name="to_email" class="form-control" required placeholder="you@example.com"
                           value="<?php echo htmlspecialchars((string) ($vars['test_to'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-default"><?php echo $lang->_('Send test email'); ?></button>
                </div>
            </div>
            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/emailtest') ?>
        </form>

        <?php echo $this->draw('admin/email/script') ?>
    </div>
</div>
