<div class="row">

    <div class="col-md-8 col-md-offset-2">

        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Reset your password'); ?>
        </h1>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_("Forgot your password? Don't worry! It happens to the best of us."); ?></p> 
           <p><?php echo \Idno\Core\Idno::site()->language()->_("Just enter the email address associated with your %s account below, and we'll send you a top secret link to your email account so that you can create a new password.", [\Idno\Core\Idno::site()->config()->title]); ?>
        </p>


        <?php echo $this->draw('account/password/nomail'); ?>

        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/password" method="post">

            <div class="row">
            <div class="form-group col-md-6">
                <label class="control-label" for="inputName">Your email address</label>
                    <!--<small>The address associated with your <?php echo \Idno\Core\Idno::site()->config()->title?> account.</small>-->
                    <input type="email" id="inputName" placeholder="me@awesome.com" class="form-control" name="email" value="" required>
            </div>
            </div>
            <?php echo $this->__(['action' => '/account/password'])->draw('forms/input/captcha'); ?>
            <div class="row">
            <div class="form-group col-md-6">
                <div class="controls">
                    <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Send password reset email'); ?></button>
                    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/password') ?>
                </div>
            </div>
            </div>

        </form>

    </div>

</div>