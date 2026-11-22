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

    // Star icon SVG
    if (!$has_liked) {
        $star_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    } else {
        $star_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }

    ?>

        <div class="idno-entry-footer-bar">
            <a class="u-url idno-entry-permalink" href="<?= $vars['object']->getDisplayURL() ?>" rel="permalink"><time class="dt-published"
                      datetime="<?= date(DATE_ATOM, $vars['object']->created) ?>"><?= date('d F Y', $vars['object']->created) ?></time></a>
            <?php if ($vars['object']->access != 'PUBLIC') { ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="idno-entry-lock-icon"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <?php } ?>

            <span class="idno-entry-footer-right">
                <span class="idno-entry-action">
                    <?php if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                        echo \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'annotation/post',
                            $star_icon,
                            [
                                'type' => 'like',
                                'object' => $vars['object']->getUUID()
                            ],
                            [
                                'method' => 'POST',
                                'class' => 'idno-action-toggle'
                            ]
                        );
                    } else {
                        echo $star_icon;
                    } ?>
                    <a href="<?= $vars['object']->getDisplayURL() ?>#comments"><?= $likes ?></a>
                </span>

                <span class="idno-entry-action">
                    <a href="<?= $vars['object']->getDisplayURL() ?>#comments">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
                        <?= $replies ?>
                    </a>
                </span>

                <?php if ($shares = $vars['object']->countAnnotations('share')) { ?>
                <span class="idno-entry-action">
                    <a href="<?= $vars['object']->getDisplayURL() ?>#comments">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>
                        <?= $shares ?>
                    </a>
                </span>
                <?php } ?>

                <?php if ($rsvps = $vars['object']->countAnnotations('rsvp')) { ?>
                <span class="idno-entry-action">
                    <a href="<?= $vars['object']->getDisplayURL() ?>#comments">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                        <?= $rsvps ?>
                    </a>
                </span>
                <?php } ?>

                <?= $this->draw('content/edit') ?>
                <?= $this->draw('content/end/links') ?>
            </span>

            <?php if (\Idno\Core\Idno::site()->currentPage()->isPermalink() && \Idno\Core\Idno::site()->config()->indieweb_citation) { ?>
                <span class="idno-entry-citation"><?= $vars['object']->getCitation() ?></span>
            <?php } ?>
        </div>
        <?php

        if (\Idno\Core\Idno::site()->currentPage()->isPermalink()) {

            if (!empty($likes) || !empty($replies) || !empty($shares) || !empty($rsvps) || !empty($mentions)) {

                ?>
                <div class="idno-annotations">

                    <a name="comments"></a>
                    <?= $this->draw('content/end/annotations') ?>
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
            <div class="idno-entry-extra">
                <?= $this->draw('content/syndication/links') ?>
            </div>
            <?php

            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                echo $this->draw('entity/annotations/comment/mini');
            }

        }
}
