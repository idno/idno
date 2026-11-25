<?php

    $object = $vars['object'];
    /* @var \IdnoPlugins\Video\Video $object */

?>
<div class="idno-video">
    <?php if (empty($vars['feed_view'])) { ?>
        <h2 class="idno-entry-title p-name"><a href="<?= htmlspecialchars($object->getDisplayURL()) ?>"><?= htmlspecialchars(strip_tags($object->getTitle())) ?></a></h2>
    <?php } ?>

    <?= $this->__(['object' => $object, 'feed_view' => !empty($vars['feed_view'])])->draw('video/player') ?>

    <?php if ($object->getDescription() !== '') { ?>
        <div class="idno-entry-body e-content">
            <?= $this->__(['value' => $object->getDescription(), 'object' => $object])->draw('forms/output/richtext') ?>
        </div>
    <?php } ?>

    <?php
    if (!empty($object->tags)) {
        echo $this->__(['tags' => $object->tags])->draw('forms/output/tags');
    }
    ?>
</div>
<?php echo $this->draw('entity/content/embed');
