<?php echo $this->draw('entity/edit/header'); ?>
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
                <?php
                if (empty($vars['object']->_id)) {
                    echo \Idno\Core\Idno::site()->language()->_('New Status Update');
                } else {
                    echo \Idno\Core\Idno::site()->language()->_('Edit Status Update');
                }
                if (!empty($vars['object']->_id) && $vars['object']->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                }
                ?>
            </h4>

            <?php
                $body = "";
            if (!empty($vars['body'])) {
                $body = $vars['body'];
            } else {
                $body = $vars['object']->body;
            } ?>
            <div class="idno-form-field" style="position:relative">
                <?= $this->__([
                    'unique_id' => 'body',
                    'name' => 'body',
                    'placeholder' => \Idno\Core\Idno::site()->language()->_("Share a quick note or comment. You can use links and #hashtags."),
                    'required' => true,
                    'class' => 'idno-textarea ctrl-enter-submit',
                    'value' => $body,
                    'height' => 140
                ])->draw('forms/input/longtext') ?>
                <span id="counter" style="display:none;position:absolute;bottom:0.5rem;right:0.75rem;font-size:var(--font-size-sm);color:var(--color-text-muted);pointer-events:none">
                    <span class="count"></span>
                </span>
            </div>
            <?php

                echo $this->draw('entity/tags/input');

            // Set focus so you can start typing straight away (on shares)
            if (\Idno\Core\Idno::site()->currentPage()->getInput('share_url')) {
                ?>
            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    var el = document.getElementById('body');
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

            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?= $this->drawSyndication('note', $vars['object']->getPosseLinks()) ?>
            <?= $this->draw('content/extra') ?>
            <?= $this->draw('content/access') ?>

            <?= \Idno\Core\Idno::site()->actions()->signForm('/status/edit') ?>

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
<script src="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>IdnoPlugins/Status/external/brevity-js/brevity.js"></script>
<script>
    function count_chars() {
        var bodyEl = document.getElementById('body');
        var counterEl = document.getElementById('counter');
        if (!bodyEl || !counterEl || typeof brevity === 'undefined') return;
        var len = brevity.tweetLength(bodyEl.value);
        if (len > 0) {
            counterEl.style.display = '';
        }
        counterEl.querySelector('.count').textContent = len;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var bodyEl = document.getElementById('body');
        if (bodyEl) {
            bodyEl.addEventListener('keyup', count_chars);
        }
    });
</script>
<?php echo $this->draw('entity/edit/footer');
