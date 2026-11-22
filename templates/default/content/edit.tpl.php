<?php /* @var \Idno\Common\Entity $vars['object'] */ ?>
<?php

if ($vars['object']->canEdit()) {

    ?>
        <span class="idno-entry-kebab" x-data="{ open: false }">
            <button type="button" class="idno-entry-action idno-entry-kebab-btn" x-on:click="open = !open" aria-label="<?= \Idno\Core\Idno::site()->language()->_('More actions') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
            </button>
            <div class="idno-entry-kebab-menu" x-show="open" x-on:click.outside="open = false" x-cloak>
                <a href="<?= $vars['object']->getEditURL() ?>" class="idno-entry-kebab-item">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                    <?= \Idno\Core\Idno::site()->language()->_('Edit') ?>
                </a>
                <?= \Idno\Core\Idno::site()->actions()->createLink(
                    $vars['object']->getDeleteURL(),
                    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg> '
                        . \Idno\Core\Idno::site()->language()->_('Delete'),
                    [],
                    [
                        'method' => 'POST',
                        'class' => 'idno-entry-kebab-item idno-entry-kebab-danger',
                        'title' => \Idno\Core\Idno::site()->language()->_('Delete'),
                        'confirm' => true,
                        'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this entry?')
                    ]
                ) ?>
            </div>
        </span>
    <?= $this->draw('content/entity/' . $vars['object']->getEntityTypeName() . '/edit') ?>
    <?php

}
