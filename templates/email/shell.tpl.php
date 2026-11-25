<?php

    /*
     * The shell of every HTML mail the site sends: the site's name, the body in a card, and
     * a footer that says why the reader got the mail and which site sent it.
     *
     * Mail clients are not browsers. The layout is tables, every style that matters is
     * inline (some clients drop the <style> element, which here only adds the phone and dark
     * variants), there is no image, no font and no other file to fetch, and no script.
     *
     * Variables, all plain text and escaped here: 'body' (HTML, from a mail's template),
     * 'title' (the subject), 'preheader', 'reason'.
     */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $title = htmlspecialchars(Email::oneLine($vars['title'] ?? ''));
    $preheader = htmlspecialchars(Email::oneLine($vars['preheader'] ?? ''));
    $reason = htmlspecialchars(Email::oneLine($vars['reason'] ?? ''));
    $siteName = htmlspecialchars(Email::siteName());
    $siteUrl = \Idno\Core\UrlValidator::httpUrl(Email::siteUrl());
    $siteHost = htmlspecialchars(rtrim((string) preg_replace('#^https?://#i', '', $siteUrl), '/'));
    $siteUrl = htmlspecialchars($siteUrl, ENT_QUOTES);

    $langCode = preg_replace('/[^A-Za-z0-9-]/', '', str_replace('_', '-', (string) $lang->getLanguage()));
if ($langCode === '' || $langCode === null) {
    $langCode = 'en';
}
    $dir = in_array(strtolower(substr($langCode, 0, 2)), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr';
    $align = $dir === 'rtl' ? 'right' : 'left';
    $font = Email::FONT;

?><!DOCTYPE html>
<html lang="<?php echo $langCode ?>" dir="<?php echo $dir ?>" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?php echo $title ?></title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; width: 100%; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: separate; mso-table-lspace: 0; mso-table-rspace: 0; }
    a { text-decoration: underline; }
    @media screen and (max-width: 620px) {
        .idno-mail-outer { padding: 20px 10px !important; }
        .idno-mail-card { padding: 24px 20px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .idno-mail-bg { background-color: #111111 !important; }
        .idno-mail-card { background-color: #1c1c1e !important; border-color: #2e2e32 !important; }
        .idno-mail-strong { color: #f9fafb !important; }
        .idno-mail-text { color: #d1d5db !important; }
        .idno-mail-muted { color: #a1a1aa !important; }
        .idno-mail-link { color: #93b4fd !important; }
        .idno-mail-button { background-color: #f9fafb !important; }
        .idno-mail-button a { color: #111111 !important; }
        .idno-mail-box { background-color: #26262a !important; border-color: #3a3a40 !important; color: #e5e7eb !important; }
        .idno-mail-rule { border-color: #2e2e32 !important; }
    }
    /* The same dark colours for Outlook.com, which rewrites a mail's own */
    [data-ogsc] .idno-mail-strong { color: #f9fafb !important; }
    [data-ogsc] .idno-mail-text { color: #d1d5db !important; }
    [data-ogsc] .idno-mail-muted { color: #a1a1aa !important; }
    [data-ogsc] .idno-mail-link { color: #93b4fd !important; }
</style>
</head>
<body class="idno-mail-bg" style="margin: 0; padding: 0; width: 100%; background-color: #f3f4f6;">
<?php if ($preheader !== '') { ?>
<div style="display: none; max-height: 0; max-width: 0; overflow: hidden; opacity: 0; font-size: 1px; line-height: 1px; color: #f3f4f6; mso-hide: all;"><?php echo $preheader ?><?php echo str_repeat('&#847;&zwnj;&nbsp;', 60) ?></div>
<?php } ?>
<table role="presentation" class="idno-mail-bg" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="width: 100%; background-color: #f3f4f6;">
<tr>
<td class="idno-mail-outer" align="center" style="padding: 32px 16px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px;">
    <tr>
        <td class="idno-mail-strong" style="padding: 0 4px 16px 4px; font-family: <?php echo $font ?>; font-size: 16px; line-height: 24px; font-weight: 700; color: #111111; text-align: <?php echo $align ?>;">
            <?php echo $siteName ?>

        </td>
    </tr>
    <tr>
        <td class="idno-mail-card idno-mail-text" bgcolor="#ffffff" style="padding: 32px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; font-family: <?php echo $font ?>; font-size: 16px; line-height: 24px; color: #374151; text-align: <?php echo $align ?>;">
<?php echo $vars['body'] ?>

        </td>
    </tr>
    <tr>
        <td class="idno-mail-muted" style="padding: 20px 4px 0 4px; font-family: <?php echo $font ?>; font-size: 13px; line-height: 20px; color: #4b5563; text-align: <?php echo $align ?>;">
<?php if ($reason !== '') { ?>
            <?php echo $reason ?><br>
<?php } ?>
            <?php echo $siteName ?><?php if ($siteUrl !== '') { ?> &middot; <a class="idno-mail-muted" href="<?php echo $siteUrl ?>" style="color: #4b5563; text-decoration: underline;"><?php echo $siteHost ?></a><?php
            } ?>

        </td>
    </tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
