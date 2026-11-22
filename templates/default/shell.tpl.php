<?php
    $template = $this->formatShellVariables($vars);
    $vars = $template->vars;
    $template->draw('shell/headers');
?>
<!DOCTYPE html>
<html lang="<?= $vars['lang'] ?>"<?= $vars['html_className'] ?>>
<head>
<?php
    echo $template->draw('shell/metatags');
    echo $template->draw('shell/icons');
    echo $template->draw('shell/favicon');
    echo $template->draw('shell/webapp');
    echo $template->draw('shell/opengraph');
    echo $template->draw('shell/structured-data');
    echo $template->draw('shell/dublincore');
    echo $template->draw('shell/amp');
    echo $template->draw('shell/bootstrap');
    echo $template->draw('shell/javascript');
    echo $template->draw('shell/css');
    echo $template->draw('shell/activitypub');
    echo $template->draw('shell/syndication');
    echo $template->draw('shell/identities');
    echo $template->draw('shell/head');
    echo $template->draw('shell/monetization');
    echo $template->draw('shell/head/final');
?>
</head>
<body class="<?= $template->getBodyClasses() ?>">
    <div class="idno-shell">
        <?= $template->draw('shell/nav') ?>
        <main class="idno-main">
            <div class="idno-feed h-feed">
                <?= $template->draw('shell/messages') ?>
                <a name="pagecontent"></a>
                <?php
                    echo $template->draw('shell/beforecontent');
                    if (!empty($vars['body'])) {
                        echo $vars['body'];
                    }
                    echo $template->draw('shell/aftercontent');
                ?>
            </div>
        </main>
        <div class="idno-spacer" aria-hidden="true"></div>
    </div>
    <?= $template->draw('shell/aftercontainer') ?>
    <?= $template->draw('shell/contentfooter') ?>
    <?= $template->draw('shell/search') ?>
    <?= $template->draw('shell/compose') ?>
<?php
    echo $template->draw('shell/assets');
    echo $template->draw('shell/footerjavascript');
    echo $template->draw('shell/footer');
    echo $template->draw('shell/form-data');
?>
</body>
</html>
