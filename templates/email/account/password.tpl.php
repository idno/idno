<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $site = htmlspecialchars((string) $config->title);
    $link = htmlspecialchars($config->getDisplayURL() . 'account/password/reset/?email=' . urlencode((string) $vars['email']) . '&code=' . urlencode((string) $vars['code']));
    ?>
<div style="font-weight: bold; font-size: 26px; line-height: 32px; color: #333" align="center">
    <?php echo $lang->_('Reset your password') ?>
</div><br>
<hr/>
<br>
<?php echo $lang->_('Somebody, probably you, asked to reset the password of your account on <strong>%s</strong>.', [$site]) ?>
<br><br>
<?php echo $lang->_('To choose a new password, use this button within three hours:') ?>
<br><br>
<div align="center">
<a href="<?php echo $link ?>" style="background-color:#111111;border:1px solid #111111;border-radius:6px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:17px;font-weight:bold;line-height:44px;text-align:center;text-decoration:none;width:240px;-webkit-text-size-adjust:none;"><?php echo $lang->_('Choose a new password') ?></a>
</div>
<br>
<?php echo $lang->_('Or copy this address into your browser:') ?><br>
<a href="<?php echo $link ?>" style="color: #2563eb; word-break: break-all;"><?php echo $link ?></a>
<br><br>
<?php echo $lang->_("If you didn't ask for this, you can ignore this email: your password stays as it is.") ?>
<br><br>
