<?php

    // Default to post
if (strtolower($vars['method']) != 'get') { $vars['method'] = 'post';
}

?>
<form method="<?php echo $vars['method']?>" action="<?php echo $vars['action']?>" enctype="multipart/form-data">
    
    <?php echo $vars['body']?>
    <?php echo $t->draw('forms/token')?>
    
</form>