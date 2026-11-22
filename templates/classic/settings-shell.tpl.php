<?php

    $template = $this->formatShellVariables($vars);
    $vars = $template->vars;

    header('Content-type: text/html');
    header("Access-Control-Allow-Origin: *");

?>
<!DOCTYPE html>
<html lang="<?php echo $vars['lang']; ?>">
<head>

<?php
    echo $template->draw('settings-shell/metatags');
    echo $template->draw('settings-shell/icons');
    echo $template->draw('settings-shell/bootstrap');
    echo $template->draw('settings-shell/javascript');
    echo $template->draw('settings-shell/css');
?>

</head>

<body class="settings-template">

    <?php echo $template->draw('settings-shell/nav'); ?>
    
    <div class="col-lg-12 col-12">

        <div class="settings-sidebar col-lg-2 col-md-2 col-sm-2 col-2">
            <?php if (strpos(\Idno\Core\Idno::site()->currentPage()->currentUrl(), \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/')!==false) {
                // This is an admin page
                echo $this->draw('admin/menu');
            } else {
                // Settings page
                echo $this->draw('account/menu');
            }?>
        </div>
        
        <div class="settings-content col-lg-10 col-md-10 col-sm-10 col-10">
    

            <?php echo $template->draw('settings-shell/messages') ?>
            <a name="pagecontent"></a>
            <?php
            if (!empty($vars['body'])) { echo $vars['body'];
            }
            ?>
            
        </div>

<?php

    echo $template->draw('settings-shell/footerjavascript');

?>
    <div class="blank-footer">    


    </div>
        
    <?php echo $template->draw('shell/form-data'); ?>
</body>
</html>
