<?php echo $this->draw('entity/edit/header'); ?>

<form action="<?= $vars['object']->getURL() ?>" method="post">
    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?php
            if (empty($vars['object']->_id)) {
                echo \Idno\Core\Idno::site()->language()->_('New Location');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('Edit Location');
                if ($vars['object']->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div id="geoplaceholder">
            <p style="text-align: center; color: var(--color-text-muted);">
                <?= \Idno\Core\Idno::site()->language()->_('Hang tight ... searching for your location.') ?>
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

                <div id="checkinMap" style="height: 250px; border-radius: var(--radius-sm); overflow: hidden;"></div>
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
            <label class="idno-label" for="anonymity" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer">
                <input name="anonymity" type="checkbox"
                       value="Yes" <?php if (!empty($vars['object']->anonymity) && $vars['object']->anonymity == 'Yes') echo 'checked'; ?>
                       title="<?= \Idno\Core\Idno::site()->language()->_('When selected, your precise location will only be shown to logged out users after 24 hours have passed') ?>">
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

        <div style="display:flex;gap:0.5rem;margin-top:var(--spacing-section)">
            <button type="submit" class="idno-btn idno-btn-primary" name="publish_status" value="published">
                <?= \Idno\Core\Idno::site()->language()->_('Publish') ?>
            </button>
            <button type="submit" class="idno-btn idno-btn-ghost" name="publish_status" value="draft">
                <?= \Idno\Core\Idno::site()->language()->_('Save as Draft') ?>
            </button>
            <a href="<?= !empty($vars['object']->_id) ? $vars['object']->getDisplayURL() : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                <?= \Idno\Core\Idno::site()->language()->_('Cancel') ?>
            </a>
        </div>

    </div>
</form>

<?php echo $this->draw('entity/edit/footer'); ?>

<script src="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>IdnoPlugins/Checkin/checkin.min.js"></script>
