<?php

    /*
     * A mail from the Moderation plugin (see \IdnoPlugins\Moderation\Mailer). Every part is
     * plain text, some of it typed by a member, and is escaped here.
     */

    $mail = $vars['mail'];
    $linkUrl = !empty($mail['link']['url']) ? \Idno\Core\UrlValidator::httpUrl((string) $mail['link']['url']) : '';

?>
<div style="font-weight: bold; font-size: 26px; line-height: 30px; color: #333" align="center">
    <?php echo htmlspecialchars((string) $mail['heading']) ?>
</div><br>
<hr/>
<br>
<?php foreach ($mail['paragraphs'] as $paragraph) { ?>
    <?php echo nl2br(htmlspecialchars((string) $paragraph)) ?>
<br><br>
<?php } ?>
<?php if ((string) $mail['quote'] !== '') { ?>
<div style="border-left: 3px solid #ddd; padding: 4px 12px; color: #444; background: #fafafa;">
    <?php echo nl2br(htmlspecialchars((string) $mail['quote'])) ?>
</div>
<br>
<?php } ?>
<?php if ($linkUrl !== '') { ?>
<a href="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES) ?>" style="color: #73b2e3; text-decoration: none;"><?php echo htmlspecialchars((string) $mail['link']['label']) ?></a>
<br><br>
<?php }
