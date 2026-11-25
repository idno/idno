<?php

    $object = $vars['object'];
    /* @var \IdnoPlugins\Event\RSVP $object */
    $lang = \Idno\Core\Idno::site()->language();

if (empty($vars['url'])) {
    // One address; an RSVP saved by an earlier version can hold a list
    $value = (string) current($object->getEventURLs());
} else {
    $value = $vars['url'];
}

?>
<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= htmlspecialchars($object->getURL()) ?>" method="post">

    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?= empty($object->_id) ? $lang->_('New RSVP') : $lang->_('Edit RSVP') ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" id="in-reply-to" for="reply"><?= $lang->_("What's the URL of the event you're responding to?") ?></label>
            <?= $this->__([
                'name' => 'inreplyto',
                'id' => 'reply',
                'placeholder' => $lang->_('The website address of the event'),
                'value' => $value,
                'class' => 'idno-input'
            ])->draw('forms/input/url') ?>
        </div>

        <div class="idno-form-field">
            <label class="idno-label" for="rsvp"><?= $lang->_('Are you going?') ?></label>
            <?= $this->__([
                'name' => 'rsvp',
                'id' => 'rsvp',
                'placeholder' => $lang->_('Are you going?'),
                'value' => $object->rsvp,
                'class' => 'idno-select',
                'options' => [
                    'yes' => $lang->_('Yes: I am attending this event'),
                    'no' => $lang->_('No: I am not attending this event'),
                    'maybe' => $lang->_('Maybe: I might attend this event')
                ],
                'required' => true
            ])->draw('forms/input/select') ?>
        </div>

        <div class="idno-form-field">
            <label class="idno-label" for="body"><?= $lang->_('Any comments?') ?></label>
            <?= $this->__([
                'name' => 'body',
                'id' => 'body',
                'unique_id' => 'body',
                'placeholder' => $lang->_('Add a comment (optional)'),
                'height' => 126,
                'value' => $object->body,
                'class' => 'idno-textarea event'
            ])->draw('forms/input/longtext') ?>
        </div>

        <?= $this->draw('entity/tags/input') ?>
        <?php if (empty($object->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>
        <?= $this->drawSyndication('note', $object->getPosseLinks()) ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/status/edit') ?>

        <div class="idno-editor-actions">
            <button type="submit" class="idno-btn idno-btn-primary">
                <?= $lang->_('Save') ?>
            </button>
            <a href="<?= !empty($object->_id) ? htmlspecialchars($object->getDisplayURL()) : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                <?= $lang->_('Cancel') ?>
            </a>
        </div>

    </div>

</form>
<?php echo $this->draw('entity/edit/footer');
