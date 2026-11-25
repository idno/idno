<?php

    /*
     * The main thing to do in a mail: 'label', 'url'. A table cell with a background, which
     * every mail client draws, and for Outlook on Windows (which ignores padding on a link)
     * a VML shape of the same size. The address is printed underneath for a reader whose
     * client shows neither, or who would rather copy it.
     */

    use Idno\Core\Email;

    $url = \Idno\Core\UrlValidator::httpUrl((string) $vars['url']);
if ($url !== '') {
    $href = htmlspecialchars($url, ENT_QUOTES);
    $label = htmlspecialchars(Email::oneLine($vars['label']));
    // The label and its background are set together, on the cell and on the link, so that a
    // mail client that turns colours over turns both; the border keeps the button's shape
    // where only the card around it has turned dark
    $fill = Email::colour('button', 'background-color');
    $ink = Email::colour('button');
    $edge = Email::colour('button', 'border-color');
    $width = max(180, 48 + 10 * mb_strlen(Email::oneLine($vars['label'])));
    ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 8px 0 16px 0;">
    <tr>
        <td class="idno-mail-button" align="center" bgcolor="<?php echo $fill ?>" style="background-color: <?php echo $fill ?>; color: <?php echo $ink ?>; border-radius: 8px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?php echo $href ?>" style="height:46px;v-text-anchor:middle;width:<?php echo $width ?>px;" arcsize="18%" strokecolor="<?php echo $edge ?>" fillcolor="<?php echo $fill ?>">
            <w:anchorlock/>
            <center style="color:<?php echo $ink ?>;font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:bold;"><?php echo $label ?></center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a class="idno-mail-button" href="<?php echo $href ?>" style="display: inline-block; padding: 10px 23px; background-color: <?php echo $fill ?>; border: 1px solid <?php echo $edge ?>; border-radius: 8px; font-family: <?php echo Email::FONT ?>; font-size: 16px; line-height: 24px; font-weight: 600; color: <?php echo $ink ?>; text-decoration: none;"><?php echo $label ?></a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
<p class="idno-mail-muted" style="margin: 0 0 16px 0; font-family: <?php echo Email::FONT ?>; font-size: 14px; line-height: 21px; color: <?php echo Email::colour('muted') ?>;"><?php echo htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Or copy this address into your browser:')) ?><br>
<a class="idno-mail-link" href="<?php echo $href ?>" style="color: <?php echo Email::colour('link') ?>; text-decoration: underline; word-break: break-all;"><?php echo htmlspecialchars($url) ?></a></p>
    <?php
}
