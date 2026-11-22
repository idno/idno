<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Email settings'); ?></h1>

        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_("Idno tries to send email using your server's default email settings. If you'd like it to do something else - for example, if you'd like to send email using an external provider - enter the new SMTP settings below."); ?>
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_("After changing the mail settings, restart the queue worker: the mails it sends (the export and import results) use the settings it read when it started."); ?>
            </p>
        </div>
    </div>
    <div class="col-md-10 col-md-offset-1">
        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/email" class="form-horizontal"
              method="post">

            <div class="row">
                <div class="col-md-10 email-settings">
                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Fill in the site email address if you would like your site to send email.'); ?>
                    </p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Site email address'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="from_email" placeholder="Site email address" class="form-control" name="from_email"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->from_email) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('This is the address that every email will be sent from.'); ?></p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 email-settings">
                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_("You can often leave the following settings blank. However, you may wish to set them if you're using a third-party service to send email."); ?>
                    </p>
                </div>
            </div>


            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('SMTP host'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="smtp_host" placeholder="SMTP host" class="form-control" name="smtp_host"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_host) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('This is the address of the server that will send email for you.'); ?></p>
                </div>
            </div>


            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('SMTP username'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="smtp_username" placeholder="SMTP username" class="form-control" name="smtp_username"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_username) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('If your mail server needs a username, enter it here.'); ?></p>
                </div>
            </div>


            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('SMTP password'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <input type="password" id="smtp_password" placeholder="SMTP password" class="form-control"
                           name="smtp_password"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->smtp_password) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('If your mail server needs a password, enter it here.'); ?></p>
                </div>
            </div>


            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('SMTP port'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="smtp_port" placeholder="SMTP password" class="form-control" name="smtp_port"
                           value="<?php

                               $port = (int)\Idno\Core\Idno::site()->config()->smtp_port;
                            if (empty($port)) {
                                $port = 25;
                            }
                               echo $port;

                            ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc">
                        <?php echo \Idno\Core\Idno::site()->language()->_('This is normally 25 or 587.'); ?>
                    </p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-2">
                    <p class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Secure connection'); ?></strong></p>
                </div>
                <div class="col-md-4">
                    <select name="smtp_secure">
                        <?php
                        foreach ([
                                         'No'        => false,
                                         'Yes (TLS)' => 'tls',
                                         'Yes (SSL)' => 'ssl'
                                     ] as $field => $value) {
                            ?>
                                <option
                                    value="<?php echo $value; ?>" <?php if (\Idno\Core\Idno::site()->config()->smtp_secure === $value) {
                                        echo "selected";
} ?>><?php echo $field; ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Select yes if you use secure logins to your mail server.'); ?></p>
                </div>
            </div>


            <div class="control-group">
                <div class="controls-save">
                    <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Save settings'); ?></button>
                </div>
            </div>

            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/email') ?>
        </form>
    </div>

    <?php if (\Idno\Core\Idno::site()->config()->from_email) { ?>
    <div class="col-md-10 col-md-offset-1" style="margin-top: 5em">
                <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/emailtest" class="form-horizontal"
                      method="post">

                    <div class="row">
                        <div class="col-md-2">
                            <p class="control-label" for="to_email"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Send a test message to'); ?>:</strong></p>
                        </div>
                        <div class="col-md-4">
                            <input type="text" id="to_email" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('To address'); ?>" class="form-control" name="to_email"
                                   value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->from_email) ?>">
                        </div>
                        <div class="col-md-4">
                            <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Check your email settings by sending a test email.'); ?></p>
                        </div>
                    </div>

                    <div class="control-group">
                        <div class="controls-save">
                            <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Test settings'); ?></button>
                        </div>
                    </div>
                    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/emailtest') ?>
                </form>
        </div>
    <?php } ?>
</div>