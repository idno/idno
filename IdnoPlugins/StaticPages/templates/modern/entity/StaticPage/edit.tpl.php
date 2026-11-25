<?php

    /* @var \Idno\Core\Template $this */

    $object = $vars['object'];
    /* @var \IdnoPlugins\StaticPages\StaticPage $object */
    $lang = \Idno\Core\Idno::site()->language();

    $title       = (string) $object->getTitle();
    $forward_url = (string) $object->forward_url;
    $hide_title  = $object->hide_title;
    $selected    = isset($vars['category']) ? $vars['category'] : 'No Category';

if ($title == 'Untitled') {
    $title = '';
}

    $advanced = !empty($hide_title) || $forward_url !== '';

?>
<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= htmlspecialchars($object->getURL()) ?>" method="post">

    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?= empty($object->_id) ? $lang->_('New page') : $lang->_('Edit page') ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" for="title"><?= $lang->_('Title') ?></label>
            <input type="text" name="title" id="title" placeholder="<?= htmlspecialchars($lang->_('Give it a title')) ?>"
                   value="<?= htmlspecialchars($title) ?>" class="idno-input"/>
        </div>

        <?= $this->__([
            'name' => 'body',
            'value' => $object->body,
            'wordcount' => false,
            'class' => 'wysiwyg',
            'height' => 500,
            'placeholder' => $lang->_('Write your page content'),
            'label' => $lang->_('Body')
        ])->draw('forms/input/richtext') ?>

        <?= $this->draw('entity/tags/input') ?>

        <div class="idno-form-field page-cat">
            <label class="idno-label" for="category"><?= $lang->_('Parent category') ?></label>
            <select name="category" id="category" class="idno-select">
                <option <?= $selected == 'No Category' ? 'selected' : '' ?>><?= $lang->_('No category') ?></option>
                <?php
                if (!empty($vars['categories'])) {
                    foreach ($vars['categories'] as $category) {
                        ?>
                        <option <?= $category == $selected ? 'selected' : '' ?>><?= htmlspecialchars($category) ?></option>
                        <?php
                    }
                }
                ?>
            </select>
        </div>

        <div x-data="{ open: <?= $advanced ? 'true' : 'false' ?> }">
            <p id="show-options" class="idno-form-field">
                <button type="button" class="idno-btn idno-btn-ghost idno-btn-sm" x-on:click="open = !open" :aria-expanded="open.toString()" aria-controls="moreoptions">
                    <span x-show="!open"><?= $lang->_('Show advanced options') ?></span>
                    <span x-show="open" x-cloak><?= $lang->_('Hide advanced options') ?></span>
                </button>
            </p>
            <div id="moreoptions" x-show="open" <?= $advanced ? '' : 'x-cloak' ?>>
                <div class="idno-form-field">
                    <label class="idno-label" for="forward_url"><?= $lang->_('Redirect address') ?></label>
                    <input type="text" name="forward_url" id="forward_url"
                           placeholder="<?= htmlspecialchars($lang->_('Address to send visitors to')) ?>"
                           value="<?= htmlspecialchars($forward_url) ?>" class="idno-input"/>
                    <p class="idno-form-help"><?= $lang->_('Usually you should leave this blank. Add an address here to send visitors to another page instead of showing this page\'s content.') ?></p>
                </div>
                <fieldset class="idno-form-field">
                    <legend class="idno-label"><?= $lang->_('Show the page title as a heading?') ?></legend>
                    <label class="idno-checkbox-label">
                        <input type="radio" name="hide_title" id="title-heading" value="0" <?= empty($hide_title) ? 'checked' : '' ?>>
                        Yes
                    </label>
                    <label class="idno-checkbox-label">
                        <input type="radio" name="hide_title" id="title-heading-no" value="1" <?= !empty($hide_title) ? 'checked' : '' ?>>
                        No
                    </label>
                </fieldset>
            </div>
        </div>

        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/staticpages/edit') ?>

        <div class="idno-editor-actions">
            <button type="submit" class="idno-btn idno-btn-primary">
                <?= $lang->_('Publish') ?>
            </button>
            <a href="<?= !empty($object->_id) ? htmlspecialchars($object->getDisplayURL()) : \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/staticpages/' ?>" class="idno-btn idno-btn-ghost">
                <?= $lang->_('Cancel') ?>
            </a>
        </div>

    </div>

</form>
<?php echo $this->draw('entity/edit/footer');
