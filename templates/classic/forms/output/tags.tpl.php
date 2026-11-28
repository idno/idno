<?php
// The tags of a post, as links: what feeds, JSON and ActivityPub draw. A tag is what whoever made the post sent, and a post stored
// earlier holds it as sent, so every tag is printed as text.
$tags = \Idno\Core\Tags::texts($vars['tags'] ?? null);
if (!empty($tags)) {
    if (is_array($vars['tags'])) {
        ?>
        <p class="tag-row">
            <i class="fa fa-tag"></i>
            <?php foreach ($tags as $tag) { ?>
            <a href="<?php echo htmlspecialchars(\Idno\Core\Tags::url($tag, \Idno\Core\Idno::site()->config()->getDisplayURL())); ?>" class="p-category" rel="tag"><?php echo htmlspecialchars($tag) ?></a>
            <?php } ?>
        </p> 
        <?php
    } else {
        ?>
        <i class="fa fa-tag"></i><a href="<?php echo htmlspecialchars(\Idno\Core\Tags::url($tags[0], \Idno\Core\Idno::site()->config()->getDisplayURL())); ?>" class="p-category" rel="tag"><?php echo htmlspecialchars($tags[0]) ?></a>
        <?php
    }
}

unset($this->vars['tags']);