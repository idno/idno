<?php

    /*
     * A member's moderation record: reports about them, what was done, their own use of
     * reporting, and ban / lift ban. Administrators only.
     */

    $language = \Idno\Core\Idno::site()->language();
    /* @var \Idno\Entities\User $member */
    $member = $vars['member'];
    $name = strip_tags((string) $member->getTitle());
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/';
    $url = $base . 'member/' . $member->getID();
    $sign = \Idno\Core\Idno::site()->actions()->signForm($url);
    $ban = $member->getBan();
    $outcomes = \IdnoPlugins\Moderation\Outcomes::labels();
    $kinds = ['post' => $language->_('Post'), 'comment' => $language->_('Comment'), 'profile' => $language->_('Profile')];

    echo $this->__(['tab' => 'member'])->draw('moderation/admin/header');

?>
<div class="mod-wide mod-member-page">
    <div class="mod-card mod-member-head">
        <img class="mod-avatar mod-avatar-lg" src="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl((string) $member->getIcon()), ENT_QUOTES) ?>" alt="">
        <div>
            <h2 class="mod-case-title"><?php echo htmlspecialchars($name) ?></h2>
            <p class="mod-muted">@<?php echo htmlspecialchars((string) $member->getHandle()) ?> · <?php echo htmlspecialchars($language->_('Member since %s', [date('M Y', (int) $member->created)])) ?> · <a href="<?php echo htmlspecialchars($member->getDisplayURL(), ENT_QUOTES) ?>"><?php echo $language->_('Profile') ?></a></p>
            <p>
                <?php if ($ban) { ?>
<span class="mod-pill mod-pill-urgent"><?php echo $language->_('Banned') ?></span>
                <?php } ?>
                <?php if (!empty($member->admin)) { ?>
<span class="mod-pill"><?php echo $language->_('Administrator') ?></span>
                <?php } ?>
                <span class="mod-muted"><?php echo htmlspecialchars($language->_('Reports filed: %d · dismissed: %d', [$vars['reporter_record']['filed'], $vars['reporter_record']['dismissed']])) ?></span>
            </p>
        </div>
    </div>

    <?php if ($ban) {
        $by = !empty($ban['by']) ? \Idno\Entities\User::getByUUID($ban['by']) : null;
        ?>
    <div class="mod-card">
        <h3 class="mod-card-title"><?php echo htmlspecialchars($language->_('Banned on %s', [date('j M Y, H:i', (int) $ban['time'])])) ?><?php if ($by) echo ' ' . htmlspecialchars($language->_('by %s', [strip_tags((string) $by->getTitle())])); ?></h3>
        <p><?php echo (string) $ban['reason'] !== '' ? nl2br(htmlspecialchars((string) $ban['reason'])) : '<span class="mod-muted">' . $language->_('No reason recorded') . '</span>' ?></p>
        <?php if ($member->isHiddenByBan()) { ?>
<p class="mod-muted"><?php echo $language->_('Their profile and posts are hidden.') ?></p>
        <?php } ?>
        <form method="post" action="<?php echo htmlspecialchars($url, ENT_QUOTES) ?>"
              data-mod-confirm-title="<?php echo htmlspecialchars($language->_('Lift the ban on %s?', [$name]), ENT_QUOTES) ?>"
              data-mod-confirm-text="<?php echo htmlspecialchars($language->_('They will be able to sign in and post again, and any posts the ban hid will come back. They get an email saying so.'), ENT_QUOTES) ?>"
              data-mod-confirm-button="<?php echo htmlspecialchars($language->_('Lift ban'), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="unban">
            <?php echo $sign ?>
            <button type="submit" class="mod-btn"><?php echo $language->_('Lift ban') ?></button>
        </form>
    </div>
    <?php } else if ($member->getUUID() !== $vars['admin']->getUUID()) { ?>
    <details class="mod-ban">
        <summary class="mod-btn mod-btn-danger"><?php echo htmlspecialchars($language->_('Ban %s…', [$name])) ?></summary>
        <form method="post" action="<?php echo htmlspecialchars($url, ENT_QUOTES) ?>" class="mod-card mod-ban-form">
            <h3><?php echo htmlspecialchars($language->_('Ban %s?', [$name])) ?></h3>
            <p><?php echo htmlspecialchars($language->_('%s will be signed out everywhere right away and will not be able to sign in or post. Their API key and connected apps stop working, and their profile stops being served to other servers.', [$name])) ?></p>
            <label class="mod-check"><input type="checkbox" name="hide_all" value="1"> <span><strong><?php echo $language->_('Also hide everything they have posted') ?></strong><small><?php echo $language->_('Their profile and posts disappear for everyone but moderators. Lifting the ban brings them back.') ?></small></span></label>
            <label class="mod-label" for="mod-ban-reason"><?php echo $language->_('Reason') ?> <span class="mod-muted"><?php echo $language->_('(only moderators see this)') ?></span></label>
            <textarea id="mod-ban-reason" class="mod-textarea" name="ban_reason" rows="2" maxlength="<?php echo \IdnoPlugins\Moderation\Moderation::NOTE_LENGTH ?>"></textarea>
            <label class="mod-check"><input type="checkbox" name="notify" value="1" checked> <span><?php echo $language->_('Email them to say their account was banned') ?></span></label>
            <input type="hidden" name="action" value="ban">
            <?php echo $sign ?>
            <div class="mod-ban-buttons"><button type="submit" class="mod-btn mod-btn-danger"><?php echo htmlspecialchars($language->_('Ban %s', [$name])) ?></button></div>
        </form>
    </details>
    <?php } ?>

    <h3 class="mod-section"><?php echo htmlspecialchars($language->_('Reports about %s (%d)', [$name, count($vars['cases'])])) ?></h3>
    <?php if (empty($vars['cases'])) { ?>
    <div class="mod-empty"><strong><?php echo $language->_('No reports.') ?></strong><span><?php echo $language->_('Nobody has reported this member or anything they posted.') ?></span></div>
    <?php } else { ?>
    <div class="mod-card mod-table-card">
        <table class="mod-table">
            <thead><tr><th scope="col"><?php echo $language->_('What') ?></th><th scope="col"><?php echo $language->_('Reasons') ?></th><th scope="col"><?php echo $language->_('Reported') ?></th><th scope="col"><?php echo $language->_('Outcome') ?></th></tr></thead>
            <tbody>
            <?php foreach ($vars['cases'] as $case) {
                $text = trim((string) ($case->snapshot['text'] ?? ''));
                $text = mb_strlen($text) > 70 ? mb_substr($text, 0, 70) . '…' : $text;
                ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($case->getAdminURL(), ENT_QUOTES) ?>"><strong><?php echo htmlspecialchars($kinds[$case->target_type] ?? '') ?></strong></a><?php if ($text !== '') { ?>
<small><?php echo htmlspecialchars($text) ?></small>
                   <?php } ?></td>
                    <td><?php foreach (array_keys(is_array($case->reasons) ? $case->reasons : []) as $slug) { ?>
<span class="mod-pill<?php if (\IdnoPlugins\Moderation\Reasons::isUrgent($slug)) echo ' mod-pill-urgent'; ?>"><?php echo htmlspecialchars(\IdnoPlugins\Moderation\Reasons::label($slug)) ?></span>
                   <?php } ?></td>
                    <td><?php echo htmlspecialchars(date('j M Y', (int) $case->first_reported)) ?></td>
                    <td><?php echo $case->isOpen() ? $language->_('Unresolved') : htmlspecialchars($outcomes[$case->outcome] ?? '') ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>

    <?php if (!empty($vars['log'])) { ?>
    <h3 class="mod-section"><?php echo $language->_('History') ?></h3>
    <div class="mod-card"><?php echo $this->__(['entries' => $vars['log'], 'links' => true])->draw('moderation/admin/log'); ?></div>
    <?php } ?>
</div>
