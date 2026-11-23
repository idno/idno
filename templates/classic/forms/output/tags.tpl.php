<?php
if (!empty($vars['tags'])) {
    if (is_array($vars['tags'])) {
        ?>
        <p class="tag-row">
            <i class="fa fa-tag"></i>
            <?php foreach ($vars['tags'] as $tag) { ?>
            <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL(); ?>tag/<?php echo urlencode($tag); ?>" class="p-category" rel="tag"><?php echo $tag ?></a>
            <?php } ?>
        </p> 
        <?php
    } else {
        ?>
        <i class="fa fa-tag"></i><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL(); ?>tag/<?php echo urlencode($vars['tags']); ?>" class="p-category" rel="tag"><?php echo $vars['tags'] ?></a>
        <?php
    }
}

unset($this->vars['tags']);