Your data import is done!
===================

We've taken the data you uploaded and turned it into Idno posts. Enjoy!
<?php if (!empty($vars['imported'])) { echo (int)$vars['imported'] . ' posts were imported' . (!empty($vars['skipped']) ? ', and ' . (int)$vars['skipped'] . ' items in the file were not (pages, attachments, other types, or items that could not be imported)' : '') . ".\n"; } ?>

Visit your site: <?php echo \Idno\Core\Idno::site()->config()->getDisplayURL();
