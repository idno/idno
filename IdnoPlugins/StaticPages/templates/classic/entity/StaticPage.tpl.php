<div>
    <?php

    if ($vars['object']->canEdit()) {

        ?>
            <div class="row edit-page-actions">
                <div class="col-md-12">
        <span class="page-action">
            <a href="<?php echo \Idno\Core\Idno::site()->config()->getURL() ?>admin/staticpages/"><i class="fa fa-cog"></i><?php echo \Idno\Core\Idno::site()->language()->_('Manage pages'); ?></a>
        </span>
                <?php

                if ($vars['object']->canEdit()) {

                    ?>

                            <span class="page-action">
                                <a href="<?php echo $vars['object']->getEditURL() ?>" class="edit"><i class="fa fa-pencil"></i><?php echo \Idno\Core\Idno::site()->language()->_('Edit'); ?></a>
                            </span>
                            <span class="page-action">
                        <?php echo \Idno\Core\Idno::site()->actions()->createLink($vars['object']->getDeleteURL(), '<i class="fa fa-trash-o"></i>'. \Idno\Core\Idno::site()->language()->_('Delete'), array(), array('method' => 'POST', 'class' => 'edit', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_("Are you sure you want to permanently delete this entry?"))); ?>
                            </span>
                        <?php

                }

                ?>
                </div>
            </div>


            <?php

    }

    if (empty($vars['object']->hide_title)) {
        ?>
            <h1 class="p-name"><?php echo $vars['object']->getTitle() ?></h1>
            <?php
    }

    if (!empty($vars['object']->forward_url)) {

        ?>
            <h2>
            <?php echo \Idno\Core\Idno::site()->language()->_('You are seeing this page because you are a site administrator. Other users will be forwarded to'); ?> <a href="<?php echo $vars['object']->forward_url ?>"><?php echo $vars['object']->forward_url ?></a>.
            </h2>
            <?php

    }

    ?>
    <?php echo $this->autop($this->parseURLs($this->parseHashtags($vars['object']->body))); ?>

</div>
