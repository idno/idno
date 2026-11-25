<?php echo $this->draw('entity/edit/header'); ?>

<form action="<?= $vars['object']->getURL() ?>" method="post">
    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?php
            if (empty($vars['object']->_id)) {
                echo \Idno\Core\Idno::site()->language()->_('New location');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('Edit location');
                if ($vars['object']->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div id="geoplaceholder">
            <p style="text-align: center; color: var(--color-text-muted);">
                <?= \Idno\Core\Idno::site()->language()->_('Hang tight… searching for your location.') ?>
            </p>
            <div class="geospinner">
                <div class="rect1"></div>
                <div class="rect2"></div>
                <div class="rect3"></div>
                <div class="rect4"></div>
                <div class="rect5"></div>
            </div>
        </div>

        <div id="geofields" class="map" style="display:none">
            <div class="geolocation idno-form-field">

                <div class="idno-form-field">
                    <label class="idno-label" for="placename">
                        <?= \Idno\Core\Idno::site()->language()->_('Location') ?>
                    </label>
                    <?= $this->__([
                        'name' => 'placename',
                        'id' => 'placename',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_("Where are you?"),
                        'value' => $vars['object']->placename,
                        'class' => 'idno-input'
                    ])->draw('forms/input/input') ?>
                    <?= $this->__([
                        'name' => 'lat',
                        'id' => 'lat',
                        'value' => $vars['object']->lat
                    ])->draw('forms/input/hidden') ?>
                    <?= $this->__([
                        'name' => 'long',
                        'id' => 'long',
                        'value' => $vars['object']->long
                    ])->draw('forms/input/hidden') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="user_address">
                        <?= \Idno\Core\Idno::site()->language()->_('Address') ?>
                        <small style="display:block;color:var(--color-text-muted)"><?= \Idno\Core\Idno::site()->language()->_("You can edit the address if it's wrong.") ?></small>
                    </label>
                    <?= $this->__([
                        'name' => 'user_address',
                        'id' => 'user_address',
                        'value' => $vars['object']->address,
                        'class' => 'idno-input'
                    ])->draw('forms/input/input') ?>
                    <?= $this->__([
                        'name' => 'address',
                        'id' => 'address'
                    ])->draw('forms/input/hidden') ?>
                </div>

                <div id="checkinMap" class="idno-map"></div>
            </div>
        </div>

        <?= $this->__([
            'name' => 'body',
            'value' => $vars['object']->body,
            'wordcount' => false,
            'class' => 'wysiwyg-short',
            'height' => 100,
            'placeholder' => '',
            'label' => \Idno\Core\Idno::site()->language()->_('Description')
        ])->draw('forms/input/richtext') ?>

        <div class="idno-form-field">
            <label class="idno-checkbox-label" for="anonymity">
                <input name="anonymity" id="anonymity" type="checkbox"
                       value="Yes" <?php if (!empty($vars['object']->anonymity) && $vars['object']->anonymity == 'Yes') echo 'checked'; ?>
                       title="<?= \Idno\Core\Idno::site()->language()->_('When this is on, visitors who aren\'t signed in will see your exact location only after 24 hours.') ?>">
                <?= \Idno\Core\Idno::site()->language()->_('Protect my location for 24 hours') ?>
            </label>
        </div>

        <?php if (empty($vars['object']->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>
        <?= $this->draw('entity/tags/input') ?>
        <?= $this->drawSyndication('place', $vars['object']->getPosseLinks()) ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/checkin/edit') ?>

        <div class="idno-editor-actions">
            <button type="submit" class="idno-btn idno-btn-primary" name="publish_status" value="published">
                <?= \Idno\Core\Idno::site()->language()->_('Publish') ?>
            </button>
            <button type="submit" class="idno-btn idno-btn-ghost" name="publish_status" value="draft">
                <?= \Idno\Core\Idno::site()->language()->_('Save as draft') ?>
            </button>
            <a href="<?= !empty($vars['object']->_id) ? $vars['object']->getDisplayURL() : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                <?= \Idno\Core\Idno::site()->language()->_('Cancel') ?>
            </a>
        </div>

    </div>
</form>

<?php echo $this->draw('entity/edit/footer'); ?>

<script>
    /*
     * Finds the place and draws the map. The default look does this with checkin.min.js,
     * which needs jQuery; Twenty26 does not load jQuery, so this is the same in plain
     * JavaScript.
     */
    // Leaflet, as it is now: a script loaded later in the page may reuse the global name L
    var CheckinLeaflet = window.L;
    document.addEventListener('DOMContentLoaded', function () {
        var L = CheckinLeaflet;
        var byId = function (id) { return document.getElementById(id); };
        var geocoder = <?= json_encode(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'service/geo/geocoder/') ?>;
        var map = null;

        function showFields() {
            byId('geoplaceholder').style.display = 'none';
            byId('geofields').style.display = '';
        }

        function fill(data, latitude, longitude) {
            if (!data) {
                return;
            }
            byId('lat').value = latitude !== undefined ? latitude : (data.latitude || '');
            byId('long').value = longitude !== undefined ? longitude : (data.longitude || '');
            if (data.name) {
                byId('placename').value = data.name;
            }
            if (data.display_name) {
                byId('address').value = data.display_name;
                byId('user_address').value = data.display_name;
            }
        }

        function ask(params) {
            return fetch(geocoder + '?' + new URLSearchParams(params).toString(), {credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .catch(function () { return null; });
        }

        function initMap(latitude, longitude) {
            showFields();
            byId('lat').value = String(latitude);
            byId('long').value = String(longitude);
            if (!L || typeof L.map !== 'function') {
                return;
            }
            if (map) {
                map.remove();
            }
            map = L.map('checkinMap').setView([latitude, longitude], 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            var marker = L.marker([latitude, longitude], {draggable: true}).addTo(map);
            marker.on('dragend', function (e) {
                var coords = e.target.getLatLng();
                ask({lat: String(coords.lat), long: String(coords.lng)}).then(function (data) {
                    fill(data, coords.lat, coords.lng);
                    byId('lat').value = String(coords.lat);
                    byId('long').value = String(coords.lng);
                });
            });
        }

        byId('user_address').addEventListener('change', function () {
            ask({address: byId('user_address').value}).then(function (data) {
                if (data && data.latitude && data.longitude) {
                    fill(data);
                    initMap(data.latitude, data.longitude);
                }
            });
        });

        var latitude = byId('lat').value;
        var longitude = byId('long').value;

        if (latitude && longitude) {
            initMap(latitude, longitude);
        } else if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (position) {
                var lat = position.coords.latitude, lng = position.coords.longitude;
                ask({lat: String(lat), long: String(lng)}).then(function (data) {
                    fill(data, lat, lng);
                    initMap(lat, lng);
                });
            }, function () {
                // No position: let the member type the place. Greenwich, as the default look does.
                initMap('51.478791', '-0.01068');
            }, {enableHighAccuracy: true, timeout: 10000});
        } else {
            initMap('51.478791', '-0.01068');
        }
    });
</script>
