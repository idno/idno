<?php
    /*
     * The body of /admin/legacytables (Idno\Pages\Admin\LegacyTables), shared by the default and the
     * Twenty26 templates. Everything that comes from the database (table names, uuids, reasons) is
     * escaped here.
     */
    $language = \Idno\Core\Idno::site()->language();
    $url = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/legacytables/';
    $e = static function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $tables = $vars['tables'] ?? [];
    $reappeared = $vars['reappeared'] ?? [];
    $rejects = $vars['rejects'] ?? [];
    $rejectCount = (int) ($vars['reject_count'] ?? 0);
    $offset = (int) ($vars['offset'] ?? 0);
    $pageSize = (int) ($vars['page_size'] ?? 100);
?>
<p class="explanation idno-admin-description">
    <?= $language->esc_('When this site was upgraded from Idno 1.x, the tables of the old database were kept, renamed legacy_ followed by their old name. The site no longer reads them. Keep them until you are sure the upgrade went well; then you can delete them here.') ?>
</p>

<?php if (empty($tables) && empty($reappeared) && $rejectCount === 0) { ?>
<div class="idno-admin-card" id="legacytables-empty">
    <p><?= $language->esc_('There is nothing here: this site has no legacy tables, and no rows were left out by an upgrade.') ?></p>
</div>
<?php } ?>

<?php if (!empty($tables)) { ?>
<div class="idno-admin-card" id="legacytables-tables">
    <h2 class="idno-admin-card-title"><?= $language->esc_('Tables of the Idno 1.x database') ?></h2>
    <table class="table">
        <thead>
            <tr><th><?= $language->esc_('Table') ?></th><th><?= $language->esc_('Rows (approximate)') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tables as $name => $rows) { ?>
            <tr><td><code><?= $e($name) ?></code></td><td><?= (int) $rows ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if (!empty($vars['restored'])) { ?>
    <p id="legacytables-restored"><strong><?= $language->esc_('These tables cannot be deleted now.') ?></strong> <?= htmlspecialchars(\Idno\Upgrade\PublicMessage::oneXBesideCompletedUpgrade()->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php } else if (!empty($vars['complete'])) { ?>
    <form action="<?= $e($url) ?>" method="post">
        <p>
            <?= $language->esc_('The row counts are the database\'s estimates.') ?>
            <?= $language->esc_('Deleting these tables cannot be undone, and afterwards the upgrade can no longer be reversed. Make a backup of the database first if you might want them again. The rows that were not carried over (below) are kept.') ?>
        </p>
        <p>
            <label for="legacytables-confirm"><?= $language->esc_('Type delete to confirm:') ?></label>
            <input type="text" name="confirm" id="legacytables-confirm" value="" autocomplete="off" class="form-control idno-input">
        </p>
        <p>
            <button type="submit" class="btn btn-danger idno-btn idno-btn-danger"><?= $language->esc_('Delete the legacy tables') ?></button>
        </p>
        <?= \Idno\Core\Idno::site()->actions()->signForm($url) ?>
    </form>
    <?php } else { ?>
    <p><?= $language->esc_('The upgrade from Idno 1.x is not complete, so these tables cannot be deleted yet.') ?></p>
    <?php } ?>
</div>
<?php } ?>

<?php if ($rejectCount > 0) { ?>
<div class="idno-admin-card" id="legacytables-rejects">
    <h2 class="idno-admin-card-title"><?= $language->esc_('Rows that were not carried over') ?></h2>
    <p>
        <?= $language->esc_('%d rows of the Idno 1.x database could not be carried into the new database. Each is listed with the reason. Their full contents are kept in the database table idno_upgrade_rejects, which is never deleted (deleting the legacy tables does not delete it).', [$rejectCount]) ?>
    </p>
    <table class="table">
        <thead>
            <tr><th><?= $language->esc_('Collection') ?></th><th><?= $language->esc_('Address (uuid) in 1.x') ?></th><th><?= $language->esc_('Reason') ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rejects as $reject) { ?>
            <tr>
                <td><?= $e($reject['collection']) ?></td>
                <td><code><?= $e($reject['legacy_uuid']) ?></code></td>
                <td><?= $e($reject['reason']) ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if ($rejectCount > $pageSize) { ?>
    <p>
        <?= $language->esc_('Showing %d to %d of %d.', [$offset + 1, $offset + count($rejects), $rejectCount]) ?>
        <?php if ($offset > 0) { ?>
            <a href="<?= $e($url . '?offset=' . max(0, $offset - $pageSize)) ?>"><?= $language->esc_('Previous') ?></a>
        <?php } ?>
        <?php if ($offset + $pageSize < $rejectCount) { ?>
            <a href="<?= $e($url . '?offset=' . ($offset + $pageSize)) ?>"><?= $language->esc_('Next') ?></a>
        <?php } ?>
    </p>
    <?php } ?>
</div>
<?php } ?>

<?php if (!empty($reappeared)) { ?>
<div class="idno-admin-card" id="legacytables-reappeared">
    <h2 class="idno-admin-card-title"><?= $language->esc_('Tables created by an old Idno process') ?></h2>
    <p>
        <?= $language->esc_('These tables have the names of the Idno 1.x tables, but they were created after the upgrade, by a process still running the old version of Idno (a queue or cron service, for example). Nothing in them is part of this site, and the site never reads them. Stop that process. These tables are not deleted from here.') ?>
    </p>
    <ul>
    <?php foreach ($reappeared as $name) { ?>
        <li><code><?= $e($name) ?></code></li>
    <?php } ?>
    </ul>
</div>
<?php } ?>
