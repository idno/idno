<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Plugins') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('Plugins allow you to add features to your site. These include new kinds of content, options to syndicate content to different sites, and features to change the way Idno behaves. To enable or disable a plugin, just click its enable or disable button.') ?>
    </p>

    <?php
    $display = [];
    if (!empty($vars['plugins_stored']) && is_array($vars['plugins_stored'])) {
        foreach ($vars['plugins_stored'] as $shortname => $plugin) {
            if (\Idno\Core\Idno::site()->plugins()->isVisible($shortname)) {
                $plugin['shortname'] = $shortname;
                $display[$plugin['Plugin description']['name']] = $this->__(array('plugin' => $plugin))->draw('admin/plugins/plugin');
            }
        }
    }
    ksort($display);
    echo implode('', $display);
    ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('submit', function(e) {
            var form = e.target.closest('form');
            if (!form || !form.querySelector('[name="plugin_action"]')) return;
            e.preventDefault();

            var container = form.querySelector('[name="container"]');
            if (!container) return;
            var containerId = container.value;

            var formData = new URLSearchParams(new FormData(form)).toString();
            fetch(form.action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Re-fetch the page to get updated plugin card HTML
                fetch(form.action)
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var updated = doc.getElementById(containerId);
                    var current = document.getElementById(containerId);
                    if (updated && current) {
                        current.innerHTML = updated.innerHTML;
                    }
                });
            });
        });
    });
</script>
