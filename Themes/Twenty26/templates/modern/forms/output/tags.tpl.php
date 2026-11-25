<?php

    /*
     * The tags of a post, as a row of links. Each link is a p-category.
     */

if (!empty($vars['tags'])) {
    $tags = is_array($vars['tags']) ? $vars['tags'] : [$vars['tags']];
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'tag/';
    ?>
    <p class="idno-tags tag-row">
        <?php foreach ($tags as $tag) {
            if (!is_scalar($tag) || trim((string) $tag) === '') {
                continue;
            }
            $tag = ltrim(trim((string) $tag), '#');
            ?>
            <a href="<?= htmlspecialchars($base . urlencode($tag)) ?>" class="p-category" rel="tag"><?= htmlspecialchars($tag) ?></a>
        <?php } ?>
    </p>
    <?php
}

unset($this->vars['tags']);
