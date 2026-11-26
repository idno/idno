<?php

    $object = $vars['object'];
    /*
     * @var \Idno\Common\Entity $object
     */

    // Where the check-in was made, as far as whoever is reading may be told
    $location = $object->location();

?>

<div>

    <h2 class="p-name">
        <?php if (empty($vars['feed_view'])) { ?>
            <a href="<?php echo $object->getURL() ?>"><?php echo htmlspecialchars($object->getTitle()) ?></a>
        <?php } ?>
    </h2>

    <span class="p-location u-checkin h-card">
        <?php echo $location['placename'] !== '' ? '<data class="p-name" value="' . htmlspecialchars($location['placename']) . '"></data>' . "\n" : ''; ?>
        <data class="p-latitude" value="<?php echo $location['latitude'] ?>"></data>
        <data class="p-longitude" value="<?php echo $location['longitude'] ?>"></data>
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
                }).setView([<?php echo $location['latitude'] ?>, <?php echo $location['longitude'] ?>], 16);
                var layer = new L.StamenTileLayer("toner-lite");
                map.addLayer(layer);
                var marker = L.marker([<?php echo $location['latitude']?>, <?php echo $location['longitude']?>]);
                marker.addTo(map);
                map.scrollWheelZoom.disable();
                map.touchZoom.disable();
                map.doubleClickZoom.disable();
            })();
        </script>
<?php }
