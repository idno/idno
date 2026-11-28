<?php

    /*
     * The tags of a post, as a row of links. Each link is a p-category.
     *
     * The tags are what whoever made the post sent, so every one is printed as text.
     */

    $tags = [];
foreach (\Idno\Core\Tags::texts($vars['tags'] ?? null) as $tag) {
    if (($tag = ltrim($tag, '#')) !== '') {
        $tags[] = $tag;
    }
}

if (!empty($tags)) {
    ?>
    <p class="idno-tags tag-row">
        <?php foreach ($tags as $tag) { ?>
            <a href="<?= htmlspecialchars(\Idno\Core\Tags::url($tag)) ?>" class="p-category" rel="tag"><?= htmlspecialchars($tag) ?></a>
        <?php } ?>
    </p>
    <?php
}

unset($this->vars['tags']);
