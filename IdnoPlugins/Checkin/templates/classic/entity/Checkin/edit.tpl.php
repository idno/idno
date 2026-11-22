<?php echo $this->draw('entity/edit/header');?>

<form action="<?php echo $vars['object']->getURL() ?>" method="post">
    <div class="row">
        <div class="col-md-8 col-md-offset-2 edit-pane">
            <h4>
                <?php

                if (empty($vars['object']->_id)) {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('New Location'); ?><?php
                } else {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit Location'); ?><?php
                }
                ?>
            </h4>
            <div id="geoplaceholder">
                <p style="text-align: center; color: #4c93cb;">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Hang tight ... searching for your location.'); ?>
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
                <div class="geolocation content-form">

                    <p>
                        <label for="placename">
                            <?php echo \Idno\Core\Idno::site()->language()->_('Location'); ?><br>
                        </label>
                        <?php echo $this->__([
                            'name' => 'placename',
                            'id' => 'placename',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_("Where are you?"),
                            'value' => $vars['object']->placename,
                        'class' => 'form-control'])->draw('forms/input/input'); ?>
                        <?php echo $this->__([
                            'name' => 'lat',
                            'id' => 'lat',
                        'value' => $vars['object']->lat])->draw('forms/input/hidden'); ?>
                        <?php echo $this->__([
                            'name' => 'long',
                            'id' => 'long',
                        'value' => $vars['object']->long])->draw('forms/input/hidden'); ?>
                    </p>

                    <p>
                        <label for="user_address"><?php echo \Idno\Core\Idno::site()->language()->_('Address'); ?><br>
                            <small><?php echo \Idno\Core\Idno::site()->language()->_("You can edit the address if it's wrong."); ?></small>
                        </label>
                        <?php echo $this->__([
                            'name' => 'user_address',
                            'id' => 'user_address',
                            'value' => $vars['object']->address,
                        'class' => 'form-control'])->draw('forms/input/input'); ?>
                        <?php echo $this->__([
                            'name' => 'address',
                        'id' => 'address'])->draw('forms/input/hidden'); ?>
                    </p>

                    <div id="checkinMap" style="height: 250px" ></div>
                </div>
            </div>

            <?php echo $this->__([
                'name' => 'body',
                'value' => $vars['object']->body,
                'wordcount' => false,
                'class' => 'wysiwyg-short',
                'height' => 100,
                'placeholder' => '',
                'label' => \Idno\Core\Idno::site()->language()->_('Description')
            ])->draw('forms/input/richtext')?>

            <div class="anonymity">
                <p>
                    <label for="anonymity">
                        <?php echo \Idno\Core\Idno::site()->language()->_('Protect my location for 24 hours'); ?> <br>
                    </label>
                    <input name="anonymity" type="checkbox" data-toggle="toggle" data-onstyle="info" data-on="Yes" data-off="No"  data-toggle="tooltip" data-placement="top" title="<?= \Idno\Core\Idno::site()->language()->_('When selected, your precise location will only be shown to logged out users after 24 hours have passed'); ?>"
                       value="Yes" name="single_user" <?php if ($vars['object']->anonymity == 'Yes') echo 'checked'; ?>>

            </div>

            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->draw('entity/tags/input');?>
            <?php echo $this->drawSyndication('place', $vars['object']->getPosseLinks()); ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>
            <p class="button-bar ">
               <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();"/>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/checkin/edit') ?>
                <input type="submit" class="btn btn-primary" value="<?php
                if (empty($vars['object']->_id)) {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?><?php
                } else {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?><?php
                } ?>"/>

            </p>
        </div>

    </div>
</form>

<?php echo $this->draw('entity/edit/footer');?>

<script src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>IdnoPlugins/Checkin/checkin.min.js"></script>
