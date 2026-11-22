<div class="row">

    <div class="col-md-10 col-md-offset-1">
    <?php echo $this->draw('admin/menu')?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Plugins'); ?></h1>

        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Plugins allow you to add features to your site. These include new kinds of content, options to syndicate content to different sites, and features to change the way Idno behaves. To enable or disable a plugin, just click its enable or disable button.'); ?>
            </p>
        </div>
        <?php

            $display = [];
        if (!empty($vars['plugins_stored']) && is_array($vars['plugins_stored'])) {
            foreach($vars['plugins_stored'] as $shortname => $plugin) {
                if (\Idno\Core\Idno::site()->plugins()->isVisible($shortname)) {
                    $plugin['shortname'] = $shortname;
                    $display[$plugin['Plugin description']['name']] = $this->__(array('plugin' => $plugin))->draw('admin/plugins/plugin');
                }
            }
        }
            ksort($display);
            echo implode('', $display);

        ?>
    </div>

</div>
<script>
    var pluginFunction = function(e) {
        e.preventDefault();
        var form = $(this).closest('form');
        var formData = {};
        form.serializeArray().map(function(x){formData[x.name] = x.value;});
        $.ajax(form.attr('action'), {
            method: 'POST',
            dataType: 'html',
            data: formData
        })
            .done(function(data, status, xhr) {
                $.ajax(form.attr('action'))
                    .done(function(data) {
                        var div = '#' + formData.container;
                        var result = $(data).find(div);
                        $(div).html(result.html());
                        $(div + ' .plugin-button').on('click', pluginFunction);
                    });
            });
    };

    $('.plugin-button').on('click', pluginFunction);
</script>