Sorry, your data import was unsuccessful.
=========================================

<?php if (!empty($vars['stopped'])) { ?>
The import was stopped after repeated errors. <?php echo (int)($vars['imported'] ?? 0); ?> posts were imported before it stopped.
Check the site's log for the cause, and check your posts before you import the file
again, or it may add them twice.
<?php } elseif (!empty($vars['startedBefore'])) { ?>
This import was started before and did not finish, so it was not run again: running it
again would import its posts twice. Some posts may already have been imported. Check
your posts first; if posts are missing, upload the file again.
<?php } else { ?>
The import stopped, and nothing in the file could be imported, or it did not finish.
Some posts may already have been imported. Check your posts before you import the file
again, or it may add them twice. Your system administrator can help: the details are in
the server's log.
<?php } ?>

Visit your site: <?php echo \Idno\Core\Idno::site()->config()->getDisplayURL();
