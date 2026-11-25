<?php
    $lang = \Idno\Core\Idno::site()->language();
    $action = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/video/';
?>
<h1 class="idno-admin-page-title"><?= $lang->_('Video') ?></h1>
<p class="idno-admin-description">
    <?= htmlspecialchars($lang->_('Members can post videos from %s.', [implode(', ', \IdnoPlugins\Video\VideoUrl::supportedServices())])) ?>
    <?= $lang->_('PeerTube and Loops are different: anybody can run one, on any address. When a member posts a video from one, this site asks that address whether it has the video, and shows its player in a restricted frame that cannot take visitors away from your site.') ?>
</p>

<form action="<?= htmlspecialchars($action) ?>" method="post">
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= $lang->_('The open social web') ?></h2>

        <div class="idno-form-group">
            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer">
                <input type="checkbox" name="video_open_services" value="1" <?= !empty($vars['open']) ? 'checked' : '' ?>>
                <span><?= $lang->_('Allow videos from PeerTube and Loops sites') ?></span>
            </label>
        </div>

        <div class="idno-form-group">
            <label class="idno-label" for="video_blocked_hosts"><?= $lang->_('Sites to block') ?></label>
            <textarea name="video_blocked_hosts" id="video_blocked_hosts" class="idno-input" style="height:8em;resize:vertical" placeholder="tube.example.org"><?= htmlspecialchars(implode("\n", $vars['blocked'] ?? [])) ?></textarea>
            <p class="idno-form-help"><?= $lang->_('One address to a line. Videos from these sites, and from sites under them, can\'t be posted, and ones already posted are shown as a link only.') ?></p>
        </div>
    </div>

    <div class="idno-form-actions" style="margin-top:var(--spacing-section,1.5rem)">
        <button type="submit" class="idno-btn idno-btn-primary"><?= $lang->_('Save') ?></button>
    </div>

    <?= \Idno\Core\Idno::site()->actions()->signForm($action) ?>
</form>
