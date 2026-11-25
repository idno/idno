<?php

    /* The heading and tabs of every moderation page. $vars['tab'] is the tab that is open. */

    $language = \Idno\Core\Idno::site()->language();
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/';
    $tabs = [
        'unresolved' => [$language->_('Unresolved'), $base, (int) ($vars['open_count'] ?? 0)],
        'resolved' => [$language->_('Resolved'), $base . '?tab=resolved', null],
        'banned' => [$language->_('Banned members'), $base . '?tab=banned', (int) ($vars['banned_count'] ?? 0)],
        'log' => [$language->_('Activity log'), $base . '?tab=log', null],
        'settings' => [$language->_('Settings'), $base . 'settings', null],
    ];

?>
<div class="mod-header">
    <h1 class="idno-admin-page-title mod-title"><?php echo $language->_('Moderation') ?></h1>
    <nav class="mod-tabs" aria-label="<?php echo htmlspecialchars($language->_('Moderation sections'), ENT_QUOTES) ?>">
        <?php foreach ($tabs as $key => $tab) { ?>
        <a class="mod-tab<?php if (($vars['tab'] ?? '') === $key) echo ' mod-tab-active'; ?>" href="<?php echo htmlspecialchars($tab[1], ENT_QUOTES) ?>"
            <?php if (($vars['tab'] ?? '') === $key) echo 'aria-current="page"'; ?>><?php echo htmlspecialchars($tab[0]) ?><?php if ($tab[2]) { ?> <span class="mod-count"><?php echo $tab[2] ?></span><?php } ?></a>
        <?php } ?>
    </nav>
</div>
