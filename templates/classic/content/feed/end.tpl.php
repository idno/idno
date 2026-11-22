<?php

    $item = $vars['object'];
    /* @var \Idno\Entities\Reader\FeedItem $item */


if (!empty($item)) {

    ?>

        <div class="permalink">
            <p>
                <a href="<?php echo $item->getAuthorURL() ?>"><?php echo $item->getAuthorName() ?></a><?php echo \Idno\Core\Idno::site()->language()->_('published this') ?>
                <a class="u-url url" href="<?php echo $item->getDisplayURL() ?>" rel="permalink">
                    <time class="dt-published"
                          datetime="<?php echo date('c', $item->created) ?>"><?php echo date('F j, Y', $item->created) ?></time>
                </a>
            </p>
        </div>
        <div class="interactions">
        <?php
        if (!$has_liked) {
            $heart_only = '<i class="fa fa-star-o"></i>';
        } else {
            $heart_only = '<i class="fa fa-star"></i>';
        }
        if ($likes == 1) {
            $heart_text = '1 ' . \Idno\Core\Idno::site()->language()->_('star');
        } else {
            $heart_text = $likes . ' ' . \Idno\Core\Idno::site()->language()->_('stars');
        }
            $heart = $heart_only . ' ' . $heart_text;
        if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
            echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'annotation/post', $heart_only, array('type' => 'like', 'object' => $vars['object']->getUUID()), array('method' => 'POST', 'class' => 'stars'));
            ?>
                    <a class="stars" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php echo $heart_text ?></a>
                <?php
        } else {
            ?>
                    <a class="stars" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php echo $heart ?></a>
                <?php
        }
        ?>
            <a class="comments" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><i class="icon-comments"></i> <?php

                //echo $replies;
            if ($replies == 1) {
                echo '1 ' . \Idno\Core\Idno::site()->language()->_('comment');
            } else {
                echo $replies . ' ' . \Idno\Core\Idno::site()->language()->_('comments');
            }

            ?></a>
            <a class="shares" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php if ($shares = $vars['object']->countAnnotations('share')) {
                    echo '<i class="fa fa-retweet"></i> ' . $shares;
} ?></a>
            <a class="shares" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php if ($rsvps = $vars['object']->countAnnotations('rsvp')) {
                    echo '<i class="fa fa-calendar-o"></i> ' . $rsvps;
} ?></a>
        </div>
        <br class="clearall"/>
        <?php

        if (\Idno\Core\Idno::site()->currentPage()->isPermalink()) {

            if (!empty($likes) || !empty($replies) || !empty($shares) || !empty($rsvps)) {

                ?>

                <div class="annotations">

                    <a name="comments"></a>
                    <?php echo $this->draw('content/end/annotations') ?>
                    <?php

                    if ($replies = $vars['object']->getAnnotations('reply')) {
                        echo $this->__(array('annotations' => $replies))->draw('entity/annotations/replies');
                    }
                    if ($likes = $vars['object']->getAnnotations('like')) {
                        echo $this->__(array('annotations' => $likes))->draw('entity/annotations/likes');
                    }
                    if ($shares = $vars['object']->getAnnotations('share')) {
                        echo $this->__(array('annotations' => $shares))->draw('entity/annotations/shares');
                    }
                    if ($rsvps = $vars['object']->getAnnotations('rsvp')) {
                        echo $this->__(array('annotations' => $rsvps))->draw('entity/annotations/rsvps');
                    }

                    ?>

                </div>

                <?php

            }

            echo $this->draw('entity/annotations/comment/main');

            if ($posse = $vars['object']->getPosseLinks()) {

                ?>
                <div class="posse">
                    <a name="posse"></a>

                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Also on'); ?>:
                        <?php

                        foreach ($posse as $service => $url) {
                            echo '<a href="' . $url . '" rel="syndication" class="u-syndication ' . $service . '">' . $service . '</a> ';
                        }

                        ?>
                    </p>
                </div>
                <?php

            }

        } else {

            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                echo $this->draw('entity/annotations/comment/mini');
            }

        }
}

