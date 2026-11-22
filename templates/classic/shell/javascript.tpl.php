<?php echo $this->draw('js/idno'); ?>

<script
    src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>js/modernizr/modernizr-custom.js"></script>

<!-- Default Idno JavaScript -->
<script src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>js/<?php echo $this->getModifiedTS('js/idno.min.js'); ?>/idno.min.js"></script>

<script
    src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>vendor/npm-asset/bootstrap-toggle/js/bootstrap-toggle.js"></script>
