<?php

    $object = $vars['object'];
    /*
     * @var \Idno\Common\Entity $object
     */

?>

<div class="idno-checkin">

    <?php if (empty($vars['feed_view'])) { ?>
    <h2 class="idno-entry-title p-name">
            <a href="<?= $object->getURL() ?>"><?= htmlspecialchars($object->getTitle()) ?></a>
    </h2>
    <?php } ?>

    <span class="p-location u-checkin h-card">
        <data class="p-name" value="<?= htmlspecialchars((string) $object->placename) ?>"></data>
        <data class="p-latitude" value="<?= $object->lat() ?>"></data>
        <data class="p-longitude" value="<?= $object->long() ?>"></data>
    </span>

    <?php if (empty($vars['feed_view'])) { ?>
        <div id="map_<?= $object->_id ?>" class="idno-map" role="img" aria-label="<?= htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Map: %s', [(string) $object->placename])) ?>"></div>
        <?php if (!empty($object->address)) { ?>
        <p class="idno-entry-kicker idno-entry-kicker-place">
            <span><?= htmlspecialchars(strip_tags((string) $object->address)) ?></span>
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
                }).setView([<?= $object->lat() ?>, <?= $object->long() ?>], 16);
                // OpenStreetMap's own tiles: the Stamen tile servers this used are gone
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                var marker = L.marker([<?= $object->lat() ?>, <?= $object->long() ?>]);
                marker.addTo(map);
                map.scrollWheelZoom.disable();
                map.touchZoom.disable();
                map.doubleClickZoom.disable();
            })();
        </script>
<?php }
