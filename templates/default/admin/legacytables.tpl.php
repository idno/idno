<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Legacy tables'); ?></h1>
        <?php echo $this->draw('admin/legacytables/body') ?>
    </div>
</div>
