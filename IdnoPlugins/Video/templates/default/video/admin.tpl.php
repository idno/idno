<?php
    $lang = \Idno\Core\Idno::site()->language();
    $action = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/video/';
?>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu'); ?>
        <h1><?php echo $lang->_('Video'); ?></h1>
        <div class="explanation">
            <p>
                <?php echo htmlspecialchars($lang->_('Members can post videos from %s.', [implode(', ', \IdnoPlugins\Video\VideoUrl::supportedServices())])); ?>
                <?php echo $lang->_('PeerTube and Loops are different: anybody can run one, on any address. When a member posts a video from one, this site asks that address whether it has the video, and shows its player in a restricted frame that cannot take visitors away from your site.'); ?>
            </p>
        </div>
    </div>
</div>
<form action="<?php echo htmlspecialchars($action) ?>" method="post">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <p>
                <label>
                    <input type="checkbox" name="video_open_services" value="1" <?php echo !empty($vars['open']) ? 'checked' : '' ?>>
                    <?php echo $lang->_('Allow videos from PeerTube and Loops sites'); ?>
                </label>
            </p>
            <p>
                <label for="video_blocked_hosts"><?php echo $lang->_('Sites to block'); ?></label>
                <textarea name="video_blocked_hosts" id="video_blocked_hosts" class="form-control" rows="5" placeholder="tube.example.org"><?php echo htmlspecialchars(implode("\n", $vars['blocked'] ?? [])) ?></textarea>
                <span class="help-block"><?php echo $lang->_('One address to a line. Videos from these sites, and from sites under them, can\'t be posted, and ones already posted are shown as a link only.'); ?></span>
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm($action) ?>
                <input type="submit" class="btn btn-primary" value="<?php echo $lang->_('Save'); ?>"/>
            </p>
        </div>
    </div>
</form>
