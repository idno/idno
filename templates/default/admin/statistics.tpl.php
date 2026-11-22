<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Statistics') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('This page provides you with information and statistics about your Idno site.') ?>
    </p>

    <?php
    if (!empty($vars['statistics']) && is_array($vars['statistics'])) {
        foreach ($vars['statistics'] as $tab => $report) {
            $sanitised_tab = strtolower(str_replace(' ', '', $tab));
    ?>
        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= $tab ?></h2>
            <?= $this->__([
                'report' => $report
            ])->draw('admin/statistics/report') ?>
        </div>
    <?php
        }
    }
    ?>
