<?php

if (!empty($vars['service']) && !empty($vars['username'])) {

    if (empty($vars['name'])) {
        $vars['name'] = $vars['username'];
    }

    $identifier = preg_replace("/[^A-Za-z0-9 ]/", '', $vars['username']);

    $human_name = str_replace(' ', '&nbsp;', htmlspecialchars($vars['name']));
    $human_icon = $this->draw('content/syndication/icon/' . $vars['service']);
    if (empty($human_icon)) {
        $human_icon = $this->draw('content/syndication/icon/generic');
    }
    $human_icon = htmlspecialchars($human_icon);

    ?>
        <span class="syndication-toggle">
            <input type="checkbox" class="checkbox" <?php echo $vars['disabled']?> name="syndication[]" id="syndication_<?php echo $vars['service']?>_<?php echo $identifier?>_toggle" value="<?php echo $vars['service']?>::<?php echo htmlentities($vars['username'])?>" data-toggle="toggle" data-onstyle="info" data-on="<?php echo $human_icon;?>&nbsp;<?php echo $human_name;?>" data-off="<?php echo $human_icon;?>&nbsp;<?php echo $human_name;?>" <?php if ($vars['selected'] == true) echo 'checked'; ?>>
        </span>
    <?php

    $this->documentFormControl("syndication[]", [
        'type' => 'checkbox',
        'disabled' => !empty($vars['disabled']),
        'id' => "syndication_{$vars['service']}_{$identifier}_toggle",
        'service' => $vars['service'],
        'username' => $vars['username'],
        'name' => $vars['name'],
        'checked' => $vars['selected'] == true
    ]);
}