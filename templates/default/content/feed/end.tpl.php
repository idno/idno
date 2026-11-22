<?php
    $object = $vars['object'];
    $replies = $object->countAnnotations('reply');
    $likes = $object->countAnnotations('like');
    $owner = $object->getOwner();
    if (!empty($owner)) {
?>
    <a class="idno-entry-permalink u-url" href="<?= $object->getDisplayURL() ?>" rel="permalink">
        <time class="dt-published" datetime="<?= date(DATE_ATOM, $object->created) ?>">
            <?= date('M j, Y', $object->created) ?>
        </time>
    </a>
    <div style="display:flex;align-items:center;gap:1rem;margin-top:0.5rem">
        <a class="idno-entry-action" href="<?= $object->getDisplayURL() ?>#comments">
            <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span><?= $likes ?></span>
        </a>
        <a class="idno-entry-action" href="<?= $object->getDisplayURL() ?>#comments">
            <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
            <span><?= $replies ?></span>
        </a>
    </div>
    <?= $this->draw('content/syndication/links') ?>
<?php } ?>
