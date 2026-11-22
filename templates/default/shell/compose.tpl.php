<?php if ($user = \Idno\Core\Idno::site()->session()->currentUser()) { ?>
<div x-data="composeModal" x-show="open" x-cloak class="idno-modal-overlay" x-on:keydown.escape.window="close()">
    <div class="idno-modal idno-modal-lg" x-on:click.outside="close()">

        <!-- Content Type Picker -->
        <template x-if="step === 'picker'">
            <div>
                <div class="idno-modal-header">
                    <h2 class="idno-modal-title"><?= \Idno\Core\Idno::site()->language()->_('New Post') ?></h2>
                    <button class="idno-modal-close" x-on:click="close()" type="button">
                        <?= $this->__(['icon' => 'x', 'class' => 'idno-nav-icon'])->draw('shell/icon') ?>
                    </button>
                </div>
                <div class="idno-modal-body">
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:var(--spacing-gap)">
                        <?php
                        $contentTypes = \Idno\Common\ContentType::getRegistered();
                        foreach ($contentTypes as $contentType) {
                            if (!$contentType->createable) continue;
                            $editUrl = $contentType->getEditURL();
                            $iconName = method_exists($contentType, 'getIconName') ? $contentType->getIconName() : 'file-text';
                            $description = method_exists($contentType, 'getDescription') ? $contentType->getDescription() : '';
                        ?>
                        <a href="<?= $editUrl ?>" class="idno-admin-card" style="text-decoration:none;display:flex;align-items:center;gap:var(--spacing-gap);cursor:pointer">
                            <?= $this->__(['icon' => $iconName, 'class' => 'idno-nav-icon'])->draw('shell/icon') ?>
                            <div>
                                <div style="font-weight:600;color:var(--color-text-strong)"><?= htmlspecialchars($contentType->getTitle()) ?></div>
                                <?php if ($description) { ?>
                                <div style="font-size:var(--font-size-sm);color:var(--color-text-muted)"><?= htmlspecialchars($description) ?></div>
                                <?php } ?>
                            </div>
                        </a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </template>

    </div>
</div>
<?php } ?>
