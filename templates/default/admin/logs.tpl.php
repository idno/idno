<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Logs') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('This page provides you with captured PHP logs. These contain sensitive information, so be very careful how you disclose the information and to whom.') ?>
    </p>

    <div class="idno-admin-card">
        <div id="logs-report" style="display:none">
            <pre class="idno-admin-log-viewer" style="max-height:600px;overflow:auto;font-size:0.8125rem"></pre>
        </div>
        <button class="idno-btn idno-btn-primary" id="logs-report-run"><?= \Idno\Core\Idno::site()->language()->_('Refresh...') ?></button>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var logPre = document.querySelector('#logs-report pre');
        var logDiv = document.getElementById('logs-report');
        var logBtn = document.getElementById('logs-report-run');
        var logUrl = '<?= \Idno\Core\Idno::site()->currentPage()->currentUrl() ?>';

        function loadLogs() {
            fetch(logUrl)
                .then(function(r) { return r.text(); })
                .then(function(data) {
                    logPre.textContent = data;
                    logDiv.style.display = '';
                });
        }

        loadLogs();

        logBtn.addEventListener('click', function() {
            loadLogs();
        });
    });
</script>
