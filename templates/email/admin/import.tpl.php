<div style="font-weight: bold; font-size: 30px; line-height: 32px; color: #333" align="center">
    Your data import is done!
</div><br>
<hr/>
<br>
We've taken the data you uploaded and turned it into Idno posts. Enjoy!
<?php if (!empty($vars['imported'])) { ?><br><br>
<?php echo (int)$vars['imported']; ?> posts were imported<?php if (!empty($vars['skipped'])) { echo ', and ' . (int)$vars['skipped'] . ' items in the file were not (pages, attachments, drafts of other types, or items that could not be imported)'; } ?>.
<?php } ?>
<br><br>
<div align="center">
    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>" style="background-color:#73B2E3;border:1px solid #73B2E3;border-radius:4px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:17px;font-weight:normal;line-height:40px;text-align:center;text-decoration:none;width:200px;-webkit-text-size-adjust:none;mso-hide:all;">Visit your site</a>
</div>
<br>
<br>
<br>