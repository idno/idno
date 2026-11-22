<div style="font-weight: bold; font-size: 30px; line-height: 32px; color: #333" align="center">
    Your data export is ready!
</div><br>
<hr/>
<br>
We've gathered all your site content and placed it in an archive for you. To download it, click the following link:
<br><br>
<?php if (!empty($vars['unrendered'])) { ?>
<p><?php echo (int)$vars['unrendered']; ?> entries could not be rendered and are included as data only.</p>
<?php } ?>
<div align="center">
    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/export/" style="background-color:#73B2E3;border:1px solid #73B2E3;border-radius:4px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:17px;font-weight:normal;line-height:40px;text-align:center;text-decoration:none;width:200px;-webkit-text-size-adjust:none;mso-hide:all;">Get your data</a>
</div>
<br>
<br><br>