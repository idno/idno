<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= $vars['object']->getURL() ?>" method="post">

    <div class="idno-editor">

        <h4 class="idno-editor-heading">
            <?php
            if (empty($vars['object']->_id)) {
                echo \Idno\Core\Idno::site()->language()->_('New event');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('Edit event');
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

        <div class="idno-editor-row">
            <div class="idno-form-field">
                <label class="idno-label" for="starttime">
                    <?= \Idno\Core\Idno::site()->language()->_('Start day and time') ?></label>
                <?= $this->__([
                    'name' => 'starttime',
                    'id' => 'starttime',
                    'placeholder' => \Idno\Core\Idno::site()->language()->_('Enter the start day and time'),
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
                    'placeholder' => \Idno\Core\Idno::site()->language()->_('Enter the end day and time'),
                    'value' => $vars['object']->endtime,
                    'class' => 'idno-input'
                ])->draw('forms/input/datetime-local') ?>
            </div>
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

        <?= $this->__([
            'height' => '100', 'name' => 'body', 'value' => $vars['object']->body, 'required' => true,
            'label' => \Idno\Core\Idno::site()->language()->_('Description')
        ])->draw('forms/input/richtext') ?>

        <?= $this->draw('entity/tags/input') ?>
        <?= $this->drawSyndication('event', $vars['object']->getPosseLinks()) ?>

        <?php if (empty($vars['object']->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>

        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/event/edit') ?>

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
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof autoSave === 'function') {
            autoSave('event', ['title','summary','location','starttime','endtime','body']);
        }
    });
</script>
<?php echo $this->draw('entity/edit/footer');
