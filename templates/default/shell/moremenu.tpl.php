<?php

    /*
     * The "More" menu of a post, a comment or a profile: a button with three dots that opens
     * a short list. "Copy link" is always in it, so the menu is drawn for everyone. Plugins
     * add items through the extension point of the template that draws this one
     * (content/menu/items, entity/annotations/menu/items, entity/User/menu/items); an item is
     * a link or a button with class "idno-more-item" and role "menuitem".
     *
     * Without JavaScript this is a <details> element and "Copy link" is a plain link.
     *
     * $vars['label']     What the menu is for, read out by screen readers ("More options for ...")
     * $vars['copy_url']  The address "Copy link" copies
     * $vars['menu_items'] Markup of the items that follow "Copy link". (Not "items": that is the
     *                    list of posts of a feed page, which the RSS and JSON shells read after
     *                    the page's body has been drawn.)
     */

    $copyUrl = \Idno\Core\UrlValidator::httpUrl((string) ($vars['copy_url'] ?? ''));
    $label = (string) ($vars['label'] ?? \Idno\Core\Idno::site()->language()->_('More options'));

?>
<details class="idno-more" data-idno-more-menu>
    <summary class="idno-more-button" aria-label="<?php echo htmlspecialchars($label, ENT_QUOTES) ?>" title="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->language()->_('More'), ENT_QUOTES) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
    </summary>
    <div class="idno-more-list" role="menu">
        <?php if ($copyUrl !== '') { ?>
        <a class="idno-more-item" role="menuitem" data-idno-copy-link href="<?php echo htmlspecialchars($copyUrl, ENT_QUOTES) ?>"
           data-copied-text="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Link copied'), ENT_QUOTES) ?>"><?php echo \Idno\Core\Idno::site()->language()->_('Copy link') ?></a>
        <?php } ?>
        <?php echo $vars['menu_items'] ?? '' ?>
    </div>
    <span class="idno-more-status" role="status" aria-live="polite"></span>
</details>
<?php

    // Prevent scope pollution
    unset($this->vars['label']);
    unset($this->vars['copy_url']);
    unset($this->vars['menu_items']);
