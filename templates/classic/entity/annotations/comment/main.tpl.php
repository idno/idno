<?php

    $user = \Idno\Core\Idno::site()->session()->currentUser();
    $object = $vars['object'];

if (!empty($user) && !empty($object)) {

    ?>
    <div class="row annotation-add">
        <div class="col-md-2 owner h-card visible-md visible-lg">
            <a href="<?php echo $user->getDisplayURL() ?>" class="u-url icon-container"><img class="u-photo"
                                                                                      src="<?php echo $user->getIcon() ?>"
                                                                                      alt="<?php echo htmlspecialchars($user->getTitle()) ?>" /></a>
        </div>
        <div class="col-md-10 idno-comment-container">
            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>annotation/post" method="post">
                <textarea name="body" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Add a comment ...'); ?>" class="form-control mentionable ctrl-enter-submit"></textarea>
                <p style="text-align: right">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('annotation/post') ?>
                    <input type="hidden" name="object" value="<?php echo $object->getUUID()?>">
                    <input type="hidden" name="type" value="reply">
                    <input type="submit" class="btn btn-save" value="<?php echo \Idno\Core\Idno::site()->language()->_('Leave Comment'); ?>">
                </p>
            </form>
        </div>
    </div>
    <?php

    // Prevent scope pollution
    unset($this->vars['action']);
}

