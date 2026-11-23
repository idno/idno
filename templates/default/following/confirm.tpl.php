<div class="row">

    <div class="col-md-10 col-md-offset-1">

        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>following/confirm/" method="post">
            <h1>
                Follow <?php echo htmlspecialchars($vars['feed']->getTitle())?>? <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Follow'); ?>">
                <input type="hidden" name="feed" value="<?php echo htmlspecialchars($vars['feed']->getDisplayURL())?>">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('following/confirm')?>
            </h1>
            <p class="explanation">
                <?php echo \Idno\Core\Idno::site()->language()->_("Here's the latest content."); ?>
            </p>
        </form>

    </div>

</div>
<?php

if (!empty($vars['items'])) {

    foreach($vars['items'] as $item) {

        /* @var \Idno\Entities\Reader\FeedItem $item */
        ?>

        <?php echo $item->draw()?>

        <?php

    }

}

