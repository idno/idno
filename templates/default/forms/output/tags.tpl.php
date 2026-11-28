<?php

    /*
     * The tags of a post, as links. Each link is a p-category.
     *
     * The tags are what whoever made the post sent, and a post stored by an earlier version
     * holds them as they were sent, so every one is printed as text.
     */

    $tags = \Idno\Core\Tags::texts($vars['tags'] ?? null);

if (!empty($tags)) {
    // A list is a row of its own; one text of tags follows the text it is drawn after
    $row = is_array($vars['tags']);
    if ($row) {
        ?>
        <p class="tag-row">
        <?php
    }
    ?>
            <i class="fa fa-tag"></i>
            <?php foreach ($tags as $tag) { ?>
            <a href="<?php echo htmlspecialchars(\Idno\Core\Tags::url($tag)); ?>" class="p-category" rel="tag"><?php echo htmlspecialchars($tag); ?></a>
            <?php } ?>
    <?php
    if ($row) {
        ?>
        </p>
        <?php
    }
}

unset($this->vars['tags']);
