<?php

    /*
     * Links from a member's profile to their posts of one kind: /profile/<handle>/<kind>,
     * where <kind> is the content type's category ("videos", "photos"...). The profile
     * itself lists everything.
     *
     * Twenty26 draws the same links its own way (templates/modern, same name); both use
     * \Idno\Pages\User\View::contentTypeLinks().
     */

    $links = \Idno\Pages\User\View::contentTypeLinks($vars['user'] ?? null, $vars['content'] ?? []);

if (!empty($links)) {
    ?>
    <ul class="nav nav-pills profile-content-types">
        <?php foreach ($links as $link) { ?>
            <li<?php echo $link['active'] ? ' class="active"' : '' ?>><a href="<?php echo htmlspecialchars($link['url']) ?>"<?php echo $link['active'] ? ' aria-current="page"' : '' ?>><?php echo htmlspecialchars($link['label']) ?></a></li>
        <?php } ?>
    </ul>
    <?php
}

    unset($this->vars['content']);
