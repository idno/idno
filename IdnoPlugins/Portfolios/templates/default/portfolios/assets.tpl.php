<?php
    $base = \Idno\Core\Idno::site()->config()->getStaticURL() . 'IdnoPlugins/Portfolios/';
    $root = \Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/Portfolios/';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($base . 'css/portfolios.css?v=' . (int) @filemtime($root . 'css/portfolios.css'), ENT_QUOTES) ?>">
<script defer src="<?php echo htmlspecialchars($base . 'js/portfolios.js?v=' . (int) @filemtime($root . 'js/portfolios.js'), ENT_QUOTES) ?>"></script>
