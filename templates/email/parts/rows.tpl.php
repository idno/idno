<?php

    /* A short list of facts: 'rows', label => value. Rows with an empty value are left out. */

    use Idno\Core\Email;

    $rows = array_filter(
        array_map([Email::class, 'oneLine'], (array) $vars['rows']), function ($value) {
            return $value !== '';
        }
    );
    if ($rows) { ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin: 0 0 16px 0;">
        <?php foreach ($rows as $label => $value) { ?>
    <tr>
        <td class="idno-mail-muted idno-mail-rule" width="34%" valign="top" style="width: 34%; padding: 8px 12px 8px 0; border-top: 1px solid #e5e7eb; font-family: <?php echo Email::FONT ?>; font-size: 14px; line-height: 21px; color: #6b7280;"><?php echo htmlspecialchars(Email::oneLine($label)) ?></td>
        <td class="idno-mail-text idno-mail-rule" valign="top" style="padding: 8px 0; border-top: 1px solid #e5e7eb; font-family: <?php echo Email::FONT ?>; font-size: 14px; line-height: 21px; color: #374151; word-break: break-word;"><?php echo htmlspecialchars($value) ?></td>
    </tr>
        <?php } ?>
</table>
    <?php }
