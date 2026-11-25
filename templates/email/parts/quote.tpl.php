<?php

    /* Words somebody typed, set apart from the mail's own: 'text' (line breaks are kept) */

    use Idno\Core\Email;

if (trim((string) $vars['text']) !== '') { ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin: 0 0 16px 0;">
    <tr>
        <td class="idno-mail-box" bgcolor="<?php echo Email::colour('box', 'background-color') ?>" style="padding: 12px 16px; background-color: <?php echo Email::colour('box', 'background-color') ?>; border-left: 3px solid <?php echo Email::colour('box', 'border-color') ?>; border-radius: 0 8px 8px 0; font-family: <?php echo Email::FONT ?>; font-size: 15px; line-height: 23px; color: <?php echo Email::colour('box') ?>; word-break: break-word;"><?php echo nl2br(htmlspecialchars(trim((string) $vars['text']))) ?></td>
    </tr>
</table>
<?php }
