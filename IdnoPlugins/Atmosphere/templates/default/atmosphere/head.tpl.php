<?php

if (!empty($vars['object'])
    && $vars['object'] instanceof \Idno\Common\Entity
    && !empty($vars['object']->standardsitesync_uri)
) {
    ?>
    <link rel="site.standard.document" href="<?php echo htmlspecialchars($vars['object']->standardsitesync_uri) ?>" />
    <?php
}
