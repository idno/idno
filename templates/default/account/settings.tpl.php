<?php
    $user = \Idno\Core\Idno::site()->session()->currentUser();
?>
<h1 class="idno-admin-page-title">
    <?php echo \Idno\Core\Idno::site()->language()->_('Account Settings'); ?>
</h1>

<p class="idno-admin-description">
    <?php echo \Idno\Core\Idno::site()->language()->_('Change your user account settings here. You may also want to <a href="%s">edit your profile</a>.', [\Idno\Core\Idno::site()->session()->currentUser()->getDisplayURL() . '/edit/']); ?>
</p>

<div class="idno-admin-card">
    <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/settings/" method="post">

        <div class="idno-form-group">
            <label for="inputName"><?php echo \Idno\Core\Idno::site()->language()->_('Your name'); ?></label>
            <?php echo $this->__(['id' => 'inputName', 'value' => $user->getTitle(), 'class' => 'idno-input', 'name' => 'name', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Your name')])->draw('forms/input/text'); ?>
        </div>

        <div class="idno-form-group">
            <label for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Your email address'); ?></label>
            <?php echo $this->__(['id' => 'inputEmail', 'value' => $user->email, 'class' => 'idno-input', 'name' => 'email', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Your email address')])->draw('forms/input/email'); ?>
            <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_('Used for account recovery and communications.'); ?></p>
        </div>

        <div class="idno-form-group">
            <label for="inputPassword"><?php echo \Idno\Core\Idno::site()->language()->_('Your password'); ?></label>
            <?php echo $this->__(['id' => 'inputPassword', 'class' => 'idno-input', 'name' => 'password', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Password'), 'autocomplete' => 'off', 'value' => ''])->draw('forms/input/password'); ?>
            <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_("Leave this blank if you don't want to change it."); ?></p>
        </div>

        <div class="idno-form-group">
            <label for="inputTimezone"><?php echo \Idno\Core\Idno::site()->language()->_('Your timezone'); ?></label>
            <?php echo $this->__(
                [
                'id' => 'inputTimezone',
                'class' => 'idno-input',
                'blank-default' => true,
                'name' => 'timezone',
                'placeholder' => \Idno\Core\Idno::site()->language()->_('Timezone'),
                'value' => $user->timezone
                ]
            )->draw('forms/input/timezones'); ?>
            <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_('Specify your timezone.'); ?></p>
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Save updates'); ?></button>
        </div>

        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings') ?>
    </form>
</div>
