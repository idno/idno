<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Email') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_("Idno tries to send email using your server's default email settings. If you'd like it to do something else - for example, if you'd like to send email using an external provider - enter the new SMTP settings below.") ?>
    </p>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_("After changing the mail settings, restart the queue worker: the mails it sends (the export and import results) use the settings it read when it started.") ?>
    </p>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/email" method="post">
        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Email Settings') ?></h2>

            <p><?= \Idno\Core\Idno::site()->language()->_('Fill in the site email address if you would like your site to send email.') ?></p>

            <div class="idno-form-group">
                <label class="idno-label" for="from_email"><?= \Idno\Core\Idno::site()->language()->_('Site email address') ?></label>
                <input type="text" id="from_email" name="from_email" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Site email address') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->from_email) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('This is the address that every email will be sent from.') ?></p>
            </div>
        </div>

        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('SMTP Settings') ?></h2>

            <p><?= \Idno\Core\Idno::site()->language()->_("You can often leave the following settings blank. However, you may wish to set them if you're using a third-party service to send email.") ?></p>

            <div class="idno-form-group">
                <label class="idno-label" for="smtp_host"><?= \Idno\Core\Idno::site()->language()->_('SMTP host') ?></label>
                <input type="text" id="smtp_host" name="smtp_host" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('SMTP host') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_host) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('This is the address of the server that will send email for you.') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="smtp_username"><?= \Idno\Core\Idno::site()->language()->_('SMTP username') ?></label>
                <input type="text" id="smtp_username" name="smtp_username" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('SMTP username') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_username) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('If your mail server needs a username, enter it here.') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="smtp_password"><?= \Idno\Core\Idno::site()->language()->_('SMTP password') ?></label>
                <input type="password" id="smtp_password" name="smtp_password" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('SMTP password') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_password) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('If your mail server needs a password, enter it here.') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="smtp_port"><?= \Idno\Core\Idno::site()->language()->_('SMTP port') ?></label>
                <input type="text" id="smtp_port" name="smtp_port" class="idno-input"
                       placeholder="25"
                       value="<?php
                           $port = (int)\Idno\Core\Idno::site()->config()->smtp_port;
                           if (empty($port)) {
                               $port = 25;
                           }
                           echo $port;
                       ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('This is normally 25 or 587.') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="smtp_secure"><?= \Idno\Core\Idno::site()->language()->_('Secure connection') ?></label>
                <select name="smtp_secure" id="smtp_secure" class="idno-input">
                    <?php
                    foreach ([
                        'No'        => false,
                        'Yes (TLS)' => 'tls',
                        'Yes (SSL)' => 'ssl'
                    ] as $field => $value) {
                    ?>
                        <option value="<?= $value ?>" <?php if (\Idno\Core\Idno::site()->config()->smtp_secure === $value) echo 'selected'; ?>><?= $field ?></option>
                    <?php } ?>
                </select>
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Select yes if you use secure logins to your mail server.') ?></p>
            </div>
        </div>

        <div style="margin-top:var(--spacing-section)">
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save settings') ?></button>
        </div>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/email') ?>
    </form>

    <?php if (\Idno\Core\Idno::site()->config()->from_email) { ?>
    <div class="idno-admin-card" style="margin-top:var(--spacing-section)">
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/emailtest" method="post">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Test Email') ?></h2>

            <div class="idno-form-group">
                <label class="idno-label" for="to_email"><?= \Idno\Core\Idno::site()->language()->_('Send a test message to') ?></label>
                <input type="text" id="to_email" name="to_email" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('To address') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->from_email) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Check your email settings by sending a test email.') ?></p>
            </div>

            <div style="margin-top:var(--spacing-section)">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Test settings') ?></button>
            </div>

            <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/emailtest') ?>
        </form>
    </div>
    <?php } ?>
