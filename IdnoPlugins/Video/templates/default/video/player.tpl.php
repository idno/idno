<?php

    /*
     * The player of a video post, and a link to the video on its own service.
     *
     * Shared by both looks. The frame's address comes from VideoUrl, which builds it from
     * the stored provider and id after checking them; nothing stored is printed into the
     * frame except the title, escaped. Without a video that passes the check there is no
     * frame, only the link. Feeds get the link alone. A player on a host that anybody can
     * run (PeerTube, Loops) is sandboxed, sent no referrer, and named in the caption.
     */

    $object = $vars['object'];
    /* @var \IdnoPlugins\Video\Video $object */

    $video = $object->getVideo();
    $address = $object->getVideoAddress();
    $lang = \Idno\Core\Idno::site()->language();

    $framed = $video && empty($vars['feed_view']);

if ($framed) {
    $ratio = $video->isPortrait() ? '9/16' : '16/9';
    $width = $video->isPortrait() ? 'max-width:20rem;' : '';
    // The player and its link share one box, so that the link sits under the player's
    // left edge when the player is narrower than the post
    ?>
    <div class="idno-video-frame" style="<?php echo $width ?>margin:0 auto 1rem auto">
    <div class="idno-video-player" style="position:relative;width:100%;aspect-ratio:<?php echo $ratio ?>;background:#000;border-radius:0.5rem;overflow:hidden">
        <iframe src="<?php echo htmlspecialchars($video->getEmbedUrl()) ?>"
                title="<?php echo htmlspecialchars($lang->_('Video player: %s', [strip_tags($object->getTitle())])) ?>"
                loading="lazy"
                allow="encrypted-media; fullscreen; picture-in-picture; web-share"
                allowfullscreen
                <?php if ($video->getSandbox() !== '') { // A host nobody has vouched for: see VideoUrl::OPEN_SANDBOX ?>
                sandbox="<?php echo htmlspecialchars($video->getSandbox()) ?>"
                referrerpolicy="no-referrer"
                <?php } else { ?>
                referrerpolicy="strict-origin-when-cross-origin"
                <?php } ?>
                style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"></iframe>
    </div>
    <?php
}

if ($address !== '') {
    if ($video && $video->isOpen()) {
        // Say where the player comes from
        $label = $lang->_('Watch on %s', [$video->getHost()]);
    } else {
        $label = $video ? $lang->_('Watch on %s', [$video->getProviderName()]) : $lang->_('Watch the video');
    }
    ?>
    <?php if ($framed) { // A small caption under the player, like the date of the post ?>
        <p class="idno-video-link" style="margin:0.375rem 0 0 0;font-size:0.8125rem;line-height:1.3;color:var(--color-text-muted, #777)"><a href="<?php echo htmlspecialchars($address) ?>" class="u-video" rel="noopener" style="color:inherit;text-decoration:none"><?php echo htmlspecialchars($label) ?></a></p>
    <?php } else { ?>
        <p class="idno-video-link"><a href="<?php echo htmlspecialchars($address) ?>" class="u-video" rel="noopener"><?php echo htmlspecialchars($label) ?></a></p>
    <?php } ?>
    <?php
}

if ($framed) {
    ?>
    </div>
    <?php
}
