<?php
    $template = $this->formatShellVariables($vars);
    $vars = $template->vars;
    $template->draw('shell/headers');
?>
<!DOCTYPE html>
<html lang="<?= $vars['lang'] ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(\Idno\Core\Idno::site()->config()->getTitle()) ?> — Admin</title>
    <link rel="stylesheet" href="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>css/idno-2026.min.css">
<?php
    // Render any plugin-registered CSS assets
    if ($assets = \Idno\Core\Idno::site()->currentPage()->getAssets('css')) {
        foreach ($assets as $asset) {
            echo '    <link rel="stylesheet" href="' . htmlspecialchars($asset) . '">' . "\n";
        }
    }
?>
</head>
<body class="admin-template">
    <div class="idno-admin-shell">
        <aside class="idno-admin-sidebar">
            <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/" class="idno-admin-logo">
                <?= htmlspecialchars(\Idno\Core\Idno::site()->config()->getTitle()) ?>
            </a>
            <?= $this->draw('admin/menu') ?>
            <div style="margin-top:auto;padding:0 0.75rem">
                <a href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-admin-nav-item">
                    <?= $this->__(['icon' => 'arrow-left'])->draw('shell/icon') ?>
                    <span><?= \Idno\Core\Idno::site()->language()->_('Back to site') ?></span>
                </a>
            </div>
        </aside>
        <main class="idno-admin-main">
            <div class="idno-admin-content">
                <?= $template->draw('shell/messages') ?>
                <?php
                    if (!empty($vars['body'])) {
                        echo $vars['body'];
                    }
                ?>
            </div>
        </main>
    </div>
    <script src="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>js/idno-2026.min.js" defer></script>
<?php
    // Render any plugin-registered JS assets
    if ($assets = \Idno\Core\Idno::site()->currentPage()->getAssets('javascript')) {
        foreach ($assets as $asset) {
            echo '    <script src="' . htmlspecialchars($asset) . '"></script>' . "\n";
        }
    }
    echo $template->draw('shell/form-data');
?>
</body>
</html>
