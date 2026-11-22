<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= $vars['object']->getURL() ?>" method="post">

    <div class="idno-editor">

        <h4 class="idno-editor-heading">
            <?php
            if (empty($vars['object']->_id)) {
                echo \Idno\Core\Idno::site()->language()->_('New Event');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('Edit Event');
                if ($vars['object']->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" for="title">
                <?= \Idno\Core\Idno::site()->language()->_('Event name') ?></label>
            <?= $this->__([
                'name' => 'title',
                'id' => 'title',
                'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a name'),
                'value' => $vars['object']->title,
                'class' => 'idno-input'
            ])->draw('forms/input/input') ?>
        </div>

        <div style="display:flex;gap:var(--spacing-gap);flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <div class="idno-form-field">
                    <label class="idno-label" for="location">
                        <?= \Idno\Core\Idno::site()->language()->_('Location') ?></label>
                    <?= $this->__([
                        'name' => 'location',
                        'id' => 'location',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Where will it take place?'),
                        'value' => $vars['object']->location,
                        'class' => 'idno-input'
                    ])->draw('forms/input/input') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="starttime">
                        <?= \Idno\Core\Idno::site()->language()->_('Start day and time') ?></label>
                    <?= $this->__([
                        'name' => 'starttime',
                        'id' => 'starttime',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Type in the start day and time?'),
                        'value' => $vars['object']->starttime,
                        'class' => 'idno-input'
                    ])->draw('forms/input/datetime-local') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="endtime">
                        <?= \Idno\Core\Idno::site()->language()->_('End day and time') ?></label>
                    <?= $this->__([
                        'name' => 'endtime',
                        'id' => 'endtime',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Type in the end day and time'),
                        'value' => $vars['object']->endtime,
                        'class' => 'idno-input'
                    ])->draw('forms/input/datetime-local') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="timezone">
                        <?= \Idno\Core\Idno::site()->language()->_('Time zone') ?></label>
                    <?= $this->__([
                        'name' => 'timezone',
                        'id' => 'timezone',
                        'required' => true,
                        'value' => empty($vars['object']->timezone) ? \Idno\Core\Idno::site()->session()->currentUser()->getTimezone() : $vars['object']->timezone,
                        'class' => 'idno-input'
                    ])->draw('forms/input/timezones') ?>
                </div>

                <?= $this->drawSyndication('event', $vars['object']->getPosseLinks()) ?>
            </div>

            <div style="flex:1;min-width:200px">
                <div class="idno-form-field">
                    <label class="idno-label" for="summary">
                        <?= \Idno\Core\Idno::site()->language()->_('Brief summary') ?></label>
                    <?= $this->__([
                        'name' => 'summary',
                        'id' => 'summary',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_("What's this about?"),
                        'value' => $vars['object']->summary,
                        'class' => 'idno-input'
                    ])->draw('forms/input/input') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="body">
                        <?= \Idno\Core\Idno::site()->language()->_('Description') ?></label>
                    <?= $this->__([
                        'height' => '100', 'name' => 'body', 'value' => $vars['object']->body, 'required' => true
                    ])->draw('forms/input/richtext') ?>
                </div>

                <?= $this->draw('entity/tags/input') ?>
            </div>
        </div>

        <?php if (empty($vars['object']->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>

        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/event/edit') ?>

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
<script>
    autoSave('event', ['title','summary','location','starttime','endtime','body']);
</script>
<?php echo $this->draw('entity/edit/footer');
