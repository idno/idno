<?php

if (empty($vars['message'])) { return;
}
    $message = $vars['message'];

?>
<div class="alert <?php echo $message['message_type'] ?> col-md-10 col-md-offset-1">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <?php echo $message['message'] ?>
</div>
