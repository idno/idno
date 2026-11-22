<?php
if (empty($vars['feed_view'])) {
    ?>
    <h2 class="idno-entry-title p-name">
        <a href="<?= $vars['object']->getDisplayURL() ?>"
           class="u-url"><?= htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8') ?></a>
    </h2>
    <?php
}
$starttime = strtotime($vars['object']->starttime);
$endtime = strtotime($vars['object']->endtime);
$timeformat = 'l, jS F Y h:i A';
?>
<div class="idno-event-details">
    <?php if (!empty($vars['object']->summary)) { ?>
    <p class="p-summary">
        <strong><?= htmlentities(strip_tags($vars['object']->summary), ENT_QUOTES, 'UTF-8') ?></strong>
    </p>
    <?php } ?>
    <?php if (!empty($vars['object']->location)) { ?>
    <p>
        <?= \Idno\Core\Idno::site()->language()->_('Location') ?>: <span
                class="p-location"><?= htmlentities(strip_tags($vars['object']->location), ENT_QUOTES, 'UTF-8') ?></span>
    </p>
    <?php } ?>
    <?php if (!empty($vars['object']->starttime)) { ?>
        <p>
            <time class="dt-start"
                  datetime="<?= date('c', $starttime) ?>"><?= date($timeformat, $starttime) ?></time>
            <?php
            if ($endtime && $endtime < $starttime + 86400) {
                ?>- <time class="dt-end"
                  datetime="<?= date('c', $endtime) ?>"><?= date('h:i A', $endtime) ?></time><?php
            }
            ?>
        </p>
    <?php } ?>
    <?php
    if ($endtime && $endtime >= $starttime + 86400) {
        ?>
        <p>
        <?= \Idno\Core\Idno::site()->language()->_('Ends') ?>:
            <time class="dt-end"
                  datetime="<?= date('c', $endtime) ?>"><?= date($timeformat, $endtime) ?></time>
        </p>
        <?php
    }
    ?>
    <?php if (!empty($vars['object']->timezone)) { ?>
    <p>
        <?= \Idno\Core\Idno::site()->language()->_('Time Zone') ?>:
        <?= $this->__(['value' => $vars['object']->timezone])->draw('forms/output/timezones') ?>
    </p>
    <?php } ?>
</div>
<div class="idno-entry-body e-content">
    <?= $this->autop($this->parseHashtags($this->parseURLs($vars['object']->body))) ?>
</div>
