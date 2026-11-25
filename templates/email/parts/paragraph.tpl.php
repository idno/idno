<?php

    /*
     * A paragraph. 'text' (line breaks are kept), or 'format' and 'values': a sentence with
     * %s for each value, the values set in bold. 'muted' for a smaller, quieter one.
     */

    use Idno\Core\Email;

    $html = (string) $vars['format'] !== ''
        ? Email::emphasise($vars['format'], (array) $vars['values'])
        : nl2br(htmlspecialchars(trim((string) $vars['text'])));

if (!empty($vars['muted'])) { ?>
<p class="idno-mail-muted" style="margin: 0 0 16px 0; font-family: <?php echo Email::FONT ?>; font-size: 14px; line-height: 21px; color: <?php echo Email::colour('muted') ?>;"><?php echo $html ?></p>
<?php } else { ?>
<p class="idno-mail-text" style="margin: 0 0 16px 0; font-family: <?php echo Email::FONT ?>; font-size: 16px; line-height: 24px; color: <?php echo Email::colour('text') ?>;"><?php echo $html ?></p>
<?php }
