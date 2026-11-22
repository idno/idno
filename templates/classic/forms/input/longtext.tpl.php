<?php

if (!empty($vars['unique_id'])) {
    $unique_id = $vars['unique_id'];
} else {
    $unique_id = 'body' . rand(0, 9999);
}
if (!empty($vars['class'])) {
    $class = $vars['class'];
} else {
    $class = '';
}
if (!empty($vars['height'])) {
    $height = $vars['height'];
} else {
    $height = 500;
}
if (!empty($vars['placeholder'])) {
    $placeholder = $vars['placeholder'];
} else {
    $placeholder = \Idno\Core\Idno::site()->language()->_('Share something brilliant...');
}
if (!empty($vars['value'])) {
    $value = $vars['value'];
} else {
    $value = '';
}
    $required = "";
if (!empty($vars['required'])) {
    $required = "required";
}
?>

<br class="clearall">
<textarea name="<?php echo $vars['name']?>"  placeholder="<?php echo htmlspecialchars($placeholder);?>" style="height:<?php echo $height?>px"
          class="bodyInput mentionable form-control <?php echo $class?>" id="<?php echo $unique_id?>" <?php echo $required; ?>><?php echo (htmlspecialchars($value)) ?></textarea>
<?php

// Expose this control to the api
$this->documentFormControl(
    $name, [
    'type' => 'longtext',
    'id' => $unique_id,
    'required' => !empty($required),
    'description' => $placeholder
    ]
);


// Prevent bonita leakage
foreach (['unique_id', 'class', 'height', 'placeholder', 'value', 'required', 'name', 'value'] as $var) {
    unset($this->vars[$var]);
}

