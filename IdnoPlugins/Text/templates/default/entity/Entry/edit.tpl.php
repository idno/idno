<?php echo $this->draw('entity/edit/header'); ?>
<?php
    $autosave = new \Idno\Core\Autosave();
if (!empty($vars['object']->body)) {
    $body = $vars['object']->body;
} else {
    $body = '';
}
if (!empty($vars['object']->title)) {
    $title = $vars['object']->title;
} else {
    $title = '';
}
if (!empty($vars['object']->short_description)) {
    $subtitle = $vars['object']->short_description;
} else {
    $subtitle = '';
}
if (!empty($vars['object'])) {
    $object = $vars['object'];
} else {
    $object = false;
}
    $unique_id = 'body' . rand(0, 9999);

    /* @var \Idno\Core\Template $this */
?>
<?php
if (!empty($vars['object']->inreplyto)) {
    if (!is_array($vars['object']->inreplyto)) {
        $vars['object']->inreplyto = array($vars['object']->inreplyto);
    }
} else {
    $vars['object']->inreplyto = array();
}
if (!empty($vars['url'])) {
    $vars['object']->inreplyto = array($vars['url']);
}
?>
    <form action="<?= $vars['object']->getURL() ?>" method="post">

        <div class="idno-editor">

                <h4 class="idno-editor-heading">
                    <?php if (empty($vars['object']->_id)) { ?>
                        <?= \Idno\Core\Idno::site()->language()->_('New Post') ?>
                    <?php } else { ?>
                        <?= \Idno\Core\Idno::site()->language()->_('Edit Post') ?>
                        <?php if ($vars['object']->getPublishStatus() === 'draft') { ?>
                            <span class="idno-badge-draft"><?= \Idno\Core\Idno::site()->language()->_('Draft') ?></span>
                        <?php } ?>
                    <?php } ?>
                </h4>

                <div class="idno-form-field">
                    <label class="idno-label" for="title"><?= \Idno\Core\Idno::site()->language()->_('Title') ?></label>
                    <?= $this->__(['name' => 'title', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a title'), 'id' => 'title', 'value' => $title, 'required' => true, 'class' => 'idno-input idno-editor-title'])->draw('forms/input/input') ?>
                </div>

                <div class="idno-form-field">
                    <label class="idno-label" for="subtitle"><?= \Idno\Core\Idno::site()->language()->_('Subtitle') ?></label>
                    <?= $this->__(['name' => 'subtitle', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Optional sub title for this post'), 'id' => 'subtitle', 'value' => $subtitle, 'class' => 'idno-input idno-editor-subtitle'])->draw('forms/input/input') ?>
                </div>

                <?= $this->__([
                    'name' => 'body',
                    'unique_id' => $unique_id,
                    'value' => $body,
                    'object' => $object,
                    'wordcount' => true,
                    'required' => true
                ])->draw('forms/input/richtext') ?>

                <?= $this->draw('entity/tags/input') ?>
                <?= $this->draw('content/unfurl') ?>

                <?php
                // Set focus so you can start typing straight away (on shares)
                if (\Idno\Core\Idno::site()->currentPage()->getInput('share_url')) {
                    ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function(){
                            var el = document.getElementById('title');
                            if (el) {
                                var len = el.value.length;
                                el.focus();
                                el.setSelectionRange(len, len);
                            }
                        });
                    </script>
                    <?php
                }
                ?>

                <?php $hasReplyTo = !empty($vars['object']->inreplyto); ?>
                <div class="idno-reply-section" x-data="{ open: <?= $hasReplyTo ? 'true' : 'false' ?> }">
                    <button type="button" class="idno-reply-toggle" x-on:click="open = !open" :class="{ 'active': open }">
                        <?= $this->__(['icon' => 'reply', 'class' => 'idno-reply-toggle-icon'])->draw('shell/icon') ?>
                        <?= \Idno\Core\Idno::site()->language()->_('Reply to a site') ?>
                    </button>
                    <div class="idno-reply-input" x-show="open" x-cloak>
                        <input type="url" name="inreplyto[]"
                               placeholder="<?= \Idno\Core\Idno::site()->language()->_('Add the URL that you\'re replying to') ?>"
                               class="idno-input"
                               value="<?= !empty($vars['object']->inreplyto) ? htmlspecialchars(is_array($vars['object']->inreplyto) ? $vars['object']->inreplyto[0] : $vars['object']->inreplyto) : '' ?>"/>
                    </div>
                </div>

                <?= $this->drawSyndication('article', $vars['object']->getPosseLinks()) ?>
                <?php if (empty($vars['object']->_id)) {
                    echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
                } ?>

                <?= $this->draw('content/extra') ?>
                <?= $this->draw('content/access') ?>

                <?= \Idno\Core\Idno::site()->actions()->signForm('/entry/edit') ?>

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
<?php echo $this->draw('entity/edit/footer'); ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Autosave the title & body
        if (typeof autoSave === 'function') {
            autoSave('entry', ['title', 'body'], {
              'body': '#<?= $unique_id ?>',
            });
        }
    });
</script>
