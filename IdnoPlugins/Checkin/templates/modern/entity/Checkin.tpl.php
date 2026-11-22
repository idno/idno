<?php

    $object = $vars['object'];
    /*
     * @var \Idno\Common\Entity $object
     */

?>

<div>

    <h2 class="idno-entry-title p-name">
        <?php if (empty($vars['feed_view'])) { ?>
            <a href="<?= $object->getURL() ?>"><?= htmlspecialchars($object->getTitle()) ?></a>
        <?php } ?>
    </h2>

    <span class="p-location u-checkin h-card">
        <data class="p-name" value="<?= htmlspecialchars((string) $object->placename) ?>"></data>
        <data class="p-latitude" value="<?= $object->lat() ?>"></data>
        <data class="p-longitude" value="<?= $object->long() ?>"></data>
    </span>

    <?php if (empty($vars['feed_view'])) { ?>
        <div id="map_<?= $object->_id ?>" style="height: 250px; border-radius: var(--radius-sm); overflow: hidden; margin: var(--spacing-gap) 0;"></div>
    <?php } ?>

    <div class="idno-entry-body e-content entry-content">
        <?php
        if (!empty($object->body)) {
            echo $this->autop($this->parseURLs($this->parseHashtags($object->body)));
        }

        if (!empty($object->tags)) {
            echo $this->__(['tags' => $object->tags])->draw('forms/output/tags');
        } ?>
    </div>

</div>
<?php if (empty($vars['feed_view'])) { ?>
        <script>
            (function () {
                var map = L.map('map_<?= $object->_id ?>', {
                    touchZoom: false,
                    scrollWheelZoom: false
                }).setView([<?= $object->lat() ?>, <?= $object->long() ?>], 16);
                var layer = new L.StamenTileLayer("toner-lite");
                map.addLayer(layer);
                var marker = L.marker([<?= $object->lat() ?>, <?= $object->long() ?>]);
                marker.addTo(map);
                map.scrollWheelZoom.disable();
                map.touchZoom.disable();
                map.doubleClickZoom.disable();
            })();
        </script>
<?php }
