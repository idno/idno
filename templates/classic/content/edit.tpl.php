<?php /* @var \IdnoPlugins\Text\Entry $vars['object'] */ ?>
<?php

if ($vars['object']->canEdit()) {

    ?>

        <a href="<?php echo $vars['object']->getEditURL()?>" class="edit"><?php echo \Idno\Core\Idno::site()->language()->_('Edit'); ?></a>
    <?php echo  \Idno\Core\Idno::site()->actions()->createLink($vars['object']->getDeleteURL(), \Idno\Core\Idno::site()->language()->_('Delete'), array(), array('method' => 'POST', 'class' => 'edit edit-delete', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this entry?')));?>

    <?php echo $this->draw('content/entity/' .  $vars['object']->getEntityTypeName() . '/edit'); ?>
    <?php

}

