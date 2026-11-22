<?php

    /* @var \Idno\Common\Entity $vars ['object'] */

    $replies = $vars['object']->countAnnotations('reply');
    $likes = $vars['object']->countAnnotations('like');
    $mentions = $vars['object']->countAnnotations('mention');
    $has_liked = false;
if ($like_annotations = $vars['object']->getAnnotations('like')) {
    foreach ($like_annotations as $like) {
        if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
            if ($like['owner_url'] == \Idno\Core\Idno::site()->session()->currentUser()->getDisplayURL()) {
                $has_liked = true;
            }
        }
    }
}
    $owner = $vars['object']->getOwner();

if (!empty($owner)) {

    ?>

        <div class="permalink">
            <p>
                <a href="<?php echo $owner->getDisplayURL() ?>"><?php echo htmlentities(strip_tags($owner->getTitle()), ENT_QUOTES, 'UTF-8') ?></a><?php echo \Idno\Core\Idno::site()->language()->_('published this'); ?>
                <a class="u-url url" href="<?php echo $vars['object']->getDisplayURL() ?>" rel="permalink"><time class="dt-published"
                          datetime="<?php echo date(DATE_ATOM, $vars['object']->created) ?>"><?php echo date('d F Y', $vars['object']->created) ?></time></a>
            <?php

            if ($vars['object']->access != 'PUBLIC') {
                ?><i class="fa fa-lock"> </i><?php
            }

            ?>
                <?php echo $this->draw('content/edit') ?>
                <?php echo $this->draw('content/end/links') ?>
                <?php

                if (\Idno\Core\Idno::site()->currentPage()->isPermalink() && \Idno\Core\Idno::site()->config()->indieweb_citation) {

                    ?>
                        <span class="citation"><?php echo $vars['object']->getCitation() ?></span>
                    <?php

                }

                ?>
            </p>
        </div>
        <div class="interactions">
        <span class="annotate-icon">
            <?php
            if (!$has_liked) {
                $star = \Idno\Core\Idno::site()->language()->_('Star this!');
                $heart_only = '<i class="fa fa-star-o" title="'.$star.'"></i>';
            } else {
                $heart_only = '<i class="fa fa-star"></i>';
            }
            if ($likes == 1) {
                $star = \Idno\Core\Idno::site()->language()->_('star');
                $heart_text = '1 ' . $star;
            } else {
                $star = \Idno\Core\Idno::site()->language()->_('stars');
                $heart_text = $likes . ' ' . $star;
            }
            $heart = $heart_only . ' ' . $heart_text;
            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                echo \Idno\Core\Idno::site()->actions()->createLink(
                    \Idno\Core\Idno::site()->config()->getDisplayURL() . 'annotation/post',
                    $heart_only,
                    [
                        'type' => 'like',
                        'object' => $vars['object']->getUUID()
                    ],
                    [
                        'method' => 'POST',
                        'class' => 'stars-toggle'
                    ]
                );
                ?>
                    <a class="stars" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php echo $heart_text ?></a>
                <?php
            } else {
                ?>
                    <a class="stars" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php echo $heart ?></a>
                <?php
            }
            ?>
            </span>
           <span class="annotate-icon"> <a class="comments" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><i class="fa fa-comments"></i> <?php

                //echo $replies;
            if ($replies == 1) {
                echo '1 ' . \Idno\Core\Idno::site()->language()->_('comment');
            } else {
                echo $replies . ' ' . \Idno\Core\Idno::site()->language()->_('comments');
            }

            ?></a></span>
            <a class="shares" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php if ($shares = $vars['object']->countAnnotations('share')) {
                    echo '<i class="fa fa-retweet"></i>' . $shares;
} ?></a>
            <a class="shares" href="<?php echo $vars['object']->getDisplayURL() ?>#comments"><?php if ($rsvps = $vars['object']->countAnnotations('rsvp')) {
                    echo '<i class="fa fa-calendar-o"></i>' . $rsvps;
} ?></a>
        </div>
        <br class="clearall"/>
        <?php

        if (\Idno\Core\Idno::site()->currentPage()->isPermalink()) {

            if (!empty($likes) || !empty($replies) || !empty($shares) || !empty($rsvps) || !empty($mentions)) {

                ?>
                <div class="annotations">

                    <a name="comments"></a>
                    <?php echo $this->draw('content/end/annotations') ?>
                    <?php
                        unset($this->vars['annotations']);
                        unset($this->vars['annotation_permalink']);
                    ?>

                </div>
                <?php

            }

            echo $this->draw('entity/annotations/comment/main');

            echo $this->draw('content/syndication/links');

        } else {

            ?>
            <div class="extra-metadata">
                <?php echo $this->draw('content/syndication/links')?>
            </div>
            <?php

            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                echo $this->draw('entity/annotations/comment/mini');
            }

        }
}

