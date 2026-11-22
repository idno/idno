<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= $vars['object']->getURL() ?>" method="post">

    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?php
            if (empty($vars['object']->_id)) {
                echo \Idno\Core\Idno::site()->language()->_('New Bookmark');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('Edit Bookmark');
                if ($vars['object']->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" for="body">
                <?= \Idno\Core\Idno::site()->language()->_('Link Address') ?></label>
            <?php
            $value = "";
            if (empty($vars['url'])) {
                $value = $vars['object']->body;
            } else {
                $value = $vars['url'];
            }
            echo $this->__([
                'name' => 'body',
                'id' => 'body',
                'placeholder' => "https://....",
                'class' => "idno-input bookmark-url",
                'value' => $value,
                'required' => true
            ])->draw('forms/input/url');
            ?>
        </div>

        <?php
        if (empty($vars['url'])) {
            ?>
            <div class="bookmark-spinner-container">
                <?= $this->__(['class' => 'bookmark-title-spinner'])->draw('entity/edit/spinner') ?>
            </div>
            <?php
        }
        ?>

        <div class="bookmark-title-container idno-form-field"
                <?php
                if (empty($vars['object']->pageTitle) && empty($vars['object']->_id) && (empty($vars['url']) && empty($vars['object']->body))) {
                    ?>style="display:none"<?php
                } ?>>
            <label class="idno-label" for="title">
                <?= \Idno\Core\Idno::site()->language()->_('Title') ?>
            </label>
            <?= $this->__([
                'name' => 'title',
                'id' => 'title',
                'placeholder' => \Idno\Core\Idno::site()->language()->_('Page name'),
                'value' => $vars['object']->pageTitle,
                'required' => true,
                'class' => 'idno-input bookmark-title'
            ])->draw('forms/input/input') ?>
        </div>

        <?= $this->draw('content/unfurl') ?>

        <?= $this->__([
            'name'        => 'description',
            'value'       => $vars['object']->description,
            'wordcount'   => false,
            'class'       => 'wysiwyg-short',
            'height'      => 250,
            'placeholder' => \Idno\Core\Idno::site()->language()->_('Add notes to your bookmark...'),
            'label'       => \Idno\Core\Idno::site()->language()->_('Description')
        ])->draw('forms/input/richtext') ?>

        <?= $this->draw('entity/tags/input') ?>
        <?= $this->drawSyndication('bookmark', $vars['object']->getPosseLinks()) ?>
        <?php if (empty($vars['object']->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/like/edit') ?>

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
        var urlInput = document.querySelector('.bookmark-url');
        if (urlInput) {
            urlInput.addEventListener('change', function () {
                if (urlInput.value) {
                    var spinner = document.querySelector('.bookmark-title-spinner');
                    if (spinner) spinner.style.display = '';
                    fetch('<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>like/callback/?url=' + encodeURIComponent(urlInput.value))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var title = document.querySelector('.bookmark-title');
                            if (title) title.value = data.value;
                            var spinnerContainer = document.querySelector('.bookmark-spinner-container');
                            if (spinnerContainer) spinnerContainer.innerHTML = ' ';
                            var titleContainer = document.querySelector('.bookmark-title-container');
                            if (titleContainer) titleContainer.style.display = '';
                            var unfurl = urlInput.closest('form').querySelector('.unfurl');
                            if (unfurl && typeof Unfurl !== 'undefined') {
                                unfurl.setAttribute('data-url', urlInput.value);
                                Unfurl.unfurl(unfurl);
                            }
                        })
                        .catch(function() {
                            var spinnerContainer = document.querySelector('.bookmark-spinner-container');
                            if (spinnerContainer) spinnerContainer.innerHTML = ' ';
                            var titleContainer = document.querySelector('.bookmark-title-container');
                            if (titleContainer) titleContainer.style.display = '';
                        });
                }
            });
        }
    });
</script>
