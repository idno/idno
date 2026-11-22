<div style="font-weight: bold; font-size: 30px; line-height: 32px; color: #333" align="center">
    Sorry, your data import was unsuccessful.
</div><br>
<hr/>
<br>
<?php if (!empty($vars['stopped'])) { ?>
The import was stopped after repeated errors. <?php echo (int)($vars['imported'] ?? 0); ?> posts were imported before it stopped. Check the site's log for the cause, and check your posts before you import the file again, or it may add them twice.
<?php } elseif (!empty($vars['startedBefore'])) { ?>
This import was started before and did not finish, so it was not run again: running it again would import its posts twice. Some posts may already have been imported. Check your posts first; if posts are missing, upload the file again.
<?php } else { ?>
The import stopped, and nothing in the file could be imported, or it did not finish. Some posts may already have been imported. Check your posts before you import the file again, or it may add them twice. Your system administrator can help: the details are in the server's log.
<?php } ?>
<br><br>
<div align="center">
    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>" style="background-color:#73B2E3;border:1px solid #73B2E3;border-radius:4px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:17px;font-weight:normal;line-height:40px;text-align:center;text-decoration:none;width:200px;-webkit-text-size-adjust:none;mso-hide:all;">Visit your site</a>
</div>
<br>
<br>
<br>