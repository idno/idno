<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Themes') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('Themes allow you to change the way your site looks. The following themes are installed.') ?>
    </p>

    <?php
    if (!empty($vars['themes_stored']) && is_array($vars['themes_stored'])) {
        $currentTheme = !empty($vars['theme']) ? $vars['theme'] : false;

        // Draw active theme first
        foreach ($vars['themes_stored'] as $shortname => $theme) {
            $theme['shortname'] = $shortname;
            if ($theme['shortname'] == $currentTheme) {
                echo $this->__(array('theme' => $theme))->draw('admin/themes/theme');
            }
        }

        // Draw remaining themes
        foreach ($vars['themes_stored'] as $shortname => $theme) {
            $theme['shortname'] = $shortname;
            if ($theme['shortname'] != $currentTheme) {
                echo $this->__(array('theme' => $theme))->draw('admin/themes/theme');
            }
        }
    }
    ?>
