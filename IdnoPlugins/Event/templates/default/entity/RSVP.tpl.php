<?php
$currentPage = \Idno\Core\Idno::site()->currentPage();
if (!empty($currentPage) && \Idno\Core\Idno::site()->currentPage()->isPermalinkFor($vars['object'])) {
    $rel = 'rel="in-reply-to" class="u-in-reply-to"';
} else {
    $rel = '';
}
?>
<p class="p-name e-content entry-content"><data class="p-rsvp" value="<?php echo htmlspecialchars((string) $vars['object']->rsvp) ?>"><strong><?php echo htmlspecialchars(ucfirst((string) $vars['object']->rsvp)) ?>:</strong> <?php echo $this->parseURLs($this->parseHashtags($this->sanitize_html((string) $vars['object']->body)), $rel) ?></data></p>
<?php echo $this->draw('entity/content/embed');
