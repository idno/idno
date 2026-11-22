<?php
$baseURL = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>

<li <?php if ($_SERVER['REQUEST_URI'] == '/account/indiepub/') echo 'class="active"'; ?> role="presentation">
  <a href="<?php echo $baseURL ?>account/indiepub/"><?php echo \Idno\Core\Idno::site()->language()->_('IndiePub'); ?></a>
</li>
