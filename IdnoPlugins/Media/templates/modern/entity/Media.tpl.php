<?php

    /*
     * An audio or video file, drawn like a post of the Video plugin: the title, the
     * player in the shared media frame, the description, the tags.
     */

    $object = $vars['object'];
    /* @var \IdnoPlugins\Media\Media $object */
    $lang = \Idno\Core\Idno::site()->language();

    $currentPage = \Idno\Core\Idno::site()->currentPage();
if (!empty($currentPage) && $currentPage->isPermalinkFor($object)) {
    $rel = 'rel="in-reply-to"';
} else {
    $rel = '';
}

    $is_video = substr((string) $object->media_type, 0, 5) == 'video';
    $title = strip_tags((string) $object->getTitle());

    // The files of the post, with addresses that are safe to print
    $sources = [];
if (empty($vars['feed_view']) && ($attachments = $object->getAttachments())) {
    foreach ($attachments as $attachment) {
        if (empty($attachment['url'])) {
            continue;
        }
        $src = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($attachment['url']);
        $src = \Idno\Core\UrlValidator::httpUrl($this->makeDisplayURL($src));
        if ($src !== '') {
            $sources[] = ['src' => $src, 'type' => !empty($attachment['mime-type']) ? (string) $attachment['mime-type'] : ''];
        }
    }
}

?>
<div class="idno-media">
    <?php if (empty($vars['feed_view']) && !empty($object->title)) { ?>
        <h2 class="idno-entry-title p-name"><a href="<?= htmlspecialchars($object->getDisplayURL()) ?>"><?= htmlspecialchars($title) ?></a></h2>
    <?php } ?>

    <?php if (!empty($sources)) { ?>
        <div class="idno-media-frame">
            <?php if ($is_video) { ?>
                <div class="idno-media-player idno-media-player-video">
                    <video class="u-video known-media-element" controls preload="metadata" playsinline
                           aria-label="<?= htmlspecialchars($lang->_('Video player: %s', [$title])) ?>">
                        <?php foreach ($sources as $source) { ?>
                            <source src="<?= htmlspecialchars($source['src']) ?>"<?= $source['type'] !== '' ? ' type="' . htmlspecialchars($source['type']) . '"' : '' ?>>
                        <?php } ?>
                        <?= $lang->_('Your browser doesn\'t support embedded video.') ?>
                    </video>
                </div>
            <?php } else { ?>
                <?php foreach ($sources as $source) { ?>
                    <div class="idno-media-player idno-audio-player" x-data="audioPlayer" :class="{ 'is-enhanced': enhanced }">
                        <audio class="u-audio known-media-element" x-ref="audio" controls preload="metadata"
                               aria-label="<?= htmlspecialchars($lang->_('Audio player: %s', [$title])) ?>">
                            <source src="<?= htmlspecialchars($source['src']) ?>"<?= $source['type'] !== '' ? ' type="' . htmlspecialchars($source['type']) . '"' : '' ?>>
                            <?= $lang->_('Your browser doesn\'t support embedded audio.') ?>
                        </audio>
                        <div class="idno-audio-controls" x-show="enhanced" x-cloak>
                            <button type="button" class="idno-audio-play" x-on:click="toggle()"
                                    :aria-label="playing ? '<?= htmlspecialchars($lang->_('Pause'), ENT_QUOTES) ?>' : '<?= htmlspecialchars($lang->_('Play'), ENT_QUOTES) ?>'"
                                    aria-label="<?= htmlspecialchars($lang->_('Play')) ?>">
                                <span x-show="!playing"><?= $this->__(['icon' => 'play', 'class' => 'idno-audio-icon'])->draw('shell/icon') ?></span>
                                <span x-show="playing" x-cloak><?= $this->__(['icon' => 'pause', 'class' => 'idno-audio-icon'])->draw('shell/icon') ?></span>
                            </button>
                            <div class="idno-audio-track">
                                <input type="range" class="idno-audio-seek" min="0" step="0.1"
                                       :max="duration || 0" :value="current"
                                       x-on:input="seek($event)"
                                       :aria-valuetext="time(current)"
                                       aria-label="<?= htmlspecialchars($lang->_('Position in the recording')) ?>">
                                <div class="idno-audio-times" aria-hidden="true">
                                    <span x-text="time(current)">0:00</span>
                                    <span x-text="duration ? time(duration) : ''"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (trim((string) $object->body) !== '') { ?>
        <div class="idno-entry-body e-content entry-content">
            <?= $this->autop($this->parseHashtags($this->parseURLs($object->body, $rel))) ?>
        </div>
    <?php } ?>

    <?php
    if (!empty($object->tags)) {
        echo $this->__(['tags' => $object->tags])->draw('forms/output/tags');
    }
    ?>
</div>
