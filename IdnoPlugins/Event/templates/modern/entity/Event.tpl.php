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
<?php if (!empty($vars['object']->summary)) { ?>
<p class="idno-entry-summary p-summary">
    <strong><?= htmlentities(strip_tags($vars['object']->summary), ENT_QUOTES, 'UTF-8') ?></strong>
</p>
<?php } ?>
<div class="idno-details idno-event-details">
    <?php if (!empty($vars['object']->starttime)) { ?>
        <p class="idno-details-row">
            <?= $this->__(['icon' => 'calendar', 'class' => 'idno-details-icon'])->draw('shell/icon') ?>
            <span>
            <time class="dt-start"
                  datetime="<?= date('c', $starttime) ?>"><?= date($timeformat, $starttime) ?></time>
            <?php
            if ($endtime && $endtime < $starttime + 86400) {
                ?>- <time class="dt-end"
                  datetime="<?= date('c', $endtime) ?>"><?= date('h:i A', $endtime) ?></time><?php
            }
            ?>
            </span>
        </p>
    <?php } ?>
    <?php
    if ($endtime && $endtime >= $starttime + 86400) {
        ?>
        <p class="idno-details-row">
            <?= $this->__(['icon' => 'calendar', 'class' => 'idno-details-icon'])->draw('shell/icon') ?>
            <span>
        <?= \Idno\Core\Idno::site()->language()->_('Ends') ?>:
            <time class="dt-end"
                  datetime="<?= date('c', $endtime) ?>"><?= date($timeformat, $endtime) ?></time>
            </span>
        </p>
        <?php
    }
    ?>
    <?php if (!empty($vars['object']->timezone)) { ?>
    <p class="idno-details-row">
        <?= $this->__(['icon' => 'clock', 'class' => 'idno-details-icon'])->draw('shell/icon') ?>
        <span>
        <?= \Idno\Core\Idno::site()->language()->_('Time zone') ?>:
        <?= $this->__(['value' => $vars['object']->timezone])->draw('forms/output/timezones') ?>
        </span>
    </p>
    <?php } ?>
    <?php if (!empty($vars['object']->location)) { ?>
    <p class="idno-details-row">
        <?= $this->__(['icon' => 'map-pin', 'class' => 'idno-details-icon'])->draw('shell/icon') ?>
        <span>
        <?= \Idno\Core\Idno::site()->language()->_('Location') ?>: <span
                class="p-location"><?= htmlentities(strip_tags($vars['object']->location), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    </p>
    <?php } ?>
</div>
<div class="idno-entry-body e-content">
    <?= $this->autop($this->parseHashtags($this->parseURLs($vars['object']->body))) ?>
</div>
