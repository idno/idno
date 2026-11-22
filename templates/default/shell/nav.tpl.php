<?php
    $user = \Idno\Core\Idno::site()->session()->currentUser();
    $is_admin = !empty($user) && $user->isAdmin();
?>
<nav class="idno-nav-sidebar" aria-label="Main navigation">
    <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-nav-logo">
        <?= htmlspecialchars(\Idno\Core\Idno::site()->config()->getTitle()) ?>
    </a>

    <ul class="idno-nav-items">
        <li>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-nav-item">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Home') ?></span>
            </a>
        </li>
        <?php if (!empty($user)) { ?>
        <li>
            <a href="<?= $user->getDisplayURL() ?>" class="idno-nav-item">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Profile') ?></span>
            </a>
        </li>
        <?php } ?>
        <li>
            <button type="button" class="idno-nav-item" x-data x-on:click="$dispatch('open-search')">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Search') ?></span>
            </button>
        </li>
        <?php if (!empty($user)) { ?>
        <li>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>drafts/" class="idno-nav-item">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Drafts') ?></span>
            </a>
        </li>
        <?php } ?>
        <?php if (!empty($user)) { ?>
        <li>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/" class="idno-nav-item">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Settings') ?></span>
            </a>
        </li>
        <?php } ?>
        <?php if ($is_admin) { ?>
        <li>
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/" class="idno-nav-item">
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Admin') ?></span>
            </a>
        </li>
        <?php } ?>
        <?php if (!empty($user)) { ?>
        <li>
            <?= \Idno\Core\Idno::site()->actions()->createLink(
                \Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/logout',
                '<svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span class="idno-nav-label">'
                    . \Idno\Core\Idno::site()->language()->_('Log out')
                    . '</span>',
                [],
                ['class' => 'idno-nav-item']
            ) ?>
        </li>
        <?php } ?>
    </ul>

    <?php if (empty($user)) { ?>
    <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>session/login" class="idno-nav-item" style="margin-top:auto">
        <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Log in') ?></span>
    </a>
    <?php } ?>

    <?php if (!empty($user)) { ?>
    <button class="idno-nav-btn" x-data x-on:click="$dispatch('open-compose')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:1.25rem;height:1.25rem"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('New Post') ?></span>
    </button>
    <?php } ?>
</nav>
