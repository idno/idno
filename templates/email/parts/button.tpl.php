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
    $width = max(180, 48 + 10 * mb_strlen(Email::oneLine($vars['label'])));
    ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 8px 0 16px 0;">
    <tr>
        <td class="idno-mail-button" align="center" bgcolor="#111111" style="background-color: #111111; border-radius: 8px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?php echo $href ?>" style="height:46px;v-text-anchor:middle;width:<?php echo $width ?>px;" arcsize="18%" stroke="f" fillcolor="#111111">
            <w:anchorlock/>
            <center style="color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:16px;font-weight:bold;"><?php echo $label ?></center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="<?php echo $href ?>" style="display: inline-block; padding: 11px 24px; font-family: <?php echo Email::FONT ?>; font-size: 16px; line-height: 24px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;"><?php echo $label ?></a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
<p class="idno-mail-muted" style="margin: 0 0 16px 0; font-family: <?php echo Email::FONT ?>; font-size: 14px; line-height: 21px; color: #6b7280;"><?php echo htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Or copy this address into your browser:')) ?><br>
<a class="idno-mail-link" href="<?php echo $href ?>" style="color: #2563eb; text-decoration: underline; word-break: break-all;"><?php echo htmlspecialchars($url) ?></a></p>
    <?php
}
