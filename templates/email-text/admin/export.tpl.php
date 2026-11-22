Your data export is ready!
===================

We've gathered all your site content and placed it in an archive for you. To download it, click the following link:

<?php if (!empty($vars['unrendered'])) { echo (int)$vars['unrendered'] . " entries could not be rendered and are included as data only.\n\n"; } ?>Get your data: <?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/export/
