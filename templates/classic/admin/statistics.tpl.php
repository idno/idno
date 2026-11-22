<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Statistics'); ?></h1>


        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('This page provides you with information and statistics about your Idno site.'); ?>
            </p>
        </div>


        <ul class="nav nav-tabs" role="tablist">

            <?php
            foreach ($vars['statistics'] as $tab => $report) {

                $sanitised_tab = strtolower(str_replace(' ', '', $tab));

                ?>

                <li role="presentation" id="tab-stats-<?php echo $sanitised_tab; ?>">
                    <a href="#stats-<?php echo $sanitised_tab; ?>" aria-controls="stats-<?php echo $sanitised_tab; ?>" role="tab"
                       data-toggle="tab"><?php echo $tab; ?></a>
                </li>

                <?php
            }
            ?>
        </ul>
        <div class="tab-content">
            <?php
            foreach ($vars['statistics'] as $tab => $report) {

                $sanitised_tab = strtolower(str_replace(' ', '', $tab));

                ?>
                <div role="tabpanel1"
                     class="tab-pane <?php echo (empty($vars['tab']) || $vars['tab'] == 'Basic') ? 'active' : '' ?>"
                     id="stats-<?php echo $sanitised_tab; ?>">
                    <?php echo $this->__(
                        [
                        'report' => $report
                        ]
                    )->draw('admin/statistics/report'); ?>
                </div>
                <?php
            }
            ?>
        </div>

    </div>

</div>

<script>
    $(document).ready(function () {
        $('#statistics-report-run').click(function () {
            $(this).html("Generating...").attr('disabled', 'true');

            $('#statistics-report pre').load('<?php echo \Idno\Core\Idno::site()->currentPage()->currentUrl(); ?>', function () {
                $('#statistics-report-run').hide();
                $('#statistics-report').fadeIn();
            });
        });
    });
</script>