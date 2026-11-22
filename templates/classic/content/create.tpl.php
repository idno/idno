<?php

if (!empty($vars['contentTypes']) && is_array($vars['contentTypes'])) {

    ?>
<div class="buttonBar">
    <div class="row ">
        <div class="col-md-12">
            <div id="contentTypeButtonBar">
            <?php

            foreach ($vars['contentTypes'] as $contentType) {
                /* @var Idno\Common\ContentType $contentType */
                $entityType = $contentType->camelCase($contentType->getEntityClassName());
                ?>

                        <a class="contentTypeButton" id="<?php echo $entityType ?>Button"
                           href="<?php echo $contentType->getEditURL() ?>"
                           onclick="event.preventDefault(); contentCreateForm('<?php echo $entityType ?>', '<?php echo $contentType->getEditURL() ?>'); return false;">
                            <span class="contentTypeLogo"><?php echo $contentType->getIcon() ?></span>
                    <?php echo $contentType->getTitle() ?>
                        </a>

                    <?php

            }

            ?>
                <br class="clearall" style="line-height: 0em"/>
            </div>
            <?php

}

?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <div id="contentCreate"></div>
        </div>
    </div>
</div>
<a name="feed"></a>