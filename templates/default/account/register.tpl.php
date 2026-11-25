<div class="row">

    <div class="col-md-8 col-md-offset-2">
        <h3 class="register">
            <?php echo \Idno\Core\Idno::site()->language()->_('Hello there!'); ?>
        </h3>
        <h4 class="register"><?php echo \Idno\Core\Idno::site()->language()->_('Create a new account to get started.'); ?></h4>
        <div class="jumbotron">
            <?php if (!empty($vars['invited'])) { ?>
                <p>
                    <?php
                    if (!empty($vars['inviter'])) {
                        echo \Idno\Core\Idno::site()->language()->esc_('%s invited you to join %s.', [$vars['inviter'], \Idno\Core\Idno::site()->config()->title]);
                    } else {
                        echo \Idno\Core\Idno::site()->language()->esc_('You have been invited to join %s.', [\Idno\Core\Idno::site()->config()->title]);
                    }
                    ?>
                </p>
                <?php if (!empty($vars['is_admin'])) { ?>
                    <p class="alert alert-info">
                        <strong><?php echo \Idno\Core\Idno::site()->language()->_('You are invited as an administrator.'); ?></strong>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Administrators can change the site\'s settings, manage its members and see everything posted here.'); ?>
                    </p>
                <?php } ?>
            <?php } ?>
            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/register" method="post" style="width: 100%" class="form-horizontal">
                <div class="control-group">
                   <label class="control-label" for="inputUsername"><?php echo \Idno\Core\Idno::site()->language()->_('Your name'); ?></label>
                    <div class="controls">
                        <input type="text" id="inputName" placeholder="Henri Matisse" class="" style="width: 100%" name="name" value="<?php echo htmlspecialchars((string) ($vars['name'] ?? '')) ?>">
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label" for="inputUsername"><?php echo \Idno\Core\Idno::site()->language()->_('Choose a handle'); ?>
                        <br /><small><?php echo \Idno\Core\Idno::site()->language()->_('Handles identify you throughout the site. Something simple is best: for example, <em>janedoe</em>.'); ?></small></label>
                    <div class="controls">
                        <input type="text" id="inputUsername" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('username'); ?>" class="" style="width: 100%" name="handle" value="<?php echo htmlspecialchars((string) ($vars['handle'] ?? '')) ?>" autocapitalize="off" autocorrect="off" spellcheck="false">
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label" for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Your email address'); ?></label>
                    <div class="controls">
                        <input type="email" id="inputEmail" placeholder="you@email.com" class="" style="width: 100%" name="email" value="<?php echo htmlspecialchars((string) ($vars['email'] ?? '')) ?>" autocapitalize="off" <?php if (!empty($vars['invited'])) echo 'readonly'; ?>>
                        <?php if (!empty($vars['invited'])) { ?>
                            <small><?php echo \Idno\Core\Idno::site()->language()->_('The invitation is for this address, so it can\'t be changed here. You can change it in your settings afterwards.'); ?></small>
                        <?php } ?>
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label" for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Create a password'); ?>
                        <br /><small><?php echo \Idno\Core\Idno::site()->language()->_('At least 7 characters please.'); ?></small></label>
                    
                    <div class="controls">
                        <input type="password" id="inputPassword" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('secret-password'); ?>" class="" style="width: 100%" name="password" autocomplete="new-password">
                    </div>
                    
                </div>
                <div class="control-group">
                    <label class="control-label" for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Your password again'); ?></label>
                    <div class="controls">
                        <input type="password" id="inputPassword" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('secret-password'); ?>" class="" style="width: 100%" name="password2">
                    </div>
                </div>
                <div class="control-group">
                    <div class="controls">
                        <button type="submit" class="btn btn-reg"><?php echo \Idno\Core\Idno::site()->language()->_('Create Account'); ?></button>
                        <input type="hidden" name="code" value="<?php echo htmlspecialchars((string) ($vars['code'] ?? '')) ?>">
                    </div>
                </div>
                <?php echo $this->__(['action' => '/account/register'])->draw('forms/input/captcha'); ?>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/register') ?>

            </form>
        </div>
    </div>

</div>