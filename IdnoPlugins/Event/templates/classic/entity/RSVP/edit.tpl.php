<?php echo $this->draw('entity/edit/header');?>
<form action="<?php echo $vars['object']->getURL()?>" method="post">

    <div class="row">

        <div class="col-md-8 col-md-offset-2 edit-pane">
        
            <h4>
                                <?php

                                if (empty($vars['object']->_id)) {
                                    ?><?php echo \Idno\Core\Idno::site()->language()->_('New RSVP'); ?><?php
                                } else {
                                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit RSVP'); ?><?php
                                }
                                ?>
            </h4>


            <div class="content-form">
                <label id="in-reply-to" for="reply">
                    <?php echo \Idno\Core\Idno::site()->language()->_("What's the URL of the event you're responding to?"); ?></label>
                    <?php
                        $value = "";
                    if (empty($vars['url'])) {
                        // One address; an RSVP saved by an earlier version can hold a list
                        $value = (string) current($vars['object']->getEventURLs());
                    } else {
                        $value = $vars['url'];
                    } ?>
                    <?php echo $this->__([
                            'name' => 'inreplyto',
                            'id' => 'reply',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('The website address of the event'),
                            'value' => $value,
                    'class' => 'form-control'])->draw('forms/input/url'); ?>
            </div>
            <div class="content-form">
                <label for="rsvp">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Are you going?'); ?></label>
                    
                    <?php echo $this->__([
                            'name' => 'rsvp',
                            'id' => 'rsvp',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Are you going?'),
                            'value' => $vars['object']->rsvp ,
                            'class' => 'form-control',
                            'options' => [
                                'yes' => \Idno\Core\Idno::site()->language()->_('Yes: I am attending this event'),
                                'no' => \Idno\Core\Idno::site()->language()->_('No: I am not attending this event'),
                                'maybe' => \Idno\Core\Idno::site()->language()->_('Maybe: I might attend this event')
                            ],
                            'required' => true
                    ])->draw('forms/input/select'); ?>
            </div>

            <div class="content-form">
                <label for="body">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Any comments?'); ?></label>
                    <?php echo $this->__([
                        'name' => 'body',
                        'id' => 'body',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Say something here...'),
                        'height' => 126,
                        'value' => $vars['object']->body,
                    'class' => 'form-control event'])->draw('forms/input/longtext'); ?>
            </div>
            <?php echo $this->draw('entity/tags/input');?>
            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->drawSyndication('note', $vars['object']->getPosseLinks()); ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>
            <p class="button-bar">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/status/edit') ?>
                <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();" />
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?>" />

            </p>

        </div>

    </div>
</form>
<?php echo $this->draw('entity/edit/footer');