<?php echo $this->draw('entity/edit/header');?>
<form action="<?php echo $vars['object']->getURL()?>" method="post">

    <div class="row">
        <div class="col-md-8 col-md-offset-2 edit-pane">
                            <h4>
                                <?php

                                if (empty($vars['object']->_id)) {
                                    ?><?php echo \Idno\Core\Idno::site()->language()->_('New Event'); ?><?php
                                } else {
                                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit Event'); ?><?php
                                }
                                ?>
            </h4></div>

        <div class="col-md-8 col-md-offset-2">
        
            <div class="content-form">
                <label for="title">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Event name'); ?></label>
                    <?php echo $this->__([
                            'name' => 'title',
                            'id' => 'title',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a name'),
                            'value' => $vars['object']->title,
                    'class' => 'form-control'])->draw('forms/input/input'); ?>

            </div>
        </div>
            
        <div class="col-md-4 col-md-offset-2">
            <div class="content-form">
                <label for="location">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Location'); ?>                                            <?php echo $this->__([
                            'name' => 'location',
                            'id' => 'location',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Where will it take place?'),
                            'value' => $vars['object']->location,
                    'class' => 'form-control'])->draw('forms/input/input'); ?>

            </div>
            <div class="content-form">
                <label for="starttime">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Start day and time'); ?></label>
                <?php echo $this->__([
                            'name' => 'starttime',
                            'id' => 'starttime',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Type in the start day and time?'),
                            'value' => $vars['object']->starttime,
                'class' => 'form-control'])->draw('forms/input/datetime-local'); ?>
            </div>
            <div class="content-form">
                <label for="endtime">
                    <?php echo \Idno\Core\Idno::site()->language()->_('End day and time'); ?></label>
                <?php echo $this->__([
                            'name' => 'endtime',
                            'id' => 'endtime',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Type in the end day and time'),
                            'value' => $vars['object']->endtime,
                'class' => 'form-control'])->draw('forms/input/datetime-local'); ?>
                    
            </div>
            <div class="content-form">
                <label for="timezone">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Time zone'); ?></label>
                <?php echo $this->__([
                            'name' => 'timezone',
                            'id' => 'timezone',
                            'required' => true,
                            'value' => empty($vars['object']->timezone) ? \Idno\Core\Idno::site()->session()->currentUser()->getTimezone() : $vars['object']->timezone,
                'class' => 'form-control'])->draw('forms/input/timezones'); ?>
                    
            </div>
            <?php echo $this->drawSyndication('event', $vars['object']->getPosseLinks()); ?>

        </div>
        <div class="col-md-4 ">
            
            <div class="content-form">
                <label for="summary">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Brief summary'); ?></label>
                    <?php echo $this->__([
                            'name' => 'summary',
                            'id' => 'summary',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_("What's this about?"),
                            'value' => $vars['object']->summary,
                    'class' => 'form-control'])->draw('forms/input/input'); ?>

            </div>

            <div class="content-form">
                <label for="body">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Description'); ?></label>
                    <?php echo $this->__([
                        'height' => '100', 'name' => 'body', 'value' => $vars['object']->body, 'required' => true
                    ])->draw('forms/input/richtext');?>
            </div>
            <?php echo $this->draw('entity/tags/input');?>
            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
        </div>

        <div class="col-md-8 col-md-offset-2">
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>
            <p class="button-bar">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/event/edit') ?>
                <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();" />
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?>" />

            </p>
        </div>
    </div>
</form>
<script>
    autoSave('event', ['title','summary','location','starttime','endtime','body']);
</script>
<?php echo $this->draw('entity/edit/footer');