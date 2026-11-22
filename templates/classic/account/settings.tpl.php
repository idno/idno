<?php
    $user = \Idno\Core\Idno::site()->session()->currentUser();
?>
<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('account/menu') ?>
        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Account Settings'); ?>
        </h1>

        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Change your user account settings here. You may also want to <a href="%s">edit your profile</a>.', [\Idno\Core\Idno::site()->session()->currentUser()->getDisplayURL() . '/edit/']); ?>
            </p>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <form class="form-horizontal admin" action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/settings/" method="post">


            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="inputName"><?php echo \Idno\Core\Idno::site()->language()->_('Your name'); ?></label>
                </div>
                <div class="col-md-4">
                    <?php echo $this->__(['id' => 'inputName', 'value' => $user->getTitle(), 'class' => 'input col-md-4 form-control', 'name' => 'name', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Your name')])->draw('forms/input/text'); ?>
                </div>

                <div class="col-md-5">
                </div>
            </div>

            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Your email address'); ?></label>
                </div>
                <div class="col-md-4">
                    <?php echo $this->__(['id' => 'inputEmail', 'value' => $user->email, 'class' => 'input col-md-4 form-control', 'name' => 'email', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Your email address')])->draw('forms/input/email'); ?>
                </div>
                <div class="col-md-5 config-desc">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Used for account recovery and communications.'); ?>
                </div>
            </div>

            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="inputPassword"><?php echo \Idno\Core\Idno::site()->language()->_('Your password'); ?><br>
                    </label>
                </div>
                <div class="col-md-4">
                    <?php echo $this->__(['id' => 'inputPassword', 'class' => 'input col-md-4 form-control', 'name' => 'password', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Password'), 'autocomplete' => 'off', 'value' => ''])->draw('forms/input/password'); ?>
                </div>
                <div class="col-md-5 config-desc">
                    <?php echo \Idno\Core\Idno::site()->language()->_("Leave this blank if you don't want to change it."); ?>
                </div>
            </div>
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="inputTimezone"><?php echo \Idno\Core\Idno::site()->language()->_('Your timezone'); ?><br>
                    </label>
                </div>
                <div class="col-md-4">
                    <?php echo $this->__(
                        [
                        'id' => 'inputTimezone',
                        'class' => 'input-timezone input col-md-4 form-control',
                        'blank-default' => true,
                        'name' => 'timezone',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Timezone'),
                        'value' => $user->timezone
                        ]
                    )->draw('forms/input/timezones'); ?>
                </div>
                <div class="col-md-5 config-desc">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Specify your timezone.'); ?>
                </div>
            </div>

            <div class="controls-save">
                <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Save updates'); ?></button>
            </div>

            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings') ?>
        </form>


    </div>
</div>
