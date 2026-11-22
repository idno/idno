<?php
if (empty($vars['feed_view'])) {

    ?>
    <h2 class="p-name">
        <a href="<?php echo $vars['object']->getDisplayURL() ?>"
           class="u-url"><?php echo htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8'); ?></a>
    </h2>
    <?php

}
$starttime = strtotime($vars['object']->starttime);
$endtime = strtotime($vars['object']->endtime);
$timeformat = 'l, jS F Y h:i A';
?>
<div class="well">
    <p class="p-summary">
        <strong><?php echo htmlentities(strip_tags($vars['object']->summary), ENT_QUOTES, 'UTF-8'); ?></strong>
    </p>
    <p>
        <?php echo \Idno\Core\Idno::site()->language()->_('Location'); ?>: <span
                class="p-location"><?php echo htmlentities(strip_tags($vars['object']->location), ENT_QUOTES, 'UTF-8'); ?></span>
    </p>
    <?php if (!empty($vars['object']->starttime)) { ?>
        <p>
            <time class="dt-start"
                  datetime="<?php echo date('c', $starttime) ?>"><?php echo date($timeformat, $starttime)?></time>
            <?php

            if ($endtime && $endtime < $starttime + 86400) {
                ?>- <time class="dt-end"
                  datetime="<?php echo date('c', $endtime) ?>"><?php echo date('h:i A', $endtime);?></time><?php
            }

            ?>
        </p>
        <?php
    }
    ?>
    <?php
    if ($endtime && $endtime >= $starttime + 86400) {
        ?>
        <p>
        <?php echo \Idno\Core\Idno::site()->language()->_('Ends'); ?>:
            <time class="dt-end"
                  datetime="<?php echo date('c', $endtime) ?>"><?php echo date($timeformat, $endtime) ?></time>
        </p>
        <?php
    }
    ?>
    <?php if (!empty($vars['object']->timezone)) { ?>
    <p>
        <?php echo \Idno\Core\Idno::site()->language()->_('Time Zone'); ?>:
        <?php echo $this->__(['value' => $vars['object']->timezone])->draw('forms/output/timezones'); ?>
    </p>
    <?php } ?>
</div>
<div class="e-content">
    <?php echo $this->autop($this->parseHashtags($this->parseURLs($vars['object']->body))) ?>
</div>
