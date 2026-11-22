<?php
$currentPage = \Idno\Core\Idno::site()->currentPage();
if (!empty($currentPage) && \Idno\Core\Idno::site()->currentPage()->isPermalink()) {
    $rel = 'rel="in-reply-to" class="u-in-reply-to"';
} else {
    $rel = '';
}
?>
<p class="p-name e-content entry-content"><data class="p-rsvp" value="<?= htmlspecialchars((string) $vars['object']->rsvp) ?>"><strong><?= htmlspecialchars(ucfirst((string) $vars['object']->rsvp)) ?>:</strong> <?= $this->parseURLs($this->parseHashtags($this->sanitize_html((string) $vars['object']->body)), $rel) ?></data></p>
<?php echo $this->draw('entity/content/embed');
