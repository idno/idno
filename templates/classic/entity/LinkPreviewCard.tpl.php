<?php
/**
 * Medium-style link preview card, rendered server-side from cached metadata.
 *
 * Expects $vars['preview'] with keys: url, title, description, image, domain, site_name
 */

if (empty($vars['preview']) || empty($vars['preview']['url'])) {
    return;
}

$preview = $vars['preview'];

// The preview is made from a page on another site: the link and the image are printed
// only if they are http or https addresses, and everything is escaped.
$link = \Idno\Core\UrlValidator::httpUrl($preview['url']);
if ($link === '') {
    return;
}
$url         = htmlentities($link, ENT_QUOTES, 'UTF-8');
$title       = htmlentities($preview['title'] ?? '', ENT_QUOTES, 'UTF-8');
$description = htmlentities($preview['description'] ?? '', ENT_QUOTES, 'UTF-8');
$image       = \Idno\Core\UrlValidator::httpUrl($preview['image'] ?? '');
$domain      = htmlentities($preview['domain'] ?? parse_url($link, PHP_URL_HOST) ?? '', ENT_QUOTES, 'UTF-8');
$siteName    = htmlentities($preview['site_name'] ?? '', ENT_QUOTES, 'UTF-8');

if (empty($title) && empty($description) && empty($image)) {
    return;
}
?>
<a href="<?= $url ?>" target="_blank" rel="noopener noreferrer" class="link-preview-card">
    <?php if (!empty($image)) { ?>
        <div class="link-preview-image">
            <img src="<?= htmlspecialchars($this->getProxiedImageUrl($image)) ?>" alt="" loading="lazy" />
        </div>
    <?php } ?>
    <div class="link-preview-content">
        <?php if (!empty($title)) { ?>
            <div class="link-preview-title"><?= $title ?></div>
        <?php } ?>
        <?php if (!empty($description)) { ?>
            <div class="link-preview-description"><?= $description ?></div>
        <?php } ?>
        <div class="link-preview-domain">
            <?= $domain ?>
        </div>
    </div>
</a>
