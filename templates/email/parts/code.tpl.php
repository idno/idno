<?php

    /* Preformatted text (an error message): 'text'. Long lines wrap; nothing is wider than the mail. */

if (trim((string) $vars['text']) !== '') { ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; table-layout: fixed; margin: 0 0 16px 0;">
    <tr>
        <td class="idno-mail-box" bgcolor="<?php echo \Idno\Core\Email::colour('box', 'background-color') ?>" style="padding: 12px 14px; background-color: <?php echo \Idno\Core\Email::colour('box', 'background-color') ?>; border: 1px solid <?php echo \Idno\Core\Email::colour('box', 'border-color') ?>; border-radius: 8px; font-family: <?php echo \Idno\Core\Email::FONT_MONO ?>; font-size: 13px; line-height: 20px; color: <?php echo \Idno\Core\Email::colour('box') ?>; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere;"><?php echo htmlspecialchars(trim((string) $vars['text'])) ?></td>
    </tr>
</table>
<?php }
