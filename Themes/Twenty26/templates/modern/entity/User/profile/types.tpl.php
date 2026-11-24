<?php

    /*
     * Links from a member's profile to their posts of one kind (videos, photos...).
     * See templates/default/entity/User/profile/types.tpl.php.
     */

    $links = \Idno\Pages\User\View::contentTypeLinks($vars['user'] ?? null, $vars['content'] ?? []);

if (!empty($links)) {
    ?>
    <nav class="idno-content-type-bar" style="flex-wrap:wrap" aria-label="<?= htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Kinds of post')) ?>">
        <?php foreach ($links as $link) { ?>
            <a href="<?= htmlspecialchars($link['url']) ?>" class="idno-content-type-tab<?= $link['active'] ? ' active' : '' ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?php
            if (!empty($link['icon'])) {
                echo $this->__(['icon' => $link['icon']])->draw('shell/icon');
            }
            ?><?= htmlspecialchars($link['label']) ?></a>
        <?php } ?>
    </nav>
    <?php
}

    unset($this->vars['content'], $this->vars['icon']);
