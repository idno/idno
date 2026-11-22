<link rel="stylesheet" href="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>css/idno-2026.min.css" />
<?php
// Render any plugin-registered CSS assets
if ($assets = \Idno\Core\Idno::site()->currentPage()->getAssets('css')) {
    foreach ($assets as $asset) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($asset) . '" />' . "\n";
    }
}
?>
