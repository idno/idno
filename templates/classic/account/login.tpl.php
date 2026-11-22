<div class="row">
    <div class="col-md-6 col-md-offset-3 well text-center">

        <h2 class="text-center welcome"><?php echo \Idno\Core\Idno::site()->language()->_('Welcome back!'); ?></h2>

        <h3 class="text-center">
            <?php echo \Idno\Core\Idno::site()->language()->_('Sign in'); ?>
        </h3>

        <div class="col-md-10 col-md-offset-1">

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>session/login" method="post">
                <div class="form-group">
                    <input type="text" id="inputEmail" name="email" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Your email address or username'); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <input type="password" id="inputPassword" name="password" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Password'); ?>" class="form-control">
                </div>
                <?php echo $this->__(['action' => '/session/login'])->draw('forms/input/captcha'); ?>
                <div class="form-group">
                    <button type="submit" class="btn btn-signin"><?php echo \Idno\Core\Idno::site()->language()->_('Sign in'); ?></button>
                    <input type="hidden" name="fwd" value="<?php
                    if (!empty($vars['fwd'])) {
                        echo htmlspecialchars($vars['fwd']);
                    } else if (!empty($_SERVER['HTTP_REFERER'])) {
                        echo htmlspecialchars($_SERVER['HTTP_REFERER']);
                    } else {
                        echo \Idno\Core\Idno::site()->config()->getDisplayURL();
                    }?>" />
                </div>

                <div class="form-group">
                    <?php
                    if (\Idno\Core\Idno::site()->config()->open_registration == true && \Idno\Core\Idno::site()->config()->canAddUsers()) {
                        ?>
                        <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/register"><?php echo \Idno\Core\Idno::site()->language()->_('New here? Register for an account.'); ?></a><br><br>
                        <?php
                    }
                    ?>
                    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/password"><?php echo \Idno\Core\Idno::site()->language()->_('Forgot your password?'); ?></a>
            </div>
            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/session/login') ?>
        </form>
        </div>

    </div>
</div>

<script language="JavaScript">
    $(document).ready(function() {
        $('#inputEmail').focus();
    });
</script>
