<?php
$plugin_description = $vars['plugin']['Plugin description'];
$shortname = $vars['plugin']['shortname'];
$isEnabled = array_key_exists($shortname, $vars['plugins_loaded']);
$isAlwaysOn = in_array($shortname, \Idno\Core\Idno::site()->config()->alwaysplugins);
?>
<div class="idno-admin-card" id="plugin-<?= strtolower($shortname) ?>">
    <div class="idno-plugin-row">
        <div class="idno-plugin-info">
            <div class="idno-plugin-name">
                <?= htmlspecialchars($plugin_description['name']) ?>
                <span class="idno-plugin-version"><?= htmlspecialchars($plugin_description['version']) ?></span>
            </div>
            <?php if (!empty($plugin_description['author'])) { ?>
            <div class="idno-plugin-author">
                <?= \Idno\Core\Idno::site()->language()->_('by') ?>
                <?php if (!empty($plugin_description['author_url'])) { ?>
                    <a href="<?= htmlspecialchars($plugin_description['author_url']) ?>"><?= htmlspecialchars($plugin_description['author']) ?></a>
                <?php } else { ?>
                    <?= htmlspecialchars($plugin_description['author']) ?>
                <?php } ?>
            </div>
            <?php } ?>
            <?php if (!empty($plugin_description['description'])) { ?>
            <div class="idno-plugin-description">
                <?= htmlspecialchars($plugin_description['description']) ?>
            </div>
            <?php } ?>
        </div>
        <div class="idno-plugin-action">
            <?php if (!$isAlwaysOn) { ?>
                <?php if ($isEnabled) { ?>
                <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/plugins/" method="post">
                    <input type="hidden" name="plugin" value="<?= $shortname ?>">
                    <input type="hidden" name="container" value="plugin-<?= strtolower($shortname) ?>">
                    <input type="hidden" name="plugin_action" value="uninstall">
                    <button type="submit" class="idno-btn idno-btn-sm idno-btn-disable"><?= \Idno\Core\Idno::site()->language()->_('Disable') ?></button>
                    <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/plugins/') ?>
                </form>
                <?php } else { ?>
                <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/plugins/" method="post">
                    <input type="hidden" name="plugin" value="<?= $shortname ?>">
                    <input type="hidden" name="container" value="plugin-<?= strtolower($shortname) ?>">
                    <input type="hidden" name="plugin_action" value="install">
                    <button type="submit" class="idno-btn idno-btn-sm idno-btn-enable"><?= \Idno\Core\Idno::site()->language()->_('Enable') ?></button>
                    <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/plugins/') ?>
                </form>
                <?php } ?>
            <?php } else { ?>
                <span class="idno-plugin-always-on"><?= \Idno\Core\Idno::site()->language()->_('Always on') ?></span>
            <?php } ?>
        </div>
    </div>
</div>
