<?php

    $object = $vars['object'];
    /*
     * @var \Idno\Common\Entity $object
     */

?>

<div>

    <h2 class="p-name">
        <?php if (empty($vars['feed_view'])) { ?>
            <a href="<?php echo $object->getURL() ?>"><?php echo htmlspecialchars($object->getTitle()) ?></a>
        <?php } ?>
    </h2>

    <span class="p-location u-checkin h-card">
        <data class="p-name" value="<?php echo htmlspecialchars((string) $object->placename) ?>"></data>
        <data class="p-latitude" value="<?php echo $object->lat() ?>"></data>
        <data class="p-longitude" value="<?php echo $object->long() ?>"></data>
    </span>

    <?php if (empty($vars['feed_view'])) { ?>
        <div id="map_<?php echo $object->_id ?>" style="height: 250px"></div>
    <?php } ?>

    <div class="e-content entry-content">
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
                var map = L.map('map_<?php echo $object->_id?>', {
                    touchZoom: false,
                    scrollWheelZoom: false
                }).setView([<?php echo $object->lat() ?>, <?php echo $object->long() ?>], 16);
                var layer = new L.StamenTileLayer("toner-lite");
                map.addLayer(layer);
                var marker = L.marker([<?php echo $object->lat()?>, <?php echo $object->long()?>]);
                marker.addTo(map);
                map.scrollWheelZoom.disable();
                map.touchZoom.disable();
                map.doubleClickZoom.disable();
            })();
        </script>
<?php }
