<?php

    $user = \Idno\Core\Idno::site()->session()->currentUser();
    $object = $vars['object'];

if (!empty($user) && !empty($object)) {

?>
    <div class="idno-comment-form">
        <a href="<?= $user->getDisplayURL() ?>" class="u-url">
            <img class="idno-annotation-avatar u-photo"
                 src="<?= $user->getIcon() ?>"
                 alt="<?= htmlspecialchars($user->getTitle()) ?>" />
        </a>
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>annotation/post" method="post">
            <textarea name="body"
                      placeholder="<?= \Idno\Core\Idno::site()->language()->_('Add a comment ...') ?>"
                      class="idno-textarea mentionable ctrl-enter-submit"></textarea>
            <?= \Idno\Core\Idno::site()->actions()->signForm('annotation/post') ?>
            <input type="hidden" name="object" value="<?= $object->getUUID() ?>">
            <input type="hidden" name="type" value="reply">
            <div class="idno-comment-form-actions">
                <button type="submit" class="idno-btn idno-btn-primary idno-btn-sm">
                    <?= \Idno\Core\Idno::site()->language()->_('Comment') ?>
                </button>
            </div>
        </form>
    </div>
<?php

    unset($this->vars['action']);
}
