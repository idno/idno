<!-- To silo is human, to syndicate divine -->
<link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars($vars['title']) ?>"
      href="<?php echo $this->getURLWithVar('_t', 'rss'); ?>"/>
<link rel="feed" type="application/rss+xml" title="<?php echo htmlspecialchars($vars['title']) ?>"
      href="<?php echo $this->getURLWithVar('_t', 'rss'); ?>"/>
<link rel="alternate feed" type="application/rss+xml"
      title="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->title) ?>: all content"
      href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>content/all?_t=rss"/>
<link rel="alternate" type="application/json"
      title="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->title) ?>: all content"
      href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>content/all?_t=rss" />
<link rel="feed" type="text/html" title="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->title) ?>"
      href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>content/all"/>

<!-- Webmention endpoint -->
<link href="<?php echo \Idno\Core\Idno::site()->config()->getURL() ?>webmention/" rel="http://webmention.org/"/>
<link href="<?php echo \Idno\Core\Idno::site()->config()->getURL() ?>webmention/" rel="webmention"/>

<?php if (!empty(\Idno\Core\Idno::site()->config()->hub)) { ?>
    <!-- WebSub hub -->
    <link href="<?php echo \Idno\Core\Idno::site()->config()->hub ?>" rel="hub"/>
<?php }
