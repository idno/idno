<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Your Drafts') ?></h1>

<?php if (empty($vars['drafts'])) { ?>
    <div class="idno-entry" style="text-align:center;padding:2rem">
        <p style="color:var(--color-text-muted)"><?= \Idno\Core\Idno::site()->language()->_('No drafts yet.') ?></p>
    </div>
<?php } else { ?>
    <div class="idno-grid">
        <?php foreach ($vars['drafts'] as $draft) { ?>
        <div class="idno-entry">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <a href="<?= $draft->getEditURL() ?>" style="font-weight:600;color:var(--color-text-strong);text-decoration:none">
                        <?= htmlspecialchars($draft->getTitle() ?: \Idno\Core\Idno::site()->language()->_('Untitled')) ?>
                    </a>
                    <div class="idno-entry-meta">
                        <?= date('M j, Y g:ia', $draft->updated) ?>
                        &middot;
                        <?= htmlspecialchars($draft->getContentTypeCategoryTitle()) ?>
                    </div>
                </div>
                <a href="<?= $draft->getEditURL() ?>" class="idno-btn idno-btn-ghost idno-btn-sm">
                    <?= \Idno\Core\Idno::site()->language()->_('Edit') ?>
                </a>
            </div>
        </div>
        <?php } ?>
    </div>

    <?php
    // Pagination
    if ($vars['total'] > $vars['count']) {
        $current_offset = $vars['offset'];
        $count = $vars['count'];
        $total = $vars['total'];
    ?>
    <div style="display:flex;justify-content:center;gap:var(--spacing-gap);padding:var(--spacing-lg) 0">
        <?php if ($current_offset > 0) { ?>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>drafts/?offset=<?= max(0, $current_offset - $count) ?>" class="idno-btn idno-btn-ghost">
                &larr; <?= \Idno\Core\Idno::site()->language()->_('Newer') ?>
            </a>
        <?php } ?>
        <?php if ($current_offset + $count < $total) { ?>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>drafts/?offset=<?= $current_offset + $count ?>" class="idno-btn idno-btn-ghost">
                <?= \Idno\Core\Idno::site()->language()->_('Older') ?> &rarr;
            </a>
        <?php } ?>
    </div>
    <?php } ?>
<?php } ?>
