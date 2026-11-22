<?php

    // The owner's address and image come from whoever sent the annotation
    $owner_url = \Idno\Core\UrlValidator::httpUrl($annotation['owner_url']);
    $owner_image = \Idno\Core\UrlValidator::httpUrl($annotation['owner_image']);
    if ($owner_image !== '') {
        $owner_image = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($owner_image);
    }

?><a href="<?php echo htmlspecialchars($owner_url) ?>" rel="nofollow" class="icon-container"><img
        src="<?php echo htmlspecialchars($owner_image) ?>"
        alt="<?php echo htmlentities($annotation['owner_name']) ?>"/></a>