<?php

    $object = $vars['object'];
    /*
     * @var \Idno\Common\Entity $object
     */

    // Where the check-in was made, as far as whoever is reading may be told
    $location = $object->location();

?>

<div class="idno-checkin">

    <?php if (empty($vars['feed_view'])) { ?>
    <h2 class="idno-entry-title p-name">
            <a href="<?= $object->getURL() ?>"><?= htmlspecialchars($object->getTitle()) ?></a>
    </h2>
    <?php } ?>

    <span class="p-location u-checkin h-card">
        <?php if ($location['placename'] !== '') { ?>
        <data class="p-name" value="<?= htmlspecialchars($location['placename']) ?>"></data>
        <?php } ?>
        <data class="p-latitude" value="<?= (float) $location['latitude'] ?>"></data>
        <data class="p-longitude" value="<?= (float) $location['longitude'] ?>"></data>
    </span>

    <?php if (empty($vars['feed_view'])) { ?>
        <div id="map_<?= $object->_id ?>" class="idno-map" role="img" aria-label="<?= htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Map: %s', [$location['placename'] !== '' ? $location['placename'] : \Idno\Core\Idno::site()->language()->_('somewhere nearby')])) ?>"></div>
        <?php if ($location['address'] !== '') { ?>
        <p class="idno-entry-kicker idno-entry-kicker-place">
            <span><?= htmlspecialchars(strip_tags($location['address'])) ?></span>
        </p>
        <?php } ?>
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
                }).setView([<?= (float) $location['latitude'] ?>, <?= (float) $location['longitude'] ?>], 16);
                // OpenStreetMap's own tiles: the Stamen tile servers this used are gone
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                var marker = L.marker([<?= (float) $location['latitude'] ?>, <?= (float) $location['longitude'] ?>]);
                marker.addTo(map);
                map.scrollWheelZoom.disable();
                map.touchZoom.disable();
                map.doubleClickZoom.disable();
            })();
        </script>
<?php }
