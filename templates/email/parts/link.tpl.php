<?php

    /* A second, quieter link: 'label', 'url' */

    use Idno\Core\Email;

    $url = \Idno\Core\UrlValidator::httpUrl((string) $vars['url']);
if ($url !== '') { ?>
<p style="margin: 0 0 16px 0; font-family: <?php echo Email::FONT ?>; font-size: 16px; line-height: 24px;"><a class="idno-mail-link" href="<?php echo htmlspecialchars($url, ENT_QUOTES) ?>" style="color: #2563eb; font-weight: 600; text-decoration: underline;"><?php echo htmlspecialchars(Email::oneLine($vars['label'])) ?></a></p>
<?php }
