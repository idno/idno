<?php

    /*
     * The invitation email. Everything that a person typed (the inviter's name, the site's
     * name) is escaped; the link is made by Invitation::getLink().
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $site = htmlspecialchars((string) $config->title);
    $link = htmlspecialchars((string) $vars['link']);
    $inviter = htmlspecialchars((string) ($vars['inviter'] ?? ''));
    // What the site says it is, unless that is still the text every new site starts with
    $description = trim((string) $config->description);
    if ($description === 'A social website powered by Idno') {
        $description = '';
    }
    ?>
<div style="font-weight: bold; font-size: 26px; line-height: 32px; color: #333" align="center">
    <?php echo $lang->_("You're invited to join %s", [$site]) ?>
</div><br>
<hr/>
<br>
<?php
if ($inviter !== '') {
    if (!empty($vars['is_admin'])) {
        echo $lang->_('<strong>%s</strong> has invited you to join <strong>%s</strong> as an <strong>administrator</strong>.', [$inviter, $site]);
    } else {
        echo $lang->_('<strong>%s</strong> has invited you to join <strong>%s</strong>.', [$inviter, $site]);
    }
} else {
    if (!empty($vars['is_admin'])) {
        echo $lang->_('You have been invited to join <strong>%s</strong> as an <strong>administrator</strong>.', [$site]);
    } else {
        echo $lang->_('You have been invited to join <strong>%s</strong>.', [$site]);
    }
}
?>
<?php if ($description !== '') { ?>
<br><br>
<em><?php echo htmlspecialchars($description) ?></em>
<?php } ?>
<br><br>
<?php
if (!empty($vars['is_admin'])) {
    echo $lang->_('As an administrator you will be able to change the site\'s settings, manage its members and invite others.') . '<br><br>';
}
echo $lang->_('To accept, create your account:');
?>
<br><br>
<div align="center">
<a href="<?php echo $link ?>" style="background-color:#111111;border:1px solid #111111;border-radius:6px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:17px;font-weight:bold;line-height:44px;text-align:center;text-decoration:none;width:240px;-webkit-text-size-adjust:none;"><?php echo $lang->_('Create your account') ?></a>
</div>
<br>
<?php echo $lang->_('Or copy this address into your browser:') ?><br>
<a href="<?php echo $link ?>" style="color: #2563eb; word-break: break-all;"><?php echo $link ?></a>
<br><br>
<?php echo $lang->_('This invitation is for %s only and works until %s.', [htmlspecialchars((string) $vars['email']), date('j F Y', (int) $vars['expires'])]) ?>
<?php echo $lang->_("If you weren't expecting it, you can ignore this email.") ?>
<br><br>
