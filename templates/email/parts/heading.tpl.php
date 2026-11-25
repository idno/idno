<?php /* The heading of a mail. 'text' */ ?>
<h1 class="idno-mail-strong" style="margin: 0 0 16px 0; font-family: <?php echo \Idno\Core\Email::FONT ?>; font-size: 24px; line-height: 30px; font-weight: 700; color: <?php echo \Idno\Core\Email::colour('strong') ?>;"><?php echo htmlspecialchars(\Idno\Core\Email::oneLine($vars['text'])) ?></h1>
