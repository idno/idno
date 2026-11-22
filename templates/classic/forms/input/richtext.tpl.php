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
    $value = $this->autop($vars['value']);
} else {
    $value = '';
}

?>

<?php

if (!empty($vars['label'])) {

    ?>
        <label for="<?php echo $unique_id?>"><?php echo htmlspecialchars($vars['label'])?></label>
        <?php

}

?>
<div class="richtext-container">
    <!--<br class="clearall">-->
    <textarea name="<?php echo $vars['name']?>"  placeholder="<?php echo htmlspecialchars($placeholder);?>" style="height:<?php echo $height?>px"
          class="bodyInput mentionable wysiwyg form-control <?php echo $class?> <?php if (!empty($vars['required'])) echo 'validation-required'; ?>" id="<?php echo $unique_id?>"><?php echo (htmlspecialchars($value)) ?></textarea>

    <?php
    if (!empty($vars['required'])) { ?>
        <div class="required-text alert alert-danger" style="display:none;"><?php echo \Idno\Core\Idno::site()->language()->_('Please complete this field.'); ?></div>
    <?php } ?>
        <br />
</div>    

<script>

    $(document).ready(function () {
        makeRichText('#<?php echo $unique_id?>');
    });

    function makeRichText(container) {
        $(container).tinymce({
            selector: 'textarea',
            license_key: 'gpl',
            statusbar: false,
            <?php if (!empty($vars['wordcount'])) {
                ?>statusbar: true, <?php
            } else {
                ?>statusbar: false,<?php
            } ?>
            branding: false,
            menubar: false,
            height: <?php echo $height?>,
            min_height: <?php echo $height?>,
            resize: true,
            relative_urls : false,
            remove_script_host : false,
            convert_urls : true,
            valid_children : "+body[style]",
            invalid_elements: 'section',
            valid_styles : 'font-style,color,text-align,text-decoration,float,display,margin-left,margin-right',
            file_picker_callback: function (callback, value, meta) {
                filePickerDialog(callback, value, meta);
            },
            toolbar: 'styles | bold italic | link image | blockquote bullist numlist | alignleft aligncenter alignright | emoticons | code',
            plugins: 'code link image autoresize lists emoticons <?php
            if (!empty($vars['wordcount'])) { echo " wordcount";
            } ?>',
        });
    }

    function filePickerDialog(callback, value, meta) {
        tinymce.activeEditor.windowManager.openUrl({
            title: 'File Manager',
            //url: '<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>filepicker/?type=' + meta.filetype,
            url: '<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>filepicker/?type=image',
            width: 650,
            height: 550,
            onMessage: function(api, message) {
                       callback(message.data.url);
            }
        });
    }
</script>
<?php

// Expose this control to the api
$this->documentFormControl($name, [
    'type' => 'richtext',
    'id' => $unique_id,
    'required' => !empty($vars['required']),
    'description' => $placeholder
]);

// Prevent bonita leakage
foreach (['unique_id', 'class', 'height', 'placeholder', 'value', 'required', 'wordcount', 'name', 'value', 'required'] as $var)
    unset($this->vars[$var]);
