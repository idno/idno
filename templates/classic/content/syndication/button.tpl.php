<?php

if (!empty($vars['service'])) {
    /*
    ?>
    <span class="syndication_<?=$vars['service']?>">
        <input type="checkbox" name="syndication[]" id="syndication_<?=$vars['service']?>_toggle" value="<?=$vars['service']?>">
        <label for="syndication_<?=$vars['service']?>_toggle"><?=ucfirst($vars['service']);?></label>
    </span>
    <?php
    */

    $human_name = str_replace(' ', '&nbsp;', ucfirst($vars['service']));
    $human_icon = $this->draw('content/syndication/icon/' . $vars['service']);
    if (empty($human_icon)) {
        $human_icon = $this->draw('content/syndication/icon/generic');
    }
    $human_icon = htmlspecialchars($human_icon);

    ?>
        <span class="syndication-toggle">
            <input type="checkbox" name="syndication[]" class="checkbox" <?php echo $vars['disabled']?> id="syndication_<?php echo $vars['service']?>_toggle" value="<?php echo $vars['service']?>" data-toggle="toggle" data-onstyle="info" data-on="<?php echo $human_icon?>&nbsp;<?php echo $human_name?>" data-off="<?php echo $human_icon?>&nbsp;<?php echo $human_name;?>" <?php if ($vars['selected'] == true) echo 'checked'; ?>>
        </span>
        <?php

}