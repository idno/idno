<?php

$path = false;
$url = false;
$shortname = 'default';

if (!empty($vars['theme'])) {
    if (!empty($vars['theme']['Theme description']['path'])) {
        $path = $vars['theme']['Theme description']['path'];
    }
    if (!empty($vars['theme']['Theme description']['url'])) {
        $url = $vars['theme']['Theme description']['url'];
    }
    if (!empty($vars['theme']['shortname'])) {
        $shortname = $vars['theme']['shortname'];
    }
}

// No theme is the built-in look, which is the card named `default`
$isActive = (\Idno\Core\Idno::site()->themes()->get() == $shortname) || ($shortname === 'default' && (string)\Idno\Core\Idno::site()->themes()->get() === '');

// Find preview image
$src = false;
if ($shortname !== 'default' && file_exists($path . 'preview.png')) {
    $src = $url . 'preview.png';
} elseif ($shortname === 'default') {
    $src = \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/themes/default.png';
}

$desc = $vars['theme']['Theme description'];
?>
<div class="idno-admin-card">
    <div class="idno-plugin-row">
        <div class="idno-plugin-info">
            <div class="idno-plugin-name">
                <?= htmlspecialchars($desc['name']) ?>
                <span class="idno-plugin-version"><?= htmlspecialchars($desc['version']) ?></span>
            </div>
            <div class="idno-plugin-author">
                <?= \Idno\Core\Idno::site()->language()->_('by') ?>
                <a href="<?= htmlspecialchars($desc['author_url']) ?>"><?= htmlspecialchars($desc['author']) ?></a>
            </div>
            <?php if (!empty($desc['description'])) { ?>
                <div class="idno-plugin-description"><?= htmlspecialchars($desc['description']) ?></div>
            <?php } ?>
        </div>
        <div class="idno-plugin-action">
            <?php if ($isActive) { ?>
                <span style="font-size: var(--font-size-sm); color: var(--color-text-secondary); font-weight: 500;"><?= \Idno\Core\Idno::site()->language()->_('Active') ?></span>
            <?php } else {
                echo \Idno\Core\Idno::site()->actions()->createLink(
                    \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/themes/',
                    \Idno\Core\Idno::site()->language()->_('Enable'),
                    array('theme' => $shortname, 'action' => 'install'),
                    array('class' => 'idno-btn-enable')
                );
            } ?>
        </div>
    </div>
    <?php if ($src) { ?>
        <div style="margin-top: 0.75rem; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--color-border-subtle);">
            <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($desc['name']) ?> preview" style="width: 100%; display: block;">
        </div>
    <?php } ?>
</div>
